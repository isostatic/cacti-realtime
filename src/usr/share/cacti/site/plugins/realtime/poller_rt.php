<?php
/*
 ex: set tabstop=4 shiftwidth=4 autoindent:
 +-------------------------------------------------------------------------+
 | Copyright (C) 2008 Mathieu Virbel <mathieu.v@capensis.fr>               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}

/* we are not talking to the browser */
$no_http_headers = true;

/* start initialization section */
include(dirname(__FILE__) . "/../../include/global.php");
include_once($config["base_path"] . "/lib/poller.php");
include_once($config["base_path"] . "/lib/data_query.php");
include_once($config["base_path"] . "/lib/graph_export.php");
include_once($config["base_path"] . "/lib/rrd.php");

/* initialize some variables */
$force    = FALSE;
$debug    = FALSE;
$graph_id = FALSE;
$interval = FALSE;

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

if (sizeof($parms)) {
foreach($parms as $parameter) {
	@list($arg, $value) = @explode("=", $parameter);

	switch ($arg) {
	case "-d":
	case "--debug":
		$debug = TRUE;

		break;
	case "--force":
		$force = TRUE;
		break;
	case "--graph":
		$graph_id = (int)$value;
		break;
	case "--interval":
		$interval = (int)$value;
		break;
	case "--version":
	case "-V":
	case "-H":
	case "--help":
		display_help();
		exit(0);
	default:
		echo "ERROR: Invalid Argument: ($arg)\n\n";
		display_help();
		exit(1);
	}
}
}

if ($graph_id === FALSE || $graph_id < 0) {
	echo "ERROR: No --graph=ID specified\n\n";
	display_help();
	exit(1);
}

if ($interval === FALSE || $interval < 0) {
	echo "ERROR: No --interval=SEC specified\n\n";
	display_help();
	exit(1);
}

/* record the start time */
list($micro,$seconds) = split(" ", microtime());
$poller_start         = $seconds + $micro;

/* get number of polling items from the database */
$poller_interval = 1;

/* retreive the last time the poller ran */
$poller_lastrun = read_config_option('poller_lastrun');

/* get the current cron interval from the database */
$cron_interval = read_config_option("cron_interval");

if ($cron_interval != 60) {
	$cron_interval = 300;
}

/* assume a scheduled task of either 60 or 300 seconds */
define("MAX_POLLER_RUNTIME", 298);

/* let PHP only run 1 second longer than the max runtime, plus the poller needs lot's of memory */
ini_set("max_execution_time", MAX_POLLER_RUNTIME + 1);

/* initialize file creation flags */
$change_files = false;

/* obtain some defaults from the database */
$max_threads = read_config_option("max_threads");

/* create a poller_id */
$poller_id = rand(20, 65535);

/* Determine Command Name */
$command_string = read_config_option("path_php_binary");
$extra_args     = "-q " . $config["base_path"] . "/plugins/realtime/cmd_rt.php $poller_id $graph_id $interval";
$method         = "cmd_rt.php";

/* Determine if Realtime will work or not */
$cache_dir = read_config_option("realtime_cache_path");
if (!is_dir($cache_dir)) {
	cacti_log("FATAL: Realtime Cache Directory '$cache_dir' Does Not Exist!");
	return -1;
}elseif (!is_writable($cache_dir)) {
	cacti_log("FATAL: Realtime Cache Directory '$cache_dir' is Not Writable!");
	return -2;
}

shell_exec("$command_string $extra_args");
usleep(100000);

/* open a pipe to rrdtool for writing */
$rrdtool_pipe = rrd_init();

/* process poller output */
process_poller_output_rt($rrdtool_pipe, $poller_id, $interval);

/* close rrd */
rrd_close($rrdtool_pipe);

/* close db */
db_close();

function display_help() {
	echo "Cacti Poller Version " . db_fetch_cell("SELECT cacti FROM version") . ", Copyright 2007-2010 - The Cacti Group\n\n";
	echo "A simple command line utility to run the Cacti Poller.\n\n";
	echo "usage: poller_rt.php --graph=ID [--force] [--debug|-d]\n\n";
	echo "Options:\n";
	echo "    --graph=ID     Specify the graph id to convert (realtime)\n";
	echo "    --interval=SEC Specify the graph interval (realtime)\n";
	echo "    --force        Override poller overrun detection and force a poller run\n";
	echo "    --debug|-d     Output debug information.  Similar to cacti's DEBUG logging level.\n\n";
}

