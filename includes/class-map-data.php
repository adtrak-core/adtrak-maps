<?php
/**
 * Map data schema, defaults and sanitisation.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * All map contents are stored as one array in post meta. Everything that comes
 * from the browser or an import file is rebuilt field by field here.
 */
class Map_Data {

	const META_KEY       = '_admaps_data';
	const SCHEMA_VERSION = 1;
	const MAX_ITEMS      = 2000;
	const MAX_POINTS     = 5000;

	const LEGEND_POSITIONS = array( 'topright', 'topleft', 'bottomright', 'bottomleft' );

	/**
	 * Default map settings.
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'height'          => Settings::get()['default_height'],
			'tiles'           => '',
			'center'          => array( 54.0, -2.5 ),
			'zoom'            => 6,
			'fit_bounds'      => true,
			'scroll_zoom'     => false,
			'zoom_control'    => true,
			'cluster'         => false,
			'legend'          => false,
			'legend_position' => 'bottomright',
			'legend_title'    => '',
			'style'           => Appearance::sanitize( array(), true ),
		);
	}

	/**
	 * Empty map data.
	 *
	 * @return array
	 */
	public static function empty_data(): array {
		return array(
			'version'  => self::SCHEMA_VERSION,
			'settings' => self::default_settings(),
			'markers'  => array(),
			'circles'  => array(),
			'polygons' => array(),
		);
	}

