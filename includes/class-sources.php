<?php
/**
 * Dynamic locations: pins (and optional radii) built from an ACF repeater or a post type.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * A map's source is stored in its data as `source`. It is read every time the
 * map is shown, so editing the repeater or the posts keeps the map up to date.
 */
class Sources {

	const TYPES        = array( '', 'repeater', 'posts' );
	const LOCATIONS    = array( 'options', 'post', 'current' );
	const RADIUS_MODES = array( 'none', 'fixed', 'field' );
	const UNITS        = array(
		'm'  => 1,
		'km' => 1000,
		'mi' => 1609.344,
	);
	const FIELD_KEYS   = array( 'name', 'lat', 'lng', 'map', 'phone', 'address', 'postcode', 'url', 'radius' );
	const MAX_ROWS     = 1000;

	/**
	 * Default source settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$site  = Settings::get();
		$items = $site['item_defaults'];
		return array(
			'type'           => '',
			'location'       => 'options',
			'post_id'        => 0,
			'field'          => '',
			'post_type'      => '',
			'fields'         => array_fill_keys( self::FIELD_KEYS, '' ),
			'geocode'        => true,
			'country'        => 'gb',
			'template'       => "<strong>{name}</strong>\n{address}\n{postcode}\n{phone}",
			'icon'           => $items['icon'],
			'directions'     => (bool) $items['directions'],
			'legend'         => false,
			'legend_label'   => '',
			'radius_mode'    => 'none',
			'radius'         => (float) $site['default_radius'],
			'unit'           => $site['default_unit'],
			'fill'           => $items['fill'],
			'fill_opacity'   => (float) $items['fill_opacity'],
			'stroke'         => $items['stroke'],
			'stroke_opacity' => (float) $items['stroke_opacity'],
			'weight'         => (int) $items['weight'],
		);
	}

	/**
	 * Post types a map can read locations from.
	 *
	 * @return array<string, string> Name => label.
	 */
	public static function post_types(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', Post_Type::NAME ), true ) ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->name;
		}
		return $out;
	}

	/**
	 * Is ACF (or Secure Custom Fields) active?
	 *
	 * @return bool
	 */
	public static function has_acf(): bool {
		return function_exists( 'get_field' );
	}

	/**
	 * Sanitise source settings.
	 *
	 * @param mixed $raw Raw settings.
	 * @return array
	 */
	public static function sanitize( $raw ): array {
		$out = self::defaults();
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		$pick = static function ( $value, array $allowed, $fallback ) {
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};

		$out['type']        = $pick( $raw['type'] ?? '', self::TYPES, '' );
		$out['location']    = $pick( $raw['location'] ?? '', self::LOCATIONS, 'options' );
		$out['post_id']     = absint( $raw['post_id'] ?? 0 );
		$out['field']       = self::field_name( $raw['field'] ?? '' );
		$out['post_type']   = array_key_exists( (string) ( $raw['post_type'] ?? '' ), self::post_types() ) ? (string) $raw['post_type'] : '';
		$out['radius_mode'] = $pick( $raw['radius_mode'] ?? '', self::RADIUS_MODES, 'none' );
		$out['unit']        = $pick( $raw['unit'] ?? '', array_keys( self::UNITS ), $out['unit'] );

		$fields = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array();
		foreach ( self::FIELD_KEYS as $key ) {
			$out['fields'][ $key ] = self::field_name( $fields[ $key ] ?? '' );
		}

		if ( isset( $raw['template'] ) ) {
			$out['template'] = wp_kses_post( substr( (string) $raw['template'], 0, 5000 ) );
		}

		if ( isset( $raw['icon'] ) && is_array( $raw['icon'] ) ) {
			$out['icon'] = Map_Data::sanitize_icon( $raw['icon'], $out['icon']['color'] );
		} elseif ( isset( $raw['pin_color'] ) ) {
			// Saved before pins could be images.
			$out['icon'] = Map_Data::sanitize_icon( array( 'color' => $raw['pin_color'] ), $out['icon']['color'] );
		}

		foreach ( array( 'fill', 'stroke' ) as $key ) {
			$color = sanitize_hex_color( (string) ( $raw[ $key ] ?? '' ) );
			if ( $color ) {
				$out[ $key ] = $color;
			}
		}

		foreach ( array( 'directions', 'legend' ) as $key ) {
			$out[ $key ] = ! empty( $raw[ $key ] ) && 'false' !== $raw[ $key ];
		}
		$out['legend_label'] = sanitize_text_field( (string) ( $raw['legend_label'] ?? '' ) );

		// Missing means on, so maps saved before lookups existed get them.
		if ( array_key_exists( 'geocode', $raw ) ) {
			$out['geocode'] = ! empty( $raw['geocode'] ) && 'false' !== $raw['geocode'];
		}
		if ( isset( $raw['country'] ) ) {
			$country        = strtolower( trim( (string) $raw['country'] ) );
			$out['country'] = preg_match( '/^[a-z]{2}$/', $country ) ? $country : '';
		}

		if ( isset( $raw['radius'] ) && is_numeric( $raw['radius'] ) && (float) $raw['radius'] > 0 ) {
			$out['radius'] = round( min( (float) $raw['radius'], 5000 ), 2 );
		}
		foreach ( array( 'fill_opacity', 'stroke_opacity' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$out[ $key ] = round( max( 0, min( 1, (float) $raw[ $key ] ) ), 2 );
			}
		}
		if ( isset( $raw['weight'] ) && is_numeric( $raw['weight'] ) ) {
			$out['weight'] = max( 0, min( 20, (int) $raw['weight'] ) );
		}

		return $out;
	}

	/**
	 * ACF field names and meta keys: letters, numbers, _ and -.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	private static function field_name( $name ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name ), 0, 64 );
	}

	/**
	 * Build the source's pins and radii.
	 *
	 * @param array $source  Sanitised source settings.
	 * @param int   $context ID of the post showing the map (for "the page showing the map").
	 * @param int   $budget  Lookups allowed now (see Geocoder); by default only saved results are used.
	 * @return array {markers, circles, total, skipped, missing, failed, pending, error}
	 */
	public static function items( array $source, int $context = 0, int $budget = Geocoder::CACHED ): array {
		$out = array(
			'markers' => array(),
			'circles' => array(),
			'total'   => 0,
			'skipped' => 0,
			'missing' => array(),
			'failed'  => array(),
			'pending' => 0,
			'error'   => '',
		);
		if ( '' === $source['type'] ) {
			return $out;
		}

		$rows = 'posts' === $source['type'] ? self::post_rows( $source, $out['error'] ) : self::repeater_rows( $source, $context, $out['error'] );
		if ( '' !== $out['error'] ) {
			return $out;
		}

		$fields   = $source['fields'];
		$template = wpautop( do_shortcode( $source['template'] ) );
		$icon     = $source['icon'];
		unset( $icon['attachment_id'] );
		$factor   = self::UNITS[ $source['unit'] ];

		$found    = array();
		$requests = array();
		foreach ( $rows as $index => $get ) {
			$pos = null;
			if ( '' !== $fields['map'] ) {
				$map = $get( $fields['map'] );
				if ( is_array( $map ) ) {
					$pos = Map_Data::sanitize_latlng( array( $map['lat'] ?? null, $map['lng'] ?? null ) );
				}
			}
			if ( ! $pos && '' !== $fields['lat'] && '' !== $fields['lng'] ) {
				$pos = Map_Data::sanitize_latlng( array( self::scalar( $get( $fields['lat'] ) ), self::scalar( $get( $fields['lng'] ) ) ) );
			}
			if ( ! $pos && $source['geocode'] ) {
				$request = Geocoder::request(
					'' !== $fields['postcode'] ? self::text( $get( $fields['postcode'] ) ) : '',
					'' !== $fields['address'] ? self::text( $get( $fields['address'] ) ) : '',
					$source['country']
				);
				if ( $request ) {
					$requests[ $index ] = $request;
				}
			}
			$found[ $index ] = $pos;
		}

		if ( $requests ) {
			Geocoder::lookup( $requests, $budget );
		}

		foreach ( $rows as $index => $get ) {
			++$out['total'];

			$pos = $found[ $index ];
			if ( ! $pos && isset( $requests[ $index ] ) ) {
				$pos = Geocoder::find( $requests[ $index ] );
				if ( false === $pos ) {
					$out['failed'][] = self::label( $fields, $get, $index );
				} elseif ( null === $pos ) {
					++$out['pending'];
				}
			} elseif ( ! $pos ) {
				++$out['skipped'];
				$out['missing'][] = self::label( $fields, $get, $index );
			}
			if ( ! $pos ) {
				continue;
			}

			$name  = sanitize_text_field( self::name( $fields, $get ) );
			$popup = self::render( $template, $fields, $get );
			$id    = 'src' . $index;

			$out['markers'][] = array(
				'id'           => $id,
				'lat'          => $pos[0],
				'lng'          => $pos[1],
				'label'        => $name,
				'popup'        => $popup,
				'legend'       => $source['legend'],
				'legend_label' => $source['legend_label'],
				'directions'   => $source['directions'],
				'dynamic'      => true,
				'popup_min'    => 0,
				'popup_max'    => 0,
				'icon'         => $icon,
			);

			$radius = 'none' === $source['radius_mode'] ? 0 : $source['radius'];
			if ( 'field' === $source['radius_mode'] && '' !== $fields['radius'] ) {
				$value = self::scalar( $get( $fields['radius'] ) );
				if ( is_numeric( $value ) && (float) $value > 0 ) {
					$radius = (float) $value;
				}
			}
			if ( $radius > 0 ) {
				$out['circles'][] = array(
					'id'             => $id . 'r',
					'lat'            => $pos[0],
					'lng'            => $pos[1],
					'radius'         => round( min( $radius * $factor, 5000000 ), 1 ),
					'unit'           => $source['unit'],
					'label'          => $name,
					'popup'          => $popup,
					'legend'         => false,
					'dynamic'        => true,
					'popup_min'      => 0,
					'popup_max'      => 0,
					'fill'           => $source['fill'],
					'fill_opacity'   => $source['fill_opacity'],
					'stroke'         => $source['stroke'],
					'stroke_opacity' => $source['stroke_opacity'],
					'weight'         => $source['weight'],
				);
			}
		}

		return $out;
	}

	/**
	 * Rows of an ACF repeater, as value getters.
	 *
	 * @param array  $source  Source settings.
	 * @param int    $context Post showing the map.
	 * @param string $error   Set when the repeater can't be read.
	 * @return callable[]
	 */
	private static function repeater_rows( array $source, int $context, string &$error ): array {
		if ( ! self::has_acf() ) {
			$error = __( 'Advanced Custom Fields (or Secure Custom Fields) needs to be active to read a repeater.', 'admaps' );
			return array();
		}
		if ( '' === $source['field'] ) {
			$error = __( 'Enter the repeater\'s field name.', 'admaps' );
			return array();
		}

		if ( 'options' === $source['location'] ) {
			$where = 'option';
		} elseif ( 'current' === $source['location'] ) {
			if ( ! $context ) {
				return array();
			}
			$where = $context;
		} else {
			$where = $source['post_id'];
			if ( ! $where || 'publish' !== get_post_status( $where ) ) {
				$error = __( 'Choose a published page or post that has the repeater.', 'admaps' );
				return array();
			}
		}

		$value = get_field( $source['field'], $where );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( array_slice( $value, 0, self::MAX_ROWS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = static function ( string $name ) use ( $row ) {
				return $row[ $name ] ?? null;
			};
		}
		return $rows;
	}

	/**
	 * Published posts of a type, as value getters.
	 *
	 * @param array  $source Source settings.
	 * @param string $error  Set when no post type is chosen.
	 * @return callable[]
	 */
	private static function post_rows( array $source, string &$error ): array {
		if ( '' === $source['post_type'] ) {
			$error = __( 'Choose the post type that holds your locations.', 'admaps' );
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => $source['post_type'],
				'post_status'      => 'publish',
				'posts_per_page'   => self::MAX_ROWS,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		// Load every post and its fields in two queries, rather than two per post.
		_prime_post_caches( $ids, false, true );

		$acf  = self::has_acf();
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = static function ( string $name ) use ( $id, $acf ) {
				if ( '__title' === $name ) {
					return get_the_title( $id );
				}
				if ( '__url' === $name ) {
					return get_permalink( $id );
				}
				if ( $acf ) {
					$value = get_field( $name, $id );
					if ( null !== $value && false !== $value ) {
						return $value;
					}
				}
				// Hidden meta (keys starting with _) is never shown on a map.
				return is_protected_meta( $name, 'post' ) ? null : get_post_meta( $id, $name, true );
			};
		}
		return $rows;
	}

	/**
	 * Fill a bubble template for one location. Every value is escaped.
	 *
	 * @param string   $template Template, already through wpautop.
	 * @param array    $fields   Field mapping.
	 * @param callable $get      Value getter for the row.
	 * @return string
	 */
	private static function render( string $template, array $fields, callable $get ): string {
		$html = preg_replace_callback(
			'/\{([A-Za-z0-9_\-]+)\}/',
			static function ( $match ) use ( $fields, $get ) {
				$key = $match[1];
				switch ( $key ) {
					case 'name':
						return esc_html( self::name( $fields, $get ) );
					case 'phone':
						$phone = trim( self::text( '' !== $fields['phone'] ? $get( $fields['phone'] ) : '' ) );
						$tel   = preg_replace( '/[^0-9+]/', '', $phone );
						return '' === $tel ? esc_html( $phone ) : '<a href="' . esc_attr( 'tel:' . $tel ) . '">' . esc_html( $phone ) . '</a>';
					case 'zip':
						$key = 'postcode';
						// Falls through: {zip} is the same as {postcode}.
					case 'address':
					case 'postcode':
						$value = '' !== $fields[ $key ] ? $get( $fields[ $key ] ) : '';
						if ( 'address' === $key && is_array( $value ) && isset( $value['address'] ) ) {
							$value = $value['address'];
						}
						return nl2br( esc_html( trim( self::text( $value ) ) ), false );
					case 'url':
						$url = '' !== $fields['url'] ? self::text( $get( $fields['url'] ) ) : (string) $get( '__url' );
						return esc_url( $url );
					default:
						return esc_html( self::text( $get( $key ) ) );
				}
			},
			$template
		);

		// Drop the gaps left by empty values.
		$html = preg_replace( '#(<br\s*/?>\s*){2,}#i', "<br>\n", $html );
		$html = preg_replace( '#<p>\s*(<br\s*/?>\s*)+#i', '<p>', $html );
		$html = preg_replace( '#(\s*<br\s*/?>)+\s*</p>#i', '</p>', $html );
		$html = preg_replace( '#<p>\s*</p>\s*#i', '', $html );
		return trim( $html );
	}

	/**
	 * A location's name: its name field, else the post title.
	 *
	 * @param array    $fields Field mapping.
	 * @param callable $get    Value getter for the row.
	 * @return string
	 */
	private static function label( array $fields, callable $get, int $index ): string {
		$name = sanitize_text_field( self::name( $fields, $get ) );
		/* translators: %d: row number in the repeater */
		return '' !== $name ? $name : sprintf( __( 'Row %d', 'admaps' ), $index + 1 );
	}

	/**
	 * A location's name: the name field, else the post title.
	 *
	 * @param array    $fields Field names.
	 * @param callable $get    Field getter.
	 * @return string
	 */
	private static function name( array $fields, callable $get ): string {
		$name = '' !== $fields['name'] ? trim( self::text( $get( $fields['name'] ) ) ) : '';
		return '' !== $name ? $name : self::text( $get( '__title' ) );
	}

	/**
	 * A field value as text (ACF link, Google Map and post object values included).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( array( 'url', 'address', 'title', 'label' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					return (string) $value[ $key ];
				}
			}
		}
		if ( $value instanceof \WP_Post ) {
			return get_the_title( $value );
		}
		return '';
	}

	/**
	 * A numeric-ish scalar, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function scalar( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : null;
	}
}