/* process_poller_output REAL TIME MODIFIED */
function process_poller_output_rt($rrdtool_pipe, $poller_id, $interval) {
	global $config;

	include_once($config["library_path"] . "/rrd.php");

	/* let's count the number of rrd files we processed */
	$rrds_processed = 0;

	/* create/update the rrd files */
	$results = db_fetch_assoc("SELECT
		poller_output_rt.output,
		poller_output_rt.time,
		poller_output_rt.local_data_id,
		poller_item.rrd_path,
		poller_item.rrd_name,
		poller_item.rrd_num
		FROM (poller_output_rt,poller_item)
		WHERE (poller_output_rt.local_data_id=poller_item.local_data_id
		AND poller_output_rt.rrd_name=poller_item.rrd_name)
		AND poller_output_rt.poller_id = $poller_id");

	if (sizeof($results) > 0) {
		/* create an array keyed off of each .rrd file */
		foreach ($results as $item) {
			$rt_graph_path    = read_config_option("realtime_cache_path") . "/realtime_" . $item["local_data_id"] . "_5.rrd";
			$data_source_path = get_data_source_path($item['local_data_id'], true);

			/* create rt rrd */
			if (!file_exists($rt_graph_path)) {
				/* get the syntax */
				$command = @rrdtool_function_create($item['local_data_id'], true);

				/* change permissions so that the poller can clear */
				@chmod($rt_graph_path, 0666);

				/* replace path */
				$command = str_replace($data_source_path, $rt_graph_path, $command);

				/* replace step */
				$command = preg_replace('/--step\s(\d+)/', '--step ' . $interval, $command);

				/* WIN32: before sending this command off to rrdtool, get rid
				of all of the '\' characters. Unix does not care; win32 does.
				Also make sure to replace all of the fancy \'s at the end of the line,
				but make sure not to get rid of the "\n"'s that are supposed to be
				in there (text format) */
				$command = str_replace("\\\n", " ", $command);

				/* create the rrdfile */
				shell_exec($command);
			}else{
				/* change permissions so that the poller can clear */
				@chmod($rt_graph_path, 0666);
			}

			/* now, let's update the path to keep the RRD's updated */
			$item["rrd_path"] = $rt_graph_path;

			/* cleanup the value */
			$value            = trim($item["output"]);
			$unix_time        = strtotime($item["time"]);

			$rrd_update_array{$item["rrd_path"]}["local_data_id"] = $item["local_data_id"];

			/* single one value output */
			if ((is_numeric($value)) || ($value == "U")) {
				$rrd_update_array{$item["rrd_path"]}["times"][$unix_time]{$item["rrd_name"]} = $value;
			/* multiple value output */
			}else{
				$values = explode(" ", $value);

				$rrd_field_names = array_rekey(db_fetch_assoc("SELECT
					data_template_rrd.data_source_name,
					data_input_fields.data_name
					FROM (data_template_rrd,data_input_fields)
					WHERE data_template_rrd.data_input_field_id=data_input_fields.id
					AND data_template_rrd.local_data_id=" . $item["local_data_id"]), "data_name", "data_source_name");

				for ($i=0; $i<count($values); $i++) {
					if (preg_match("/^([a-zA-Z0-9_\.-]+):([eE0-9\+\.-]+)$/", $values[$i], $matches)) {
						if (isset($rrd_field_names{$matches[1]})) {
							$rrd_update_array{$item["rrd_path"]}["times"][$unix_time]{$rrd_field_names{$matches[1]}} = $matches[2];
						}
					}
				}
			}

			/* fallback values */
			if ((!isset($rrd_update_array{$item["rrd_path"]}["times"][$unix_time])) && ($item["rrd_name"] != "")) {
				$rrd_update_array{$item["rrd_path"]}["times"][$unix_time]{$item["rrd_name"]} = "U";
			}else if ((!isset($rrd_update_array{$item["rrd_path"]}["times"][$unix_time])) && ($item["rrd_name"] == "")) {
				unset($rrd_update_array{$item["rrd_path"]});
			}
		}

		/* make sure each .rrd file has complete data */
		reset($results);
		foreach ($results as $item) {
			db_execute("DELETE FROM poller_output_rt
				WHERE local_data_id='" . $item["local_data_id"] . "'
				AND rrd_name='" . $item["rrd_name"] . "'
				AND time='" . $item["time"] . "'
				AND poller_id='" . $poller_id . "'");
		}

		$rrds_processed = rrdtool_function_update($rrd_update_array, $rrdtool_pipe);
	}

	return $rrds_processed;
}


?>
