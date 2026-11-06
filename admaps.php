<?php
/**
 * Plugin Name:       Adtrak Maps
 * Description:       Lightweight Leaflet maps with pins, radii and coverage areas, output via the [admaps] shortcode.
 * Version:           0.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Adtrak
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admaps
 *
 * @package Admaps
 */

defined( 'ABSPATH' ) || exit;

define( 'ADMAPS_VERSION', '0.3.1' );
define( 'ADMAPS_FILE', __FILE__ );
define( 'ADMAPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADMAPS_URL', plugin_dir_url( __FILE__ ) );

require_once ADMAPS_PATH . 'includes/class-map-data.php';
require_once ADMAPS_PATH . 'includes/class-assets.php';
require_once ADMAPS_PATH . 'includes/class-post-type.php';
require_once ADMAPS_PATH . 'includes/class-shortcode.php';
require_once ADMAPS_PATH . 'includes/class-admin-editor.php';
require_once ADMAPS_PATH . 'includes/class-import-export.php';
require_once ADMAPS_PATH . 'includes/class-settings.php';
require_once ADMAPS_PATH . 'includes/class-appearance.php';

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'admaps', false, dirname( plugin_basename( ADMAPS_FILE ) ) . '/languages' );

		\Admaps\Post_Type::init();
		\Admaps\Assets::init();
		\Admaps\Shortcode::init();

		if ( is_admin() ) {
			\Admaps\Admin_Editor::init();
			\Admaps\Import_Export::init();
			\Admaps\Settings::init();
		}
	}
);
