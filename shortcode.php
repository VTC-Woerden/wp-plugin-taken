<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * Shortcode [vtc_taken]
 *
 * [vtc_taken]                     On a team page: the tasks of that team.
 *                                 On any other page: the tasks of all teams.
 * [vtc_taken team="heren-1"]      The tasks of one team, on any page.
 * [vtc_taken toon_verleden="ja"]  Also show tasks from the past.
 * [vtc_taken print="nee"]         Hide the "Printen" button.
 *
 * The page prints only the tasks (see the print part in taken-style.css).
 * With ?print in the URL the print window opens by itself, like the old zaaltaken page.
 *
 * The 'teams' post type has no content editor, so in the theme template of a
 * team page (for example single-teams.php) add:
 *     <?php echo do_shortcode('[vtc_taken]'); ?>
 */
add_shortcode('vtc_taken', 'vtc_taken_shortcode');
function vtc_taken_shortcode($atts = [], $content = null) {
	$atts = shortcode_atts(array(
		'team' => '',
		'toon_verleden' => 'nee',
		'print' => 'ja',
	), $atts, 'vtc_taken');

	$show_past = ($atts['toon_verleden'] == 'ja');
	$show_print = ($atts['print'] != 'nee');

	// 1. A team was given in the shortcode
	if ($atts['team'] != '') {
		$team = vtc_taken_get_team_by_slug($atts['team']);

		if (!$team) {
			return '<p>Team niet gevonden: ' . esc_html($atts['team']) . '</p>';
		}

		return vtc_taken_wrap_printable(
			vtc_taken_render_team($team->ID, $show_past, false),
			'Taken ' . get_the_title($team->ID),
			$show_print
		);
	}

	// 2. No team given, but we are on a team page: use that team
	if (is_singular('teams')) {
		return vtc_taken_wrap_printable(
			vtc_taken_render_team(get_the_ID(), $show_past, false),
			'Taken ' . get_the_title(),
			$show_print
		);
	}

	// 3. Otherwise: all teams that have tasks, each with their name above it
	$html = '';
	foreach (vtc_taken_get_teams() as $team) {
		$tasks = vtc_taken_get_tasks($team->ID, $show_past);

		if (!empty($tasks)) {
			$html .= vtc_taken_render_team($team->ID, $show_past, true);
		}
	}

	if ($html == '') {
		return "<p class='vtc-taken__empty'>Er zijn geen taken gepland.</p>";
	}

	return vtc_taken_wrap_printable($html, 'Taken', $show_print);
}

/*
 * Puts the tasks in a block that can be printed:
 * - a "Printen" button above it (only on screen)
 * - a title that is only shown on paper (on screen the page already has its own title)
 */
function vtc_taken_wrap_printable($content, $print_title, $show_button) {
	$html = "<div class='vtc-taken-printable'>";

	if ($show_button) {
		$html .= "<button type='button' class='vtc-taken__print-button' onclick='window.print()'>Printen</button>";
	}

	$html .= "<h2 class='vtc-taken__print-title'>" . esc_html($print_title) . "</h2>";
	$html .= $content;
	$html .= "</div>";

	// ?print in the URL: open the print window right away (the old zaaltaken page did the same)
	if (isset($_GET['print'])) {
		$html .= "<script>window.addEventListener('load', function () { window.print(); });</script>";
	}

	return $html;
}

/*
 * The HTML for the tasks of one team.
 * $show_team_name = true shows the team name as a heading (used in the overview of all teams)
 */
function vtc_taken_render_team($team_id, $show_past, $show_team_name) {
	$tasks = vtc_taken_get_tasks($team_id, $show_past);
	$types = vtc_taken_task_types();

	$html = "<div class='vtc-taken'>";

	if ($show_team_name) {
		$html .= "<h3 class='vtc-taken__team'>" . esc_html(get_the_title($team_id)) . "</h3>";
	}

	if (empty($tasks)) {
		$html .= "<p class='vtc-taken__empty'>Er zijn geen taken gepland.</p>";
		$html .= "</div>";
		return $html;
	}

	// A table: Datum | Tijd | Taak
	$html .= "<table class='vtc-taken__table'>";
	$html .= "<thead><tr><th class='vtc-taken__col-date'>Datum</th><th class='vtc-taken__col-time'>Tijd</th><th>Taak</th></tr></thead>";
	$html .= "<tbody>";

	$today = current_time('Y-m-d');

	foreach ($tasks as $task) {
		// Escape everything that comes from the database before printing it
		$title = esc_html($task->title);
		$type = esc_html(isset($types[$task->task_type]) ? $types[$task->task_type] : $task->task_type);
		$type_class = esc_attr($task->task_type);

		$row_class = 'vtc-taken__row vtc-taken__row--' . $type_class;

		if (empty($task->task_date)) {
			$date_text = 'Doorlopend';
			$time_text = '';
		} else {
			$date_text = esc_html(vtc_taken_format_table_date($task->task_date));

			// '14:00', or '14:00 - 17:00' when there is an until time
			$time_text = esc_html(vtc_taken_format_time_range($task));

			// Tasks from the past (only shown with toon_verleden="ja") are greyed out
			if (substr($task->task_date, 0, 10) < $today) {
				$row_class .= ' vtc-taken__row--past';
			}
		}

		$description_html = '';
		if (!empty($task->description)) {
			// nl2br keeps the line breaks from the admin form
			$description_html = "<div class='vtc-taken__description'>" . nl2br(esc_html($task->description)) . "</div>";
		}

		$html .= <<<HTML
		<tr class="{$row_class}">
			<td class="vtc-taken__col-date">{$date_text}</td>
			<td class="vtc-taken__col-time">{$time_text}</td>
			<td class="vtc-taken__col-task">
				<span class="vtc-taken__type">{$type}</span>
				<span class="vtc-taken__title">{$title}</span>
				{$description_html}
			</td>
		</tr>
HTML;
	}

	$html .= "</tbody></table>";
	$html .= "</div>";

	return $html;
}

/*
 * The date for the table: 'zaterdag 21 november'.
 * The year is only added when it is not this year: 'zaterdag 3 april 2027'.
 */
function vtc_taken_format_table_date($task_date) {
	$text = vtc_taken_format_date($task_date); // 'zaterdag 21 november 2026'
	$year = date_create($task_date)->format('Y');

	if ($year == current_time('Y')) {
		$text = substr($text, 0, -5); // remove ' 2026'
	}

	return $text;
}
