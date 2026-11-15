<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * The screens for in the sports hall (they replace the pages of the old wedstrijd planner):
 *
 * Wedstrijddag: the matches of one day per field, with who does the tellen and fluiten.
 *   /?vtc_scherm=wedstrijddag&datum=2026-11-21     (without datum: today)
 *
 * Weekschema: all days of one week below each other, including the bar shifts.
 *   /?vtc_scherm=weekschema&weeknummer=47&jaar=2026&columns=2     (all optional, default this week)
 *
 * The old addresses /wedstrijddag/?datum=... and /weekschema/?... also work, as soon as the old
 * wedstrijd planner is deactivated (that removes its pages, then WordPress would show "not found").
 *
 * These pages don't use the theme: they are made to fill a screen.
 */

// Our club logo, shown at the top of the weekschema
define('VTC_TAKEN_LOGO_URL', 'https://vtcwoerden.nl/wp-content/uploads/2022/09/VTC-logo-nieuw-blauw.jpg');

// Reload the screen every 10 minutes, so a screen in the hall shows changes without anyone touching it
define('VTC_TAKEN_SCREEN_REFRESH_SECONDS', 600);

/*
 * ------------------------------------------------------------
 * Which screen is asked for?
 * ------------------------------------------------------------
 */

function vtc_taken_screens_router() {
	$screen = isset($_GET['vtc_scherm']) ? sanitize_key($_GET['vtc_scherm']) : '';

	// The old addresses, only when WordPress has no page for them (so the old planner is gone)
	if ($screen == '' && is_404()) {
		$path = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
		$home_path = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');

		if ($home_path != '' && strpos($path, $home_path) === 0) {
			$path = trim(substr($path, strlen($home_path)), '/');
		}

		if ($path == 'wedstrijddag' || $path == 'weekschema') {
			$screen = $path;
		}
	}

	if ($screen == 'wedstrijddag') {
		vtc_taken_show_wedstrijddag();
		exit;
	}

	if ($screen == 'weekschema') {
		vtc_taken_show_weekschema();
		exit;
	}
}

add_action('template_redirect', 'vtc_taken_screens_router', 1);

/*
 * ------------------------------------------------------------
 * The two screens
 * ------------------------------------------------------------
 */

function vtc_taken_show_wedstrijddag() {
	// The date from the URL (2026-11-21), otherwise today
	$date = isset($_GET['datum']) ? sanitize_text_field($_GET['datum']) : '';
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		$date = current_time('Y-m-d');
	}

	$title = 'Wedstrijddag ' . vtc_taken_format_table_date($date);

	vtc_taken_screen_start($title);
	echo vtc_taken_render_screen_day($date, vtc_taken_screen_data($date, $date), true);
	vtc_taken_screen_end();
}

function vtc_taken_show_weekschema() {
	$week = isset($_GET['weeknummer']) ? intval($_GET['weeknummer']) : intval(current_time('W'));
	$year = isset($_GET['jaar']) ? intval($_GET['jaar']) : intval(current_time('o')); // 'o' = the year the week belongs to
	$columns = isset($_GET['columns']) ? intval($_GET['columns']) : 2;

	// Keep the numbers sensible
	$week = max(1, min(53, $week));
	$columns = max(1, min(4, $columns));

	// Monday and sunday of that week
	$monday = new DateTime();
	$monday->setISODate($year, $week, 1);
	$sunday = clone $monday;
	$sunday->modify('+6 days');

	$data = vtc_taken_screen_data($monday->format('Y-m-d'), $sunday->format('Y-m-d'));

	// Only the days that have matches or bar shifts
	$days = array_unique(array_merge(array_keys($data['matches']), array_keys($data['bar'])));
	sort($days);

	vtc_taken_screen_start('Weekschema week ' . $week);
	?>
	<header class="vtc-scherm__header">
		<img class="vtc-scherm__logo" src="<?php echo esc_url(VTC_TAKEN_LOGO_URL); ?>" alt="VTC Woerden">
		<div class="vtc-scherm__week">Week <?php echo intval($week); ?> &middot; <?php echo intval($year); ?></div>
	</header>

	<?php if (empty($days)) : ?>
		<p class="vtc-scherm__leeg">Geen wedstrijden of bardiensten in week <?php echo intval($week); ?>.</p>
	<?php else : ?>
		<div class="vtc-weekschema" style="grid-template-columns: repeat(<?php echo intval($columns); ?>, 1fr);">
			<?php foreach ($days as $day) : ?>
				<?php echo vtc_taken_render_screen_day($day, $data, true); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php
	vtc_taken_screen_end();
}

