<?php
/**
 * Theme styling reference: the classes, custom properties and events themes can use.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Theme styling tab on the settings page.
 */
class Style_Reference {

	/**
	 * Classes, grouped by the part of the map they belong to.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function classes(): array {
		return array(
			__( 'Map', 'admaps' )                => array(
				'.admaps-map'                                        => __( 'The outer element of every map. Appearance settings are set on it as custom properties.', 'admaps' ),
				'.admaps-map--theme-font'                            => __( 'Added when "Use the theme\'s font" is on.', 'admaps' ),
				'.admaps-map--tint-grey .admaps-map--tint-dark'      => __( 'Added when the map has a Greyscale or Dark tint.', 'admaps' ),
				'#admaps-{map ID}-{n}'                               => __( 'Unique ID of each map on the page; n counts maps on the page from 1.', 'admaps' ),
				'.leaflet-tile-pane'                                 => __( 'The map background (tiles). Tints apply a CSS filter here.', 'admaps' ),
				'.leaflet-control-zoom'                              => __( 'The + / − zoom buttons.', 'admaps' ),
				'.leaflet-control-attribution'                       => __( 'The credits in the corner. Restyle it if you like, but don\'t hide it: OpenStreetMap\'s licence requires it.', 'admaps' ),
				'.admaps-error'                                      => __( 'Message shown, to editors only, when a shortcode points at a missing or unpublished map.', 'admaps' ),
			),
			__( 'Pins', 'admaps' )               => array(
				'.admaps-pin'                => __( 'A coloured pin. The pin is an SVG inside it.', 'admaps' ),
				'.admaps-icon-image'         => __( 'A pin that uses a custom image.', 'admaps' ),
				'.admaps-pin.admaps-dynamic .admaps-icon-image.admaps-dynamic' => __( 'A pin from Dynamic locations (an ACF repeater or posts) rather than drawn on the map.', 'admaps' ),
				'.marker-cluster .marker-cluster-small .marker-cluster-medium .marker-cluster-large' => __( 'Grouped pins, when clustering is on. Small, medium and large reflect how many pins are grouped.', 'admaps' ),
			),
			__( 'Radii and areas', 'admaps' )    => array(
				'.leaflet-overlay-pane path.leaflet-interactive' => __( 'Radius circles and areas are SVG paths. Their colours are set per item, but CSS fill, stroke and opacity rules override them.', 'admaps' ),
				'path.admaps-dynamic'                            => __( 'A coverage radius from Dynamic locations.', 'admaps' ),
			),
			__( 'Info bubbles and labels', 'admaps' ) => array(
				'.leaflet-popup'                 => __( 'The whole bubble, including its pointer.', 'admaps' ),
				'.leaflet-popup-content-wrapper' => __( 'The bubble\'s box (background, corners, shadow).', 'admaps' ),
				'.leaflet-popup-content'         => __( 'The content inside the bubble (padding, text).', 'admaps' ),
				'.leaflet-popup-tip'             => __( 'The pointer under the bubble.', 'admaps' ),
				'.leaflet-popup-close-button'    => __( 'The × button.', 'admaps' ),
				'.admaps-directions'             => __( 'Paragraph holding the "Get directions" link, at the end of a pin\'s bubble.', 'admaps' ),
				'.leaflet-tooltip'               => __( 'The label shown when hovering over an item.', 'admaps' ),
			),
			__( 'Legend', 'admaps' )             => array(
				'.admaps-legend'                       => __( 'The legend box. ul > li holds one row per item.', 'admaps' ),
				'.admaps-legend__title'                => __( 'The legend title.', 'admaps' ),
				'.admaps-legend__icon'                 => __( 'A pin icon in a legend row.', 'admaps' ),
				'.admaps-legend__swatch'               => __( 'A colour square for an area in a legend row.', 'admaps' ),
				'.admaps-legend__swatch--circle'       => __( 'The same, round, for a radius.', 'admaps' ),
			),
			__( 'Phones and tablets', 'admaps' ) => array(
				'.admaps-touch-hint'      => __( 'The "Use two fingers to move the map" overlay.', 'admaps' ),
				'.admaps-map.is-touch-hint' => __( 'Added to the map while that overlay is showing.', 'admaps' ),
			),
		);
	}

	/**
	 * Render the tab. Must be outside the settings form.
	 */
	public static function render_panel(): void {
		$fields = Appearance::fields();
		$groups = Appearance::groups();
		?>
		<section class="admaps-tab-panel" data-admaps-panel="styling">
			<p class="admaps-tab-intro"><?php esc_html_e( 'Everything a theme can use to style maps. The plugin\'s stylesheet loads only on pages with a map and its selectors are short, so a slightly more specific theme rule (for example one starting with body) wins whichever stylesheet loads last.', 'admaps' ); ?></p>

			<h2 class="admaps-section-title"><?php esc_html_e( 'Custom properties', 'admaps' ); ?></h2>
			<p class="admaps-section-intro"><?php esc_html_e( 'The easiest way to restyle bubbles and legends. Set them on .admaps-map in your theme. Anything set in the Appearance tab or a map\'s Appearance box takes priority; values left at the built-in default don\'t, so your theme\'s values apply.', 'admaps' ); ?></p>
			<table class="widefat striped admaps-ref">
				<thead><tr><th><?php esc_html_e( 'Property', 'admaps' ); ?></th><th><?php esc_html_e( 'Controls', 'admaps' ); ?></th><th><?php esc_html_e( 'Built-in default', 'admaps' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( Appearance::css_var_names() as $key => $var ) : ?>
						<?php
						$field   = $fields[ $key ];
						$default = (string) $field['default'];
						if ( '' !== $default && 'px' === $field['type'] ) {
							$default .= 'px';
						}
						?>
						<tr>
							<td><code><?php echo esc_html( $var ); ?></code></td>
							<td><?php echo esc_html( $groups[ $field['group'] ] . ': ' . $field['label'] ); ?></td>
							<td><?php echo '' !== $default ? '<code>' . esc_html( $default ) . '</code>' : esc_html__( 'Leaflet\'s blue', 'admaps' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 class="admaps-section-title"><?php esc_html_e( 'Classes', 'admaps' ); ?></h2>
			<p class="admaps-section-intro"><?php esc_html_e( 'Classes starting leaflet- come from Leaflet, the mapping library. Scope them under .admaps-map so they only affect these maps.', 'admaps' ); ?></p>
			<?php foreach ( self::classes() as $heading => $rows ) : ?>
				<h3><?php echo esc_html( $heading ); ?></h3>
				<table class="widefat striped admaps-ref">
					<tbody>
						<?php foreach ( $rows as $selectors => $description ) : ?>
							<tr>
								<td>
									<?php foreach ( explode( ' ', $selectors ) as $selector ) : ?>
										<code><?php echo esc_html( $selector ); ?></code>
									<?php endforeach; ?>
								</td>
								<td><?php echo esc_html( $description ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<h2 class="admaps-section-title"><?php esc_html_e( 'Dynamic locations', 'admaps' ); ?></h2>
			<p class="admaps-section-intro"><?php esc_html_e( 'Pins and radii from a map\'s Dynamic locations box use the same markup as drawn ones, plus the admaps-dynamic class, so they can be styled apart. For example, to make dynamic radii dashed and lift dynamic pins on hover:', 'admaps' ); ?></p>
			<pre class="admaps-code"><code>body .admaps-map path.admaps-dynamic {
  stroke-dasharray: 6 4;
}

body .admaps-map .leaflet-marker-icon.admaps-dynamic:hover {
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, .35));
}</code></pre>

			<h2 class="admaps-section-title"><?php esc_html_e( 'Adding your own class', 'admaps' ); ?></h2>
			<p class="admaps-section-intro"><?php esc_html_e( 'Add classes to one map\'s outer element with the shortcode, then target them in your CSS. Use plain class names: WordPress strips characters such as [ ] : and /, so Tailwind utility classes with arbitrary values or variants won\'t survive.', 'admaps' ); ?></p>
			<pre class="admaps-code"><code>[admaps id="6" class="map-contact"]</code></pre>

			<h2 class="admaps-section-title"><?php esc_html_e( 'Tailwind', 'admaps' ); ?></h2>
			<p class="admaps-section-intro"><?php esc_html_e( 'The map\'s inner markup is built in the browser, so Tailwind classes can\'t be added to it. Style it from your theme\'s CSS instead, using your theme values and @apply. Keep these rules outside @layer: Leaflet\'s and the plugin\'s CSS aren\'t in a cascade layer, so anything inside @layer components loses to them. Tailwind v4 example, in your main CSS file:', 'admaps' ); ?></p>
			<pre class="admaps-code"><code>.admaps-map {
  --admaps-popup-bg: var(--color-white);
  --admaps-popup-text: var(--color-gray-900);
  --admaps-popup-link: var(--color-brand-600);
  --admaps-popup-radius: var(--radius-lg);
}

body .admaps-map .leaflet-popup-content-wrapper {
  @apply shadow-xl ring-1 ring-black/5;
}

body .admaps-map .leaflet-popup-content h3 {
  @apply mb-1 text-lg font-semibold;
}

body .admaps-map .admaps-directions a {
  @apply font-semibold underline underline-offset-2;
}</code></pre>
			<p class="admaps-section-intro"><?php esc_html_e( 'In Tailwind v3, use theme(\'colors.brand.600\') in place of the var(--color-…) values. Tailwind\'s base styles set img { max-width: 100% }, which would squash map tiles; the plugin already resets this inside .admaps-map.', 'admaps' ); ?></p>

			<h2 class="admaps-section-title"><?php esc_html_e( 'JavaScript', 'admaps' ); ?></h2>
			<p class="admaps-section-intro"><?php esc_html_e( 'Each map fires an admaps:ready event once it has loaded. It bubbles, and event.detail.map is the Leaflet map, for adding your own layers or behaviour.', 'admaps' ); ?></p>
			<pre class="admaps-code"><code>document.addEventListener( 'admaps:ready', function ( event ) {
  var map = event.detail.map;
  map.on( 'popupopen', function () {
    // e.g. send an analytics event
  } );
} );</code></pre>
		</section>
		<?php
	}
}
