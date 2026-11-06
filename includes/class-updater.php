<?php
/**
 * Updates from the plugin's GitHub releases.
 *
 * @package Admaps
 */

namespace Admaps;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Offers new releases on the Plugins and Updates screens.
 */
class Updater {

	const REPOSITORY = 'https://github.com/adtrak-core/adtrak-maps/';

	/**
	 * Hook up. Skipped in a git checkout so a development copy is never overwritten.
	 */
	public static function init(): void {
		if ( is_dir( ADMAPS_PATH . '.git' ) ) {
			return;
		}

		require_once ADMAPS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPOSITORY, ADMAPS_FILE, 'admaps' );
		$checker->setBranch( 'main' );

		// Optional: GitHub allows 60 unauthenticated API requests an hour per server IP.
		if ( defined( 'ADMAPS_UPDATE_TOKEN' ) && ADMAPS_UPDATE_TOKEN ) {
			$checker->setAuthentication( (string) ADMAPS_UPDATE_TOKEN );
		}

		$api = $checker->getVcsApi();
		$api->enableReleaseAssets( '/^admaps-[0-9][0-9a-z.\-]*\.zip$/i', Api::REQUIRE_RELEASE_ASSETS );
		if ( Settings::get()['prerelease_updates'] ) {
			$api->setReleaseFilter( null, Api::RELEASE_FILTER_ALL );
		}

		// Only ever install a published release's zip, never a bare tag or the branch.
		$checker->addFilter(
			'vcs_update_detection_strategies',
			static function ( array $strategies ): array {
				return array_intersect_key( $strategies, array( Api::STRATEGY_LATEST_RELEASE => true ) );
			}
		);
	}
}
