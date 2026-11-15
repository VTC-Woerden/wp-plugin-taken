<?php

/*
 * Plugin Name: VTC Taken
 * Description: Taken voor teams beheren in het admin panel en tonen op de teampagina's.
 * Version: 1.0.0
 */

// Stop when this file is opened directly instead of through WordPress
if (!defined('ABSPATH')) {
	exit;
}

// Raise this number when the database table changes.
// The plugin then updates the table automatically (see vtc_taken_check_version).
define('VTC_TAKEN_DB_VERSION', '1.3');

// The Nevobo Excel export with all matches in our sports hall
define('VTC_TAKEN_NEVOBO_URL', 'https://api.nevobo.nl/export/sporthal/WOEPO/programma.xlsx');

// The Nevobo Excel export with all matches of our club (home and away). Used for the away matches.
// CKL9X7N is the Nevobo code of VTC Woerden.
define('VTC_TAKEN_NEVOBO_CLUB_URL', 'https://api.nevobo.nl/export/vereniging/CKL9X7N/programma.xlsx');

// The Nevobo code of our sports hall. Matches in the club export with another hall code are away matches.
define('VTC_TAKEN_HALL_CODE', 'WOEPO');

// Our club name as Nevobo writes it. Everything before it (a sponsor name) is ignored when comparing team names.
define('VTC_TAKEN_CLUB_NAME', 'VTC Woerden');

// The default minimum time (in minutes) between a team's own match and a task of that team.
// You can change it on the Instellingen page, this is only used until it is saved there.
define('VTC_TAKEN_MATCH_MINUTES', 120);

require_once(dirname(__FILE__) . '/database.php');
require_once(dirname(__FILE__) . '/matches.php');
require_once(dirname(__FILE__) . '/install.php');
require_once(dirname(__FILE__) . '/admin.php');
require_once(dirname(__FILE__) . '/admin-matches.php');
require_once(dirname(__FILE__) . '/admin-settings.php');
require_once(dirname(__FILE__) . '/shortcode.php');
require_once(dirname(__FILE__) . '/screens.php');

// Create the table and the role when the plugin is activated
register_activation_hook(__FILE__, 'vtc_taken_install');

// Also run the install again after a plugin update that changed the table
add_action('plugins_loaded', 'vtc_taken_check_version');

function vtc_taken_styles() {
	// Raise the version number when you change the CSS, so browsers load the new file
	wp_enqueue_style('vtc_taken', plugin_dir_url(__FILE__) . 'taken-style.css', array(), '2.2.0');
}

add_action('wp_enqueue_scripts', 'vtc_taken_styles');
