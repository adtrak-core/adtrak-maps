<?php
/**
 * Pin style controls (coloured pin or image) for settings forms.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the pin style rows used by Settings → New item defaults and the
 * Dynamic locations box. Values post as `{name}[type|color|attachment_id|width|anchor]`
 * and are cleaned by Map_Data::sanitize_icon().
 */
class Pin_Field {

	/**
	 * Load what the controls need (Media Library, colour picker, script).
	 */
	public static function enqueue(): void {
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'admaps-pin-field', ADMAPS_URL . 'assets/js/admin-pin-field.js', array( 'jquery', 'wp-color-picker' ), ADMAPS_VERSION, true );
		wp_localize_script(
			'admaps-pin-field',
			'admapsPinField',
			array(
				'choose' => __( 'Choose pin image', 'admaps' ),
				'use'    => __( 'Use as pin', 'admaps' ),
			)
		);
	}

	/**
	 * Table rows for a pin style.
	 *
	 * @param string $name Input name prefix, e.g. admaps_source[icon].
	 * @param string $id   ID prefix for labels.
	 * @param array  $icon Sanitised icon.
	 */
	public static function render( string $name, string $id, array $icon ): void {
		$icon  = Map_Data::sanitize_icon( $icon );
		$image = 'image' === $icon['type'];
		?>
		<tr class="admaps-pin-field" data-pin-type="<?php echo esc_attr( $icon['type'] ); ?>">
			<th scope="row"><?php esc_html_e( 'Pin style', 'admaps' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Pin style', 'admaps' ); ?></legend>
					<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[type]" value="svg" data-pin-type-choice <?php checked( ! $image ); ?>> <?php esc_html_e( 'Coloured pin', 'admaps' ); ?></label>
					<label class="admaps-inline-field"><input type="radio" name="<?php echo esc_attr( $name ); ?>[type]" value="image" data-pin-type-choice <?php checked( $image ); ?>> <?php esc_html_e( 'Image', 'admaps' ); ?></label>
				</fieldset>
			</td>
		</tr>
		<tr class="admaps-pin-field__svg" data-pin-show="svg">
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-color"><?php esc_html_e( 'Pin colour', 'admaps' ); ?></label></th>
			<td><input type="text" class="admaps-color-field" id="<?php echo esc_attr( $id ); ?>-color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $icon['color'] ); ?>"></td>
		</tr>
		<tr data-pin-show="image">
			<th scope="row"><?php esc_html_e( 'Pin image', 'admaps' ); ?></th>
			<td>
				<div class="admaps-pin-image">
					<span class="admaps-pin-image__preview">
						<?php if ( $image && $icon['url'] ) : ?>
							<img src="<?php echo esc_url( $icon['url'] ); ?>" alt="">
						<?php endif; ?>
					</span>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[attachment_id]" value="<?php echo esc_attr( (string) $icon['attachment_id'] ); ?>" data-pin-attachment>
					<button type="button" class="button" data-pin-choose><?php esc_html_e( 'Choose image', 'admaps' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'From the Media Library. PNG or SVG with a transparent background works best.', 'admaps' ); ?></p>
			</td>
		</tr>
		<tr data-pin-show="image">
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-anchor"><?php esc_html_e( 'Image anchor', 'admaps' ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $id ); ?>-anchor" name="<?php echo esc_attr( $name ); ?>[anchor]">
					<option value="bottom" <?php selected( $icon['anchor'], 'bottom' ); ?>><?php esc_html_e( 'Bottom centre (the image points at the spot)', 'admaps' ); ?></option>
					<option value="center" <?php selected( $icon['anchor'], 'center' ); ?>><?php esc_html_e( 'Centre (e.g. a logo or dot)', 'admaps' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>-width"><?php esc_html_e( 'Pin size', 'admaps' ); ?></label></th>
			<td>
				<input type="number" min="8" max="200" class="small-text" id="<?php echo esc_attr( $id ); ?>-width" name="<?php echo esc_attr( $name ); ?>[width]" value="<?php echo esc_attr( (string) $icon['width'] ); ?>" data-pin-width> <?php esc_html_e( 'px wide', 'admaps' ); ?>
				<p class="description"><?php esc_html_e( 'The height follows the pin\'s or image\'s proportions.', 'admaps' ); ?></p>
			</td>
		</tr>
		<?php
	}
}
