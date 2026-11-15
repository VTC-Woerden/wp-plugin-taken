<?php

if (!defined('ABSPATH')) {
	exit;
}

/*
 * The admin pages:
 * - Taken > Alle taken   (list of tasks)
 * - Taken > Nieuwe taak  (form to add or edit a task)
 *
 * Saving and deleting is done by the functions at the bottom of this file.
 * The forms send their data to admin-post.php, WordPress then calls our function
 * through the 'admin_post_...' hook.
 */

/*
 * ------------------------------------------------------------
 * Menu
 * ------------------------------------------------------------
 */

function vtc_taken_admin_menu() {
	// Only users with the 'vtc_manage_tasks' permission see this menu (see install.php)
	add_menu_page('Taken', 'Taken', 'vtc_manage_tasks', 'vtc-taken', 'vtc_taken_page_list', 'dashicons-clipboard', 52);

	// The first submenu item uses the same slug as the main menu, so it replaces the duplicate "Taken" item
	add_submenu_page('vtc-taken', 'Alle taken', 'Alle taken', 'vtc_manage_tasks', 'vtc-taken', 'vtc_taken_page_list');
	add_submenu_page('vtc-taken', 'Nieuwe taak', 'Nieuwe taak', 'vtc_manage_tasks', 'vtc-taken-form', 'vtc_taken_page_form');
}

add_action('admin_menu', 'vtc_taken_admin_menu');

/*
 * ------------------------------------------------------------
 * Page: Alle taken
 * ------------------------------------------------------------
 */

/*
 * The filters of the "Alle taken" list, from the URL. For example: admin.php?page=vtc-taken&team=14&past=1&type=bardienst
 * Used by the list and by the Excel export, so the export always has exactly the same tasks as the list.
 */
function vtc_taken_list_filters() {
	$type = isset($_GET['type']) ? sanitize_key($_GET['type']) : '';

	// Only accept types we know
	if (!array_key_exists($type, vtc_taken_task_types())) {
		$type = '';
	}

	return array(
		'team' => isset($_GET['team']) ? intval($_GET['team']) : 0,
		'past' => !empty($_GET['past']),
		'type' => $type,
	);
}

