<?php
/**
 * Visual options for bubbles and legends: site defaults (Settings) with
 * optional per-map overrides. Output as CSS custom properties.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions, sanitising, resolving and rendering.
 */
class Appearance {

	/**
	 * Field definitions. Empty string always means "inherit" at map level.
	 *
	 * @return array<string, array>
	 */
	public static function fields(): array {
		return array(
			'theme_font'       => array(
				'type'    => 'toggle',
				'label'   => __( 'Use the theme\'s font', 'admaps' ),
				'default' => '1',
				'group'   => 'general',
			),
			'popup_min'        => array(
				'type'    => 'px',
				'label'   => __( 'Minimum width', 'admaps' ),
				'default' => 160,
				'min'     => 50,
				'max'     => 800,
				'group'   => 'popup',
			),
			'popup_max'        => array(
				'type'    => 'px',
				'label'   => __( 'Maximum width', 'admaps' ),
				'default' => 320,
				'min'     => 80,
				'max'     => 1000,
				'group'   => 'popup',
			),
			'popup_font_size'  => array(
				'type'    => 'px',
				'label'   => __( 'Text size', 'admaps' ),
				'default' => 14,
				'min'     => 10,
				'max'     => 32,
				'group'   => 'popup',
			),
			'popup_padding'    => array(
				'type'    => 'px',
				'label'   => __( 'Padding', 'admaps' ),
				'default' => 14,
				'min'     => 0,
				'max'     => 48,
				'group'   => 'popup',
			),
			'popup_radius'     => array(
				'type'    => 'px',
				'label'   => __( 'Corner radius', 'admaps' ),
				'default' => 12,
				'min'     => 0,
				'max'     => 40,
				'group'   => 'popup',
			),
			'popup_bg'         => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'admaps' ),
				'default' => '#ffffff',
				'group'   => 'popup',
			),
			'popup_text'       => array(
				'type'    => 'color',
				'label'   => __( 'Text colour', 'admaps' ),
				'default' => '#333333',
				'group'   => 'popup',
			),
			'popup_link'       => array(
				'type'    => 'color',
				'label'   => __( 'Link colour', 'admaps' ),
				'default' => '',
				'group'   => 'popup',
			),
			'legend_font_size' => array(
				'type'    => 'px',
				'label'   => __( 'Text size', 'admaps' ),
				'default' => 13,
				'min'     => 10,
				'max'     => 24,
				'group'   => 'legend',
			),
			'legend_bg'        => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'admaps' ),
				'default' => '#ffffff',
				'group'   => 'legend',
			),
			'legend_text'      => array(
				'type'    => 'color',
				'label'   => __( 'Text colour', 'admaps' ),
				'default' => '#222222',
				'group'   => 'legend',
			),
		);
	}

	/**
	 * Group headings.
	 *
	 * @return array<string, string>
	 */
	public static function groups(): array {
		return array(
			'general' => __( 'General', 'admaps' ),
			'popup'   => __( 'Info bubbles', 'admaps' ),
			'legend'  => __( 'Legend', 'admaps' ),
		);
	}

	/**
	 * Site defaults as stored in Settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			$out[ $key ] = $field['default'];
		}
		return $out;
	}

	/**
	 * Sanitise a style array.
	 *
	 * @param mixed $raw         Raw values.
	 * @param bool  $allow_empty True for map overrides, where '' means inherit.
	 * @return array
	 */
	public static function sanitize( $raw, bool $allow_empty ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();

		foreach ( self::fields() as $key => $field ) {
			$value = isset( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';

			if ( '' === $value && ( $allow_empty || 'color' === $field['type'] ) ) {
				$out[ $key ] = '';
				continue;
			}

			switch ( $field['type'] ) {
				case 'color':
					$color       = sanitize_hex_color( $value );
					$out[ $key ] = $color ? $color : ( $allow_empty ? '' : $field['default'] );
					break;
				case 'px':
					$out[ $key ] = is_numeric( $value )
						? max( $field['min'], min( $field['max'], (int) round( (float) $value ) ) )
						: ( $allow_empty ? '' : $field['default'] );
					break;
				case 'toggle':
					$out[ $key ] = in_array( $value, array( '1', 'on', 'true', 'yes' ), true ) ? '1' : '0';
					break;
			}
		}

		return $out;
	}

	/**
	 * Effective style for a map: site defaults with non-empty map overrides.
	 *
	 * @param array $map_style Map-level overrides.
	 * @return array
	 */
	public static function resolve( array $map_style ): array {
		$style = Settings::get()['appearance'];
		foreach ( $map_style as $key => $value ) {
			if ( '' !== $value && array_key_exists( $key, $style ) ) {
				$style[ $key ] = $value;
			}
		}
		if ( (int) $style['popup_max'] < (int) $style['popup_min'] ) {
			$style['popup_max'] = $style['popup_min'];
		}
		return $style;
	}

	/**
	 * CSS custom properties for the map container.
	 *
	 * @param array $style Resolved style.
	 * @return string
	 */
	public static function css_vars( array $style ): string {
		$map = array(
			'popup_font_size'  => '--admaps-popup-size',
			'popup_padding'    => '--admaps-popup-padding',
			'popup_radius'     => '--admaps-popup-radius',
			'popup_bg'         => '--admaps-popup-bg',
			'popup_text'       => '--admaps-popup-text',
			'popup_link'       => '--admaps-popup-link',
			'legend_font_size' => '--admaps-legend-size',
			'legend_bg'        => '--admaps-legend-bg',
			'legend_text'      => '--admaps-legend-text',
		);
		$fields = self::fields();
		$css    = '';
		foreach ( $map as $key => $var ) {
			if ( ! isset( $style[ $key ] ) || '' === $style[ $key ] ) {
				continue;
			}
			$css .= $var . ':' . $style[ $key ] . ( 'px' === $fields[ $key ]['type'] ? 'px' : '' ) . ';';
		}
		return $css;
	}

	/**
	 * Render the fields as table rows.
	 *
	 * @param string $name     Input name prefix, e.g. admaps_settings[appearance].
	 * @param array  $values   Current values.
	 * @param bool   $override True for the per-map box (blank = site default).
	 */
	public static function render_fields( string $name, array $values, bool $override ): void {
		$defaults = Settings::get()['appearance'];
		$fields   = self::fields();

		foreach ( self::groups() as $group => $heading ) {
			echo '<tr class="admaps-appearance__group"><th colspan="2"><h3>' . esc_html( $heading ) . '</h3></th></tr>';

			foreach ( $fields as $key => $field ) {
				if ( $field['group'] !== $group ) {
					continue;
				}
				$id    = 'admaps-style-' . ( $override ? 'map-' : '' ) . $key;
				$input = $name . '[' . $key . ']';
				$value = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
				$site  = (string) $defaults[ $key ];

				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

				if ( 'toggle' === $field['type'] ) {
					if ( $override ) {
						$options = array(
							''  => sprintf( /* translators: %s: Yes/No */ __( 'Site default (%s)', 'admaps' ), '1' === $site ? __( 'Yes', 'admaps' ) : __( 'No', 'admaps' ) ),
							'1' => __( 'Yes', 'admaps' ),
							'0' => __( 'No', 'admaps' ),
						);
						echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $input ) . '">';
						foreach ( $options as $opt => $label ) {
							echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $value, $opt, false ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select>';
					} else {
						echo '<input type="hidden" name="' . esc_attr( $input ) . '" value="0">';
						echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input ) . '" value="1" ' . checked( $value, '1', false ) . '> ' . esc_html__( 'Bubbles and legends use the site\'s font instead of Leaflet\'s', 'admaps' ) . '</label>';
					}
				} elseif ( 'color' === $field['type'] ) {
					$placeholder = '' !== $site ? $site : __( 'Map default', 'admaps' );
					echo '<input type="text" class="admaps-color-field" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input ) . '" value="' . esc_attr( $value ) . '"' . ( $override ? ' data-placeholder="' . esc_attr( $placeholder ) . '"' : '' ) . '>';
					if ( $override ) {
						echo '<p class="description">' . esc_html(
							'' !== $site
								/* translators: %s: colour value */
								? sprintf( __( 'Blank uses the site default (%s).', 'admaps' ), $site )
								: __( 'Blank uses the site default.', 'admaps' )
						) . '</p>';
					} elseif ( 'popup_link' === $key ) {
						echo '<p class="description">' . esc_html__( 'Leave blank to keep Leaflet\'s default link colour.', 'admaps' ) . '</p>';
					}
				} else {
					echo '<input type="number" step="any" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $input ) . '" value="' . esc_attr( $value ) . '"' . ( $override ? ' placeholder="' . esc_attr( $site ) . '"' : '' ) . '> px';
				}

				echo '</td></tr>';
			}
		}
	}
}