/*
 * ------------------------------------------------------------
 * Data for the screens
 * ------------------------------------------------------------
 */

/*
 * Everything the screens need for a period, loaded once:
 * - matches:     per day ('2026-11-21') the matches
 * - assignments: per match id the team ids for tellen / fluiten
 * - bar:         per day the bar shifts
 * - teams:       team id => team name
 */
function vtc_taken_screen_data($start, $end) {
	$matches = vtc_taken_get_matches_between($start, $end);

	$data = array(
		'matches' => array(),
		'assignments' => vtc_taken_get_match_assignments($matches),
		'bar' => array(),
		'teams' => array(),
	);

	foreach ($matches as $match) {
		$data['matches'][substr($match->match_date, 0, 10)][] = $match;
	}

	foreach (vtc_taken_get_tasks_of_type_between('bardienst', $start, $end) as $task) {
		$data['bar'][substr($task->task_date, 0, 10)][] = $task;
	}

	foreach (vtc_taken_get_teams() as $team) {
		$data['teams'][$team->ID] = $team->post_title;
	}

	return $data;
}

/*
 * ------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------
 */

// The start of the page: no theme, only our own CSS
function vtc_taken_screen_start($title) {
	status_header(200);
	nocache_headers();
	?>
<!DOCTYPE html>
<html lang="nl">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<meta http-equiv="refresh" content="<?php echo intval(VTC_TAKEN_SCREEN_REFRESH_SECONDS); ?>">
	<title><?php echo esc_html($title); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url(plugin_dir_url(__FILE__) . 'screens.css?ver=1.2.0'); ?>">
</head>
<body class="vtc-scherm">
	<?php
}

function vtc_taken_screen_end() {
	?>
</body>
</html>
	<?php
}

/*
 * One day: the title, the bar shifts and the hall with its fields.
 * The fields are drawn like the hall: kantine on the left, veld 4 and 3 at the top,
 * veld 1 (with the hoofdveld below it) and veld 2 at the bottom, the tribune below.
 */
function vtc_taken_render_screen_day($date, $data, $show_bar) {
	$matches = isset($data['matches'][$date]) ? $data['matches'][$date] : array();
	$bar_tasks = isset($data['bar'][$date]) ? $data['bar'][$date] : array();

	// Group the matches per field: array('1' => array(...), 'H1' => array(...))
	$per_field = array();
	foreach ($matches as $match) {
		$per_field[strtoupper($match->field)][] = $match;
	}

	$html = '<section class="vtc-dag">';
	$html .= '<h1 class="vtc-dag__titel">' . esc_html(ucfirst(vtc_taken_format_table_date($date))) . '</h1>';

	if ($show_bar && !empty($bar_tasks)) {
		$html .= vtc_taken_render_screen_bar($bar_tasks, $data['teams']);
	}

	// A day without matches (for example only a bar shift): no empty hall
	if (empty($matches)) {
		$html .= '<p class="vtc-dag__geen">Geen wedstrijden</p>';
		$html .= '</section>';
		return $html;
	}

	$html .= '<div class="vtc-hal">';
	$html .= '<div class="vtc-hal__kantine">Kantine</div>';
	$html .= vtc_taken_render_screen_field('vtc-hal__veld--4', array('Veld 4' => '4'), $per_field, $data);
	$html .= vtc_taken_render_screen_field('vtc-hal__veld--3', array('Veld 3' => '3'), $per_field, $data);
	$html .= vtc_taken_render_screen_field('vtc-hal__veld--1', array('Veld 1' => '1', 'Hoofdveld' => 'H1'), $per_field, $data);
	$html .= vtc_taken_render_screen_field('vtc-hal__veld--2', array('Veld 2' => '2'), $per_field, $data);
	$html .= '<div class="vtc-hal__tribune">Tribune</div>';
	$html .= '</div>';

	$html .= '</section>';

	return $html;
}