function vtc_taken_page_list() {
	$filters = vtc_taken_list_filters();
	$team_id = $filters['team'];
	$show_past = $filters['past'];
	$type = $filters['type'];
	$types = vtc_taken_task_types();

	$tasks = vtc_taken_get_tasks($team_id, $show_past, $type);

	// The export link gets the same filters as the list (and a nonce, to check the click came from this page)
	$export_url = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'vtc_taken_export',
				'team' => $team_id,
				'past' => $show_past ? 1 : 0,
				'type' => $type,
			),
			admin_url('admin-post.php')
		),
		'vtc_taken_export'
	);

	$new_url = admin_url('admin.php?page=vtc-taken-form');
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Taken</h1>
		<a href="<?php echo esc_url($new_url); ?>" class="page-title-action">Nieuwe taak</a>
		<hr class="wp-header-end">

		<?php vtc_taken_show_message(); ?>

		<form method="get" style="margin: 15px 0;">
			<input type="hidden" name="page" value="vtc-taken">

			<?php
			// wp_dropdown_pages() also works for the 'teams' post type and shows sub teams indented
			echo wp_dropdown_pages(array(
				'post_type' => 'teams',
				'name' => 'team',
				'selected' => $team_id,
				'show_option_none' => 'Alle teams',
				'option_none_value' => 0,
				'echo' => 0,
			));
			?>

			<select name="type">
				<option value="">Alle types</option>
				<?php foreach ($types as $key => $label) : ?>
					<option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>

			<label style="margin: 0 10px;">
				<input type="checkbox" name="past" value="1" <?php checked($show_past); ?>>
				Toon verleden taken
			</label>

			<button type="submit" class="button">Filteren</button>

			<a href="<?php echo esc_url($export_url); ?>" class="button" style="margin-left: 10px;">
				Exporteren naar Excel (<?php echo intval(count($tasks)); ?>)
			</a>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 20%;">Datum</th>
					<th>Taak</th>
					<th style="width: 15%;">Team</th>
					<th style="width: 10%;">Type</th>
					<th style="width: 15%;">Acties</th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($tasks)) : ?>
					<tr>
						<td colspan="5">Geen taken gevonden.</td>
					</tr>
				<?php endif; ?>

				<?php foreach ($tasks as $task) : ?>
					<?php
					$edit_url = admin_url('admin.php?page=vtc-taken-form&id=' . $task->id);

					// Tellen / fluiten tasks are edited on the Wedstrijden page, at the row of that match
					if (!empty($task->match_id)) {
						$edit_url = admin_url('admin.php?page=vtc-taken-matches&season=' . vtc_taken_season_of_date($task->task_date) . '#wedstrijd-' . $task->match_id);
					}

					// The nonce in this link proves the click came from this page
					$delete_url = wp_nonce_url(
						admin_url('admin-post.php?action=vtc_taken_delete&id=' . $task->id),
						'vtc_taken_delete_' . $task->id
					);

					$team_name = get_the_title($task->team_id);
					if ($team_name == '') {
						$team_name = '(team niet gevonden)';
					}

					$date_text = 'Geen datum';
					if (!empty($task->task_date)) {
						$date_text = vtc_taken_format_date($task->task_date) . ' ' . vtc_taken_format_time_range($task);
					}

					$type_text = isset($types[$task->task_type]) ? $types[$task->task_type] : $task->task_type;
					?>
					<tr>
						<td><?php echo esc_html($date_text); ?></td>
						<td>
							<strong><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($task->title); ?></a></strong>
							<?php if (!empty($task->description)) : ?>
								<br><span class="description"><?php echo esc_html(wp_trim_words($task->description, 15)); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html($team_name); ?></td>
						<td><?php echo esc_html($type_text); ?></td>
						<td>
							<a href="<?php echo esc_url($edit_url); ?>">Bewerken</a>
							|
							<a href="<?php echo esc_url($delete_url); ?>" style="color: #b32d2e;" onclick="return confirm('Weet je zeker dat je deze taak wilt verwijderen?');">Verwijderen</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/*
 * ------------------------------------------------------------
 * Page: Nieuwe taak / Taak bewerken
 * ------------------------------------------------------------
 */

