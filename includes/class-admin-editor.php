<?php
/**
 * Map edit screen.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Meta boxes, editor assets and saving.
 */
class Admin_Editor {

	const NONCE = 'admaps_save_map';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Post_Type::NAME, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'save_post_' . Post_Type::NAME, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Title placeholder.
	 *
	 * @param string   $text Placeholder.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function title_placeholder( string $text, \WP_Post $post ): string {
		return Post_Type::NAME === $post->post_type ? __( 'Map name (e.g. Client – Coverage area)', 'admaps' ) : $text;
	}

	/**
	 * Register meta boxes.
	 */
	public static function meta_boxes(): void {
		add_meta_box( 'admaps-editor', __( 'Map', 'admaps' ), array( __CLASS__, 'render_editor' ), Post_Type::NAME, 'normal', 'high' );
		add_meta_box( 'admaps-shortcode', __( 'Shortcode', 'admaps' ), array( __CLASS__, 'render_shortcode' ), Post_Type::NAME, 'side', 'high' );
		add_meta_box( 'admaps-settings', __( 'Map settings', 'admaps' ), array( __CLASS__, 'render_settings' ), Post_Type::NAME, 'side', 'default' );
		add_meta_box( 'admaps-appearance', __( 'Appearance', 'admaps' ), array( __CLASS__, 'render_appearance' ), Post_Type::NAME, 'normal', 'default' );
		add_filter( 'postbox_classes_' . Post_Type::NAME . '_admaps-appearance', array( __CLASS__, 'appearance_box_classes' ) );
	}

	/**
	 * Start the Appearance box collapsed until the user has arranged their boxes.
	 *
	 * @param array $classes Postbox classes.
	 * @return array
	 */
	public static function appearance_box_classes( array $classes ): array {
		if ( false === get_user_option( 'closedpostboxes_' . Post_Type::NAME ) ) {
			$classes[] = 'closed';
		}
		return $classes;
	}

