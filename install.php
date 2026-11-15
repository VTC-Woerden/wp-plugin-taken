<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * Creates (or updates) the tasks table and the Takenbeheerder role.
 * Runs when the plugin is activated.
 */
function vtc_taken_install() {
	global $wpdb;

	$table = vtc_taken_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta() is picky about the layout of this SQL:
	// - every column on its own line
	// - two spaces after PRIMARY KEY
	// It creates the table when it does not exist, and adds new columns when it does.
	//
	// team_id  = the ID of a post from the 'teams' post type
	// end_time = optional "until" time on the same day as task_date, for example 17:00 for a bar shift
	// match_id = empty for normal tasks, the id of the match for tellen / fluiten tasks
	$sql = "CREATE TABLE $table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		team_id bigint(20) unsigned NOT NULL,
		title varchar(255) NOT NULL,
		description text NULL,
		task_date datetime NULL,
		end_time time NULL,
		task_type varchar(20) NOT NULL DEFAULT 'algemeen',
		match_id bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY team_id (team_id),
		KEY task_date (task_date)
	) $charset_collate;";

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);

	// The home matches imported from Nevobo.
	// code    = the Nevobo match code (for example 'XC2M1-DB'), used to find the match again on the next import
	// status  = 'gepland' or 'vervallen'
	// changed = 1 when Nevobo changed the match since you last clicked "Wijzigingen gezien"
	// is_home = 1 for matches in our own hall, 0 for away matches of our teams (only used for warnings)
	// location = where an away match is played, for example 'IJsselhal, IJsselstein UT'
	//
	// The tellen / fluiten assignments are normal tasks in the tasks table, with match_id = id of this table.
	$matches_table = vtc_taken_matches_table_name();

	$sql = "CREATE TABLE $matches_table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		code varchar(50) NOT NULL,
		match_date datetime NOT NULL,
		home_team varchar(255) NOT NULL,
		away_team varchar(255) NOT NULL,
		field varchar(20) NULL,
		region varchar(50) NULL,
		poule varchar(50) NULL,
		status varchar(20) NOT NULL DEFAULT 'gepland',
		changed tinyint(1) NOT NULL DEFAULT 0,
		is_home tinyint(1) NOT NULL DEFAULT 1,
		location varchar(255) NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY code (code),
		KEY match_date (match_date)
	) $charset_collate;";

	dbDelta($sql);

	vtc_taken_add_role();

	update_option('vtc_taken_db_version', VTC_TAKEN_DB_VERSION);
}

/*
 * Runs on every page load, but only does something when the saved
 * database version is different from VTC_TAKEN_DB_VERSION in plugin.php.
 */
function vtc_taken_check_version() {
	if (get_option('vtc_taken_db_version') != VTC_TAKEN_DB_VERSION) {
		vtc_taken_install();
	}
}

/*
 * Adds the permission 'vtc_manage_tasks'.
 * - Administrators always get it.
 * - The new role 'Takenbeheerder' only gets this permission (plus 'read' to log in),
 *   so people with that role only see the Taken menu.
 */
function vtc_taken_add_role() {
	// add_role() does nothing when the role already exists
	add_role('vtc_takenbeheerder', 'Takenbeheerder', array(
		'read' => true,
		'vtc_manage_tasks' => true,
	));

	$admin = get_role('administrator');
	if ($admin) {
		$admin->add_cap('vtc_manage_tasks');
	}
}
