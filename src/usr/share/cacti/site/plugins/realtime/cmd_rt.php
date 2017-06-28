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

$start = date("Y-n-d H:i:s"); // for runtime measurement

ini_set("max_execution_time", "0");

/* we are not talking to the browser */
$no_http_headers = true;

include(dirname(__FILE__) . "/../../include/global.php");
include_once($config["base_path"] . "/lib/snmp.php");
include_once($config["base_path"] . "/lib/poller.php");
include_once($config["base_path"] . "/lib/rrd.php");
include_once($config["base_path"] . "/lib/ping.php");

/* correct for a windows PHP bug. fixed in 5.2.0 */
if (count($_SERVER['argv']) < 4) {
	echo "No graph_id, interval, pollerid specified.\n\n";
	echo "Usage: cmd_rt.php POLLER_ID GRAPH_ID INTERVAL\n\n";
	exit(-1);
}

$poller_id = (int)$_SERVER['argv'][1];
$graph_id  = (int)$_SERVER['argv'][2];
$interval  = (int)$_SERVER['argv'][3];

if ($poller_id <= 0) {
	echo "Invalid poller_id specified.\n\n";
	exit(-1);
}

if ($graph_id <= 0)
{
	echo "Invalid graph_id specified.\n\n";
	exit(-1);
}

if ($interval <= 0) {
	echo "Invalid interval specified.\n\n";
	exit(-1);
}

/* record the start time */
list($micro,$seconds) = split(" ", microtime());
$start = $seconds + $micro;

/* initialize the polling items */
$polling_items = array();

/* get poller_item for graph_id */
$local_data_ids = db_fetch_assoc("SELECT DISTINCT data_template_rrd.local_data_id
	FROM graph_templates_item
	LEFT JOIN data_template_rrd ON (graph_templates_item.task_item_id=data_template_rrd.id)
	WHERE graph_templates_item.local_graph_id=$graph_id
	AND data_template_rrd.local_data_id IS NOT NULL");

if (!count($local_data_ids)) {
	echo "No local_graph_id found\n\n";
	exit(-1);
}

$ids = array();
foreach ($local_data_ids as $row) $ids[] = $row['local_data_id'];

/* check arguments */
$polling_items       = db_fetch_assoc("SELECT *
	FROM poller_item
	WHERE local_data_id IN (".implode(',', $ids).")
	ORDER by host_id");

$script_server_calls = db_fetch_cell("SELECT count(*)
	FROM poller_item
	WHERE (action=2)");

$print_data_to_stdout = true;

/* get the number of polling items from the database */
$hosts = db_fetch_assoc("SELECT * FROM host WHERE disabled = '' ORDER by id");

/* rework the hosts array to be searchable */
$hosts = array_rekey($hosts, "id", $host_struc);

$host_count = sizeof($hosts);
$script_server_calls = db_fetch_cell("SELECT count(*) from poller_item WHERE action=2");

if ((sizeof($polling_items) > 0)) {
	/* startup Cacti php polling server and include the include file for script processing */
	if ($script_server_calls > 0) {
		$cactides = array(
			0 => array("pipe", "r"), // stdin is a pipe that the child will read from
			1 => array("pipe", "w"), // stdout is a pipe that the child will write to
			2 => array("pipe", "w")  // stderr is a pipe to write to
			);

		if (function_exists("proc_open")) {
			$cactiphp = proc_open(read_config_option("path_php_binary") . " -q " . $config["base_path"] . "/script_server.php cmd", $cactides, $pipes);
			$output = fgets($pipes[1], 1024);
			$using_proc_function = true;
		}else {
			$using_proc_function = false;
		}
	}else{
		$using_proc_function = FALSE;
	}

	/* all polled items need the same insert time */
	$host_update_time = date("Y-m-d H:i:s");

	foreach ($polling_items as $item) {
		$data_source = $item["local_data_id"];
		$host_id     = $item["host_id"];

		switch ($item["action"]) {
		case POLLER_ACTION_SNMP: /* snmp */
			if (($item["snmp_version"] == 0) || (($item["snmp_community"] == "") && ($item["snmp_version"] != 3))) {
				$output = "U";
			}else {
				$output = cacti_snmp_get($item["hostname"], $item["snmp_community"], $item["arg1"],
					$item["snmp_version"], $item["snmp_username"], $item["snmp_password"],
					$item["snmp_auth_protocol"], $item["snmp_priv_passphrase"], $item["snmp_priv_protocol"],
					$item["snmp_context"], $item["snmp_port"], $item["snmp_timeout"], read_config_option("snmp_retries"), SNMP_CMDPHP);

				/* remove any quotes from string */
				$output = strip_quotes($output);

				if (!validate_result($output)) {
					if (strlen($output) > 20) {
						$strout = 20;
					} else {
						$strout = strlen($output);
					}

					$output = "U";
				}
			}

			break;
		case POLLER_ACTION_SCRIPT: /* script (popen) */
			$output = trim(exec_poll($item["arg1"]));

			/* remove any quotes from string */
			$output = strip_quotes($output);

			if (!validate_result($output)) {
				if (strlen($output) > 20) {
					$strout = 20;
				} else {
					$strout = strlen($output);
				}

				$output = "U";
			}

			break;
		case POLLER_ACTION_SCRIPT_PHP: /* script (php script server) */
			if ($using_proc_function == true) {
				$output = trim(str_replace("\n", "", exec_poll_php($item["arg1"], $using_proc_function, $pipes, $cactiphp)));

				/* remove any quotes from string */
				$output = strip_quotes($output);

				if (!validate_result($output)) {
					if (strlen($output) > 20) {
						$strout = 20;
					} else {
						$strout = strlen($output);
					}

					$output = "U";
				}
			}else{
				$output = "U";
			}

			break;
		}

		if (isset($output)) {
			/* insert a U in place of the actual value if the snmp agent restarts */
			db_execute("insert into poller_output_rt (local_data_id, rrd_name, time, poller_id, output) values (" . $item["local_data_id"] . ", '" . $item["rrd_name"] . "', '$host_update_time', '".$poller_id."', '" . addslashes($output) . "')");
		}
	}

	if (($using_proc_function == true) && ($script_server_calls > 0)) {
		/* close php server process */
		fwrite($pipes[0], "quit\r\n");
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$return_value = proc_close($cactiphp);
	}
}
?>
