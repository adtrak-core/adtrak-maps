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
	 * Hook up. Does nothing without a token, e.g. in a development checkout.
	 */
	public static function init(): void {
		$token = self::token();
		if ( '' === $token ) {
			return;
		}

		require_once ADMAPS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker( self::REPOSITORY, ADMAPS_FILE, 'admaps' );
		$checker->setBranch( 'main' );
		$checker->setAuthentication( $token );

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

	/**
	 * Read-only GitHub token. ADMAPS_UPDATE_TOKEN in wp-config.php wins over the one added to release zips.
	 *
	 * @return string
	 */
	private static function token(): string {
		if ( defined( 'ADMAPS_UPDATE_TOKEN' ) ) {
			return (string) ADMAPS_UPDATE_TOKEN;
		}
		$file = ADMAPS_PATH . 'update-token.php';
		if ( is_readable( $file ) ) {
			$token = include $file;
			return is_string( $token ) ? trim( $token ) : '';
		}
		return '';
	}
}
