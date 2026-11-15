<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * Admin page: Taken > Wedstrijden
 *
 * Shows the home matches of one season (half year), grouped by day.
 * For every match you choose which team does the tellen and the fluiten.
 *
 * Buttons on this page:
 * - Wedstrijden ophalen  -> vtc_taken_handle_import()
 * - Wijzigingen gezien   -> vtc_taken_handle_clear_changed()
 * - Opslaan              -> vtc_taken_handle_save_matches()
 */

function vtc_taken_matches_menu() {
	add_submenu_page('vtc-taken', 'Wedstrijden', 'Wedstrijden', 'vtc_manage_tasks', 'vtc-taken-matches', 'vtc_taken_page_matches');
}

add_action('admin_menu', 'vtc_taken_matches_menu');

// CSS and JavaScript, only on our own admin pages
function vtc_taken_admin_files($hook) {
	if (strpos($hook, 'vtc-taken') === false) {
		return;
	}

	// Raise the version numbers when you change these files, so browsers load the new version
	wp_enqueue_style('vtc_taken_admin', plugin_dir_url(__FILE__) . 'admin-style.css', array(), '1.7.0');
	wp_enqueue_script('vtc_taken_admin', plugin_dir_url(__FILE__) . 'admin-matches.js', array(), '1.6.0', true);

	// The task form: Dutch calendar and time fields. 'jquery-ui-datepicker' comes with WordPress.
	if (strpos($hook, 'vtc-taken-form') !== false) {
		wp_enqueue_script('vtc_taken_form', plugin_dir_url(__FILE__) . 'admin-form.js', array('jquery', 'jquery-ui-datepicker'), '1.0.0', true);
	}
}

add_action('admin_enqueue_scripts', 'vtc_taken_admin_files');

/*
 * A <select> with all teams.
 * $name     = the name of the field, for example 'tellen[12]'
 * $teams    = array(team_id => team name)
 * $selected = the team_id that is chosen now (0 = nobody)
 */
function vtc_taken_team_select($name, $teams, $selected) {
	$html = '<select name="' . esc_attr($name) . '" class="vtc-taken-team-select">';
	$html .= '<option value="0"></option>';

	foreach ($teams as $team_id => $team_name) {
		$html .= '<option value="' . esc_attr($team_id) . '" ' . selected($selected, $team_id, false) . '>' . esc_html($team_name) . '</option>';
	}

	$html .= '</select>';

	return $html;
}

/*
 * ------------------------------------------------------------
 * Page: Wedstrijden
 * ------------------------------------------------------------
 */

