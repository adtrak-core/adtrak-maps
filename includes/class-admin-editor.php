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
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_screen' ) );
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
	 * Only the core Publish box stays a meta box; everything else is rendered inline.
	 */
	public static function meta_boxes(): void {
		remove_meta_box( 'slugdiv', Post_Type::NAME, 'normal' );
	}

	/**
	 * Render the editor screen below the title, outside meta boxes.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_screen( \WP_Post $post ): void {
		if ( Post_Type::NAME !== $post->post_type ) {
			return;
		}
		self::render_shortcode( $post );
		self::render_editor( $post );
		?>
		<div class="admaps-section" id="admaps-settings">
			<h2 class="admaps-section__title"><?php esc_html_e( 'Map settings', 'admaps' ); ?></h2>
			<?php self::render_settings( $post ); ?>
		</div>
		<?php
		self::render_appearance( $post );
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
				'itemDefaults' => array_merge(
					$site['item_defaults'],
					array(
						'radius' => $site['default_radius'],
						'unit'   => $site['default_unit'],
					)
				),
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
	 * Map editor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_editor( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="admaps-editor" id="admaps-editor">
			<div class="admaps-toolbar">
				<div class="admaps-toolbar__group">
					<button type="button" class="button" data-admaps-draw="marker"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php esc_html_e( 'Add pin', 'admaps' ); ?></button>
					<button type="button" class="button" data-admaps-draw="circle"><span class="dashicons dashicons-marker" aria-hidden="true"></span> <?php esc_html_e( 'Add radius', 'admaps' ); ?></button>
					<button type="button" class="button" data-admaps-draw="polygon"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> <?php esc_html_e( 'Draw area', 'admaps' ); ?></button>
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
				<div class="admaps-editor__map" id="admaps-editor-map"></div>
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
	 * Shortcode line under the title.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_shortcode( \WP_Post $post ): void {
		echo '<div class="admaps-shortcode-row" id="admaps-shortcode"><strong>' . esc_html__( 'Shortcode:', 'admaps' ) . '</strong> ';
		if ( 'auto-draft' === $post->post_status ) {
			echo '<span class="description">' . esc_html__( 'Save the map to get its shortcode.', 'admaps' ) . '</span>';
		} else {
			Post_Type::shortcode_field( $post->ID );
			echo ' <span class="description">' . esc_html__( 'Optional attributes: height="300px", zoom="12", class="my-class".', 'admaps' ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Map settings fields.
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
				'<input type="hidden" name="admaps_settings[%1$s]" value="0"><label><input type="checkbox" name="admaps_settings[%1$s]" value="1" data-admaps-setting="%1$s" %2$s> %3$s</label>',
				esc_attr( $key ),
				checked( $s[ $key ], true, false ),
				esc_html( $label )
			);
		};
		?>
		<div class="admaps-fields">
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
		<fieldset class="admaps-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'Map behaviour', 'admaps' ); ?></legend>
			<?php
			$checkbox( 'fit_bounds', __( 'Fit to contents (ignore start view)', 'admaps' ) );
			$checkbox( 'zoom_control', __( 'Show zoom buttons', 'admaps' ) );
			$checkbox( 'scroll_zoom', __( 'Zoom with scroll wheel', 'admaps' ) );
			$checkbox( 'cluster', __( 'Group nearby pins (clustering)', 'admaps' ) );
			$checkbox( 'legend', __( 'Show legend', 'admaps' ) );
			?>
		</fieldset>
		</div>
		<div class="admaps-fields admaps-legend-settings">
			<p>
				<label for="admaps-legend-title"><strong><?php esc_html_e( 'Legend title', 'admaps' ); ?></strong></label><br>
				<input type="text" id="admaps-legend-title" name="admaps_settings[legend_title]" value="<?php echo esc_attr( $s['legend_title'] ); ?>" class="widefat" data-admaps-setting="legend_title">
			</p>
			<p>
				<label for="admaps-legend-position"><strong><?php esc_html_e( 'Legend position', 'admaps' ); ?></strong></label><br>
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
		<details class="admaps-section" id="admaps-appearance" <?php echo $overrides ? 'open' : ''; ?>>
			<summary class="admaps-section__title">
				<?php
				esc_html_e( 'Appearance overrides', 'admaps' );
				if ( $overrides ) {
					echo ' <span class="admaps-count">' . esc_html( sprintf( /* translators: %d: number of overridden settings */ _n( '%d set', '%d set', $overrides, 'admaps' ), $overrides ) ) . '</span>';
				}
				?>
			</summary>
			<p class="admaps-appearance__intro">
				<?php
				printf(
					/* translators: %s: link to the settings page */
					esc_html__( 'Override the site-wide %s for this map only. Leave a field blank to use the site default.', 'admaps' ),
					'<a href="' . esc_url( $link ) . '">' . esc_html__( 'appearance settings', 'admaps' ) . '</a>'
				);
				?>
			</p>
			<table class="form-table admaps-appearance" role="presentation">
				<?php Appearance::render_fields( 'admaps_settings[style]', $style, true ); ?>
			</table>
		</details>
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
