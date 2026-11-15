<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * Admin page: Taken > Instellingen
 *
 * 1. Three lists for the Wedstrijden page (same as in the old wedstrijd planner):
 *    - poules to hide
 *    - home teams that need a second referee
 *    - home teams that only need counters (no referee)
 * 2. A button to copy matches, assignments and these lists from the old
 *    wedstrijd planner. Only shown when the old planner's table exists.
 */

function vtc_taken_settings_menu() {
	add_submenu_page('vtc-taken', 'Instellingen', 'Instellingen', 'vtc_manage_tasks', 'vtc-taken-settings', 'vtc_taken_page_settings');
}

add_action('admin_menu', 'vtc_taken_settings_menu');

// The table name of the old wedstrijd planner, or an empty text when it doesn't exist
function vtc_taken_old_planner_table() {
	global $wpdb;
	$table = $wpdb->prefix . 'wedstrijd_planner';

	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) == $table) {
		return $table;
	}

	return '';
}

// Turns the text of a textarea into a list. Accepts one item per line, or items separated by commas.
function vtc_taken_text_to_list($text) {
	$items = preg_split('/[\r\n,]+/', $text);

	$list = array();
	foreach ($items as $item) {
		$item = vtc_taken_clean_name($item);
		if ($item != '') {
			$list[] = $item;
		}
	}

	return array_values(array_unique($list));
}

/*
 * ------------------------------------------------------------
 * Page: Instellingen
 * ------------------------------------------------------------
 */

function vtc_taken_page_settings() {
	$lists = array(
		'exclude_poules' => array(
			'label' => 'Poules verbergen',
			'help' => 'Wedstrijden in deze poules worden niet getoond op de Wedstrijden pagina. Bijvoorbeeld: XC2M1',
		),
		'second_referee' => array(
			'label' => 'Thuisteams met tweede scheidsrechter',
			'help' => 'Bij deze thuisteams staat "Tweede scheidsrechter" bij het fluiten. Gebruik de naam zoals in Nevobo, bijvoorbeeld: VTC Woerden HS 1',
		),
		'teller_only' => array(
			'label' => 'Thuisteams met alleen tellers',
			'help' => 'Bij deze thuisteams hoeft er niemand te fluiten. Gebruik de naam zoals in Nevobo',
		),
	);
	?>
	<div class="wrap vtc-taken-settings">
		<h1>Instellingen</h1>

		<?php if (isset($_GET['message']) && $_GET['message'] == 'saved') : ?>
			<div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>
		<?php endif; ?>

		<?php vtc_taken_show_migration_result(); ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<input type="hidden" name="action" value="vtc_taken_save_settings">
			<?php wp_nonce_field('vtc_taken_save_settings'); ?>

			<table class="form-table">
				<tr>
					<th><label for="vtc_taken_task_window">Tijd tussen eigen wedstrijd en taak</label></th>
					<td>
						<input type="number" name="task_window_minutes" id="vtc_taken_task_window" min="0" max="600" step="15"
							value="<?php echo esc_attr(vtc_taken_get_task_window_minutes()); ?>" class="small-text"> minuten
						<p class="description">
							Zoveel tijd moet er minimaal zitten tussen de eigen wedstrijd van een team en een tel- of fluittaak van dat team.
							Bij 120: speelt een team om 13:00, dan kan het een taak doen om 11:00 of eerder, of om 15:00 of later.
							Op de Wedstrijden pagina wordt een taak die hier niet aan voldoet rood. Geldt voor alle teams.
						</p>
					</td>
				</tr>
				<?php foreach ($lists as $name => $info) : ?>
					<tr>
						<th><label for="vtc_taken_<?php echo esc_attr($name); ?>"><?php echo esc_html($info['label']); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr($name); ?>" id="vtc_taken_<?php echo esc_attr($name); ?>" rows="6"><?php echo esc_textarea(implode("\n", vtc_taken_get_setting_list($name))); ?></textarea>
							<p class="description"><?php echo esc_html($info['help']); ?>. Eén per regel.</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button('Instellingen opslaan'); ?>
		</form>

		<hr>
		<?php vtc_taken_render_team_links(); ?>

		<hr>
		<h2 id="overnemen">Overnemen uit de oude wedstrijdplanner</h2>

		<?php if (vtc_taken_old_planner_table() == '') : ?>
			<?php // Show why the button is missing, instead of hiding the whole section ?>
			<div class="notice notice-info inline">
				<p>
					De gegevens van de oude wedstrijdplanner zijn niet gevonden op deze website
					(de databasetabel <code><?php echo esc_html($GLOBALS['wpdb']->prefix . 'wedstrijd_planner'); ?></code> bestaat niet).
					Daarom is er niets om over te nemen. Op de website waar de oude planner gebruikt wordt, staat hier de knop.
				</p>
			</div>
		<?php else : ?>
			<p>
				Kopieert de wedstrijden, de toegewezen tellers en scheidsrechters, en de drie lijsten hierboven uit de oude wedstrijdplanner.<br>
				Teams worden gekoppeld op naam: de teamnaam in de oude planner moet gelijk zijn aan de titel van een team onder <strong>Teams</strong>.<br>
				Je kunt dit vaker doen: bestaande toewijzingen in deze plugin worden niet overschreven.
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="vtc_taken_migrate">
				<?php wp_nonce_field('vtc_taken_migrate'); ?>
				<button type="submit" class="button">Overnemen uit oude wedstrijdplanner</button>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

function vtc_taken_handle_save_settings() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_save_settings');

	foreach (array('exclude_poules', 'second_referee', 'teller_only') as $name) {
		$text = isset($_POST[$name]) ? sanitize_textarea_field(wp_unslash($_POST[$name])) : '';
		vtc_taken_save_setting_list($name, vtc_taken_text_to_list($text));
	}

	// The time window, between 0 and 10 hours
	if (isset($_POST['task_window_minutes'])) {
		$minutes = max(0, min(600, intval($_POST['task_window_minutes'])));
		update_option('vtc_taken_task_window_minutes', $minutes);
	}

	wp_safe_redirect(admin_url('admin.php?page=vtc-taken-settings&message=saved'));
	exit;
}