	/**
	 * Is this the map edit screen?
	 *
	 * @return bool
	 */
	private static function is_editor_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'post' === $screen->base && Post_Type::NAME === $screen->post_type;
	}

	/**
	 * Enqueue editor assets.
	 */
	public static function assets(): void {
		if ( ! self::is_editor_screen() ) {
			return;
		}

		$post = get_post();
		$data  = $post ? Map_Data::get( $post->ID ) : Map_Data::empty_data();
		$site  = Settings::get();
		$style = Appearance::resolve( $data['settings']['style'] );

		wp_enqueue_media();
		wp_enqueue_editor();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'admaps-geoman' );
		wp_enqueue_style( 'admaps-frontend' );

		wp_enqueue_script(
			'admaps-import',
			ADMAPS_URL . 'assets/js/import.js',
			array( 'admaps-leaflet' ),
			ADMAPS_VERSION,
			true
		);
		wp_enqueue_script(
			'admaps-editor',
			ADMAPS_URL . 'assets/js/admin-editor.js',
			array( 'jquery', 'wp-color-picker', 'admaps-frontend', 'admaps-geoman', 'admaps-import', 'editor' ),
			ADMAPS_VERSION,
			true
		);

		$providers = array();
		foreach ( Assets::tile_providers() as $slug => $provider ) {
			$providers[ $slug ] = Assets::resolve_tiles( $slug );
		}

		wp_localize_script(
			'admaps-editor',
			'admapsEditor',
			array(
				'data'         => $data,
				'tiles'        => $providers,
				'defaultTiles' => $site['default_tiles'],
				'defaultTint'  => $site['default_tint'],
				'itemDefaults' => array_merge(
					$site['item_defaults'],
					array(
						'radius' => $site['default_radius'],
						'unit'   => $site['default_unit'],
					)
				),
				'appearance'   => array(
					'site'   => $site['appearance'],
					'vars'   => Appearance::css_var_names(),
					'px'     => array_keys( wp_list_filter( Appearance::fields(), array( 'type' => 'px' ) ) ),
				),
				'labels'       => array( 'directions' => $site['directions_text'] ),
				'popupSize'    => array(
					'min' => (int) $style['popup_min'],
					'max' => (int) $style['popup_max'],
				),
				'geocodeUrl'   => 'https://nominatim.openstreetmap.org/search',
				'locale'       => get_bloginfo( 'language' ),
				'i18n'         => array(
					'marker'          => __( 'Pin', 'admaps' ),
					'circle'          => __( 'Radius', 'admaps' ),
					'polygon'         => __( 'Area', 'admaps' ),
					'allItems'        => __( 'All items (%d)', 'admaps' ),
					'bubblePreview'   => __( 'Preview on map', 'admaps' ),
					'bubbleEmpty'     => __( 'This bubble is empty, so it won\'t open on the live map.', 'admaps' ),
					'labelPlaceholder' => __( 'Shown on hover and in the legend', 'admaps' ),
					'noItems'         => __( 'Nothing on this map yet. Use the buttons above to add a pin, radius or area.', 'admaps' ),
					'deleteConfirm'   => __( 'Delete this item?', 'admaps' ),
					'chooseImage'     => __( 'Choose pin image', 'admaps' ),
					'useImage'        => __( 'Use this image', 'admaps' ),
					'searching'       => __( 'Searching…', 'admaps' ),
					'noResults'       => __( 'No results found.', 'admaps' ),
					'searchError'     => __( 'Search failed. Try again in a moment.', 'admaps' ),
					'addPinHere'      => __( 'Add pin', 'admaps' ),
					'viewSaved'       => __( 'Start view saved. Turn off "Fit to contents" to use it.', 'admaps' ),
					'drawHint'        => __( 'Click on the map to place it. Press Esc to cancel.', 'admaps' ),
					'polygonHint'     => __( 'Click to add corners; click the first corner to finish. Press Esc to cancel.', 'admaps' ),
					'importDone'      => __( 'Imported %1$d pins, %2$d radii and %3$d areas.', 'admaps' ),
					'importSkipped'   => __( '%d unsupported features (lines, etc.) were skipped.', 'admaps' ),
					'importFailed'    => __( 'That file could not be read. Use a .kml, .geojson or .json file.', 'admaps' ),
					'importKmz'       => __( 'KMZ files are zipped KML. Unzip it and import the doc.kml inside.', 'admaps' ),
					'externalImage'   => __( 'This image is hosted on another site. Choose an image from the media library to keep it working long term.', 'admaps' ),
					'editShape'       => __( 'Edit shape', 'admaps' ),
					'finishEditing'   => __( 'Finish editing', 'admaps' ),
					'points'          => __( '%d corners', 'admaps' ),
				),
			)
		);
	}

	/**
	 * Main editor meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_editor( \WP_Post $post ): void {
		$style = Appearance::resolve( Map_Data::get( $post->ID )['settings']['style'] );
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="admaps-editor" id="admaps-editor">
			<div class="admaps-toolbar">
				<div class="admaps-toolbar__group">
					<button type="button" class="button" data-admaps-draw="marker"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php esc_html_e( 'Add pin', 'admaps' ); ?></button>
					<button type="button" class="button" data-admaps-draw="circle"><span class="dashicons dashicons-marker" aria-hidden="true"></span> <?php esc_html_e( 'Add radius', 'admaps' ); ?></button>
					<button type="button" class="button" data-admaps-draw="polygon"><svg class="admaps-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 7.5 10.5 3 17 7 15 16 5 15.5Z" fill="currentColor" fill-opacity=".2" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><g fill="currentColor"><circle cx="4" cy="7.5" r="1.8"/><circle cx="10.5" cy="3" r="1.8"/><circle cx="17" cy="7" r="1.8"/><circle cx="15" cy="16" r="1.8"/><circle cx="5" cy="15.5" r="1.8"/></g></svg> <?php esc_html_e( 'Draw area', 'admaps' ); ?></button>
				</div>
				<div class="admaps-toolbar__group admaps-search">
					<label class="screen-reader-text" for="admaps-search-input"><?php esc_html_e( 'Search for an address, postcode or ZIP code', 'admaps' ); ?></label>
					<input type="search" id="admaps-search-input" form="admaps-detached" placeholder="<?php esc_attr_e( 'Search address, postcode or ZIP…', 'admaps' ); ?>">
					<button type="button" class="button" id="admaps-search-button"><?php esc_html_e( 'Search', 'admaps' ); ?></button>
					<ul class="admaps-search__results" id="admaps-search-results" hidden></ul>
				</div>
				<div class="admaps-toolbar__group">
					<button type="button" class="button" id="admaps-save-view" title="<?php esc_attr_e( 'Use the current position and zoom as the map\'s starting view', 'admaps' ); ?>"><?php esc_html_e( 'Set start view', 'admaps' ); ?></button>
					<label class="button" for="admaps-import-file"><?php esc_html_e( 'Import KML / GeoJSON', 'admaps' ); ?></label>
					<input type="file" id="admaps-import-file" form="admaps-detached" accept=".kml,.geojson,.json,.kmz" hidden>
				</div>
			</div>
			<div class="admaps-notice" id="admaps-notice" role="status" aria-live="polite" hidden></div>
			<div class="admaps-editor__body">
				<div class="admaps-editor__map admaps-map<?php echo '1' === (string) $style['theme_font'] ? ' admaps-map--theme-font' : ''; ?>" id="admaps-editor-map" style="<?php echo esc_attr( Appearance::css_vars( $style ) ); ?>"></div>
				<div class="admaps-panel">
					<ul class="admaps-items" id="admaps-items"></ul>
					<div class="admaps-item-form" id="admaps-item-form"></div>
				</div>
			</div>
			<input type="hidden" name="admaps_data" id="admaps-data" value="">
			<noscript><p><?php esc_html_e( 'The map editor needs JavaScript.', 'admaps' ); ?></p></noscript>
		</div>
		<?php
	}

	/**
	 * Shortcode meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_shortcode( \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the map to get its shortcode.', 'admaps' ) . '</p>';
			return;
		}
		Post_Type::shortcode_field( $post->ID );
		echo '<p class="description">' . esc_html__( 'Optional attributes: height="300px", zoom="12", class="my-class".', 'admaps' ) . '</p>';
	}

	/**
	 * Settings meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_settings( \WP_Post $post ): void {
		$s         = Map_Data::get( $post->ID )['settings'];
		$providers = Assets::tile_providers();
		$default   = Settings::get()['default_tiles'];
		$default   = isset( $providers[ $default ] ) ? $providers[ $default ]['label'] : '';

		$checkbox = static function ( string $key, string $label ) use ( $s ) {
			printf(
				'<p><input type="hidden" name="admaps_settings[%1$s]" value="0"><label><input type="checkbox" name="admaps_settings[%1$s]" value="1" data-admaps-setting="%1$s" %2$s> %3$s</label></p>',
				esc_attr( $key ),
				checked( $s[ $key ], true, false ),
				esc_html( $label )
			);
		};
		?>
		<p>
			<label for="admaps-height"><strong><?php esc_html_e( 'Height', 'admaps' ); ?></strong></label><br>
			<input type="text" id="admaps-height" name="admaps_settings[height]" value="<?php echo esc_attr( $s['height'] ); ?>" class="widefat" placeholder="450px">
			<span class="description"><?php esc_html_e( 'px, vh, rem, em or %', 'admaps' ); ?></span>
		</p>
		<p>
			<label for="admaps-tiles"><strong><?php esc_html_e( 'Map style', 'admaps' ); ?></strong></label><br>
			<select id="admaps-tiles" name="admaps_settings[tiles]" class="widefat" data-admaps-setting="tiles">
				<option value="" <?php selected( $s['tiles'], '' ); ?>>
					<?php
					/* translators: %s: default tile provider name */
					echo esc_html( sprintf( __( 'Site default (%s)', 'admaps' ), $default ) );
					?>
				</option>
				<?php foreach ( $providers as $slug => $provider ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['tiles'], $slug ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="admaps-tint"><strong><?php esc_html_e( 'Map tint', 'admaps' ); ?></strong></label><br>
			<select id="admaps-tint" name="admaps_settings[tint]" class="widefat" data-admaps-setting="tint">
				<option value="" <?php selected( $s['tint'], '' ); ?>>
					<?php
					/* translators: %s: default tint name */
					echo esc_html( sprintf( __( 'Site default (%s)', 'admaps' ), Map_Data::tint_labels()[ Settings::get()['default_tint'] ] ) );
					?>
				</option>
				<?php foreach ( Map_Data::tint_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['tint'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
		$checkbox( 'fit_bounds', __( 'Fit to contents (ignore start view)', 'admaps' ) );
		$checkbox( 'zoom_control', __( 'Show zoom buttons', 'admaps' ) );
		$checkbox( 'scroll_zoom', __( 'Zoom with scroll wheel', 'admaps' ) );
		$checkbox( 'cluster', __( 'Group nearby pins (clustering)', 'admaps' ) );
		$checkbox( 'legend', __( 'Show legend', 'admaps' ) );
		?>
		<div class="admaps-legend-settings">
			<p>
				<label for="admaps-legend-title"><?php esc_html_e( 'Legend title', 'admaps' ); ?></label><br>
				<input type="text" id="admaps-legend-title" name="admaps_settings[legend_title]" value="<?php echo esc_attr( $s['legend_title'] ); ?>" class="widefat" data-admaps-setting="legend_title">
			</p>
			<p>
				<label for="admaps-legend-position"><?php esc_html_e( 'Legend position', 'admaps' ); ?></label><br>
				<select id="admaps-legend-position" name="admaps_settings[legend_position]" class="widefat" data-admaps-setting="legend_position">
					<?php
					$positions = array(
						'topright'    => __( 'Top right', 'admaps' ),
						'topleft'     => __( 'Top left', 'admaps' ),
						'bottomright' => __( 'Bottom right', 'admaps' ),
						'bottomleft'  => __( 'Bottom left', 'admaps' ),
					);
					foreach ( $positions as $value => $label ) {
						printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $s['legend_position'], $value, false ), esc_html( $label ) );
					}
					?>
				</select>
			</p>
			<p class="description"><?php esc_html_e( 'Items with a label and "Show in legend" ticked are listed.', 'admaps' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Per-map appearance overrides.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_appearance( \WP_Post $post ): void {
		$style     = Map_Data::get( $post->ID )['settings']['style'];
		$overrides = count( array_filter( $style, 'strlen' ) );
		$link      = admin_url( 'edit.php?post_type=' . Post_Type::NAME . '&page=admaps-settings#appearance' );
		?>
		<p class="admaps-tab-intro">
			<?php
			printf(
				/* translators: %s: link to the settings page */
				esc_html__( 'Override the site-wide %s for this map only. Leave a field blank to use the site default.', 'admaps' ),
				'<a href="' . esc_url( $link ) . '">' . esc_html__( 'appearance settings', 'admaps' ) . '</a>'
			);
			if ( $overrides ) {
				echo ' <strong>' . esc_html( sprintf( /* translators: %d: number of overridden settings */ _n( '%d setting overridden.', '%d settings overridden.', $overrides, 'admaps' ), $overrides ) ) . '</strong>';
			}
			?>
		</p>
		<?php Appearance::render_sections( 'admaps_settings[style]', $style, true, 'h3' ); ?>
		<?php
	}

	/**
	 * Save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$existing = Map_Data::get( $post_id );

		// An empty field means the editor script never ran; keep the stored items.
		$json = isset( $_POST['admaps_data'] ) ? trim( (string) wp_unslash( $_POST['admaps_data'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Map_Data.
		$data = '' !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			$data = $existing;
		}

		$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : $existing['settings'];
		if ( isset( $_POST['admaps_settings'] ) && is_array( $_POST['admaps_settings'] ) ) {
			$settings = array_merge( $settings, wp_unslash( $_POST['admaps_settings'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Map_Data.
		}
		$data['settings'] = $settings;

		Map_Data::save( $post_id, $data );
	}
}
