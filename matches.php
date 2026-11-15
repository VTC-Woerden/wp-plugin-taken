<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * Everything about matches:
 * - reading and saving matches in the database
 * - importing the home matches from Nevobo
 * - the settings lists (excluded poules, second referee, only counters)
 * - the tellen / fluiten tasks that belong to a match
 *
 * A match task is a normal row in the tasks table with:
 *   match_id  = the id of the match
 *   task_type = 'tellen' or 'fluiten'
 * That's why match tasks show up in [vtc_taken] and in "Alle taken" automatically.
 */

// The full table name, usually 'wp_vtc_matches'
function vtc_taken_matches_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'vtc_matches';
}

/*
 * ------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------
 */

// Nevobo sometimes uses a special space (between sponsor name and team name). Make it a normal space.
function vtc_taken_clean_name($name) {
	$name = str_replace("\xc2\xa0", ' ', (string) $name);
	$name = preg_replace('/\s+/', ' ', $name);
	return trim($name);
}

/*
 * Removes the sponsor name in front of our own teams:
 * 'Quadrant Bouw VTC Woerden HS 1' becomes 'VTC Woerden HS 1'.
 * Teams of other clubs stay the same.
 */
function vtc_taken_club_team_name($name) {
	$name = vtc_taken_clean_name($name);
	$position = stripos($name, VTC_TAKEN_CLUB_NAME);

	if ($position === false) {
		return $name;
	}

	return substr($name, $position);
}

/*
 * All our own team names that appear in the matches, without sponsor names.
 * For example: array('VTC Woerden DS 1', 'VTC Woerden HS 1', ...)
 */
function vtc_taken_get_nevobo_club_teams() {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	$names = array_merge(
		$wpdb->get_col("SELECT DISTINCT home_team FROM $table"),
		$wpdb->get_col("SELECT DISTINCT away_team FROM $table")
	);

	$club_teams = array();
	foreach ($names as $name) {
		if (stripos($name, VTC_TAKEN_CLUB_NAME) !== false) {
			$club_teams[] = vtc_taken_club_team_name($name);
		}
	}

	$club_teams = array_unique($club_teams);
	natcasesort($club_teams);

	return array_values($club_teams);
}

/*
 * Which Nevobo team belongs to which team post.
 * Saved on the team post itself, as post meta 'vtc_nevobo_team' (see Instellingen page).
 * Returns: array(team_id => 'VTC Woerden HS 1'), only teams that are linked.
 */
function vtc_taken_get_team_nevobo_names() {
	$result = array();

	foreach (vtc_taken_get_teams() as $team) {
		$nevobo_name = get_post_meta($team->ID, 'vtc_nevobo_team', true);

		if ($nevobo_name != '') {
			$result[$team->ID] = $nevobo_name;
		}
	}

	return $result;
}

/*
 * Is $team in $list? Ignores capitals and extra spaces.
 * Used for the settings lists, for example "does this home team need a second referee?"
 */
function vtc_taken_team_in_list($team, $list) {
	$team = strtolower(vtc_taken_clean_name($team));

	foreach ($list as $item) {
		if (strtolower(vtc_taken_clean_name($item)) == $team) {
			return true;
		}
	}

	return false;
}

/*
 * A season is half a year: '2026-1' = january to june, '2026-2' = july to december.
 * (Same as the old wedstrijd planner.)
 */
function vtc_taken_season_of_date($date) {
	$date = date_create($date);
	$half = ($date->format('n') <= 6) ? 1 : 2;

	return $date->format('Y') . '-' . $half;
}

function vtc_taken_current_season() {
	return vtc_taken_season_of_date(current_time('mysql'));
}

// '2026-2' becomes array('2026-07-01 00:00:00', '2026-12-31 23:59:59')
function vtc_taken_season_start_end($season) {
	$parts = explode('-', $season);
	$year = intval($parts[0]);
	$half = isset($parts[1]) ? intval($parts[1]) : 1;

	if ($half == 1) {
		return array($year . '-01-01 00:00:00', $year . '-06-30 23:59:59');
	}

	return array($year . '-07-01 00:00:00', $year . '-12-31 23:59:59');
}