function vtc_taken_page_form() {
	// With ?id=5 in the URL we edit task 5, without it we add a new task
	$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
	$task = null;

	if ($id) {
		$task = vtc_taken_get_task($id);
		if (!$task) {
			echo '<div class="wrap"><h1>Taak niet gevonden</h1></div>';
			return;
		}

		if (!empty($task->match_id)) {
			$matches_url = admin_url('admin.php?page=vtc-taken-matches&season=' . vtc_taken_season_of_date($task->task_date) . '#wedstrijd-' . $task->match_id);
			echo '<div class="wrap"><h1>Taak bewerken</h1><p>Deze taak hoort bij een wedstrijd. Je wijzigt hem op de <a href="' . esc_url($matches_url) . '">Wedstrijden</a> pagina.</p></div>';
			return;
		}
	}

	// The values to show in the form (empty for a new task)
	$team_id = $task ? $task->team_id : 0;
	$title = $task ? $task->title : '';
	$description = $task ? $task->description : '';
	$task_type = $task ? $task->task_type : 'algemeen';
	$date = '';
	$time = '';
	$end_time = $task ? vtc_taken_format_end_time($task->end_time) : '';

	if ($task && !empty($task->task_date)) {
		// Dutch notation in the form: 28/11/2026
		$date = date_create($task->task_date)->format('d/m/Y');
		$time = vtc_taken_format_time($task->task_date);
	}

	$teams_dropdown = wp_dropdown_pages(array(
		'post_type' => 'teams',
		'name' => 'team_id',
		'id' => 'vtc_taken_team',
		'selected' => $team_id,
		'show_option_none' => '- Kies een team -',
		'option_none_value' => '',
		'echo' => 0,
	));
	?>
	<div class="wrap">
		<h1><?php echo $task ? 'Taak bewerken' : 'Nieuwe taak'; ?></h1>

		<?php if ($teams_dropdown == '') : ?>
			<div class="notice notice-warning"><p>Er zijn nog geen teams. Maak eerst een team aan onder <strong>Teams</strong>.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<!-- Tells WordPress to call vtc_taken_handle_save() -->
			<input type="hidden" name="action" value="vtc_taken_save">
			<input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
			<?php wp_nonce_field('vtc_taken_save'); ?>

			<table class="form-table">
				<tr>
					<th><label for="vtc_taken_team">Team</label></th>
					<td><?php echo str_replace('<select', '<select required', $teams_dropdown); ?></td>
				</tr>
				<tr>
					<th><label for="vtc_taken_title">Taak</label></th>
					<td>
						<input type="text" name="title" id="vtc_taken_title" class="regular-text" required value="<?php echo esc_attr($title); ?>">
						<p class="description">Bijvoorbeeld: Bardienst, Zaal opbouwen, Kantine schoonmaken</p>
					</td>
				</tr>
				<tr>
					<th><label for="vtc_taken_description">Omschrijving</label></th>
					<td><textarea name="description" id="vtc_taken_description" class="large-text" rows="4"><?php echo esc_textarea($description); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="vtc_taken_date">Datum</label></th>
					<td>
						<?php
						// Normal text fields instead of type="date" / type="time": those show the format of the
						// browser language (often mm/dd/yyyy and AM/PM). admin-form.js adds a Dutch calendar.
						?>
						<input type="text" name="task_date" id="vtc_taken_date" class="vtc-taken-date" value="<?php echo esc_attr($date); ?>"
							placeholder="dd/mm/jjjj" pattern="\d{2}/\d{2}/\d{4}" maxlength="10" autocomplete="off" size="11">
						<input type="text" name="task_time" class="vtc-taken-time" value="<?php echo esc_attr($time); ?>" aria-label="Van"
							placeholder="uu:mm" pattern="([01]\d|2[0-3]):[0-5]\d" maxlength="5" inputmode="numeric" autocomplete="off" size="6">
						tot
						<input type="text" name="task_end_time" class="vtc-taken-time" value="<?php echo esc_attr($end_time); ?>" aria-label="Tot"
							placeholder="uu:mm" pattern="([01]\d|2[0-3]):[0-5]\d" maxlength="5" inputmode="numeric" autocomplete="off" size="6">
						<p class="description">Datum als dd/mm/jjjj, tijden als uu:mm (24 uur). Laat de datum leeg voor een doorlopende taak. De tijden zijn optioneel, bijvoorbeeld 14:00 tot 17:00 voor een bardienst.</p>
					</td>
				</tr>
				<tr>
					<th><label for="vtc_taken_type">Type</label></th>
					<td>
						<select name="task_type" id="vtc_taken_type">
							<?php foreach (vtc_taken_manual_task_types() as $key => $label) : ?>
								<option value="<?php echo esc_attr($key); ?>" <?php selected($task_type, $key); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<p class="description">Tellen en fluiten bij wedstrijden plan je op de <a href="<?php echo esc_url(admin_url('admin.php?page=vtc-taken-matches')); ?>">Wedstrijden</a> pagina.</p>

			<?php submit_button($task ? 'Taak opslaan' : 'Taak toevoegen'); ?>
		</form>
	</div>
	<?php
}

/*
 * ------------------------------------------------------------
 * Saving and deleting
 * ------------------------------------------------------------
 */