/*
 * One field box. $fields = array('Veld 1' => '1', 'Hoofdveld' => 'H1'):
 * the Hoofdveld part is only shown when there are matches on it (same as the old planner).
 */
function vtc_taken_render_screen_field($class, $fields, $per_field, $data) {
	$second_referee_teams = vtc_taken_get_setting_list('second_referee');
	$teller_only_teams = vtc_taken_get_setting_list('teller_only');

	$html = '<div class="vtc-hal__veld ' . esc_attr($class) . '">';
	$is_first = true;

	foreach ($fields as $label => $field) {
		$field_matches = isset($per_field[$field]) ? $per_field[$field] : array();

		// Extra fields (like the hoofdveld) only when they are used this day
		if (!$is_first && empty($field_matches)) {
			continue;
		}
		$is_first = false;

		$html .= '<h2 class="vtc-hal__veldnaam">' . esc_html($label) . '</h2>';

		foreach ($field_matches as $match) {
			$assigned = isset($data['assignments'][$match->id]) ? $data['assignments'][$match->id] : array();

			$teller = isset($assigned['tellen'], $data['teams'][$assigned['tellen']]) ? $data['teams'][$assigned['tellen']] : '-';
			$referee = isset($assigned['fluiten'], $data['teams'][$assigned['fluiten']]) ? $data['teams'][$assigned['fluiten']] : '-';

			$html .= '<div class="vtc-wedstrijd">';
			$html .= '<div class="vtc-wedstrijd__teams"><span class="vtc-wedstrijd__tijd">' . esc_html(vtc_taken_format_time($match->match_date)) . '</span> '
				. esc_html($match->home_team . ' - ' . $match->away_team) . '</div>';
			$html .= '<div class="vtc-wedstrijd__taken">';

			if (!vtc_taken_team_in_list($match->home_team, $teller_only_teams)) {
				$referee_label = vtc_taken_team_in_list($match->home_team, $second_referee_teams) ? 'Fluiten (2ᵉ)' : 'Fluiten';
				$html .= '<span>' . esc_html($referee_label) . ' <b>' . esc_html($referee) . '</b></span>';
			}

			$html .= '<span>Tellen <b>' . esc_html($teller) . '</b></span>';
			$html .= '</div></div>';
		}
	}

	$html .= '</div>';

	return $html;
}

// The bar shifts of one day, as a strip above the hall
function vtc_taken_render_screen_bar($bar_tasks, $teams) {
	$html = '<div class="vtc-bar"><span class="vtc-bar__label">Bardienst</span>';

	foreach ($bar_tasks as $task) {
		$team = isset($teams[$task->team_id]) ? $teams[$task->team_id] : '';
		$time = vtc_taken_format_time_range($task);

		$html .= '<span class="vtc-bar__dienst">';
		if ($time != '') {
			$html .= '<span class="vtc-bar__tijd">' . esc_html($time) . '</span> ';
		}
		$html .= '<b>' . esc_html($team) . '</b>';

		// Show the task name too, when it says more than just "Bardienst"
		if (strtolower(trim($task->title)) != 'bardienst') {
			$html .= ' <span class="vtc-bar__naam">(' . esc_html($task->title) . ')</span>';
		}

		$html .= '</span>';
	}

	$html .= '</div>';

	return $html;
}
