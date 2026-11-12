<?php
/**
 * "Dynamic locations" box on the map edit screen, and its live preview.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Settings form for a map's source, plus an AJAX preview for the editor map.
 */
class Admin_Source {

	const NONCE = 'admaps_source_preview';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'wp_ajax_admaps_source_preview', array( __CLASS__, 'preview' ) );
	}

	/**
	 * Register the box (called from the editor so it sits above Appearance).
	 */
	public static function meta_box(): void {
		add_meta_box( 'admaps-source', __( 'Dynamic locations', 'admaps' ), array( __CLASS__, 'render' ), Post_Type::NAME, 'normal', 'default' );
	}

	/**
	 * Data for the box's script.
	 */
	public static function assets(): void {
		if ( ! Admin_Editor::is_editor_screen() ) {
			return;
		}
		wp_localize_script(
			'admaps-editor',
			'admapsSource',
			array(
				'nonce'  => wp_create_nonce( self::NONCE ),
				'fields' => self::acf_fields(),
				'i18n'   => array(
					/* translators: %d: number of locations */
					'showing'  => __( 'Showing %d locations, as they will appear on the map (faded here).', 'admaps' ),
					/* translators: %d: number of locations */
					'skipped'  => __( '%d skipped because they have no latitude and longitude, postcode or address.', 'admaps' ),
					/* translators: %d: number of locations */
					'noLatLng' => __( '%d skipped because they have no latitude and longitude.', 'admaps' ),
					/* translators: %s: list of location names */
					'notFound' => __( 'Couldn\'t find the postcode or address for: %s. Check them, or give these latitude and longitude.', 'admaps' ),
					/* translators: %d: number of locations */
					'pending'  => __( '%d still being looked up…', 'admaps' ),
					/* translators: %d: number of locations */
					'more'     => __( 'and %d more', 'admaps' ),
					'none'     => __( 'No locations found yet. Check the field names.', 'admaps' ),
					'current'  => __( 'Locations come from the page showing the map, so they can\'t be previewed here.', 'admaps' ),
					'loading'  => __( 'Loading locations…', 'admaps' ),
					'failed'   => __( 'The preview could not be loaded.', 'admaps' ),
					'postType' => __( 'Post title', 'admaps' ),
					'postUrl'  => __( 'The post\'s own page', 'admaps' ),
				),
			)
		);
	}

	/**
	 * ACF repeaters and per-post-type fields, used to suggest field names.
	 *
	 * @return array{repeaters: array, postTypes: array}
	 */
	private static function acf_fields(): array {
		$out = array(
			'repeaters' => array(),
			'postTypes' => array(),
		);
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $out;
		}

		$describe = static function ( array $fields ): array {
			$list = array();
			foreach ( $fields as $field ) {
				if ( ! empty( $field['name'] ) ) {
					$list[] = array(
						'name'  => (string) $field['name'],
						'label' => (string) ( $field['label'] ?? $field['name'] ),
						'type'  => (string) ( $field['type'] ?? '' ),
					);
				}
			}
			return $list;
		};

		foreach ( acf_get_field_groups() as $group ) {
			$fields = acf_get_fields( $group );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field ) {
				if ( 'repeater' === ( $field['type'] ?? '' ) && ! empty( $field['name'] ) ) {
					$out['repeaters'][ $field['name'] ] = array(
						'label' => (string) $field['label'],
						'subs'  => $describe( $field['sub_fields'] ?? array() ),
					);
				}
			}
			foreach ( (array) ( $group['location'] ?? array() ) as $rules ) {
				foreach ( (array) $rules as $rule ) {
					if ( 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
						$type                       = (string) $rule['value'];
						$out['postTypes'][ $type ] = array_merge( $out['postTypes'][ $type ] ?? array(), $describe( $fields ) );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Map post.
	 */
	public static function render( \WP_Post $post ): void {
		$s    = Map_Data::get( $post->ID )['source'];
		$name = 'admaps_source';
		$f    = $s['fields'];

		$text = static function ( string $key, string $label, string $description = '', string $placeholder = '' ) use ( $name, $f ) {
			$id = 'admaps-source-f-' . $key;
			echo '<tr data-show="type=repeater|posts"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="text" class="regular-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . '[fields][' . $key . ']' ) . '" value="' . esc_attr( $f[ $key ] ) . '" list="admaps-source-fieldlist" data-source-field="' . esc_attr( $key ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off">';
			if ( '' !== $description ) {
				echo '<p class="description">' . esc_html( $description ) . '</p>';
			}
			echo '</td></tr>';
		};
		?>
		<p class="admaps-tab-intro"><?php esc_html_e( 'Show pins from an ACF repeater or a post type, as well as anything drawn on the map. The map reads them every time it is shown, so adding or editing a location there updates the map.', 'admaps' ); ?></p>
		<?php if ( ! Sources::has_acf() ) : ?>
			<p class="description"><?php esc_html_e( 'Repeaters need Advanced Custom Fields Pro or Secure Custom Fields. Post types also work with plain custom fields.', 'admaps' ); ?></p>
		<?php endif; ?>

		<h3 class="admaps-section-title"><?php esc_html_e( 'Source', 'admaps' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="admaps-source-type"><?php esc_html_e( 'Locations from', 'admaps' ); ?></label></th>
				<td>
					<select id="admaps-source-type" name="<?php echo esc_attr( $name ); ?>[type]" data-source-key="type">
						<option value="" <?php selected( $s['type'], '' ); ?>><?php esc_html_e( 'Nothing: only items drawn on the map', 'admaps' ); ?></option>
						<option value="repeater" <?php selected( $s['type'], 'repeater' ); ?>><?php esc_html_e( 'An ACF repeater', 'admaps' ); ?></option>
						<option value="posts" <?php selected( $s['type'], 'posts' ); ?>><?php esc_html_e( 'Posts (e.g. a Locations post type)', 'admaps' ); ?></option>
					</select>
				</td>
			</tr>
			<tr data-show="type=repeater">
				<th scope="row"><label for="admaps-source-location"><?php esc_html_e( 'Repeater is on', 'admaps' ); ?></label></th>
				<td>
					<select id="admaps-source-location" name="<?php echo esc_attr( $name ); ?>[location]" data-source-key="location">
						<option value="options" <?php selected( $s['location'], 'options' ); ?>><?php esc_html_e( 'An options page', 'admaps' ); ?></option>
						<option value="post" <?php selected( $s['location'], 'post' ); ?>><?php esc_html_e( 'A particular page or post', 'admaps' ); ?></option>
						<option value="current" <?php selected( $s['location'], 'current' ); ?>><?php esc_html_e( 'The page showing the map', 'admaps' ); ?></option>
					</select>
					<p class="description" data-show="type=repeater location=current">
						<?php
						printf(
							/* translators: %s: shortcode */
							esc_html__( 'Each page reads its own repeater, so one map can be used on many pages. A shortcode can also choose: %s', 'admaps' ),
							'<code>' . esc_html( '[admaps id="' . $post->ID . '" source="current"]' ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr data-show="type=repeater location=post">
				<th scope="row"><label for="admaps-source-post"><?php esc_html_e( 'Page or post ID', 'admaps' ); ?></label></th>
				<td>
					<input type="number" min="1" class="small-text" id="admaps-source-post" name="<?php echo esc_attr( $name ); ?>[post_id]" value="<?php echo $s['post_id'] ? esc_attr( (string) $s['post_id'] ) : ''; ?>">
					<p class="description"><?php esc_html_e( 'Shown in the address bar when editing it (post=123). It must be published.', 'admaps' ); ?></p>
				</td>
			</tr>
			<tr data-show="type=repeater">
				<th scope="row"><label for="admaps-source-field"><?php esc_html_e( 'Repeater field name', 'admaps' ); ?></label></th>
				<td><input type="text" class="regular-text code" id="admaps-source-field" name="<?php echo esc_attr( $name ); ?>[field]" value="<?php echo esc_attr( $s['field'] ); ?>" list="admaps-source-repeaters" autocomplete="off"></td>
			</tr>
			<tr data-show="type=posts">
				<th scope="row"><label for="admaps-source-post-type"><?php esc_html_e( 'Post type', 'admaps' ); ?></label></th>
				<td>
					<select id="admaps-source-post-type" name="<?php echo esc_attr( $name ); ?>[post_type]">
						<option value=""><?php esc_html_e( 'Choose…', 'admaps' ); ?></option>
						<?php foreach ( Sources::post_types() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $s['post_type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Published posts only, up to 1,000.', 'admaps' ); ?></p>
				</td>
			</tr>
		</table>

		<div data-show="type=repeater|posts">
			<h3 class="admaps-section-title"><?php esc_html_e( 'Fields', 'admaps' ); ?></h3>
			<p class="admaps-section-intro"><?php esc_html_e( 'The field name (not the label) holding each piece of information. Suggestions come from your ACF field groups, and obvious matches are filled in for you. Leave out anything you don\'t have.', 'admaps' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$text( 'name', __( 'Name', 'admaps' ), __( 'Shown on hover, and as {name} in the bubble.', 'admaps' ) );
				$text( 'lat', __( 'Latitude', 'admaps' ) );
				$text( 'lng', __( 'Longitude', 'admaps' ) );
				$text( 'map', __( 'Google Map field', 'admaps' ), __( 'Use instead of latitude and longitude if the locations use ACF\'s Google Map field.', 'admaps' ) );
				$text( 'phone', __( 'Phone', 'admaps' ) );
				$text( 'address', __( 'Address', 'admaps' ) );
				$text( 'postcode', __( 'Postcode', 'admaps' ) );
				$text( 'url', __( 'Link', 'admaps' ), __( 'A page to link to, as {url} in the bubble.', 'admaps' ) );
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'No latitude and longitude?', 'admaps' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[geocode]" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[geocode]" value="1" <?php checked( $s['geocode'] ); ?>> <?php esc_html_e( 'Find the position from the postcode or address', 'admaps' ); ?></label>
						<p class="description"><?php esc_html_e( 'UK postcodes are looked up with postcodes.io, anything else with OpenStreetMap. Each one is looked up once and saved, mostly in the background just after locations are saved. Latitude and longitude always win when a location has them.', 'admaps' ); ?></p>
						<p>
							<label for="admaps-source-country"><?php esc_html_e( 'Search in country', 'admaps' ); ?></label>
							<input type="text" class="small-text code" id="admaps-source-country" name="<?php echo esc_attr( $name ); ?>[country]" value="<?php echo esc_attr( $s['country'] ); ?>" maxlength="2" placeholder="gb">
						</p>
						<p class="description"><?php esc_html_e( 'A two-letter country code (gb, ie, fr…) so addresses aren\'t matched abroad. Leave blank to search everywhere.', 'admaps' ); ?></p>
					</td>
				</tr>
			</table>
			<datalist id="admaps-source-fieldlist"></datalist>
			<datalist id="admaps-source-repeaters"></datalist>

			<h3 class="admaps-section-title"><?php esc_html_e( 'Info bubble', 'admaps' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="admaps-source-template"><?php esc_html_e( 'Bubble content', 'admaps' ); ?></label></th>
					<td>
						<textarea class="large-text code" rows="5" id="admaps-source-template" name="<?php echo esc_attr( $name ); ?>[template]"><?php echo esc_textarea( $s['template'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'HTML is allowed. Placeholders:', 'admaps' ); ?>
							<code>{name}</code> <code>{address}</code> <code>{postcode}</code> <code>{phone}</code> <code>{url}</code>.
							<?php esc_html_e( 'The phone number becomes a tap-to-call link. Any other field can be used by its name, e.g. {email}. Lines left empty by a location are removed.', 'admaps' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Directions link', 'admaps' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[directions]" value="1" <?php checked( $s['directions'] ); ?>> <?php esc_html_e( 'Add a "Get directions" link (Google Maps) to each bubble', 'admaps' ); ?></label></td>
				</tr>
			</table>

			<h3 class="admaps-section-title"><?php esc_html_e( 'Pins', 'admaps' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="admaps-source-pin"><?php esc_html_e( 'Pin colour', 'admaps' ); ?></label></th>
					<td><input type="text" class="admaps-color-field" id="admaps-source-pin" name="<?php echo esc_attr( $name ); ?>[pin_color]" value="<?php echo esc_attr( $s['pin_color'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Legend', 'admaps' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[legend]" value="1" <?php checked( $s['legend'] ); ?>> <?php esc_html_e( 'Show these pins as one row in the legend', 'admaps' ); ?></label>
						<p><label for="admaps-source-legend-label" class="screen-reader-text"><?php esc_html_e( 'Legend text', 'admaps' ); ?></label>
						<input type="text" class="regular-text" id="admaps-source-legend-label" name="<?php echo esc_attr( $name ); ?>[legend_label]" value="<?php echo esc_attr( $s['legend_label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Our depots', 'admaps' ); ?>"></p>
						<p class="description"><?php esc_html_e( 'The legend also needs "Show legend" ticked in Map settings.', 'admaps' ); ?></p>
					</td>
				</tr>
			</table>

			<h3 class="admaps-section-title"><?php esc_html_e( 'Coverage radius', 'admaps' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="admaps-source-radius-mode"><?php esc_html_e( 'Radius', 'admaps' ); ?></label></th>
					<td>
						<select id="admaps-source-radius-mode" name="<?php echo esc_attr( $name ); ?>[radius_mode]" data-source-key="radius">
							<option value="none" <?php selected( $s['radius_mode'], 'none' ); ?>><?php esc_html_e( 'None: just pins', 'admaps' ); ?></option>
							<option value="fixed" <?php selected( $s['radius_mode'], 'fixed' ); ?>><?php esc_html_e( 'The same for every location', 'admaps' ); ?></option>
							<option value="field" <?php selected( $s['radius_mode'], 'field' ); ?>><?php esc_html_e( 'From a field on each location', 'admaps' ); ?></option>
						</select>
					</td>
				</tr>
				<tr data-show="radius=field">
					<th scope="row"><label for="admaps-source-f-radius"><?php esc_html_e( 'Radius field', 'admaps' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="admaps-source-f-radius" name="<?php echo esc_attr( $name ); ?>[fields][radius]" value="<?php echo esc_attr( $f['radius'] ); ?>" list="admaps-source-fieldlist" data-source-field="radius" autocomplete="off">
						<p class="description"><?php esc_html_e( 'A number field, in the unit below.', 'admaps' ); ?></p>
					</td>
				</tr>
				<tr data-show="radius=fixed|field">
					<th scope="row"><label for="admaps-source-radius"><?php esc_html_e( 'Radius size', 'admaps' ); ?></label></th>
					<td>
						<input type="number" step="any" min="0" class="small-text" id="admaps-source-radius" name="<?php echo esc_attr( $name ); ?>[radius]" value="<?php echo esc_attr( (string) $s['radius'] ); ?>">
						<select name="<?php echo esc_attr( $name ); ?>[unit]" aria-label="<?php esc_attr_e( 'Radius unit', 'admaps' ); ?>">
							<option value="mi" <?php selected( $s['unit'], 'mi' ); ?>><?php esc_html_e( 'miles', 'admaps' ); ?></option>
							<option value="km" <?php selected( $s['unit'], 'km' ); ?>><?php esc_html_e( 'km', 'admaps' ); ?></option>
							<option value="m" <?php selected( $s['unit'], 'm' ); ?>><?php esc_html_e( 'metres', 'admaps' ); ?></option>
						</select>
						<p class="description" data-show="radius=field"><?php esc_html_e( 'Used for locations whose radius field is blank.', 'admaps' ); ?></p>
					</td>
				</tr>
				<tr data-show="radius=fixed|field">
					<th scope="row"><label for="admaps-source-fill"><?php esc_html_e( 'Fill', 'admaps' ); ?></label></th>
					<td>
						<input type="text" class="admaps-color-field" id="admaps-source-fill" name="<?php echo esc_attr( $name ); ?>[fill]" value="<?php echo esc_attr( $s['fill'] ); ?>">
						<label class="admaps-inline-field"><?php esc_html_e( 'Opacity', 'admaps' ); ?> <input type="number" step="any" min="0" max="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[fill_opacity]" value="<?php echo esc_attr( (string) $s['fill_opacity'] ); ?>"></label>
					</td>
				</tr>
				<tr data-show="radius=fixed|field">
					<th scope="row"><label for="admaps-source-stroke"><?php esc_html_e( 'Line', 'admaps' ); ?></label></th>
					<td>
						<input type="text" class="admaps-color-field" id="admaps-source-stroke" name="<?php echo esc_attr( $name ); ?>[stroke]" value="<?php echo esc_attr( $s['stroke'] ); ?>">
						<label class="admaps-inline-field"><?php esc_html_e( 'Opacity', 'admaps' ); ?> <input type="number" step="any" min="0" max="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[stroke_opacity]" value="<?php echo esc_attr( (string) $s['stroke_opacity'] ); ?>"></label>
						<label class="admaps-inline-field"><?php esc_html_e( 'Width', 'admaps' ); ?> <input type="number" min="0" max="20" class="small-text" name="<?php echo esc_attr( $name ); ?>[weight]" value="<?php echo esc_attr( (string) $s['weight'] ); ?>"> px</label>
					</td>
				</tr>
			</table>
		</div>

		<p class="admaps-source-status" id="admaps-source-status" role="status" aria-live="polite" data-show="type=repeater|posts"></p>
		<?php
	}

	/**
	 * Preview the locations for the editor map, from the box's unsaved values.
	 */
	public static function preview(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || Post_Type::NAME !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( null, 403 );
		}

		$source = Sources::sanitize( isset( $_POST['admaps_source'] ) && is_array( $_POST['admaps_source'] ) ? wp_unslash( $_POST['admaps_source'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Sources.
		if ( 'repeater' === $source['type'] && 'current' === $source['location'] ) {
			wp_send_json_success( array( 'current' => true ) );
		}
		wp_send_json_success( Sources::items( $source, 0, Geocoder::ADMIN ) );
	}
}