function vtc_taken_handle_save() {
	// 1. Is this user allowed to do this?
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}

	// 2. Did the form really come from our page? (checks the nonce from wp_nonce_field)
	check_admin_referer('vtc_taken_save');

	// 3. Read and clean the form fields.
	// WordPress adds slashes to $_POST (it's -> it\'s), wp_unslash() removes them again.
	$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
	$team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : 0;
	$title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
	$description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
	$date = isset($_POST['task_date']) ? sanitize_text_field($_POST['task_date']) : '';
	$time = isset($_POST['task_time']) ? sanitize_text_field($_POST['task_time']) : '';
	$end_time = isset($_POST['task_end_time']) ? sanitize_text_field($_POST['task_end_time']) : '';
	$task_type = isset($_POST['task_type']) ? sanitize_key($_POST['task_type']) : 'algemeen';

	if ($team_id == 0 || $title == '') {
		wp_die('Kies een team en vul een taak in.', 'Taak niet opgeslagen', array('back_link' => true));
	}

	// Only allow the types of the form (tellen / fluiten are made on the Wedstrijden page)
	if (!array_key_exists($task_type, vtc_taken_manual_task_types())) {
		$task_type = 'algemeen';
	}

	// The form uses Dutch notation: date 28/11/2026, times 14:00 (24 hours).
	// Turn them into the database notation, and stop with a message when something is typed wrong.
	$date = vtc_taken_parse_dutch_date($date);
	$time = vtc_taken_parse_time($time);
	$end_time = vtc_taken_parse_time($end_time);

	if ($date === false) {
		wp_die('De datum klopt niet. Gebruik dd/mm/jjjj, bijvoorbeeld 28/11/2026.', 'Taak niet opgeslagen', array('back_link' => true));
	}
	if ($time === false || $end_time === false) {
		wp_die('Een tijd klopt niet. Gebruik uu:mm, bijvoorbeeld 09:30 of 14:00.', 'Taak niet opgeslagen', array('back_link' => true));
	}

	// Combine date and time into one database value.
	// No date = NULL (an ongoing task). No time = 00:00, which we don't show on the website.
	$task_date = null;
	if ($date != '') {
		if ($time == '') {
			$time = '00:00';
		}
		$task_date = $date . ' ' . $time . ':00';
	}

	// The optional until time. Only saved when there is a date.
	$task_end_time = null;
	if ($task_date !== null && $end_time != '') {
		$task_end_time = $end_time . ':00';

		// An until time before the start time is a typo
		if ($time != '00:00' && $end_time <= $time) {
			wp_die('De eindtijd moet na de begintijd liggen.', 'Taak niet opgeslagen', array('back_link' => true));
		}
	}

	$data = array(
		'team_id' => $team_id,
		'title' => $title,
		'description' => $description,
		'task_date' => $task_date,
		'end_time' => $task_end_time,
		'task_type' => $task_type,
	);

	// 4. Save
	if ($id) {
		vtc_taken_update_task($id, $data);
	} else {
		vtc_taken_add_task($data);
	}

	// 5. Back to the list, with a message
	wp_safe_redirect(admin_url('admin.php?page=vtc-taken&message=saved'));
	exit;
}

add_action('admin_post_vtc_taken_save', 'vtc_taken_handle_save');

/*
 * '28/11/2026' becomes '2026-11-28' (the database notation).
 * Returns '' when the field is empty, and false when the date is wrong (for example 31/02/2026).
 */
function vtc_taken_parse_dutch_date($text) {
	$text = trim($text);

	if ($text == '') {
		return '';
	}

	// Also accept 28-11-2026 and 1/2/2026
	if (!preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $text, $parts)) {
		return false;
	}

	$day = intval($parts[1]);
	$month = intval($parts[2]);
	$year = intval($parts[3]);

	if (!checkdate($month, $day, $year)) {
		return false;
	}

	return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/*
 * '9:30', '09:30' and '09.30' all become '09:30'.
 * Returns '' when the field is empty, and false when the time is wrong (for example 25:00).
 */