function vtc_taken_page_matches() {
	$seasons = vtc_taken_get_seasons();

	// Which season to show: from the URL, otherwise the current one
	$season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : vtc_taken_current_season();

	$matches = vtc_taken_get_matches_for_season($season);
	$assignments = vtc_taken_get_match_assignments($matches);

	$second_referee_teams = vtc_taken_get_setting_list('second_referee');
	$teller_only_teams = vtc_taken_get_setting_list('teller_only');

	// All teams as array(team_id => name), so we only load them once
	$teams = array();
	foreach (vtc_taken_get_teams() as $team) {
		$teams[$team->ID] = $team->post_title;
	}

	// Group the matches by day, and per day by field (veld):
	// array('2026-11-21' => array('1' => array(match, match), '2' => array(match), ...))
	// The matches come from the database sorted by time, so within a field they stay in order of time.
	$matches_per_day = array();
	foreach ($matches as $match) {
		$day = substr($match->match_date, 0, 10);
		$matches_per_day[$day][$match->field][] = $match;
	}

	// Sort the fields of every day: 1, 2, 3, 4, H1
	foreach ($matches_per_day as $day => $fields) {
		ksort($fields, SORT_NATURAL);
		$matches_per_day[$day] = $fields;
	}

	$last_import = get_option('vtc_taken_last_import');

	/*
	 * Data for the conflict checker in admin-matches.js:
	 * - teams:        array(team_id => 'VTC Woerden HS 1'), from the Instellingen page
	 * - matchMinutes: minimum time between a team's own match and its task (Instellingen page)
	 * - days:         per day all matches (also the hidden poules and the away matches),
	 *                 with the start time in minutes after midnight
	 */
	$team_links = vtc_taken_get_team_nevobo_names();

	// 'vtc woerden hs 1' => 'Heren 1', to show the website name of a team in the away list
	$website_names = array();
	foreach ($team_links as $team_id => $nevobo_name) {
		$website_names[strtolower($nevobo_name)] = get_the_title($team_id);
	}

	$check_days = array();
	$away_per_day = array(); // per day the away matches, shown at the bottom of each day

	foreach (vtc_taken_get_matches_for_season($season, true, true) as $match) {
		if ($match->status == 'vervallen') {
			continue;
		}

		$day = substr($match->match_date, 0, 10);
		$time = date_create($match->match_date);

		$check_days[$day][] = array(
			'start' => intval($time->format('G')) * 60 + intval($time->format('i')),
			'home' => vtc_taken_club_team_name($match->home_team),
			'away' => vtc_taken_club_team_name($match->away_team),
			'time' => $time->format('H:i'),
			'label' => $match->home_team . ' - ' . $match->away_team,
			'isAway' => !$match->is_home,
			'location' => (string) $match->location,
		);

		if (!$match->is_home) {
			// Which of the two teams is ours? (Usually the away team, but Nevobo may list us as home team at another hall)
			$our_team = stripos($match->away_team, VTC_TAKEN_CLUB_NAME) !== false ? $match->away_team : $match->home_team;
			$our_team = vtc_taken_club_team_name($our_team);

			$away_per_day[$day][] = array(
				'time' => vtc_taken_format_time($match->match_date),
				'team' => isset($website_names[strtolower($our_team)]) ? $website_names[strtolower($our_team)] : $our_team,
				'match' => $match->home_team . ' - ' . $match->away_team,
				'location' => (string) $match->location,
			);
		}
	}

	$check_data = array(
		'teams' => $team_links,
		'matchMinutes' => vtc_taken_get_task_window_minutes(),
		'days' => $check_days,
	);
	?>
	<script>
		var vtcTakenCheckData = <?php echo wp_json_encode($check_data); ?>;
	</script>
	<div class="wrap vtc-taken-matches">
		<h1>Wedstrijden</h1>

		<?php vtc_taken_show_matches_messages(); ?>

		<div class="vtc-taken-toolbar">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="vtc_taken_import">
				<input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
				<?php wp_nonce_field('vtc_taken_import'); ?>
				<button type="submit" class="button button-primary">Wedstrijden ophalen bij Nevobo</button>
			</form>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="vtc_taken_clear_changed">
				<input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
				<?php wp_nonce_field('vtc_taken_clear_changed'); ?>
				<button type="submit" class="button">Wijzigingen gezien</button>
			</form>

			<span class="description">
				<span class="vtc-taken-changed-dot"></span> = gewijzigd of nieuw bij Nevobo.
				<?php if ($last_import) : ?>
					Laatst opgehaald: <?php echo esc_html(vtc_taken_format_date($last_import) . ' ' . date_create($last_import)->format('H:i')); ?>
				<?php endif; ?>
			</span>
		</div>

		<?php if (!empty($seasons)) : ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ($seasons as $item) : ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=vtc-taken-matches&season=' . $item)); ?>" class="nav-tab <?php echo ($item == $season) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html(vtc_taken_season_label($item)); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php if (empty($matches)) : ?>
			<p>Geen wedstrijden gevonden in <?php echo esc_html(vtc_taken_season_label($season)); ?>. Klik op <strong>Wedstrijden ophalen bij Nevobo</strong>.</p>
			<?php return; ?>
		<?php endif; ?>

		<?php if (empty($teams)) : ?>
			<div class="notice notice-warning"><p>Er zijn nog geen teams. Maak eerst teams aan onder <strong>Teams</strong>.</p></div>
		<?php endif; ?>

		<?php if (!empty($teams) && count($team_links) < count($teams)) : ?>
			<div class="notice notice-info">
				<p>
					<?php echo intval(count($teams) - count($team_links)); ?> van de <?php echo intval(count($teams)); ?> teams zijn nog niet gekoppeld aan Nevobo.
					Voor die teams kan niet gecontroleerd worden of ze zelf spelen.
					<a href="<?php echo esc_url(admin_url('admin.php?page=vtc-taken-settings#teams-koppelen')); ?>">Teams koppelen</a>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vtc-taken-matches-form">
			<input type="hidden" name="action" value="vtc_taken_save_matches">
			<input type="hidden" name="season" value="<?php echo esc_attr($season); ?>">
			<?php wp_nonce_field('vtc_taken_save_matches'); ?>

			<div class="vtc-taken-columns">
				<div class="vtc-taken-main">
					<?php foreach ($matches_per_day as $day => $fields) : ?>
						<?php
						// Count the matches of this day, for the header
						$day_count = 0;
						foreach ($fields as $field_matches) {
							$day_count += count($field_matches);
						}
						?>
						<!-- Every day is one card: a coloured header with the date, and the tables per field below it -->
						<div class="vtc-taken-day">
						<div class="vtc-taken-day-header">
							<h2><?php echo esc_html(ucfirst(vtc_taken_format_date($day))); ?></h2>
							<span>
								<?php echo intval($day_count) . ($day_count == 1 ? ' wedstrijd' : ' wedstrijden'); ?>
								<?php
								// Links to the screens for in the hall (see screens.php)
								$day_time = date_create($day);
								$wedstrijddag_url = home_url('/?vtc_scherm=wedstrijddag&datum=' . $day);
								$weekschema_url = home_url('/?vtc_scherm=weekschema&weeknummer=' . intval($day_time->format('W')) . '&jaar=' . $day_time->format('o'));
								?>
								&middot; <a href="<?php echo esc_url($wedstrijddag_url); ?>" target="_blank">Wedstrijddag</a>
								&middot; <a href="<?php echo esc_url($weekschema_url); ?>" target="_blank">Weekschema</a>
							</span>
						</div>
						<div class="vtc-taken-day-body">

						<?php $is_first_field = true; ?>

						<?php foreach ($fields as $field => $field_matches) : ?>
						<div class="vtc-taken-field">Veld <?php echo esc_html($field); ?></div>

						<table class="wp-list-table widefat striped vtc-taken-matches-table">
							<?php if ($is_first_field) : ?>
								<?php // Only the first table of a day gets the column names, like in the old planner ?>
								<thead>
									<tr>
										<th class="col-changed"></th>
										<th>Wedstrijd</th>
										<th class="col-time">Tijd</th>
										<th class="col-team">Tellen</th>
										<th class="col-team">Fluiten</th>
									</tr>
								</thead>
								<?php $is_first_field = false; ?>
							<?php endif; ?>
							<tbody>
								<?php foreach ($field_matches as $match) : ?>
									<?php
									$tellen_team = isset($assignments[$match->id]['tellen']) ? $assignments[$match->id]['tellen'] : 0;
									$fluiten_team = isset($assignments[$match->id]['fluiten']) ? $assignments[$match->id]['fluiten'] : 0;
									$is_cancelled = ($match->status == 'vervallen');
									?>
									<?php
									// Day and start time (minutes after midnight), used by the conflict checker
									$match_time = date_create($match->match_date);
									$start_minutes = intval($match_time->format('G')) * 60 + intval($match_time->format('i'));
									?>
									<tr id="wedstrijd-<?php echo esc_attr($match->id); ?>"
										class="<?php echo $is_cancelled ? 'vtc-taken-cancelled' : ''; ?>"
										data-day="<?php echo esc_attr($day); ?>"
										data-start="<?php echo esc_attr($start_minutes); ?>"
										data-time="<?php echo esc_attr($match_time->format('H:i')); ?>">
										<td class="col-changed">
											<?php if ($match->changed) : ?>
												<span class="vtc-taken-changed-dot" title="Gewijzigd of nieuw bij Nevobo"></span>
											<?php endif; ?>
										</td>
										<td title="<?php echo esc_attr($match->code . ' / ' . $match->poule); ?>">
											<?php echo esc_html($match->home_team . ' - ' . $match->away_team); ?>
											<?php if ($is_cancelled) : ?>
												<strong>(vervallen)</strong>
											<?php endif; ?>
										</td>
										<td class="col-time"><?php echo esc_html(vtc_taken_format_time($match->match_date)); ?></td>

										<?php if ($is_cancelled) : ?>
											<td class="col-team"></td>
											<td class="col-team"></td>
										<?php else : ?>
											<td class="col-team">
												<?php echo vtc_taken_team_select('tellen[' . $match->id . ']', $teams, $tellen_team); ?>
											</td>
											<td class="col-team">
												<?php if (vtc_taken_team_in_list($match->home_team, $teller_only_teams)) : ?>
													<span class="description">Alleen tellen</span>
												<?php else : ?>
													<?php if (vtc_taken_team_in_list($match->home_team, $second_referee_teams)) : ?>
														<span class="description">Tweede scheidsrechter</span><br>
													<?php endif; ?>
													<?php echo vtc_taken_team_select('fluiten[' . $match->id . ']', $teams, $fluiten_team); ?>
												<?php endif; ?>
											</td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php endforeach; ?>

						<!-- Tasks per team on this day. Filled in (and updated while you choose) by admin-matches.js -->
						<div class="vtc-taken-day-counts">
							<strong>Taken deze dag:</strong>
							<span class="vtc-taken-day-counts-list"></span>
						</div>

						<!-- Our teams that play an away match on this day -->
						<div class="vtc-taken-away">
							<strong>Uitwedstrijden deze dag:</strong>
							<?php if (empty($away_per_day[$day])) : ?>
								<span class="description">geen</span>
							<?php else : ?>
								<ul>
									<?php foreach ($away_per_day[$day] as $away) : ?>
										<?php // Only the time and our team. The opponent and hall are in the title, visible on hover. ?>
										<li title="<?php echo esc_attr($away['match'] . ($away['location'] != '' ? ' · ' . $away['location'] : '')); ?>">
											<span class="vtc-taken-away__time"><?php echo esc_html($away['time']); ?></span>
											<strong><?php echo esc_html($away['team']); ?></strong>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
						</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="vtc-taken-sidebar">
					<div class="vtc-taken-sidebar-box">
						<?php submit_button('Opslaan', 'primary', 'submit', false); ?>

						<!-- Filled in by the conflict checker in admin-matches.js -->
						<p class="vtc-taken-conflict-summary"></p>

						<h3>Takenoverzicht</h3>
						<p class="description">Aantal keer tellen + fluiten in <?php echo esc_html(vtc_taken_season_label($season)); ?>.</p>

						<!-- The numbers are filled in (and updated while you choose) by admin-matches.js -->
						<table class="widefat striped vtc-taken-counts">
							<tbody>
								<?php foreach ($teams as $team_id => $team_name) : ?>
									<tr data-team="<?php echo esc_attr($team_id); ?>">
										<td><?php echo esc_html($team_name); ?></td>
										<td class="vtc-taken-count">0</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</form>
	</div>
	<?php
}