add_action('admin_post_vtc_taken_save_settings', 'vtc_taken_handle_save_settings');

/*
 * ------------------------------------------------------------
 * Link every team to its Nevobo team name
 * ------------------------------------------------------------
 *
 * The teams on the website are called for example 'Heren 1', in Nevobo that team is 'VTC Woerden HS 1'.
 * With this link the Wedstrijden page can see when a team plays itself, and warn when you
 * give that team a task at the same time.
 */

function vtc_taken_render_team_links() {
	$teams = vtc_taken_get_teams();
	$nevobo_teams = vtc_taken_get_nevobo_club_teams();
	$links = vtc_taken_get_team_nevobo_names();
	?>
	<h2 id="teams-koppelen">Teams koppelen aan Nevobo</h2>
	<p>
		Kies per team de naam die Nevobo gebruikt. Daarmee ziet de Wedstrijden pagina wanneer een team zelf speelt,
		en wordt een taak rood als het team op dat moment niet kan.<br>
		De lijst komt uit de opgehaalde wedstrijden. Staat een team er niet tussen? Haal eerst de wedstrijden op.
	</p>

	<?php if (isset($_GET['message']) && $_GET['message'] == 'links_saved') : ?>
		<div class="notice notice-success is-dismissible"><p>Koppelingen opgeslagen.</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="vtc_taken_save_team_links">
		<?php wp_nonce_field('vtc_taken_save_team_links'); ?>

		<table class="wp-list-table widefat striped vtc-taken-team-links">
			<thead>
				<tr>
					<th>Team op de website</th>
					<th>Team in Nevobo</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($teams as $team) : ?>
					<?php $current = isset($links[$team->ID]) ? $links[$team->ID] : ''; ?>
					<tr>
						<td><?php echo esc_html($team->post_title); ?></td>
						<td>
							<select name="nevobo_team[<?php echo esc_attr($team->ID); ?>]">
								<option value="">- Niet gekoppeld -</option>
								<?php foreach ($nevobo_teams as $nevobo_team) : ?>
									<option value="<?php echo esc_attr($nevobo_team); ?>" <?php selected($current, $nevobo_team); ?>><?php echo esc_html($nevobo_team); ?></option>
								<?php endforeach; ?>

								<?php // Keep a saved name visible, also when it is not in the current matches anymore ?>
								<?php if ($current != '' && !in_array($current, $nevobo_teams)) : ?>
									<option value="<?php echo esc_attr($current); ?>" selected><?php echo esc_html($current); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button('Koppelingen opslaan'); ?>
	</form>
	<?php
}

function vtc_taken_handle_save_team_links() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_save_team_links');

	$posted = isset($_POST['nevobo_team']) && is_array($_POST['nevobo_team']) ? $_POST['nevobo_team'] : array();

	foreach (vtc_taken_get_teams() as $team) {
		$name = isset($posted[$team->ID]) ? sanitize_text_field(wp_unslash($posted[$team->ID])) : '';

		if ($name == '') {
			delete_post_meta($team->ID, 'vtc_nevobo_team');
		} else {
			update_post_meta($team->ID, 'vtc_nevobo_team', $name);
		}
	}

	// No #teams-koppelen here: WordPress shows the message at the top of the page
	wp_safe_redirect(admin_url('admin.php?page=vtc-taken-settings&message=links_saved'));
	exit;
}

add_action('admin_post_vtc_taken_save_team_links', 'vtc_taken_handle_save_team_links');

/*
 * ------------------------------------------------------------
 * Copy everything from the old wedstrijd planner
 * ------------------------------------------------------------
 */

/*
 * Finds a team post by name. Ignores capitals, and treats '-' as a space
 * (the old planner did the same). Returns the team ID, or 0 when not found.
 */