// 'Najaar 2026' or 'Voorjaar 2027'
function vtc_taken_season_label($season) {
	$parts = explode('-', $season);

	if (isset($parts[1]) && $parts[1] == '1') {
		return 'Voorjaar ' . $parts[0];
	}

	return 'Najaar ' . $parts[0];
}

/*
 * ------------------------------------------------------------
 * Settings lists (saved in wp_options, see admin-settings.php)
 * ------------------------------------------------------------
 */

// $name is 'exclude_poules', 'second_referee' or 'teller_only'
function vtc_taken_get_setting_list($name) {
	$list = get_option('vtc_taken_' . $name, array());

	if (!is_array($list)) {
		return array();
	}

	return $list;
}

function vtc_taken_save_setting_list($name, $list) {
	update_option('vtc_taken_' . $name, array_values($list));
}

/*
 * The minimum time (in minutes) between a team's own match and a task of that team.
 * Example with 120: the team plays at 13:00, so it can do a task at 11:00 or earlier, or at 15:00 or later.
 */
function vtc_taken_get_task_window_minutes() {
	return intval(get_option('vtc_taken_task_window_minutes', VTC_TAKEN_MATCH_MINUTES));
}

/*
 * ------------------------------------------------------------
 * Matches in the database
 * ------------------------------------------------------------
 */

function vtc_taken_get_match($id) {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

function vtc_taken_get_match_by_code($code) {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code = %s", $code));
}

// All seasons that have home matches, oldest first. For example: array('2026-2', '2027-1')
function vtc_taken_get_seasons() {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	return $wpdb->get_col("SELECT DISTINCT CONCAT(YEAR(match_date), '-', IF(MONTH(match_date) <= 6, 1, 2)) AS season FROM $table WHERE is_home = 1 ORDER BY season");
}

/*
 * The home matches of one season, sorted by date and field.
 * Matches in an excluded poule are left out, unless $include_excluded is true.
 * Away matches are only included when $include_away is true.
 * (The conflict checker needs all matches: a team in a hidden poule or away still plays.)
 */
function vtc_taken_get_matches_for_season($season, $include_excluded = false, $include_away = false) {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	$start_end = vtc_taken_season_start_end($season);

	$sql = "SELECT * FROM $table WHERE match_date BETWEEN %s AND %s";
	if (!$include_away) {
		$sql .= " AND is_home = 1";
	}
	$sql .= " ORDER BY match_date ASC, field ASC";

	$matches = $wpdb->get_results($wpdb->prepare($sql, $start_end[0], $start_end[1]));

	if ($include_excluded) {
		return $matches;
	}

	$exclude_poules = vtc_taken_get_setting_list('exclude_poules');

	$result = array();
	foreach ($matches as $match) {
		if (!vtc_taken_team_in_list($match->poule, $exclude_poules)) {
			$result[] = $match;
		}
	}

	return $result;
}

/*
 * All home matches between two dates (also the hidden poules), sorted by time.
 * $start and $end are 'Y-m-d' dates, both included.
 * Used by the Wedstrijddag and Weekschema screens.
 */
function vtc_taken_get_matches_between($start, $end) {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	return $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $table WHERE match_date BETWEEN %s AND %s AND status != 'vervallen' AND is_home = 1 ORDER BY match_date ASC",
		$start . ' 00:00:00',
		$end . ' 23:59:59'
	));
}

/*
 * The tasks of one type between two dates, sorted by time. For example all bar shifts of a week.
 * $start and $end are 'Y-m-d' dates, both included.
 */
function vtc_taken_get_tasks_of_type_between($type, $start, $end) {
	global $wpdb;
	$table = vtc_taken_table_name();

	return $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM $table WHERE task_type = %s AND task_date BETWEEN %s AND %s ORDER BY task_date ASC, end_time ASC",
		$type,
		$start . ' 00:00:00',
		$end . ' 23:59:59'
	));
}