// Shows the messages after a redirect: saved, changes seen, or the result of the import
function vtc_taken_show_matches_messages() {
	if (isset($_GET['message']) && $_GET['message'] == 'saved') {
		echo '<div class="notice notice-success is-dismissible"><p>Opgeslagen.</p></div>';
	}

	if (isset($_GET['message']) && $_GET['message'] == 'seen') {
		echo '<div class="notice notice-success is-dismissible"><p>Alle wijzigingen zijn als gezien gemarkeerd.</p></div>';
	}

	// The import result is saved for a minute in a "transient" (temporary option), per user
	$result = get_transient('vtc_taken_import_result_' . get_current_user_id());

	if ($result) {
		delete_transient('vtc_taken_import_result_' . get_current_user_id());

		if ($result['error'] != '') {
			echo '<div class="notice notice-error"><p>' . esc_html($result['error']) . '</p></div>';
		} elseif (empty($result['messages'])) {
			echo '<div class="notice notice-success is-dismissible"><p>Wedstrijden opgehaald. Er is niets veranderd.</p></div>';
		} else {
			echo '<div class="notice notice-warning"><p><strong>Wedstrijden opgehaald. ' . count($result['messages']) . ' wijziging(en):</strong></p><ul>';
			foreach ($result['messages'] as $message) {
				echo '<li>' . esc_html($message) . '</li>';
			}
			echo '</ul></div>';
		}
	}
}