	/**
	 * Load a map's data, filling in any missing defaults.
	 *
	 * @param int $post_id Map post ID.
	 * @return array
	 */
	public static function get( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return self::empty_data();
		}
		return self::sanitize( $stored );
	}

	/**
	 * Save a map's data.
	 *
	 * @param int   $post_id Map post ID.
	 * @param array $data    Unsanitised data.
	 * @return array The sanitised data that was stored.
	 */
	public static function save( int $post_id, array $data ): array {
		$clean = self::sanitize( $data );
		update_post_meta( $post_id, self::META_KEY, wp_slash( $clean ) );
		return $clean;
	}

	/**
	 * Sanitise a complete map data array.
	 *
	 * @param mixed $raw Raw data (array or JSON string).
	 * @return array
	 */
	public static function sanitize( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$out             = self::empty_data();
		$out['settings'] = self::sanitize_settings( isset( $raw['settings'] ) && is_array( $raw['settings'] ) ? $raw['settings'] : array() );

		$types = array(
			'markers'  => 'sanitize_marker',
			'circles'  => 'sanitize_circle',
			'polygons' => 'sanitize_polygon',
		);
		$count = 0;
		$ids   = array();

		foreach ( $types as $key => $method ) {
			if ( empty( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
				continue;
			}
			foreach ( $raw[ $key ] as $item ) {
				if ( ! is_array( $item ) || $count >= self::MAX_ITEMS ) {
					continue;
				}
				$clean = self::$method( $item );
				if ( null === $clean ) {
					continue;
				}
				if ( '' === $clean['id'] || isset( $ids[ $clean['id'] ] ) ) {
					$clean['id'] = self::new_id();
				}
				$ids[ $clean['id'] ] = true;
				$out[ $key ][]       = $clean;
				++$count;
			}
		}

		return $out;
	}

	/**
	 * Sanitise map settings.
	 *
	 * @param array $raw Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( array $raw ): array {
		$defaults = self::default_settings();
		$out      = $defaults;

		if ( isset( $raw['height'] ) ) {
			$height        = self::sanitize_css_length( (string) $raw['height'] );
			$out['height'] = '' !== $height ? $height : $defaults['height'];
		}

		if ( isset( $raw['tiles'] ) ) {
			$tiles        = sanitize_key( (string) $raw['tiles'] );
			$out['tiles'] = array_key_exists( $tiles, Assets::tile_providers() ) ? $tiles : '';
		}

		if ( isset( $raw['center'] ) ) {
			$center        = self::sanitize_latlng( $raw['center'] );
			$out['center'] = $center ? $center : $defaults['center'];
		}

		if ( isset( $raw['zoom'] ) && is_numeric( $raw['zoom'] ) ) {
			$out['zoom'] = max( 0, min( 22, (int) $raw['zoom'] ) );
		}

		foreach ( array( 'fit_bounds', 'scroll_zoom', 'zoom_control', 'cluster', 'legend' ) as $flag ) {
			if ( isset( $raw[ $flag ] ) ) {
				$out[ $flag ] = self::to_bool( $raw[ $flag ] );
			}
		}

		if ( isset( $raw['legend_position'] ) && in_array( $raw['legend_position'], self::LEGEND_POSITIONS, true ) ) {
			$out['legend_position'] = $raw['legend_position'];
		}

		if ( isset( $raw['legend_title'] ) ) {
			$out['legend_title'] = sanitize_text_field( (string) $raw['legend_title'] );
		}

		if ( isset( $raw['style'] ) && is_array( $raw['style'] ) ) {
			$out['style'] = Appearance::sanitize( $raw['style'], true );
		}

		return $out;
	}

	/**
	 * Sanitise a pin.
	 *
	 * @param array $raw Raw marker.
	 * @return array|null
	 */
	private static function sanitize_marker( array $raw ): ?array {
		$pos = self::sanitize_latlng( array( $raw['lat'] ?? null, $raw['lng'] ?? null ) );
		if ( ! $pos ) {
			return null;
		}

		$icon     = isset( $raw['icon'] ) && is_array( $raw['icon'] ) ? $raw['icon'] : array();
		$type     = isset( $icon['type'] ) && 'image' === $icon['type'] ? 'image' : 'svg';
		$color    = sanitize_hex_color( (string) ( $icon['color'] ?? '' ) );
		$attach   = absint( $icon['attachment_id'] ?? 0 );
		$url      = esc_url_raw( (string) ( $icon['url'] ?? '' ) );
		$width    = self::clamp_int( $icon['width'] ?? 0, 8, 200, 'image' === $type ? 40 : 28 );
		$height   = self::clamp_int( $icon['height'] ?? 0, 8, 200, 'image' === $type ? 40 : 42 );
		$anchor   = isset( $icon['anchor'] ) && 'center' === $icon['anchor'] ? 'center' : 'bottom';

		if ( $attach && 'attachment' === get_post_type( $attach ) ) {
			$attach_url = wp_get_attachment_url( $attach );
			$url        = $attach_url ? $attach_url : $url;
		} else {
			$attach = 0;
		}

		if ( 'image' === $type && '' === $url ) {
			$type = 'svg';
		}

		return array(
			'id'     => self::sanitize_id( $raw['id'] ?? '' ),
			'lat'    => $pos[0],
			'lng'    => $pos[1],
			'label'  => sanitize_text_field( (string) ( $raw['label'] ?? '' ) ),
			'popup'  => wp_kses_post( (string) ( $raw['popup'] ?? '' ) ),
			'legend' => self::to_bool( $raw['legend'] ?? false ),
		) + self::sanitize_popup_size( $raw ) + array(
			'icon'   => array(
				'type'          => $type,
				'color'         => $color ? $color : '#e63946',
				'attachment_id' => $attach,
				'url'           => $url,
				'width'         => $width,
				'height'        => $height,
				'anchor'        => $anchor,
			),
		);
	}

	/**
	 * Sanitise a radius.
	 *
	 * @param array $raw Raw circle.
	 * @return array|null
	 */
	private static function sanitize_circle( array $raw ): ?array {
		$pos = self::sanitize_latlng( array( $raw['lat'] ?? null, $raw['lng'] ?? null ) );
		if ( ! $pos || ! isset( $raw['radius'] ) || ! is_numeric( $raw['radius'] ) ) {
			return null;
		}
		$radius = (float) $raw['radius'];
		if ( $radius <= 0 ) {
			return null;
		}

		$unit = isset( $raw['unit'] ) && in_array( $raw['unit'], array( 'm', 'km', 'mi' ), true ) ? $raw['unit'] : 'mi';

		return array_merge(
			array(
				'id'     => self::sanitize_id( $raw['id'] ?? '' ),
				'lat'    => $pos[0],
				'lng'    => $pos[1],
				'radius' => round( min( $radius, 5000000 ), 1 ),
				'unit'   => $unit,
			),
			self::sanitize_shape_common( $raw )
		);
	}

	/**
	 * Sanitise a coverage polygon.
	 *
	 * @param array $raw Raw polygon.
	 * @return array|null
	 */
	private static function sanitize_polygon( array $raw ): ?array {
		if ( empty( $raw['points'] ) || ! is_array( $raw['points'] ) ) {
			return null;
		}

		$points = array();
		foreach ( array_slice( $raw['points'], 0, self::MAX_POINTS ) as $point ) {
			$clean = self::sanitize_latlng( $point );
			if ( $clean ) {
				$points[] = $clean;
			}
		}
		if ( count( $points ) < 3 ) {
			return null;
		}

		return array_merge(
			array(
				'id'     => self::sanitize_id( $raw['id'] ?? '' ),
				'points' => $points,
			),
			self::sanitize_shape_common( $raw )
		);
	}

	/**
	 * Fields shared by radii and polygons.
	 *
	 * @param array $raw Raw shape.
	 * @return array
	 */
	private static function sanitize_shape_common( array $raw ): array {
		$stroke = sanitize_hex_color( (string) ( $raw['stroke'] ?? '' ) );
		$fill   = sanitize_hex_color( (string) ( $raw['fill'] ?? '' ) );

		return self::sanitize_popup_size( $raw ) + array(
			'label'          => sanitize_text_field( (string) ( $raw['label'] ?? '' ) ),
			'popup'          => wp_kses_post( (string) ( $raw['popup'] ?? '' ) ),
			'legend'         => self::to_bool( $raw['legend'] ?? true ),
			'stroke'         => $stroke ? $stroke : '#1d3557',
			'stroke_opacity' => self::clamp_float( $raw['stroke_opacity'] ?? 1, 0, 1, 1 ),
			'weight'         => self::clamp_int( $raw['weight'] ?? 2, 0, 20, 2 ),
			'fill'           => $fill ? $fill : '#457b9d',
			'fill_opacity'   => self::clamp_float( $raw['fill_opacity'] ?? 0.25, 0, 1, 0.25 ),
		);
	}

	/**
	 * Per-item bubble width overrides; 0 means use the map's value.
	 *
	 * @param array $raw Raw item.
	 * @return array
	 */
	private static function sanitize_popup_size( array $raw ): array {
		$size = static function ( $value ) {
			return is_numeric( $value ) && (int) $value > 0 ? max( 50, min( 1000, (int) $value ) ) : 0;
		};
		return array(
			'popup_min' => $size( $raw['popup_min'] ?? 0 ),
			'popup_max' => $size( $raw['popup_max'] ?? 0 ),
		);
	}

	/**
	 * Build the config passed to the front-end script.
	 *
	 * @param array $data Sanitised data.
	 * @return array
	 */
	public static function to_frontend( array $data ): array {
		$settings = $data['settings'];
		$tiles    = Assets::resolve_tiles( $settings['tiles'] );

		$popup = static function ( $html ) {
			return '' === trim( $html ) ? '' : wpautop( do_shortcode( $html ) );
		};

		foreach ( $data['markers'] as &$marker ) {
			$marker['popup'] = $popup( $marker['popup'] );
			unset( $marker['icon']['attachment_id'] );
		}
		unset( $marker );
		foreach ( array( 'circles', 'polygons' ) as $type ) {
			foreach ( $data[ $type ] as &$shape ) {
				$shape['popup'] = $popup( $shape['popup'] );
			}
			unset( $shape );
		}

		$style = Appearance::resolve( $settings['style'] );
		unset( $settings['tiles'], $settings['height'], $settings['style'] );

		return array(
			'settings' => $settings,
			'popup'    => array(
				'minWidth' => (int) $style['popup_min'],
				'maxWidth' => (int) $style['popup_max'],
			),
			'tiles'    => $tiles,
			'markers'  => $data['markers'],
			'circles'  => $data['circles'],
			'polygons' => $data['polygons'],
		);
	}

	/**
	 * Validate a CSS length for map height.
	 *
	 * @param string $value Raw value.
	 * @return string Empty string when invalid.
	 */
	public static function sanitize_css_length( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( is_numeric( $value ) ) {
			$value .= 'px';
		}
		return preg_match( '/^\d{1,4}(\.\d+)?(px|vh|rem|em|%)$/', $value ) ? $value : '';
	}

	/**
	 * Validate a [lat, lng] pair.
	 *
	 * @param mixed $value Raw pair.
	 * @return array|null
	 */
	public static function sanitize_latlng( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$value = array_values( $value );
		if ( ! isset( $value[0], $value[1] ) || ! is_numeric( $value[0] ) || ! is_numeric( $value[1] ) ) {
			return null;
		}
		$lat = (float) $value[0];
		$lng = (float) $value[1];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}
		return array( round( $lat, 7 ), round( $lng, 7 ) );
	}

	/**
	 * Generate an item ID.
	 *
	 * @return string
	 */
	public static function new_id(): string {
		return 'i' . substr( md5( uniqid( '', true ) ), 0, 10 );
	}

	/**
	 * Item IDs are short keys.
	 *
	 * @param mixed $id Raw ID.
	 * @return string
	 */
	private static function sanitize_id( $id ): string {
		return substr( sanitize_key( (string) $id ), 0, 32 );
	}

	/**
	 * Interpret checkbox-ish values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $min     Minimum.
	 * @param int   $max     Maximum.
	 * @param int   $default Used when the value is not numeric.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $value ) || (int) $value < $min ) {
			return $default;
		}
		return min( $max, (int) $value );
	}

	/**
	 * Clamp a float.
	 *
	 * @param mixed $value   Raw value.
	 * @param float $min     Minimum.
	 * @param float $max     Maximum.
	 * @param float $default Used when the value is not numeric.
	 * @return float
	 */
	private static function clamp_float( $value, float $min, float $max, float $default ): float {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return round( max( $min, min( $max, (float) $value ) ), 2 );
	}
}