function vtc_taken_parse_time($text) {
	$text = trim($text);

	if ($text == '') {
		return '';
	}

	if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', $text, $parts)) {
		return false;
	}

	$hours = intval($parts[1]);
	$minutes = intval($parts[2]);

	if ($hours > 23 || $minutes > 59) {
		return false;
	}

	return sprintf('%02d:%02d', $hours, $minutes);
}

function vtc_taken_handle_delete() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}

	$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

	// Checks the nonce from the delete link (see vtc_taken_page_list)
	check_admin_referer('vtc_taken_delete_' . $id);

	vtc_taken_delete_task($id);

	wp_safe_redirect(admin_url('admin.php?page=vtc-taken&message=deleted'));
	exit;
}

add_action('admin_post_vtc_taken_delete', 'vtc_taken_handle_delete');

/*
 * ------------------------------------------------------------
 * Excel export of the "Alle taken" list
 * ------------------------------------------------------------
 *
 * Exports exactly the tasks that the list shows with the current filters.
 */
function vtc_taken_handle_export() {
	if (!current_user_can('vtc_manage_tasks')) {
		wp_die('Je hebt geen toegang tot deze pagina.');
	}
	check_admin_referer('vtc_taken_export');

	$filters = vtc_taken_list_filters();
	$tasks = vtc_taken_get_tasks($filters['team'], $filters['past'], $filters['type']);
	$types = vtc_taken_task_types();

	// The first row: the column names, <b> makes them bold in Excel
	$rows = array(
		array('<b>Datum</b>', '<b>Van</b>', '<b>Tot</b>', '<b>Team</b>', '<b>Type</b>', '<b>Taak</b>', '<b>Omschrijving</b>'),
	);

	foreach ($tasks as $task) {
		$date = '';
		if (!empty($task->task_date)) {
			// dd/mm/yyyy becomes a real date in Excel, so you can sort and filter on it
			$date = date_create($task->task_date)->format('d/m/Y');
		}

		$rows[] = array(
			$date,
			vtc_taken_format_time($task->task_date),
			vtc_taken_format_end_time($task->end_time),
			get_the_title($task->team_id),
			isset($types[$task->task_type]) ? $types[$task->task_type] : $task->task_type,
			$task->title,
			str_replace("\n", ' / ', (string) $task->description),
		);
	}

	// SimpleXLSXGen is a small library that writes Excel files. You don't need to change it.
	// The old wedstrijd planner has its own copy with the same class name, so only load ours when needed.
	if (!class_exists('Shuchkin\SimpleXLSXGen')) {
		require_once(dirname(__FILE__) . '/lib/SimpleXLSXGen.php');
	}

	$xlsx = Shuchkin\SimpleXLSXGen::fromArray($rows, 'Taken');
	$xlsx->freezePanes('A2'); // keep the column names visible while scrolling

	// File name with the filters in it, for example: taken-bardienst-heren-1-2026-11-15.xlsx
	$name = 'taken';
	if ($filters['type'] != '') {
		$name .= '-' . $filters['type'];
	}
	if ($filters['team']) {
		$name .= '-' . sanitize_title(get_the_title($filters['team']));
	}
	$name .= '-' . current_time('Y-m-d') . '.xlsx';

	$xlsx->downloadAs($name);
	exit;
}

add_action('admin_post_vtc_taken_export', 'vtc_taken_handle_export');

// Shows "Taak opgeslagen" or "Taak verwijderd" after a redirect
function vtc_taken_show_message() {
	if (!isset($_GET['message'])) {
		return;
	}

	$text = '';
	if ($_GET['message'] == 'saved') {
		$text = 'Taak opgeslagen.';
	}
	if ($_GET['message'] == 'deleted') {
		$text = 'Taak verwijderd.';
	}

	if ($text != '') {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
	}
}
