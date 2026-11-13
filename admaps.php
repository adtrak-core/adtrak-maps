<?php
/**
 * Plugin Name:       Adtrak Maps
 * Description:       Lightweight Leaflet maps with pins, radii and coverage areas, output via the [admaps] shortcode.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Adtrak
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       admaps
 * Update URI:        https://github.com/adtrak-core/adtrak-maps
 *
 * @package Admaps
 */

defined( 'ABSPATH' ) || exit;

define( 'ADMAPS_VERSION', '0.4.0' );
define( 'ADMAPS_FILE', __FILE__ );
define( 'ADMAPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADMAPS_URL', plugin_dir_url( __FILE__ ) );

require_once ADMAPS_PATH . 'includes/class-map-data.php';
require_once ADMAPS_PATH . 'includes/class-sources.php';
require_once ADMAPS_PATH . 'includes/class-geocoder.php';
require_once ADMAPS_PATH . 'includes/class-locations.php';
require_once ADMAPS_PATH . 'includes/class-assets.php';
require_once ADMAPS_PATH . 'includes/class-post-type.php';
require_once ADMAPS_PATH . 'includes/class-shortcode.php';
require_once ADMAPS_PATH . 'includes/class-pin-field.php';
require_once ADMAPS_PATH . 'includes/class-admin-editor.php';
require_once ADMAPS_PATH . 'includes/class-admin-source.php';
require_once ADMAPS_PATH . 'includes/class-import-export.php';
require_once ADMAPS_PATH . 'includes/class-settings.php';
require_once ADMAPS_PATH . 'includes/class-style-reference.php';
require_once ADMAPS_PATH . 'includes/class-appearance.php';
require_once ADMAPS_PATH . 'includes/class-updater.php';

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'admaps', false, dirname( plugin_basename( ADMAPS_FILE ) ) . '/languages' );

		\Admaps\Post_Type::init();
		\Admaps\Assets::init();
		\Admaps\Shortcode::init();
		\Admaps\Updater::init();
		\Admaps\Locations::init();

		if ( is_admin() ) {
			\Admaps\Admin_Editor::init();
			\Admaps\Admin_Source::init();
			\Admaps\Import_Export::init();
			\Admaps\Settings::init();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_unschedule_hook( 'admaps_geocode' );
		wp_unschedule_hook( 'admaps_locations_refresh' );
	}
);
