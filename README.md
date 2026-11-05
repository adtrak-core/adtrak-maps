# Adtrak Maps

Lightweight Leaflet maps for client WordPress sites, built to replace Scribble Maps embeds but can be used for any map requirement (excluding Google Maps). Add pins with HTML info bubbles, radius circles and drawn coverage areas, style them, and drop the map into any page with a shortcode.

- Multiple maps, managed under **Maps** in the admin
- Pins with HTML info bubbles: coloured SVG pins or your own image
- Radius circles (miles, km or metres) and polygon coverage areas
- Colours and opacities for everything, plus an optional legend
- Optional clustering of nearby pins
- Site-wide appearance defaults with per-map and per-item overrides
- JSON export/import between sites; KML/GeoJSON import
- Leaflet loads only on pages that contain a map
- No CDN: all libraries are bundled

**Requirements:** WordPress 6.0+, PHP 7.4+ (tested on 7.4 and 8.2).

## Installation

Download a release zip (or zip the `admaps` folder) and upload it via **Plugins → Add New → Upload Plugin**, then activate. To update, upload the new zip and choose *Replace current with uploaded*. Maps and settings are kept.

## Using it

1. **Maps → Add New Map** and give it a name.
2. Add items with the toolbar:
   - **Add pin**: click the map to place it.
   - **Add radius**: click to place the centre, then set the radius in the side panel.
   - **Draw area**: click to add corners; click the first corner to finish. Drag handles to reshape, right-click a corner to remove it.
   - **Search** an address, postcode or ZIP, then use **+ Pin** or **+ Radius** on a result.
3. Select an item in the list to edit its label, colours, info bubble, legend visibility and (under **Bubble size**) bubble width.
4. Set height, map style, zoom behaviour, clustering and legend in the **Map settings** box. Use **Set start view** if *Fit to contents* is off.
5. Publish, then copy the shortcode from the **Shortcode** box (or the Maps list).

### Shortcode

```
[admaps id="123"]
[admaps id="123" height="300px" zoom="12" class="my-map"]
```

| Attribute | Description |
|---|---|
| `id` | Map ID (required). |
| `height` | Overrides the map's height. `px`, `vh`, `rem`, `em` or `%`. |
| `zoom` | Fixed zoom level (0–22); turns off *Fit to contents* for this instance. |
| `class` | Extra CSS classes on the map container. |

Draft maps are shown only to people who can edit them. A missing map renders nothing for visitors and shows an error to editors. Maps are lazy-initialised when scrolled near, and resize correctly inside tabs, accordions and popups.

## Settings

**Maps → Settings** holds the site-wide defaults:

| Tab | Contents |
|---|---|
| General | Default map style (tile layer) and default height for new maps. |
| Appearance | Info bubble width, text size, padding, corner radius and colours; legend text size and colours; whether to use the theme's font. |
| New item defaults | Starting colours, opacities, line width and radius for new items. After the first item, new items copy the previous item of the same type. |
| Custom tiles | A keyed tile provider (MapTiler, Stadia, Mapbox…), which then appears as a map style. |
| Advanced | Delete all maps and settings when the plugin is deleted (off by default). |

Each map has an **Appearance** box (below the editor, collapsed by default) to override any of these for that map. Blank fields use the site default. Each pin and shape can also override its bubble width under **Bubble size**. Bubbles always shrink to fit small maps.

### Theming with CSS

Appearance settings are output as CSS custom properties on `.admaps-map`, so a theme can restyle every map from its own stylesheet:

```css
.admaps-map {
	--admaps-popup-bg: #1d3557;
	--admaps-popup-text: #fff;
	--admaps-popup-link: #f4a261;
	--admaps-popup-size: 15px;
	--admaps-popup-padding: 16px;
	--admaps-popup-radius: 4px;
	--admaps-legend-bg: #fff;
	--admaps-legend-text: #222;
	--admaps-legend-size: 13px;
}
```

Values set in the admin are applied inline, so target `.admaps-map` with higher specificity (or `!important`) if the theme must win. When *Use the theme's font* is on, the container gets `.admaps-map--theme-font`.

## Import and export