// Saves a new match, returns the new id
function vtc_taken_add_match($data) {
	global $wpdb;

	$data['updated_at'] = current_time('mysql');
	$wpdb->insert(vtc_taken_matches_table_name(), $data);

	return $wpdb->insert_id;
}

function vtc_taken_update_match($id, $data) {
	global $wpdb;

	$data['updated_at'] = current_time('mysql');
	$wpdb->update(vtc_taken_matches_table_name(), $data, array('id' => $id));
}

// Removes all red "changed" markers
function vtc_taken_clear_changed_matches() {
	global $wpdb;
	$table = vtc_taken_matches_table_name();

	$wpdb->query("UPDATE $table SET changed = 0");
}

/*
 * ------------------------------------------------------------
 * Importing from Nevobo
 * ------------------------------------------------------------
 */

/*
 * Downloads a Nevobo Excel file and turns every row into a match array.
 * $url = the hall export (default) or the club export (for the away matches).
 * Returns an array of matches, or a text with the error when something went wrong.
 */
function vtc_taken_fetch_nevobo_matches($url = VTC_TAKEN_NEVOBO_URL) {
	$response = wp_remote_get($url, array('timeout' => 30));

	if (is_wp_error($response)) {
		return 'Kon Nevobo niet bereiken: ' . $response->get_error_message();
	}

	if (wp_remote_retrieve_response_code($response) != 200) {
		return 'Nevobo gaf een foutmelding (code ' . wp_remote_retrieve_response_code($response) . ').';
	}

	// SimpleXLSX is a small library that reads Excel files. You don't need to change it.
	// The old wedstrijd planner has its own copy with the same class name. Loading it twice would
	// crash PHP, so only load our copy when the class doesn't exist yet.
	if (!class_exists('Shuchkin\SimpleXLSX')) {
		require_once(dirname(__FILE__) . '/lib/SimpleXLSX.php');
	}

	$xlsx = Shuchkin\SimpleXLSX::parseData(wp_remote_retrieve_body($response));

	if (!$xlsx) {
		return 'Kon het Excel bestand van Nevobo niet lezen.';
	}

	$matches = array();

	// The columns in the Nevobo Excel file:
	// 0 Datum, 1 Tijd, 2 Team thuis, 3 Team uit, 4 Locatie, 5 Veld, 6 Regio, 7 Poule, 8 Code,
	// 9 Zaalcode, 10 Zaal, 11 Plaats, ... last: Wedstrijd status
	foreach ($xlsx->rows() as $index => $row) {
		if ($index == 0) {
			continue; // the first row is the heading
		}

		$code = isset($row[8]) ? trim($row[8]) : '';
		if ($code == '' || empty($row[0])) {
			continue;
		}

		// The date is '2026-11-21 00:00:00'. Add the time when there is one.
		// For cancelled matches the time column says 'Vervallen', then we keep 00:00.
		$date = substr($row[0], 0, 10);
		$time = trim($row[1]);
		if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
			$time = '00:00';
		}

		$status = strtolower(trim(end($row)));
		if ($status != 'vervallen') {
			$status = 'gepland';
		}

		$matches[] = array(
			'code' => $code,
			'match_date' => date('Y-m-d H:i:s', strtotime($date . ' ' . $time)),
			'home_team' => vtc_taken_clean_name($row[2]),
			'away_team' => vtc_taken_clean_name($row[3]),
			'field' => (string) $row[5],
			'region' => (string) $row[6],
			'poule' => (string) $row[7],
			'status' => $status,
			// The hall code is only used to recognise away matches, it is not saved
			'hall_code' => isset($row[9]) ? strtoupper(trim($row[9])) : '',
			'location' => trim((isset($row[10]) ? $row[10] : '') . ', ' . (isset($row[11]) ? $row[11] : ''), ', '),
		);
	}

	return $matches;
}

/*
 * Imports the matches from Nevobo into the database.
 * - Only matches from today onwards. Past matches are never changed.
 * - New matches are added.
 * - Changed matches (other time, field, team or status) are updated and get a red marker.
 * - When a match is cancelled ('vervallen'), its tellen / fluiten tasks are removed.
 *
 * Returns an array with:
 *   'error'    => text, empty when everything went fine
 *   'messages' => list of texts about new / changed / cancelled matches
 */
