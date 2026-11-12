<?php
/**
 * Plugin-wide settings: Maps > Settings.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page.
 */
class Settings {

	const OPTION = 'admaps_settings';

	/**
	 * Cached settings for the request.
	 *
	 * @var array|null
	 */
	private static ?array $cache = null;

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'default_tiles'       => 'osm',
			'default_tint'        => 'none',
			'default_height'      => '450px',
			'default_unit'        => 'mi',
			'default_radius'      => 5,
			'directions_text'     => __( 'Get directions', 'admaps' ),
			'custom_label'        => '',
			'custom_url'          => '',
			'custom_attribution'  => '',
			'custom_subdomains'   => '',
			'custom_max_zoom'     => 19,
			'delete_on_uninstall' => false,
			'two_finger_touch'    => true,
			'prerelease_updates'  => false,
			'appearance'          => Appearance::defaults(),
			'item_defaults'       => self::item_defaults(),
		);
	}

	/**
	 * Default styles for newly added pins and shapes.
	 *
	 * @return array
	 */
	public static function item_defaults(): array {
		return array(
			'pin_color'      => '#e63946',
			'icon'           => array(
				'type'          => 'svg',
				'color'         => '#e63946',
				'attachment_id' => 0,
				'url'           => '',
				'width'         => 28,
				'height'        => 42,
				'anchor'        => 'bottom',
			),
			'stroke'         => '#1d3557',
			'stroke_opacity' => 1,
			'weight'         => 2,
			'fill'           => '#457b9d',
			'fill_opacity'   => 0.25,
			'directions'     => false,
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored   = get_option( self::OPTION, array() );
			$stored   = is_array( $stored ) ? $stored : array();
			$defaults = self::defaults();
			$settings = array_merge( $defaults, $stored );
			foreach ( array( 'appearance', 'item_defaults' ) as $group ) {
				$settings[ $group ] = array_merge( $defaults[ $group ], isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ? $stored[ $group ] : array() );
			}
			// Saved before pins could be images: keep the chosen colour.
			if ( empty( $stored['item_defaults']['icon'] ) ) {
				$settings['item_defaults']['icon']['color'] = $settings['item_defaults']['pin_color'];
			}
			self::$cache = $settings;
		}
		return self::$cache;
	}

	/**
	 * Clear the request cache after the option changes.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Add the submenu page.
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::NAME,
			__( 'Map Settings', 'admaps' ),
			__( 'Settings', 'admaps' ),
			'manage_options',
			'admaps-settings',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register(): void {
		register_setting(
			'admaps_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Settings page assets.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( string $hook ): void {
		if ( Post_Type::NAME . '_page_admaps-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'admaps-admin', ADMAPS_URL . 'assets/css/admin.css', array(), ADMAPS_VERSION );
		wp_enqueue_script( 'admaps-settings', ADMAPS_URL . 'assets/js/admin-settings.js', array( 'jquery', 'wp-color-picker' ), ADMAPS_VERSION, true );
		Pin_Field::enqueue();
	}

	/**
	 * Sanitise the option.
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();

		$out['custom_label']        = sanitize_text_field( (string) ( $raw['custom_label'] ?? '' ) );
		$out['custom_url']          = self::sanitize_tile_url( (string) ( $raw['custom_url'] ?? '' ) );
		$out['custom_attribution']  = wp_kses(
			(string) ( $raw['custom_attribution'] ?? '' ),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		$out['custom_subdomains']   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $raw['custom_subdomains'] ?? '' ) ) );
		$out['custom_max_zoom']     = max( 1, min( 22, (int) ( $raw['custom_max_zoom'] ?? 19 ) ) );
		$out['delete_on_uninstall'] = ! empty( $raw['delete_on_uninstall'] );
		$out['two_finger_touch']    = ! empty( $raw['two_finger_touch'] );
		$out['prerelease_updates']  = ! empty( $raw['prerelease_updates'] );

		$tiles = sanitize_key( (string) ( $raw['default_tiles'] ?? 'osm' ) );
		if ( 'custom' === $tiles && '' === $out['custom_url'] ) {
			$tiles = 'osm';
		}
		$out['default_tiles'] = $tiles;
		$out['default_tint']  = in_array( $raw['default_tint'] ?? '', Map_Data::TINTS, true ) ? $raw['default_tint'] : 'none';

		$height                = Map_Data::sanitize_css_length( (string) ( $raw['default_height'] ?? '' ) );
		$out['default_height'] = '' !== $height ? $height : '450px';
		$out['default_unit']   = in_array( $raw['default_unit'] ?? '', array( 'mi', 'km', 'm' ), true ) ? $raw['default_unit'] : 'mi';
		$radius                = (float) ( $raw['default_radius'] ?? 5 );
		$out['default_radius'] = $radius > 0 ? round( min( $radius, 5000 ), 2 ) : 5;

		$directions             = sanitize_text_field( (string) ( $raw['directions_text'] ?? '' ) );
		$out['directions_text'] = '' !== $directions ? $directions : __( 'Get directions', 'admaps' );

		$out['appearance'] = Appearance::sanitize( $raw['appearance'] ?? array(), false );

		$items = isset( $raw['item_defaults'] ) && is_array( $raw['item_defaults'] ) ? $raw['item_defaults'] : array();
		$base  = self::item_defaults();
		foreach ( array( 'pin_color', 'stroke', 'fill' ) as $key ) {
			$color                         = sanitize_hex_color( (string) ( $items[ $key ] ?? '' ) );
			$out['item_defaults'][ $key ] = $color ? $color : $base[ $key ];
		}
		foreach ( array( 'stroke_opacity', 'fill_opacity' ) as $key ) {
			$value                         = $items[ $key ] ?? $base[ $key ];
			$out['item_defaults'][ $key ] = is_numeric( $value ) ? round( max( 0, min( 1, (float) $value ) ), 2 ) : $base[ $key ];
		}
		$weight                          = $items['weight'] ?? $base['weight'];
		$out['item_defaults']['weight'] = is_numeric( $weight ) ? max( 0, min( 20, (int) $weight ) ) : $base['weight'];

		$out['item_defaults']['directions'] = ! empty( $items['directions'] );

		$icon = isset( $items['icon'] ) && is_array( $items['icon'] ) ? $items['icon'] : array( 'color' => $out['item_defaults']['pin_color'] );
		$out['item_defaults']['icon']      = Map_Data::sanitize_icon( $icon, $base['pin_color'] );
		$out['item_defaults']['pin_color'] = $out['item_defaults']['icon']['color'];

		return $out;
	}

	/**
	 * Tile URL templates contain {z}/{x}/{y} placeholders, which esc_url_raw would mangle.
	 *
	 * @param string $url Raw URL template.
	 * @return string
	 */
	private static function sanitize_tile_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! preg_match( '#^https://[^\s"\'<>]+$#i', $url ) ) {
			return '';
		}
		foreach ( array( '{z}', '{x}', '{y}' ) as $placeholder ) {
			if ( false === strpos( $url, $placeholder ) ) {
				return '';
			}
		}
		return $url;
	}

	/**
	 * A section heading within a tab.
	 *
	 * @param string $title Heading.
	 * @param string $intro Optional short description.
	 */
	private static function section( string $title, string $intro = '' ): void {
		echo '<h2 class="admaps-section-title">' . esc_html( $title ) . '</h2>';
		if ( '' !== $intro ) {
			echo '<p class="admaps-section-intro">' . esc_html( $intro ) . '</p>';
		}
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = self::get();
		$name      = self::OPTION;
		$providers = Assets::tile_providers();
		$items     = $s['item_defaults'];
		$tabs      = array(
			'general'       => __( 'General', 'admaps' ),
			'appearance'    => __( 'Appearance', 'admaps' ),
			'items'         => __( 'New item defaults', 'admaps' ),
			'tiles'         => __( 'Custom tiles', 'admaps' ),
			'advanced'      => __( 'Advanced', 'admaps' ),
			'import-export' => __( 'Import / Export', 'admaps' ),
			'styling'       => __( 'Theme styling', 'admaps' ),
		);
		?>
		<div class="wrap admaps-settings">
			<h1><?php esc_html_e( 'Map Settings', 'admaps' ); ?></h1>
			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'admaps' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="#<?php echo esc_attr( $slug ); ?>" class="nav-tab" data-admaps-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( 'admaps_settings' ); ?>

				<section class="admaps-tab-panel" data-admaps-panel="general">
					<?php self::section( __( 'Maps', 'admaps' ), __( 'Defaults for every map. Each map can change these in its Map settings box.', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="admaps-default-tiles"><?php esc_html_e( 'Map style', 'admaps' ); ?></label></th>
							<td>
								<select id="admaps-default-tiles" name="<?php echo esc_attr( $name ); ?>[default_tiles]">
									<?php foreach ( $providers as $slug => $provider ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['default_tiles'], $slug ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used by maps set to "Site default". OpenStreetMap\'s tile servers are for light use only; for busy sites or a different look, add a keyed provider under Custom tiles.', 'admaps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-default-tint"><?php esc_html_e( 'Map tint', 'admaps' ); ?></label></th>
							<td>
								<select id="admaps-default-tint" name="<?php echo esc_attr( $name ); ?>[default_tint]">
									<?php foreach ( Map_Data::tint_labels() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['default_tint'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Recolours the map itself (not pins, areas or bubbles). Greyscale makes coloured items stand out; Dark suits dark websites.', 'admaps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-default-height"><?php esc_html_e( 'Default map height', 'admaps' ); ?></label></th>
							<td>
								<input type="text" class="small-text" id="admaps-default-height" name="<?php echo esc_attr( $name ); ?>[default_height]" value="<?php echo esc_attr( $s['default_height'] ); ?>">
								<p class="description"><?php esc_html_e( 'Used by new maps. px, vh, rem, em or %.', 'admaps' ); ?></p>
							</td>
						</tr>
					</table>

					<?php self::section( __( 'Directions link', 'admaps' ), __( 'Pins can show a link that opens Google Maps directions to the pin in a new tab (or the Google Maps app on phones).', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="admaps-directions-text"><?php esc_html_e( 'Link text', 'admaps' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="admaps-directions-text" name="<?php echo esc_attr( $name ); ?>[directions_text]" value="<?php echo esc_attr( $s['directions_text'] ); ?>">
							</td>
						</tr>
					</table>
				</section>

				<section class="admaps-tab-panel" data-admaps-panel="appearance">
					<p class="admaps-tab-intro"><?php esc_html_e( 'The site-wide look of info bubbles and legends. Each map can override these in its Appearance box. To style maps from your theme instead, see Theme styling.', 'admaps' ); ?></p>
					<?php Appearance::render_sections( $name . '[appearance]', $s['appearance'], false ); ?>
				</section>

				<section class="admaps-tab-panel" data-admaps-panel="items">
					<p class="admaps-tab-intro"><?php esc_html_e( 'Starting styles for newly added pins, radii and areas, and for dynamic locations on new maps. After the first item, new items copy the style of the previous one of the same type.', 'admaps' ); ?></p>
					<?php self::section( __( 'Pins', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<?php Pin_Field::render( $name . '[item_defaults][icon]', 'admaps-item-pin', $items['icon'] ); ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Directions link', 'admaps' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[item_defaults][directions]" value="1" <?php checked( $items['directions'] ); ?>> <?php esc_html_e( 'Add a directions link to new pins', 'admaps' ); ?></label></td>
						</tr>
					</table>

					<?php self::section( __( 'Radii and areas', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="admaps-default-radius"><?php esc_html_e( 'Radius size', 'admaps' ); ?></label></th>
							<td>
								<input type="number" step="any" class="small-text" id="admaps-default-radius" name="<?php echo esc_attr( $name ); ?>[default_radius]" value="<?php echo esc_attr( (string) $s['default_radius'] ); ?>">
								<select name="<?php echo esc_attr( $name ); ?>[default_unit]" aria-label="<?php esc_attr_e( 'Radius unit', 'admaps' ); ?>">
									<option value="mi" <?php selected( $s['default_unit'], 'mi' ); ?>><?php esc_html_e( 'miles', 'admaps' ); ?></option>
									<option value="km" <?php selected( $s['default_unit'], 'km' ); ?>><?php esc_html_e( 'km', 'admaps' ); ?></option>
									<option value="m" <?php selected( $s['default_unit'], 'm' ); ?>><?php esc_html_e( 'metres', 'admaps' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-item-fill"><?php esc_html_e( 'Fill colour', 'admaps' ); ?></label></th>
							<td><input type="text" class="admaps-color-field" id="admaps-item-fill" name="<?php echo esc_attr( $name ); ?>[item_defaults][fill]" value="<?php echo esc_attr( $items['fill'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-item-fill-opacity"><?php esc_html_e( 'Fill opacity', 'admaps' ); ?></label></th>
							<td><input type="number" step="any" class="small-text" id="admaps-item-fill-opacity" name="<?php echo esc_attr( $name ); ?>[item_defaults][fill_opacity]" value="<?php echo esc_attr( (string) $items['fill_opacity'] ); ?>"> <span class="description"><?php esc_html_e( '0 to 1', 'admaps' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-item-stroke"><?php esc_html_e( 'Line colour', 'admaps' ); ?></label></th>
							<td><input type="text" class="admaps-color-field" id="admaps-item-stroke" name="<?php echo esc_attr( $name ); ?>[item_defaults][stroke]" value="<?php echo esc_attr( $items['stroke'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-item-stroke-opacity"><?php esc_html_e( 'Line opacity', 'admaps' ); ?></label></th>
							<td><input type="number" step="any" class="small-text" id="admaps-item-stroke-opacity" name="<?php echo esc_attr( $name ); ?>[item_defaults][stroke_opacity]" value="<?php echo esc_attr( (string) $items['stroke_opacity'] ); ?>"> <span class="description"><?php esc_html_e( '0 to 1', 'admaps' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-item-weight"><?php esc_html_e( 'Line width', 'admaps' ); ?></label></th>
							<td><input type="number" step="any" class="small-text" id="admaps-item-weight" name="<?php echo esc_attr( $name ); ?>[item_defaults][weight]" value="<?php echo esc_attr( (string) $items['weight'] ); ?>"> px</td>
						</tr>
					</table>
				</section>

				<section class="admaps-tab-panel" data-admaps-panel="tiles">
					<?php self::section( __( 'Custom tile provider', 'admaps' ), __( 'Use this for a keyed provider such as MapTiler, Stadia Maps or Mapbox. Include the API key in the URL. Once saved it appears as a map style.', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="admaps-custom-label"><?php esc_html_e( 'Name', 'admaps' ); ?></label></th>
							<td><input type="text" class="regular-text" id="admaps-custom-label" name="<?php echo esc_attr( $name ); ?>[custom_label]" value="<?php echo esc_attr( $s['custom_label'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-custom-url"><?php esc_html_e( 'Tile URL template', 'admaps' ); ?></label></th>
							<td>
								<input type="text" class="large-text code" id="admaps-custom-url" name="<?php echo esc_attr( $name ); ?>[custom_url]" value="<?php echo esc_attr( $s['custom_url'] ); ?>" placeholder="https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=YOUR_KEY">
								<p class="description"><?php esc_html_e( 'Must use https and contain {z}, {x} and {y}. {s} and {r} are also supported.', 'admaps' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-custom-attr"><?php esc_html_e( 'Attribution', 'admaps' ); ?></label></th>
							<td><input type="text" class="large-text" id="admaps-custom-attr" name="<?php echo esc_attr( $name ); ?>[custom_attribution]" value="<?php echo esc_attr( $s['custom_attribution'] ); ?>">
							<p class="description"><?php esc_html_e( 'Links are allowed. Most providers require attribution.', 'admaps' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-custom-sub"><?php esc_html_e( 'Subdomains', 'admaps' ); ?></label></th>
							<td><input type="text" class="small-text" id="admaps-custom-sub" name="<?php echo esc_attr( $name ); ?>[custom_subdomains]" value="<?php echo esc_attr( $s['custom_subdomains'] ); ?>" placeholder="abc"></td>
						</tr>
						<tr>
							<th scope="row"><label for="admaps-custom-zoom"><?php esc_html_e( 'Max zoom', 'admaps' ); ?></label></th>
							<td><input type="number" step="any" class="small-text" id="admaps-custom-zoom" name="<?php echo esc_attr( $name ); ?>[custom_max_zoom]" value="<?php echo esc_attr( (string) $s['custom_max_zoom'] ); ?>"></td>
						</tr>
					</table>
				</section>

				<section class="admaps-tab-panel" data-admaps-panel="advanced">
					<?php self::section( __( 'Phones and tablets', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Touch screens', 'admaps' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[two_finger_touch]" value="1" <?php checked( $s['two_finger_touch'] ); ?>> <?php esc_html_e( 'Use two fingers to move the map on phones and tablets', 'admaps' ); ?></label>
								<p class="description"><?php esc_html_e( 'One finger scrolls the page past the map, so visitors don\'t get stuck. Tapping pins and areas still opens their bubbles.', 'admaps' ); ?></p>
							</td>
						</tr>
					</table>

					<?php self::section( __( 'Updates', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Pre-releases', 'admaps' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[prerelease_updates]" value="1" <?php checked( $s['prerelease_updates'] ); ?>> <?php esc_html_e( 'Offer pre-release (test) versions', 'admaps' ); ?></label>
								<p class="description"><?php esc_html_e( 'For test sites only. Client sites should only get stable releases.', 'admaps' ); ?></p>
							</td>
						</tr>
					</table>

					<?php self::section( __( 'Uninstalling', 'admaps' ) ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Remove data', 'admaps' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all maps and settings when the plugin is deleted', 'admaps' ); ?></label></td>
						</tr>
					</table>
				</section>

				<div class="admaps-settings-submit"><?php submit_button(); ?></div>
			</form>

			<?php Import_Export::render_panel(); ?>
			<?php Style_Reference::render_panel(); ?>
		</div>
		<?php
	}
}
