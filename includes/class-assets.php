<?php
/**
 * Script/style registration and tile providers.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Everything is registered on every request but only enqueued when a map is
 * rendered, so Leaflet never loads on pages without one.
 */
class Assets {

	const LEAFLET_VERSION = '1.9.4';
	const GEOMAN_VERSION  = '2.20.6';
	const CLUSTER_VERSION = '1.5.3';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_styles_early' ), 20 );
	}

	/**
	 * Register all handles.
	 */
	public static function register(): void {
		$vendor = ADMAPS_URL . 'assets/vendor/';

		wp_register_style( 'admaps-leaflet', $vendor . 'leaflet/leaflet.css', array(), self::LEAFLET_VERSION );
		wp_register_script( 'admaps-leaflet', $vendor . 'leaflet/leaflet.js', array(), self::LEAFLET_VERSION, true );

		wp_register_style( 'admaps-markercluster', $vendor . 'markercluster/MarkerCluster.css', array( 'admaps-leaflet' ), self::CLUSTER_VERSION );
		wp_register_style( 'admaps-markercluster-default', $vendor . 'markercluster/MarkerCluster.Default.css', array( 'admaps-markercluster' ), self::CLUSTER_VERSION );
		wp_register_script( 'admaps-markercluster', $vendor . 'markercluster/leaflet.markercluster.js', array( 'admaps-leaflet' ), self::CLUSTER_VERSION, true );

		wp_register_style( 'admaps-geoman', $vendor . 'geoman/leaflet-geoman.css', array( 'admaps-leaflet' ), self::GEOMAN_VERSION );
		wp_register_script( 'admaps-geoman', $vendor . 'geoman/leaflet-geoman.js', array( 'admaps-leaflet' ), self::GEOMAN_VERSION, true );

		wp_register_style( 'admaps-frontend', ADMAPS_URL . 'assets/css/frontend.css', array( 'admaps-leaflet' ), ADMAPS_VERSION );
		wp_register_script( 'admaps-frontend', ADMAPS_URL . 'assets/js/frontend.js', array( 'admaps-leaflet' ), ADMAPS_VERSION, true );
	}

	/**
	 * Enqueue front-end assets. Called from the shortcode.
	 *
	 * @param bool $cluster Whether marker clustering is needed.
	 */
	public static function enqueue_frontend( bool $cluster = false ): void {
		if ( ! wp_script_is( 'admaps-leaflet', 'registered' ) ) {
			self::register();
		}

		wp_enqueue_style( 'admaps-frontend' );
		wp_enqueue_script( 'admaps-frontend' );

		if ( $cluster ) {
			wp_enqueue_style( 'admaps-markercluster-default' );
			wp_enqueue_script( 'admaps-markercluster' );
		}
	}

	/**
	 * When we can tell in advance that the page has a map, print the CSS in
	 * the head to avoid an unstyled flash. Otherwise the shortcode enqueues it
	 * late and WordPress prints it in the footer.
	 */
	public static function maybe_enqueue_styles_early(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$has_map = has_shortcode( $post->post_content, 'admaps' );
		if ( ! $has_map ) {
			$breakdance = get_post_meta( $post->ID, '_breakdance_data', true );
			$has_map    = is_string( $breakdance ) && false !== strpos( $breakdance, '[admaps' );
		}

		/**
		 * Filter whether the current page is known to contain a map.
		 *
		 * @param bool     $has_map Whether a map was detected.
		 * @param \WP_Post $post    Current post.
		 */
		if ( apply_filters( 'admaps_page_has_map', $has_map, $post ) ) {
			wp_enqueue_style( 'admaps-frontend' );
		}
	}

	/**
	 * Available tile layers.
	 *
	 * @return array<string, array>
	 */
	public static function tile_providers(): array {
		$osm_attr = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

		$providers = array(
			'osm' => array(
				'label'       => __( 'OpenStreetMap', 'admaps' ),
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => $osm_attr,
				'maxZoom'     => 19,
			),
		);

		$custom = Settings::get();
		if ( '' !== $custom['custom_url'] ) {
			$providers['custom'] = array(
				'label'       => '' !== $custom['custom_label'] ? $custom['custom_label'] : __( 'Custom tiles', 'admaps' ),
				'url'         => $custom['custom_url'],
				'attribution' => $custom['custom_attribution'],
				'subdomains'  => $custom['custom_subdomains'],
				'maxZoom'     => $custom['custom_max_zoom'],
			);
		}

		/**
		 * Filter the tile providers. Each entry needs label, url and attribution;
		 * subdomains and maxZoom are optional.
		 *
		 * @param array $providers Providers keyed by slug.
		 */
		return apply_filters( 'admaps_tile_providers', $providers );
	}

	/**
	 * Resolve a provider slug (empty = site default) to a Leaflet tile config.
	 *
	 * @param string $slug Provider slug.
	 * @return array
	 */
	public static function resolve_tiles( string $slug ): array {
		$providers = self::tile_providers();
		if ( '' === $slug || ! isset( $providers[ $slug ] ) ) {
			$slug = Settings::get()['default_tiles'];
		}
		if ( ! isset( $providers[ $slug ] ) ) {
			$slug = 'osm';
		}

		$tile = $providers[ $slug ];
		unset( $tile['label'] );
		$tile['attribution'] = wp_kses(
			(string) ( $tile['attribution'] ?? '' ),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		return $tile;
	}
}