function vtc_taken_import_matches() {
	$nevobo_matches = vtc_taken_fetch_nevobo_matches();

	// A text instead of an array means something went wrong
	if (!is_array($nevobo_matches)) {
		return array('error' => $nevobo_matches, 'messages' => array());
	}

	$messages = array();
	$new_matches = array();
	$today = current_time('Y-m-d') . ' 00:00:00';

	foreach ($nevobo_matches as $new) {
		if ($new['match_date'] < $today) {
			continue;
		}

		// Everything in the hall export is a home match
		unset($new['hall_code']);
		$new['is_home'] = 1;

		$name = $new['home_team'] . ' - ' . $new['away_team'] . ' (' . vtc_taken_format_date($new['match_date'], true) . ')';
		$existing = vtc_taken_get_match_by_code($new['code']);

		// A new match
		if (!$existing) {
			$new['changed'] = 1;
			vtc_taken_add_match($new);
			$new_matches[] = 'Nieuw: ' . $name;
			continue;
		}

		// An existing match: did something important change?
		$is_changed = $existing->match_date != $new['match_date']
			|| $existing->home_team != $new['home_team']
			|| $existing->away_team != $new['away_team']
			|| $existing->field != $new['field']
			|| $existing->status != $new['status']
			|| $existing->is_home != 1; // was an away match, now moved to our hall

		if (!$is_changed) {
			continue;
		}

		$new['changed'] = 1;
		vtc_taken_update_match($existing->id, $new);

		if ($new['status'] == 'vervallen') {
			vtc_taken_delete_match_tasks($existing->id);
			$messages[] = 'Vervallen (tellen/fluiten verwijderd): ' . $name;
		} else {
			// Update the date and text of the tellen / fluiten tasks of this match
			vtc_taken_refresh_match_tasks($existing->id);
			$messages[] = 'Gewijzigd: ' . $name;
		}
	}

	// Show new matches one by one when there are a few. When there are many
	// (for example the first import of a season) one line is enough.
	if (count($new_matches) > 10) {
		$messages[] = count($new_matches) . ' nieuwe wedstrijden toegevoegd.';
	} else {
		$messages = array_merge($messages, $new_matches);
	}

	// Also get the away matches of our teams (for the warnings on the Wedstrijden page)
	$messages = array_merge($messages, vtc_taken_import_away_matches());

	update_option('vtc_taken_last_import', current_time('mysql'));

	return array('error' => '', 'messages' => $messages);
}

/*
 * Imports the away matches of our teams from the club export.
 * Away match = a match in the club export that is not in our own hall.
 * They are saved with is_home = 0. They don't get tellen / fluiten tasks and never get a red marker,
 * they are only used to show who plays away, and to warn when such a team gets a task.
 *
 * Returns a list of messages for the import result.
 */
function vtc_taken_import_away_matches() {
	$club_matches = vtc_taken_fetch_nevobo_matches(VTC_TAKEN_NEVOBO_CLUB_URL);

	// A text instead of an array means something went wrong. The home matches are still imported.
	if (!is_array($club_matches)) {
		return array('Let op: de uitwedstrijden konden niet worden opgehaald. ' . $club_matches);
	}

	$messages = array();
	$added = 0;
	$updated = 0;
	$today = current_time('Y-m-d') . ' 00:00:00';

	foreach ($club_matches as $new) {
		// Home matches come from the hall export, past matches are never changed
		if ($new['hall_code'] == VTC_TAKEN_HALL_CODE || $new['match_date'] < $today) {
			continue;
		}

		unset($new['hall_code']);
		$new['is_home'] = 0;

		$existing = vtc_taken_get_match_by_code($new['code']);

		if (!$existing) {
			vtc_taken_add_match($new);
			$added++;
			continue;
		}

		// A match that was in our hall, but is now played somewhere else
		if ($existing->is_home == 1) {
			$new['changed'] = 1;
			vtc_taken_update_match($existing->id, $new);
			vtc_taken_delete_match_tasks($existing->id);
			$messages[] = 'Verplaatst naar een andere zaal (tellen/fluiten verwijderd): ' . $new['home_team'] . ' - ' . $new['away_team']
				. ' (' . vtc_taken_format_date($new['match_date'], true) . ')';
			continue;
		}

		$is_changed = $existing->match_date != $new['match_date']
			|| $existing->home_team != $new['home_team']
			|| $existing->away_team != $new['away_team']
			|| $existing->location != $new['location']
			|| $existing->status != $new['status'];

		if ($is_changed) {
			vtc_taken_update_match($existing->id, $new);
			$updated++;
		}
	}

	if ($added > 0 || $updated > 0) {
		$messages[] = 'Uitwedstrijden: ' . $added . ' nieuw, ' . $updated . ' gewijzigd.';
	}

	return $messages;
}