- **Between sites:** **Maps → Import / Export** exports selected or all maps as JSON (also available as a row action and bulk action in the Maps list). Importing can optionally copy custom pin images into the media library.
- **KML / GeoJSON:** in the map editor, **Import KML / GeoJSON** adds features from a file (Google My Maps, Google Earth, Scribble Maps paid plans). Pins, polygons and their colours are imported; many-sided circles are converted back to editable radii; HTML descriptions are sanitised. Lines are skipped. KMZ files need unzipping first.

Scribble Maps' free plan can't export, so most migrations are quickest rebuilt by hand with the address search.

## Privacy and third parties

Leaflet and its plugins are served from the plugin itself. Visitors' browsers request **map tiles** from the chosen provider (OpenStreetMap by default). The admin address search uses OpenStreetMap's **Nominatim** service. It runs only in the editor, never on the front end.

OpenStreetMap's tile servers are for light use, and CARTO's free basemaps are for non-commercial use. For busy or commercial sites, use a keyed provider under **Custom tiles**.

## Permissions

Maps use page capabilities, so Editors and Administrators can manage them (change with the `admaps_capability_type` filter). Settings and Import / Export require `manage_options`.

## Developer reference

### Filters

| Filter | Arguments | Use |
|---|---|---|
| `admaps_frontend_config` | `array $config, int $map_id` | Modify the config passed to the front-end script. |
| `admaps_tile_providers` | `array $providers` | Add or change tile layers. Each needs `label`, `url`, `attribution`; `subdomains` and `maxZoom` are optional. |
| `admaps_page_has_map` | `bool $has_map, WP_Post $post` | Tell the plugin a page contains a map, so its CSS loads in the `<head>`. Shortcodes in post content and Breakdance data are detected automatically. |
| `admaps_capability_type` | `string $type` | Capability type for the map post type (default `page`). |

```php
add_filter( 'admaps_tile_providers', function ( $providers ) {
	$providers['stadia-smooth'] = array(
		'label'       => 'Stadia Alidade Smooth',
		'url'         => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png?api_key=KEY',
		'attribution' => '&copy; Stadia Maps &copy; OpenStreetMap contributors',
		'maxZoom'     => 20,
	);
	return $providers;
} );
```

### JavaScript

Each map fires a bubbling `admaps:ready` event with the Leaflet map instance, which is also available as `element.admapsMap`:

```js
document.addEventListener( 'admaps:ready', function ( e ) {
	var map = e.detail.map; // L.Map
} );
```

### Structure

```
admaps.php                   Bootstrap and constants
includes/
  class-map-data.php         Data schema, defaults, sanitising (single source of truth)
  class-appearance.php       Appearance fields, resolving and CSS variables
  class-settings.php         Maps → Settings
  class-post-type.php        admaps_map post type and list screen
  class-admin-editor.php     Edit screen meta boxes and saving
  class-shortcode.php        [admaps] output
  class-assets.php           Script/style registration and tile providers
  class-import-export.php    JSON export/import
assets/js/frontend.js        Front-end renderer (also used by the admin preview)
assets/js/admin-editor.js    Map editor
assets/js/import.js          KML/GeoJSON parser
assets/vendor/               Leaflet 1.9.4, Leaflet.markercluster 1.5.3, Leaflet-Geoman free 2.20.6
uninstall.php                Optional data removal
```

Map contents are stored as one array in the `_admaps_data` post meta of each `admaps_map` post. Everything from the browser or an import is rebuilt field by field in `Map_Data::sanitize()`.

There is no build step. Edit the files directly. Code must stay PHP 7.4 compatible (no union types, `match`, named arguments, nullsafe operator or constructor promotion).

### Releasing

Versions follow [semantic versioning](https://semver.org/); `0.x` while in testing. To release:

1. Update `Version:` and `ADMAPS_VERSION` in `admaps.php`.
2. Commit, then tag: `git tag -a v0.2.0 -m "…" && git push --follow-tags`.
3. Build the zip from the `plugins` directory:
   ```bash
   zip -r admaps-0.2.0.zip admaps -x "*.DS_Store" "admaps/.git/*" "admaps/.gitignore" "admaps/README.md"
   ```

## Licence

GPL-2.0-or-later. Bundled libraries keep their own licences (BSD-2-Clause for Leaflet, MIT for Leaflet.markercluster and Leaflet-Geoman); see each folder in `assets/vendor/`.
