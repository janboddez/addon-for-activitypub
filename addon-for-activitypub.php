<?php
/**
 * Plugin Name:       Add-on for ActivityPub
 * Description:       Mod the ActivityPub plugin for WordPress.
 * Author:            Jan Boddez
 * Author URI:        https://jan.boddez.net/
 * License:           GNU General Public License v3
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       addon-for-activitypub
 * Version:           0.1.0
 * Requires at least: 6.5
 * GitHub Plugin URI: https://github.com/janboddez/addon-for-activitypub
 * Primary Branch:    main
 *
 * @author  Jan Boddez <jan@janboddez.be>
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package ActivityPub\Addon
 */

namespace Activitypub\Addon;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load dependencies.
require_once __DIR__ . '/includes/class-options-handler.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/functions.php';

$addon_for_activitypub = Plugin::get_instance();
$addon_for_activitypub->register();
