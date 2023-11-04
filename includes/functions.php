<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2023 The Cacti Group                                 |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include_once(__DIR__ . '/constants.php');
include_once(__DIR__ . '/arrays.php');
include_once(__DIR__ . '/../classes/cURL.php');
include_once(__DIR__ . '/../classes/mxlookup.php');

function webseer_show_tab($current_tab) {
	global $config;

	$tabs = array(
		'webseer.php'         => __('Checks HTTP/HTTPS', 'webseer'),
		'webseer_proxies.php' => __('Proxies', 'webseer')
	);

	if (get_request_var('action') == 'history') {
		if ($current_tab == 'webseer.php') {
			$current_tab = 'webseer.php?action=history&id=' . get_filter_request_var('id');
			$tabs[$current_tab] = __('Log History', 'webeer');
		} else { //!!  tady bude odkaz na historii u emailu
			$current_tab = 'webseer_sxexrxvxers.php?action=history&id=' . get_filter_request_var('id');
			$tabs[$current_tab] = __('Log History', 'webeer');
		}
	}

	print "<div class='tabs'><nav><ul>\n";

	if (cacti_sizeof($tabs)) {
		foreach ($tabs as $url => $name) {
			print "<li><a class='" . (($url == $current_tab) ? 'pic selected' : 'pic') .  "' href='" . $config['url_path'] .
				"plugins/webseer/$url'>$name</a></li>";
		}
	}

	print '</ul></nav></div>';
}


function plugin_webseer_remove_old_users () {
	$users = db_fetch_assoc('SELECT id FROM user_auth');

	$u = array();

	foreach ($users as $user) {
		$u[] = $user['id'];
	}

	$contacts = db_fetch_assoc('SELECT DISTINCT user_id FROM plugin_webseer_contacts');

	foreach ($contacts as $c) {
		if (!in_array($c['user_id'], $u)) {
			db_execute_prepared('DELETE FROM plugin_webseer_contacts WHERE user_id = ?', array($c['user_id']));
		}
	}
}

function plugin_webseer_check_dns ($host) {
	$results = false;

	if (cacti_sizeof($host)) {
		$results = array();
		$results['result']                     = 0;
		$results['options']['http_code']       = 0;
		$results['error']                      = '';
		$results['options']['total_time']      = 0;
		$results['options']['namelookup_time'] = 0;
		$results['options']['connect_time']    = 0;
		$results['options']['redirect_time']   = 0;
		$results['options']['redirect_count']  = 0;
		$results['options']['size_download']   = 0;
		$results['options']['speed_download']  = 0;
		$results['time']                       = time();

		$s = microtime(true);
		$a = new mxlookup($host['search'], $host['url']);
		$t = microtime(true) - $s;
		$results['options']['connect_time'] = $results['options']['total_time'] = $results['options']['namelookup_time'] = round($t, 4);

		$results['data'] = '';
		foreach ($a->arrMX as $m) {
			$results['data'] .= "A RECORD: $m\n";
			if ($m == $host['search_maint']) {
				$results['result'] = 1;
			}
		}
	}

	return $results;
}



function plugin_webseer_update_contacts() {
	$users = db_fetch_assoc("SELECT id, 'email' AS type, email_address FROM user_auth WHERE email_address!=''");
	if (cacti_sizeof($users)) {
		foreach($users as $u) {
			$cid = db_fetch_cell('SELECT id FROM plugin_webseer_contacts WHERE type="email" AND user_id=' . $u['id']);

			if ($cid) {
				db_execute("REPLACE INTO plugin_webseer_contacts (id, user_id, type, data) VALUES ($cid, " . $u['id'] . ", 'email', '" . $u['email_address'] . "')");
			} else {
				db_execute("REPLACE INTO plugin_webseer_contacts (user_id, type, data) VALUES (" . $u['id'] . ", 'email', '" . $u['email_address'] . "')");
			}
		}
	}
}

function plugin_webseer_check_debug() {
	global $debug;
	if (!$debug) {
		$plugin_debug = read_config_option('selective_plugin_debug');
		if (preg_match('/(^|[, ]+)(webseer)($|[, ]+)/', $plugin_debug, $matches)) {
			$debug = (cacti_sizeof($matches) == 4 && $matches[2] == 'webseer');
		}
	}
}

function plugin_webseer_debug($message='', $host=array()) {
	global $debug;
	if ($debug) {
		$prefix = (empty($host['id']) && empty($host['debug_type'])) ? '' : '[';
		$suffix = (empty($host['id']) && empty($host['debug_type'])) ? '' : '] ';
		$spacer = (empty($host['id']) || empty($host['debug_type'])) ? '' : ' ';
		$host_id = (empty($host['id'])) ? '' : $host['id'];
		$host_dt = (empty($host['debug_type'])) ? '' : $host['debug_type'];
		cacti_log('DEBUG: ' . $prefix . $host_dt . $spacer . $host_id . $suffix . trim($message), true, 'WEBSEER');
	}
}