function vtc_taken_find_team_id_by_name($name, $teams) {
	$name = strtolower(vtc_taken_clean_name(str_replace('-', ' ', $name)));

	foreach ($teams as $team) {
		$title = strtolower(vtc_taken_clean_name(str_replace('-', ' ', $team->post_title)));

		if ($title == $name) {
			return $team->ID;
		}
	}

	return 0;
}

function vtc_taken_handle_migrate() {
	global $wpdb;

	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_migrate');

	$old_table = vtc_taken_old_planner_table();
	if ($old_table == '') {
		wp_die('De tabel van de oude wedstrijdplanner bestaat niet.', '', array('back_link' => true));
	}

	// 1. The three lists. We add the old items to what is already there.
	$old_lists = array(
		'exclude_poules' => array('table' => 'wedstrijd_planner_exclude_poules', 'column' => 'poule'),
		'second_referee' => array('table' => 'wedstrijd_planner_second_referee', 'column' => 'team'),
		'teller_only' => array('table' => 'wedstrijd_planner_teller_only', 'column' => 'team'),
	);

	foreach ($old_lists as $name => $info) {
		$table = $wpdb->prefix . $info['table'];

		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) != $table) {
			continue;
		}

		$old_items = $wpdb->get_col("SELECT {$info['column']} FROM $table");
		$merged = vtc_taken_text_to_list(implode("\n", array_merge(vtc_taken_get_setting_list($name), $old_items)));

		vtc_taken_save_setting_list($name, $merged);
	}

	// 2. The matches and their assignments
	$teams = vtc_taken_get_teams();
	$old_matches = $wpdb->get_results("SELECT * FROM $old_table ORDER BY datum");

	$matches_added = 0;
	$tasks_added = 0;
	$unknown_teams = array();

	foreach ($old_matches as $old) {
		// Skip rows with a broken date (the old planner saved cancelled matches without a valid time)
		if (empty($old->datum) || substr($old->datum, 0, 4) == '0000') {
			continue;
		}

		$match = vtc_taken_get_match_by_code($old->code);

		if (!$match) {
			vtc_taken_add_match(array(
				'code' => $old->code,
				'match_date' => $old->datum,
				'home_team' => vtc_taken_clean_name($old->team_thuis),
				'away_team' => vtc_taken_clean_name($old->team_uit),
				'field' => (string) $old->veld,
				'region' => (string) $old->regio,
				'poule' => (string) $old->poule,
				'status' => 'gepland',
				'changed' => 0,
			));
			$match = vtc_taken_get_match_by_code($old->code);
			$matches_added++;
		}

		// teller -> tellen, scheidsrechter -> fluiten
		$old_assignments = array('tellen' => $old->teller, 'fluiten' => $old->scheidsrechter);

		foreach ($old_assignments as $type => $team_name) {
			if (empty($team_name)) {
				continue;
			}

			// Don't overwrite an assignment that was already made in this plugin
			if (vtc_taken_get_match_task($match->id, $type)) {
				continue;
			}

			$team_id = vtc_taken_find_team_id_by_name($team_name, $teams);

			if ($team_id == 0) {
				// Remember how often a name was not found, to show it afterwards
				$unknown_teams[$team_name] = isset($unknown_teams[$team_name]) ? $unknown_teams[$team_name] + 1 : 1;
				continue;
			}

			vtc_taken_assign_match_task($match, $type, $team_id);
			$tasks_added++;
		}
	}

	set_transient('vtc_taken_migration_result_' . get_current_user_id(), array(
		'matches_added' => $matches_added,
		'tasks_added' => $tasks_added,
		'unknown_teams' => $unknown_teams,
	), 300);

	wp_safe_redirect(admin_url('admin.php?page=vtc-taken-settings'));
	exit;
}

add_action('admin_post_vtc_taken_migrate', 'vtc_taken_handle_migrate');

function vtc_taken_show_migration_result() {
	$result = get_transient('vtc_taken_migration_result_' . get_current_user_id());

	if (!$result) {
		return;
	}

	delete_transient('vtc_taken_migration_result_' . get_current_user_id());

	echo '<div class="notice notice-success"><p>Overgenomen uit de oude wedstrijdplanner: '
		. intval($result['matches_added']) . ' wedstrijden en '
		. intval($result['tasks_added']) . ' tel/fluit taken. De lijsten hieronder zijn aangevuld.</p></div>';

	if (!empty($result['unknown_teams'])) {
		echo '<div class="notice notice-warning"><p><strong>Deze teamnamen uit de oude planner zijn niet gevonden onder Teams.</strong> '
			. 'Hun taken zijn niet overgenomen. Pas de titel van het team aan (of de naam in de oude planner) en klik nog een keer op overnemen.</p><ul>';

		foreach ($result['unknown_teams'] as $name => $count) {
			echo '<li>' . esc_html($name) . ' (' . intval($count) . ' taken)</li>';
		}

		echo '</ul></div>';
	}
}