/*
 * ------------------------------------------------------------
 * Handlers for the buttons
 * ------------------------------------------------------------
 */

// Back to the Wedstrijden page, in the same season
function vtc_taken_redirect_to_matches($message = '') {
	$season = isset($_POST['season']) ? sanitize_text_field($_POST['season']) : '';

	$url = admin_url('admin.php?page=vtc-taken-matches&season=' . urlencode($season));
	if ($message != '') {
		$url .= '&message=' . $message;
	}

	wp_safe_redirect($url);
	exit;
}

function vtc_taken_handle_import() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_import');

	$result = vtc_taken_import_matches();

	set_transient('vtc_taken_import_result_' . get_current_user_id(), $result, 60);

	vtc_taken_redirect_to_matches();
}

add_action('admin_post_vtc_taken_import', 'vtc_taken_handle_import');

function vtc_taken_handle_clear_changed() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_clear_changed');

	vtc_taken_clear_changed_matches();

	vtc_taken_redirect_to_matches('seen');
}

add_action('admin_post_vtc_taken_clear_changed', 'vtc_taken_handle_clear_changed');

function vtc_taken_handle_save_matches() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_save_matches');

	// The form sends: tellen[match_id] = team_id and fluiten[match_id] = team_id
	foreach (array('tellen', 'fluiten') as $type) {
		if (!isset($_POST[$type]) || !is_array($_POST[$type])) {
			continue;
		}

		foreach ($_POST[$type] as $match_id => $team_id) {
			$match = vtc_taken_get_match(intval($match_id));

			// Skip unknown, cancelled and away matches (away matches never get tasks)
			if (!$match || $match->status == 'vervallen' || !$match->is_home) {
				continue;
			}

			vtc_taken_assign_match_task($match, $type, intval($team_id));
		}
	}

	vtc_taken_redirect_to_matches('saved');
}

add_action('admin_post_vtc_taken_save_matches', 'vtc_taken_handle_save_matches');