/*
 * ------------------------------------------------------------
 * The tellen / fluiten tasks of a match
 * ------------------------------------------------------------
 */

/*
 * The assigned teams for a list of matches.
 * Returns: array( match_id => array('tellen' => team_id, 'fluiten' => team_id) )
 */
function vtc_taken_get_match_assignments($matches) {
	global $wpdb;
	$table = vtc_taken_table_name();

	if (empty($matches)) {
		return array();
	}

	// intval() makes sure only numbers end up in the SQL
	$ids = array();
	foreach ($matches as $match) {
		$ids[] = intval($match->id);
	}

	$tasks = $wpdb->get_results("SELECT * FROM $table WHERE match_id IN (" . implode(',', $ids) . ")");

	$assignments = array();
	foreach ($tasks as $task) {
		$assignments[$task->match_id][$task->task_type] = $task->team_id;
	}

	return $assignments;
}

// The tellen or fluiten task of one match, or null
function vtc_taken_get_match_task($match_id, $type) {
	global $wpdb;
	$table = vtc_taken_table_name();

	return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE match_id = %d AND task_type = %s", $match_id, $type));
}

/*
 * The title, description and date for a match task.
 * Title:       'VTC Woerden MC 1 - DVC XC 1'
 * Description: 'Veld 2' (plus 'Tweede scheidsrechter' for teams in that settings list)
 */
function vtc_taken_match_task_texts($match, $type) {
	$description = '';
	if ($match->field != '') {
		$description = 'Veld ' . $match->field;
	}

	if ($type == 'fluiten' && vtc_taken_team_in_list($match->home_team, vtc_taken_get_setting_list('second_referee'))) {
		$description .= "\nTweede scheidsrechter";
	}

	return array(
		'title' => $match->home_team . ' - ' . $match->away_team,
		'description' => trim($description),
		'task_date' => $match->match_date,
	);
}

/*
 * Gives a team the tellen or fluiten task for a match.
 * $team_id = 0 removes the task.
 */
function vtc_taken_assign_match_task($match, $type, $team_id) {
	$task = vtc_taken_get_match_task($match->id, $type);

	// No team chosen: remove the task when there is one
	if ($team_id == 0) {
		if ($task) {
			vtc_taken_delete_task($task->id);
		}
		return;
	}

	$data = vtc_taken_match_task_texts($match, $type);
	$data['team_id'] = $team_id;

	if ($task) {
		vtc_taken_update_task($task->id, $data);
	} else {
		$data['task_type'] = $type;
		$data['match_id'] = $match->id;
		vtc_taken_add_task($data);
	}
}

// Updates the date and texts of the tasks of a match (after Nevobo changed the match)
function vtc_taken_refresh_match_tasks($match_id) {
	$match = vtc_taken_get_match($match_id);
	if (!$match) {
		return;
	}

	foreach (array('tellen', 'fluiten') as $type) {
		$task = vtc_taken_get_match_task($match_id, $type);

		if ($task) {
			vtc_taken_update_task($task->id, vtc_taken_match_task_texts($match, $type));
		}
	}
}

function vtc_taken_delete_match_tasks($match_id) {
	global $wpdb;

	$wpdb->delete(vtc_taken_table_name(), array('match_id' => $match_id), array('%d'));
}
