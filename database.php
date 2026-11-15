<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * All functions that read or write the database are in this file.
 * Other files never write SQL themselves, they call these functions.
 */

// The full table name, usually 'wp_vtc_tasks'
function vtc_taken_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'vtc_tasks';
}

// The types a task can have. Key = saved in the database, value = shown on the screen.
function vtc_taken_task_types() {
	return array(
		'algemeen' => 'Algemeen',
		'bardienst' => 'Bardienst',
		'tellen' => 'Tellen',
		'fluiten' => 'Fluiten',
	);
}

/*
 * The types you can choose in the "Nieuwe taak" form.
 * Tellen and fluiten are not in here: those are planned on the Wedstrijden page.
 */
function vtc_taken_manual_task_types() {
	$types = vtc_taken_task_types();
	unset($types['tellen'], $types['fluiten']);

	return $types;
}

/*
 * ------------------------------------------------------------
 * Teams (these come from the existing 'teams' post type)
 * ------------------------------------------------------------
 */

// All published teams, sorted by name
function vtc_taken_get_teams() {
	return get_posts(array(
		'post_type' => 'teams',
		'post_status' => 'publish',
		'numberposts' => -1,
		'orderby' => 'title',
		'order' => 'ASC',
	));
}

// Find a team by its slug (the last part of the URL, for example 'heren-1')
function vtc_taken_get_team_by_slug($slug) {
	return get_page_by_path($slug, OBJECT, 'teams');
}

/*
 * ------------------------------------------------------------
 * Tasks
 * ------------------------------------------------------------
 */

// One task, or null when it does not exist
function vtc_taken_get_task($id) {
	global $wpdb;
	$table = vtc_taken_table_name();

	return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

/*
 * A list of tasks.
 * $team_id   = 0 for all teams, or the ID of one team
 * $show_past = false to only get tasks from today onwards (and tasks without a date)
 * $type      = '' for all types, or one type, for example 'bardienst'
 *
 * Tasks without a date come first, then the tasks sorted by date.
 */
function vtc_taken_get_tasks($team_id = 0, $show_past = false, $type = '') {
	global $wpdb;
	$table = vtc_taken_table_name();

	// We build the WHERE part step by step, depending on the filters
	$where = array();
	$values = array();

	if ($team_id) {
		$where[] = 'team_id = %d';
		$values[] = $team_id;
	}

	if ($type != '') {
		$where[] = 'task_type = %s';
		$values[] = $type;
	}

	if (!$show_past) {
		// From the start of today, so a task at 10:00 is still shown in the afternoon
		$where[] = '(task_date IS NULL OR task_date >= %s)';
		$values[] = current_time('Y-m-d') . ' 00:00:00';
	}

	$sql = "SELECT * FROM $table";

	if (!empty($where)) {
		$sql .= ' WHERE ' . implode(' AND ', $where);
	}

	$sql .= ' ORDER BY task_date IS NULL DESC, task_date ASC, id ASC';

	// prepare() safely puts the values into the SQL (protects against SQL injection)
	if (!empty($values)) {
		$sql = $wpdb->prepare($sql, $values);
	}

	return $wpdb->get_results($sql);
}

/*
 * Adds a new task. $data is an array with the columns:
 * team_id, title, description, task_date, task_type (and later match_id)
 */
function vtc_taken_add_task($data) {
	global $wpdb;

	$data['created_at'] = current_time('mysql');

	$wpdb->insert(vtc_taken_table_name(), $data);

	return $wpdb->insert_id;
}

// Changes an existing task
function vtc_taken_update_task($id, $data) {
	global $wpdb;

	$wpdb->update(vtc_taken_table_name(), $data, array('id' => $id));
}

// Removes a task
function vtc_taken_delete_task($id) {
	global $wpdb;

	$wpdb->delete(vtc_taken_table_name(), array('id' => $id), array('%d'));
}

/*
 * ------------------------------------------------------------
 * Small helpers used on several pages
 * ------------------------------------------------------------
 */

/*
 * Turns a database date ('2026-11-22 14:00:00') into Dutch text.
 * $short = true  gives 'za 22 nov'
 * $short = false gives 'zaterdag 22 november 2026'
 *
 * Time 00:00 means "no time filled in", so we don't show it.
 */
function vtc_taken_format_date($task_date, $short = false) {
	if (empty($task_date)) {
		return '';
	}

	$days = array('zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag');
	$days_short = array('zo', 'ma', 'di', 'wo', 'do', 'vr', 'za');
	$months = array('januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december');
	$months_short = array('jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec');

	$date = date_create($task_date);

	$day_number = $date->format('w');        // 0 = sunday
	$month_number = $date->format('n') - 1;  // 0 = january

	if ($short) {
		return $days_short[$day_number] . ' ' . $date->format('j') . ' ' . $months_short[$month_number];
	}

	return $days[$day_number] . ' ' . $date->format('j') . ' ' . $months[$month_number] . ' ' . $date->format('Y');
}

// The time of a task ('14:00'), or an empty text when no time was filled in
function vtc_taken_format_time($task_date) {
	if (empty($task_date)) {
		return '';
	}

	$time = date_create($task_date)->format('H:i');

	if ($time == '00:00') {
		return '';
	}

	return $time;
}

// The "until" time of a task: the database gives '17:00:00', this returns '17:00' (or '' when empty)
function vtc_taken_format_end_time($end_time) {
	if (empty($end_time)) {
		return '';
	}

	return substr($end_time, 0, 5);
}

/*
 * The time of a task as text, with the optional until time:
 * '14:00 - 17:00', '14:00', 'tot 17:00' or '' (no time at all)
 */
function vtc_taken_format_time_range($task) {
	$start = vtc_taken_format_time($task->task_date);
	$end = isset($task->end_time) ? vtc_taken_format_end_time($task->end_time) : '';

	if ($start != '' && $end != '') {
		return $start . ' - ' . $end;
	}

	if ($end != '') {
		return 'tot ' . $end;
	}

	return $start;
}
