<?php
/**
 * [admaps id="123"] shortcode.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a map container and enqueues the front-end assets.
 */
class Shortcode {

	/**
	 * Per-request counter so the same map can appear twice on a page.
	 *
	 * @var int
	 */
	private static int $instance = 0;

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_shortcode( 'admaps', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'height' => '',
				'zoom'   => '',
				'class'  => '',
			),
			$atts,
			'admaps'
		);

		$map_id = absint( $atts['id'] );
		$post   = $map_id ? get_post( $map_id ) : null;

		if ( ! $post || Post_Type::NAME !== $post->post_type || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $map_id ) ) ) {
			return current_user_can( 'edit_pages' )
				? '<p class="admaps-error">' . esc_html( sprintf( /* translators: %s: map ID */ __( 'Adtrak Maps: map "%s" not found or not published.', 'admaps' ), $atts['id'] ) ) . '</p>'
				: '';
		}

		$data     = Map_Data::get( $map_id );
		$settings = $data['settings'];

		$height = Map_Data::sanitize_css_length( (string) $atts['height'] );
		if ( '' === $height ) {
			$height = $settings['height'];
		}

		$config = Map_Data::to_frontend( $data );
		if ( is_numeric( $atts['zoom'] ) ) {
			$config['settings']['zoom']       = max( 0, min( 22, (int) $atts['zoom'] ) );
			$config['settings']['fit_bounds'] = false;
		}

		/**
		 * Filter the config passed to the front-end script.
		 *
		 * @param array $config Map config.
		 * @param int   $map_id Map ID.
		 */
		$config = apply_filters( 'admaps_frontend_config', $config, $map_id );

		$needs_cluster = ! empty( $config['settings']['cluster'] ) && count( $config['markers'] ) > 1;
		Assets::enqueue_frontend( $needs_cluster );

		$style   = Appearance::resolve( $settings['style'] );
		$classes = array( 'admaps-map' );
		if ( '1' === (string) $style['theme_font'] ) {
			$classes[] = 'admaps-map--theme-font';
		}
		foreach ( preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$classes[] = sanitize_html_class( $class );
		}

		++self::$instance;

		return sprintf(
			'<div id="%1$s" class="%2$s" style="%3$s" role="region" aria-label="%4$s" data-admaps="%5$s"></div>',
			esc_attr( 'admaps-' . $map_id . '-' . self::$instance ),
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_attr( 'height:' . $height . ';' . Appearance::css_vars( $style ) ),
			esc_attr( '' !== $post->post_title ? $post->post_title : __( 'Map', 'admaps' ) ),
			esc_attr( wp_json_encode( $config ) )
		);
	}
}
