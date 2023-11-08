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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

include_once(__DIR__ . '/includes/constants.php');
include_once(__DIR__ . '/includes/arrays.php');

function plugin_webseer_install () {
	api_plugin_register_hook('webseer', 'draw_navigation_text', 'plugin_webseer_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('webseer', 'config_arrays',        'plugin_webseer_config_arrays',        'setup.php');
	api_plugin_register_hook('webseer', 'poller_bottom',        'plugin_webseer_poller_bottom',        'setup.php');
	api_plugin_register_hook('webseer', 'replicate_out',        'webseer_replicate_out',               'setup.php');

	api_plugin_register_realm('webseer', 'webseer.php,webseer_mail.php,webseer_proxies.php', __('Service Check Admin', 'webseer'), 1);

	plugin_webseer_setup_table();
}

function plugin_webseer_uninstall () {
	db_execute('DROP TABLE IF EXISTS plugin_webseer_servers');
	db_execute('DROP TABLE IF EXISTS plugin_webseer_servers_log');
	db_execute('DROP TABLE IF EXISTS plugin_webseer_urls');
	db_execute('DROP TABLE IF EXISTS plugin_webseer_urls_log');
	db_execute('DROP TABLE IF EXISTS plugin_webseer_proxies');
	db_execute('DROP TABLE IF EXISTS plugin_webseer_processes');
	db_execute('DROP TABLE IF EXISTS plugin_webseer_contacts');
}

function plugin_webseer_check_config () {
	// Here we will check to ensure everything is configured
	plugin_webseer_upgrade();
	return true;
}

function plugin_webseer_upgrade() {
	// Here we will upgrade to the newest version
	global $config;

	$info = plugin_webseer_version();
	$new  = $info['version'];
	$old  = db_fetch_cell('SELECT version FROM plugin_config WHERE directory="webseer"');

	if ($new != $old) {
		if (version_compare($old, '1.1', '<')) {
			db_execute("CREATE TABLE IF NOT EXISTS `plugin_webseer_contacts` (
				`id` int(12) NOT NULL AUTO_INCREMENT,
				`user_id` int(12) NOT NULL,
				`type` varchar(32) NOT NULL,
				`data` text NOT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `user_id_type` (`user_id`,`type`),
				KEY `type` (`type`),
				KEY `user_id` (`user_id`))
				ENGINE=InnoDB
				COMMENT='Table of WebSeer contacts'");
		}

		if (version_compare($old, '2.0', '<')) {
			db_execute("CREATE TABLE `plugin_webseer_proxies` (
				`id` int(11) unsigned NOT NULL AUTO_INCREMENT	,
				`name` varchar(30) DEFAULT '',
				`hostname` varchar(64) DEFAULT '',
				`http_port` mediumint(8) unsigned DEFAULT '80',
				`https_port` mediumint(8) unsigned DEFAULT '443',
				`username` varchar(40) DEFAULT '',
				`password` varchar(60) DEFAULT '',
				PRIMARY KEY (`id`),
				KEY `hostname` (`hostname`),
				KEY `name` (`name`))
				ENGINE=InnoDB
				COMMENT='Holds Proxy Information for Connections'");

			if (!db_column_exists('plugins_webseer_urls', 'proxy_server')) {
				db_execute('ALTER TABLE plugin_webseer_urls
					ADD COLUMN proxy_server int(11) unsigned NOT NULL default "0" AFTER requiresauth');
			}
		}

		if (version_compare($old, '3.0', '<')) {
			db_execute('RENAME TABLE `plugin_webseer_url_log` TO `plugin_webseer_urls_log`');
			db_execute('ALTER TABLE `plugin_webseer_urls`
				ADD COLUMN compression int(3) unsigned NOT NULL default "0" AFTER lastcheck,
				ADD COLUMN notify_format int(3) unsigned NOT NULL default "0" AFTER notify_accounts');
			db_execute('ALTER TABLE `plugin_webseer_urls_log`
				ADD COLUMN compression int(3) unsigned NOT NULL default "0" AFTER lastcheck');
			db_execute('ALTER TABLE `plugin_webseer_servers`
				ADD COLUMN compression int(3) unsigned NOT NULL default "0" AFTER lastcheck');
			db_execute('ALTER TABLE `plugin_webseer_servers_log`
				ADD COLUMN compression int(3) unsigned NOT NULL default "0" AFTER lastcheck');
		}

		if (version_compare($old, '3.4', '<')) {
			db_execute('DROP TABLE plugin_webseer_servers');
			db_execute('DROP TABLE plugin_webseer_servers_log');

			db_execute('ALTER TABLE `plugin_webseer_urls`
				ADD COLUMN search_result int(3) NOT NULL default "-1" AFTER result');
			db_execute('ALTER TABLE `plugin_webseer_urls`
				ADD COLUMN certexpirenotify char(2) NOT NULL default "on" AFTER checkcert');
			db_execute('ALTER TABLE `plugin_webseer_urls`
				ADD COLUMN last_exp_notify timestamp NOT NULL default "0000-00-00" AFTER lastcheck');
			db_execute('ALTER TABLE `plugin_webseer_urls`
				ADD COLUMN subtype enum("IMAP","POP3","SMTP") NOT NULL default "SMTP" AFTER type');
			db_execute('ALTER TABLE `plugin_webseer_urls`
				ADD COLUMN notes text default NULL AFTER notify_format');

			db_execute('ALTER TABLE `plugin_webseer_urls_log`
				ADD COLUMN search_result varchar(250) NOT NULL default "" AFTER result');
			db_execute('ALTER TABLE `plugin_webseer_urls_log`
				ADD COLUMN cert_expire timestamp NOT NULL default "0000-00-00" AFTER lastcheck');
		}

		if (!db_column_exists('plugin_webseer_urls', 'notify_list')) {
			db_execute('ALTER TABLE plugin_webseer_urls ADD COLUMN notify_list int(10) unsigned NOT NULL default "0" AFTER checkcert');
		}

		if (!db_column_exists('plugin_webseer_urls', 'poller_id')) {
			db_execute('ALTER TABLE plugin_webseer_urls ADD COLUMN poller_id int(10) unsigned NOT NULL default "1" AFTER id');
		}

		if (!db_column_exists('plugin_webseer_processes', 'poller_id')) {
			db_execute('ALTER TABLE plugin_webseer_processes ADD COLUMN poller_id int(10) unsigned NOT NULL default "1" AFTER id');
		}

		db_execute_prepared('UPDATE plugin_config
			SET version = ?
			WHERE directory = "webseer"',
			array($new));

		db_execute_prepared("UPDATE plugin_config SET
			version = ?, name = ?, author = ?, webpage = ?
			WHERE directory = ?",
			array(
				$info['version'],
				$info['longname'],
				$info['author'],
				$info['homepage'],
				$info['name']
			)
		);

		db_execute_prepared('UPDATE plugin_realms
			SET file = ?
			WHERE file LIKE "%webseer.php%"',
			array('webseer.php,webseer_mail.php,webseer_proxies.php'));

		api_plugin_register_hook('webseer', 'replicate_out', 'webseer_replicate_out', 'setup.php', '1');
	}

	return true;
}

function plugin_webseer_version() {
	global $config;
	$info = parse_ini_file($config['base_path'] . '/plugins/webseer/INFO', true);
	return $info['info'];
}

function plugin_webseer_setup_table() {


	db_execute("CREATE TABLE IF NOT EXISTS `plugin_webseer_urls` (
		`id` int(11) unsigned NOT NULL auto_increment,
		`poller_id` int(11) unsigned NOT NULL default '1',
		`enabled` char(2) NOT NULL default 'on',
		`type` varchar(32) NOT NULL default 'http',
		`subtype` enum('IMAP','POP3','SMTP) NOT NULL default 'SMTP',
		`display_name` varchar(64) NOT NULL default '',
		`url` varchar(256) NOT NULL,
		`ip` varchar(120) NOT NULL default '',
		`search` varchar(1024) NOT NULL,
		`search_maint` varchar(1024) NOT NULL,
		`search_failed` varchar(1024) NOT NULL,
		`requiresauth` char(2) NOT NULL default '',
		`proxy_server` int(11) unsigned NOT NULL default '0',
		`checkcert` char(2) NOT NULL default 'on',
		`certexpirenotify` char(2) NOT NULL default 'on',
		`notify_list` int(10) unsigned NOT NULL default '0',
		`notify_accounts` varchar(256) NOT NULL,
		`notify_extra` varchar(256) NOT NULL,
		`notify_format` int(3) unsigned NOT NULL default '0',
		`notes` text default NULL,
		`result` int(3) NOT NULL default '-1',
		`search_result` int(3) NOT NULL default '-1',
		`downtrigger` int(11) unsigned NOT NULL default '3',
		`timeout_trigger` int(11) unsigned NOT NULL default '4',
		`failures` int(11) unsigned NOT NULL default '0',
		`triggered` int(11) unsigned NOT NULL default '0',
		`lastcheck` timestamp NOT NULL default '0000-00-00',
		`last_exp_notify` timestamp NOT NULL default '0000-00-00',
		`compression` int(3) unsigned NOT NULL default '0',
		`error` varchar(256) default NULL,
		`http_code` int(11) unsigned default NULL,
		`total_time` double default NULL,
		`namelookup_time` double default NULL,
		`connect_time` double default NULL,
		`redirect_time` double default NULL,
		`speed_download` int(11) unsigned default NULL,
		`size_download` int(11) unsigned default NULL,
		`redirect_count` int(11) unsigned default NULL,
		`debug` longblob default NULL,
		PRIMARY KEY  (`id`),
		KEY `lastcheck` (`lastcheck`),
		KEY `triggered` (`triggered`),
		KEY `result` (`result`),
		KEY `enabled` (`enabled`))
		ENGINE=InnoDB
		COMMENT='Holds WebSeer Service Check Definitions'");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_webseer_urls_log` (
		`id` int(11) unsigned NOT NULL auto_increment,
		`url_id` int(11) unsigned NOT NULL default '0',
		`lastcheck` timestamp NOT NULL default '0000-00-00',
		`cert_expire` timestamp NOT NULL default '0000-00-00',
		`compression` int(3) unsigned NOT NULL default '0',
		`result` int(11) unsigned NOT NULL default '0',
		`search_result` varchar(250) NOT NULL default '',
		`http_code` int(11) unsigned default NULL,
		`error` varchar(256) default NULL,
		`total_time` double default NULL,
		`namelookup_time` double default NULL,
		`connect_time` double default NULL,
		`redirect_time` double unsigned default NULL,
		`redirect_count` int(11) unsigned default NULL,
		`size_download` int(11) unsigned default NULL,
		`speed_download` int(11) unsigned default NULL,
		PRIMARY KEY  (`id`),
		KEY `url_id` (`url_id`),
		KEY `lastcheck` (`lastcheck`),
		KEY `result` (`result`))
		ENGINE=InnoDB
		COMMENT='Holds WebSeer Service Check Logs'");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_webseer_processes` (
		`id` bigint unsigned NOT NULL auto_increment,
		`poller_id` int(11) unsigned NOT NULL default '1',
		`url_id` int(11) unsigned NOT NULL,
		`pid` int(11) unsigned NOT NULL,
		`time` timestamp default CURRENT_TIMESTAMP,
		PRIMARY KEY  (`id`),
		KEY `pid` (`pid`),
		KEY `url_id` (`url_id`),
		KEY `time` (`time`))
		ENGINE=MEMORY
		COMMENT='Holds running process information'");

	db_execute("CREATE TABLE IF NOT EXISTS `plugin_webseer_contacts` (
		`id` int(12) NOT NULL auto_increment,
		`user_id` int(12) NOT NULL,
		`type` varchar(32) NOT NULL,
		`data` text NOT NULL,
		PRIMARY KEY (`id`),
		UNIQUE KEY `user_id_type` (`user_id`,`type`),
		KEY `type` (`type`),
		KEY `user_id` (`user_id`))
		ENGINE=InnoDB
		COMMENT='Table of WebSeer contacts'");

	db_execute("CREATE TABLE `plugin_webseer_proxies` (
		`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		`name` varchar(30) DEFAULT '',
		`hostname` varchar(64) DEFAULT '',
		`http_port` mediumint(8) unsigned DEFAULT '80',
		`https_port` mediumint(8) unsigned DEFAULT '443',
		`username` varchar(40) DEFAULT '',
		`password` varchar(60) DEFAULT '',
		PRIMARY KEY (`id`),
		KEY `hostname` (`hostname`),
		KEY `name` (`name`))
		ENGINE=InnoDB
		COMMENT='Holds Proxy Information for Connections'");
}

function plugin_webseer_poller_bottom() {
	global $config;

	include_once($config['library_path'] . '/database.php');

	$command_string = trim(read_config_option('path_php_binary'));

	// If its not set, just assume its in the path
	if (trim($command_string) == '')
		$command_string = 'php';
	$extra_args = ' -q ' . $config['base_path'] . '/plugins/webseer/poller_webseer.php';

	exec_background($command_string, $extra_args);
}

function plugin_webseer_config_arrays() {
	global $menu, $user_auth_realms, $user_auth_realm_filenames;

	$menu[__('Management')]['plugins/webseer/webseer.php'] = __('Service Checks', 'webseer');

	$files = array('index.php', 'plugins.php', 'webseer.php');
	if (in_array(get_current_page(), $files)) {
		plugin_webseer_check_config();
	}
}

function plugin_webseer_draw_navigation_text($nav) {
	$nav['webseer.php:'] = array(
		'title' => __('WebSeer Web Service Checks', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer.php:edit'] = array(
		'title' => __('Web Service Check Edit', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer.php:save'] = array(
		'title' => __('Web Service Check Save', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer_mail.php:'] = array(
		'title' => __('WebSeer Email Service Checks', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer_mail.php:edit'] = array(
		'title' => __('Email Service Check Edit', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer_mail.php:save'] = array(
		'title' => __('Email Service Check Save', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer_proxies.php:'] = array(
		'title' => __('WebSeer Proxies', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer_proxies.php:edit'] = array(
		'title' => __('Proxie Edit', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	$nav['webseer_proxies.php:save'] = array(
		'title' => __('Save Proxy', 'webseer'),
		'mapping' => 'index.php:',
		'url' => 'webseer.php',
		'level' => '1'
	);

	return $nav;
}

function webseer_replicate_out($data) {
	$remote_poller_id = $data['remote_poller_id'];
	$rcnn_id          = $data['rcnn_id'];
	$class            = $data['class'];

	cacti_log('INFO: Replacting for the WebSeer Plugin', false, 'REPLICATE');

	$tables = array(
		'plugin_webseer_contacts',
		'plugin_webseer_proxies',
		'plugin_webseer_urls'
	);

	if ($class == 'all') {
		foreach($tables as $table) {
			$tdata = db_fetch_assoc('SELECT * FROM ' . $table);
			replicate_out_table($rcnn_id, $tdata, $table, $remote_poller_id);
		}
	}

    return $data;
}


