/**
 * Adtrak Maps admin editor.
 *
 * Single source of truth is `state`; it is serialised into #admaps-data on every
 * change and sanitised server-side on save.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.admapsEditor;
	var R = window.AdmapsRender;
	var L = window.L;
	if ( ! cfg || ! R || ! L || ! document.getElementById( 'admaps-editor-map' ) ) {
		return;
	}

	var i18n = cfg.i18n;
	var EDITOR_ID = 'admaps-popup-editor';
	var UNITS = { m: 1, km: 1000, mi: 1609.344 };
	var UNIT_LABELS = { mi: 'miles', km: 'km', m: 'metres' };
	var TYPE_LABEL = { markers: i18n.marker, circles: i18n.circle, polygons: i18n.polygon };

	var ITEM = $.extend( {
		pin_color: '#e63946',
		stroke: '#1d3557',
		stroke_opacity: 1,
		weight: 2,
		fill: '#457b9d',
		fill_opacity: 0.25,
		radius: 5,
		unit: 'mi'
	}, cfg.itemDefaults || {} );
	if ( ! UNITS[ ITEM.unit ] ) {
		ITEM.unit = 'mi';
	}
	var SHAPE_DEFAULTS = {
		label: '',
		popup: '',
		legend: true,
		popup_min: 0,
		popup_max: 0,
		stroke: ITEM.stroke,
		stroke_opacity: +ITEM.stroke_opacity,
		weight: +ITEM.weight,
		fill: ITEM.fill,
		fill_opacity: +ITEM.fill_opacity
	};
	var DEFAULTS = {
		markers: {
			label: '',
			popup: '',
			legend: false,
			directions: !! ITEM.directions,
			popup_min: 0,
			popup_max: 0,
			icon: { type: 'svg', color: ITEM.pin_color, attachment_id: 0, url: '', width: 28, height: 42, anchor: 'bottom' }
		},
		circles: $.extend( {}, SHAPE_DEFAULTS, {
			radius: round( ITEM.radius * UNITS[ ITEM.unit ], 1 ),
			unit: ITEM.unit
		} ),
		polygons: $.extend( {}, SHAPE_DEFAULTS )
	};
	var POPUP_SIZE = cfg.popupSize || { min: 160, max: 320 };
	var STYLE_KEYS = {
		markers: [ 'icon' ],
		circles: [ 'radius', 'unit', 'stroke', 'stroke_opacity', 'weight', 'fill', 'fill_opacity' ],
		polygons: [ 'stroke', 'stroke_opacity', 'weight', 'fill', 'fill_opacity' ]
	};

	var state = normalise( cfg.data );
	var layers = {};
	var selected = null;
	var drawingType = null;
	var sourcePreview = { markers: [], circles: [] };
	var editorActive = false;
	var previewPopup = null;
	var previewTimer = null;
	var bubblePreview = false;
	try {
		bubblePreview = window.localStorage.getItem( 'admapsBubblePreview' ) === '1';
	} catch ( e ) {}
	var legendCtl = null;
	var noticeTimer = null;

	var $data = $( '#admaps-data' );
	var $list = $( '#admaps-items' );
	var $form = $( '#admaps-item-form' );
	var $panel = $( '.admaps-panel' );
	var $notice = $( '#admaps-notice' );

	/* ---------- utilities ---------- */

	function normalise( data ) {
		data = $.extend( true, {}, data || {} );
		data.settings = data.settings || {};
		[ 'markers', 'circles', 'polygons' ].forEach( function ( type ) {
			data[ type ] = Array.isArray( data[ type ] ) ? data[ type ] : [];
			data[ type ].forEach( function ( item ) {
				item.id = item.id || uid();
			} );
		} );
		return data;
	}

	function uid() {
		return 'i' + Math.random().toString( 36 ).slice( 2, 12 );
	}

	function round( n, dp ) {
		var f = Math.pow( 10, dp == null ? 7 : dp );
		return Math.round( n * f ) / f;
	}

	function sprintf( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return str.replace( /%(\d+)\$[ds]|%[ds]/g, function ( match, pos ) {
			return pos ? args[ pos - 1 ] : args[ i++ ];
		} );
	}

	function esc( str ) {
		return R.escapeHtml( str );
	}

	function sync() {
		$data.val( JSON.stringify( state ) );
	}

	function find( type, id ) {
		for ( var i = 0; i < state[ type ].length; i++ ) {
			if ( state[ type ][ i ].id === id ) {
				return state[ type ][ i ];
			}
		}
		return null;
	}

	function current() {
		return selected ? find( selected.type, selected.id ) : null;
	}

	function notice( message, kind ) {
		clearTimeout( noticeTimer );
		$notice.attr( 'class', 'admaps-notice admaps-notice--' + ( kind || 'info' ) ).text( message ).prop( 'hidden', false );
		if ( kind !== 'error' && kind !== 'sticky' ) {
			noticeTimer = setTimeout( hideNotice, 7000 );
		}
	}

	function hideNotice() {
		$notice.prop( 'hidden', true );
	}

	/**
	 * New items copy the style of the last item of the same type.
	 */
	function newItem( type, overrides ) {
		var base = $.extend( true, {}, DEFAULTS[ type ] );
		var last = state[ type ][ state[ type ].length - 1 ];
		if ( last && ! overrides.keepImportedStyle ) {
			STYLE_KEYS[ type ].forEach( function ( key ) {
				if ( last[ key ] !== undefined ) {
					base[ key ] = $.isPlainObject( last[ key ] ) ? $.extend( true, {}, last[ key ] ) : last[ key ];
				}
			} );
		}
		delete overrides.keepImportedStyle;
		base.name = nextName( type );
		var item = $.extend( true, base, overrides );
		item.id = uid();
		return item;
	}

	/* ---------- map ---------- */

	var map = L.map( 'admaps-editor-map', { scrollWheelZoom: true } );
	var tiles = null;

	function tileConfig() {
		var slug = state.settings.tiles || cfg.defaultTiles;
		return cfg.tiles[ slug ] || cfg.tiles[ cfg.defaultTiles ] || cfg.tiles.osm;
	}

	function setTiles() {
		if ( tiles ) {
			map.removeLayer( tiles );
		}
		tiles = R.tileLayer( tileConfig() ).addTo( map );
	}

	function setTint() {
		var el = map.getContainer();
		var tint = state.settings.tint || cfg.defaultTint;
		el.classList.remove( 'admaps-map--tint-grey', 'admaps-map--tint-dark' );
		if ( tint && tint !== 'none' ) {
			el.classList.add( 'admaps-map--tint-' + tint );
		}
	}

	function readGeometry( type, item, layer ) {
		if ( type === 'polygons' ) {
			var rings = layer.getLatLngs();
			var ring = Array.isArray( rings[ 0 ] ) ? rings[ 0 ] : rings;
			item.points = ring.map( function ( ll ) {
				ll = ll.wrap();
				return [ round( ll.lat ), round( ll.lng ) ];
			} );
		} else {
			var ll = layer.getLatLng().wrap();
			item.lat = round( ll.lat );
			item.lng = round( ll.lng );
			if ( type === 'circles' ) {
				item.radius = round( layer.getRadius(), 1 );
			}
		}
	}

	function geometryChanged( type, item, layer ) {
		readGeometry( type, item, layer );
		if ( selected && selected.id === item.id ) {
			updateFormGeometry( type, item );
			refreshPreview();
		}
		sync();
	}

	function bindLabel( type, layer, item ) {
		layer.unbindTooltip();
		if ( item.label ) {
			layer.bindTooltip( esc( item.label ), { direction: 'top', sticky: type !== 'markers' } );
		}
	}

	function buildLayer( type, item ) {
		var layer;
		if ( type === 'markers' ) {
			layer = R.markerLayer( item, { draggable: true, autoPan: true } );
			layer.on( 'dragend', function () {
				geometryChanged( type, item, layer );
			} );
		} else {
			layer = type === 'circles' ? R.circleLayer( item ) : R.polygonLayer( item );
			layer.on( 'pm:edit pm:dragend pm:markerdragend pm:vertexadded pm:vertexremoved', function () {
				geometryChanged( type, item, layer );
			} );
		}
		layer.on( 'click', function ( e ) {
			// While placing or drawing, let the click reach the map so items can go on top of shapes.
			if ( drawingType || map.pm.globalDrawModeEnabled() ) {
				return;
			}
			L.DomEvent.stopPropagation( e );
			select( type, item.id );
		} );
		bindLabel( type, layer, item );
		layer.addTo( map );
		layers[ item.id ] = layer;
		return layer;
	}

	function updateLayer( type, item ) {
		var layer = layers[ item.id ];
		if ( ! layer ) {
			return;
		}
		var editing = type !== 'markers' && layer.pm && layer.pm.enabled();
		if ( editing ) {
			layer.pm.disable();
		}
		if ( type === 'markers' ) {
			layer.setLatLng( [ item.lat, item.lng ] );
			layer.setIcon( R.markerIcon( item.icon ) );
			markSelectedMarker();
		} else {
			layer.setStyle( R.shapeStyle( item ) );
			if ( type === 'circles' ) {
				layer.setLatLng( [ item.lat, item.lng ] );
				layer.setRadius( +item.radius );
			}
		}
		bindLabel( type, layer, item );
		if ( editing ) {
			layer.pm.enable( { allowSelfIntersection: true } );
		}
	}

	function removeLayer( id ) {
		if ( layers[ id ] ) {
			if ( layers[ id ].pm ) {
				layers[ id ].pm.disable();
			}
			map.removeLayer( layers[ id ] );
			delete layers[ id ];
		}
	}

	function allBounds() {
		var bounds = L.latLngBounds( [] );
		[ 'markers', 'circles', 'polygons' ].forEach( function ( type ) {
			state[ type ].forEach( function ( item ) {
				bounds.extend( R.itemBounds( type, item ) );
			} );
		} );
		return bounds;
	}

	function fitAll() {
		var bounds = allBounds();
		if ( bounds.isValid() ) {
			map.fitBounds( bounds, { padding: [ 30, 30 ], maxZoom: 15 } );
		} else {
			map.setView( state.settings.center || [ 54, -2.5 ], state.settings.zoom == null ? 6 : state.settings.zoom );
		}
	}

	function refreshLegend() {
		if ( legendCtl ) {
			map.removeControl( legendCtl );
			legendCtl = null;
		}
		if ( state.settings.legend ) {
			legendCtl = R.legendControl( $.extend( {}, state, {
				markers: state.markers.concat( sourcePreview.markers ),
				circles: state.circles.concat( sourcePreview.circles )
			} ) );
			if ( legendCtl ) {
				legendCtl.addTo( map );
			}
		}
		$( '.admaps-legend-settings' ).toggle( !! state.settings.legend );
	}

	function markSelectedMarker() {
		Object.keys( layers ).forEach( function ( id ) {
			var el = layers[ id ].getElement && layers[ id ].getElement();
			if ( el && layers[ id ] instanceof L.Marker ) {
				el.classList.toggle( 'admaps-selected', !! selected && selected.id === id );
			}
		} );
	}

	/* ---------- selection, list and form ---------- */

	function stopShapeEditing() {
		Object.keys( layers ).forEach( function ( id ) {
			var layer = layers[ id ];
			if ( ! ( layer instanceof L.Marker ) && layer.pm && layer.pm.enabled() ) {
				layer.pm.disable();
			}
		} );
	}

	function select( type, id, pan ) {
		destroyEditor();
		stopShapeEditing();
		selected = type && id ? { type: type, id: id } : null;

		var item = current();
		if ( item && type !== 'markers' ) {
			var layer = layers[ id ];
			layer.pm.enable( { allowSelfIntersection: true, snappable: false } );
			layer.bringToFront();
		}
		if ( item && pan ) {
			var bounds = R.itemBounds( type, item );
			if ( ! map.getBounds().contains( bounds ) ) {
				map.fitBounds( bounds, { padding: [ 40, 40 ], maxZoom: Math.max( map.getZoom(), 12 ) } );
			}
		}

		markSelectedMarker();
		renderList();
		renderForm();
		refreshPreview();
		$panel.scrollTop( 0 );
	}

	/**
	 * Back to the list, keeping the item that was being edited in view.
	 */
	function backToList() {
		var id = selected && selected.id;
		select( null );
		var btn = id && $list.find( '[data-id="' + id + '"]' )[ 0 ];
		if ( btn ) {
			btn.scrollIntoView( { block: 'nearest' } );
			btn.focus( { preventScroll: true } );
		}
	}

	function swatchHtml( type, item ) {
		if ( type === 'markers' ) {
			if ( item.icon.type === 'image' && item.icon.url ) {
				return '<img class="admaps-item__swatch admaps-item__swatch--img" src="' + esc( item.icon.url ) + '" alt="">';
			}
			return '<span class="admaps-item__swatch admaps-item__swatch--pin" style="background:' + esc( item.icon.color ) + '"></span>';
		}
		return '<span class="admaps-item__swatch' + ( type === 'circles' ? ' admaps-item__swatch--circle' : '' ) +
			'" style="background:' + esc( item.fill ) + ';border-color:' + esc( item.stroke ) + '"></span>';
	}

	/**
	 * Name shown in the editor: the public label, else the editor-only name, else "Pin 2" by position.
	 */
	function displayName( type, item ) {
		if ( item.label ) {
			return item.label;
		}
		if ( item.name ) {
			return item.name;
		}
		return TYPE_LABEL[ type ] + ' ' + ( state[ type ].indexOf( item ) + 1 );
	}

	function nextName( type ) {
		var taken = {};
		state[ type ].forEach( function ( item ) {
			taken[ displayName( type, item ) ] = true;
		} );
		var n = state[ type ].length + 1;
		while ( taken[ TYPE_LABEL[ type ] + ' ' + n ] ) {
			n++;
		}
		return TYPE_LABEL[ type ] + ' ' + n;
	}

	function itemCount() {
		return state.markers.length + state.circles.length + state.polygons.length;
	}

	function renderList() {
		var html = '';
		[ 'markers', 'circles', 'polygons' ].forEach( function ( type ) {
			state[ type ].forEach( function ( item ) {
				html += '<li><button type="button" class="admaps-item" data-type="' + type + '" data-id="' + esc( item.id ) + '">' +
					swatchHtml( type, item ) +
					'<span class="admaps-item__type">' + esc( TYPE_LABEL[ type ] ) + '</span>' +
					'<span class="admaps-item__label">' + esc( displayName( type, item ) ) + '</span></button></li>';
			} );
		} );
		$list.html( html || '<li class="admaps-items__empty">' + esc( i18n.noItems ) + '</li>' );
		$list.prop( 'hidden', !! selected );
	}

	function field( label, input, extraClass ) {
		return '<div class="admaps-field' + ( extraClass ? ' ' + extraClass : '' ) + '"><label>' + esc( label ) + '</label>' + input + '</div>';
	}

	function colorInput( name, value ) {
		return '<input type="text" class="admaps-color" data-field="' + name + '" value="' + esc( value ) + '">';
	}

	function opacityInput( name, value ) {
		return '<span class="admaps-range"><input type="range" min="0" max="1" step="0.05" data-field="' + name + '" value="' + esc( value ) + '"><output>' + Math.round( value * 100 ) + '%</output></span>';
	}

	function latLngFields( item ) {
		return '<div class="admaps-field-row">' +
			field( 'Latitude', '<input type="number" step="any" min="-90" max="90" data-field="lat" value="' + esc( item.lat ) + '">' ) +
			field( 'Longitude', '<input type="number" step="any" min="-180" max="180" data-field="lng" value="' + esc( item.lng ) + '">' ) +
			'</div>';
	}

	function renderForm() {
		var item = current();
		if ( ! item ) {
			$form.empty();
			return;
		}
		var type = selected.type;
		var html = '<button type="button" class="admaps-back"><span aria-hidden="true">&larr;</span> ' + esc( sprintf( i18n.allItems, itemCount() ) ) + '</button>';
		html += '<h3 class="admaps-item-form__title">' + swatchHtml( type, item ) + '<span class="admaps-item-form__name">' + esc( displayName( type, item ) ) + '</span></h3>';

		html += field( 'Label', '<input type="text" class="widefat" data-field="label" value="' + esc( item.label ) + '" placeholder="' + esc( i18n.labelPlaceholder ) + '">' );
		html += '<p class="admaps-field admaps-field--check"><label><input type="checkbox" data-field="legend"' + ( item.legend ? ' checked' : '' ) + '> Show in legend</label></p>';

		if ( type === 'markers' ) {
			var icon = item.icon;
			html += latLngFields( item );
			html += '<fieldset class="admaps-field"><legend>Pin style</legend>' +
				'<label><input type="radio" name="admaps-icon-type" data-field="icon.type" value="svg"' + ( icon.type !== 'image' ? ' checked' : '' ) + '> Coloured pin</label> ' +
				'<label><input type="radio" name="admaps-icon-type" data-field="icon.type" value="image"' + ( icon.type === 'image' ? ' checked' : '' ) + '> Custom image</label></fieldset>';
			html += '<div data-show-icon="svg">' + field( 'Pin colour', colorInput( 'icon.color', icon.color ) ) + '</div>';
			html += '<div data-show-icon="image">' +
				'<div class="admaps-field admaps-image">' +
				( icon.url ? '<img src="' + esc( icon.url ) + '" alt="">' : '' ) +
				'<button type="button" class="button admaps-choose-image">' + esc( i18n.chooseImage ) + '</button></div>' +
				( icon.url && ! icon.attachment_id ? '<p class="description admaps-warning">' + esc( i18n.externalImage ) + '</p>' : '' ) +
				field( 'Anchor', '<select data-field="icon.anchor"><option value="bottom"' + ( icon.anchor !== 'center' ? ' selected' : '' ) + '>Bottom centre</option><option value="center"' + ( icon.anchor === 'center' ? ' selected' : '' ) + '>Centre</option></select>' ) +
				'</div>';
			html += field( 'Size (px wide)', '<input type="number" min="8" max="200" data-field="icon.width" value="' + esc( icon.width ) + '">' +
				'<span class="description">Height follows the pin\'s proportions.</span>' );
		}

		if ( type === 'circles' ) {
			html += latLngFields( item );
			var units = Object.keys( UNITS ).map( function ( u ) {
				return '<option value="' + u + '"' + ( item.unit === u ? ' selected' : '' ) + '>' + UNIT_LABELS[ u ] + '</option>';
			} ).join( '' );
			html += '<div class="admaps-field-row">' +
				field( 'Radius', '<input type="number" min="0" step="any" data-field="radius_display" value="' + round( item.radius / UNITS[ item.unit ], 2 ) + '">' ) +
				field( 'Unit', '<select data-field="unit">' + units + '</select>' ) +
				'</div>';
		}

		if ( type === 'polygons' ) {
			html += '<p class="description admaps-points">' + esc( sprintf( i18n.points, item.points.length ) ) + ' – drag the corner handles to reshape, right-click a corner to remove it.</p>';
		}

		if ( type !== 'markers' ) {
			html += '<div class="admaps-field-row">' +
				field( 'Fill colour', colorInput( 'fill', item.fill ) ) +
				field( 'Fill opacity', opacityInput( 'fill_opacity', item.fill_opacity ) ) +
				'</div><div class="admaps-field-row">' +
				field( 'Line colour', colorInput( 'stroke', item.stroke ) ) +
				field( 'Line opacity', opacityInput( 'stroke_opacity', item.stroke_opacity ) ) +
				'</div>' +
				field( 'Line width (px)', '<input type="number" min="0" max="20" data-field="weight" value="' + esc( item.weight ) + '">' );
		}

		html += '<div class="admaps-field"><div class="admaps-field__head"><label for="' + EDITOR_ID + '">Info bubble</label>' +
			'<label class="admaps-preview-toggle"><input type="checkbox" class="admaps-bubble-preview"' + ( bubblePreview ? ' checked' : '' ) + '> ' + esc( i18n.bubblePreview ) + '</label></div>' +
			'<textarea id="' + EDITOR_ID + '" rows="6"></textarea></div>';
		if ( type === 'markers' ) {
			html += '<p class="admaps-field admaps-field--check"><label><input type="checkbox" data-field="directions"' + ( item.directions ? ' checked' : '' ) + '> Add a "Get directions" link (Google Maps)</label></p>';
		}
		var sized = item.popup_min > 0 || item.popup_max > 0;
		html += '<details class="admaps-advanced"' + ( sized ? ' open' : '' ) + '><summary>Bubble size' + ( sized ? ' (custom)' : '' ) + '</summary>' +
			'<div class="admaps-field-row">' +
			field( 'Min width (px)', '<input type="number" step="any" data-field="popup_min" value="' + ( item.popup_min > 0 ? esc( item.popup_min ) : '' ) + '" placeholder="' + esc( POPUP_SIZE.min ) + '">' ) +
			field( 'Max width (px)', '<input type="number" step="any" data-field="popup_max" value="' + ( item.popup_max > 0 ? esc( item.popup_max ) : '' ) + '" placeholder="' + esc( POPUP_SIZE.max ) + '">' ) +
			'</div><p class="description">Leave blank to use the map\'s bubble size. Bubbles always shrink to fit small screens.</p></details>';
		html += '<p class="admaps-item-form__actions">' +
			'<button type="button" class="button admaps-zoom-to">Zoom to</button> ' +
			'<button type="button" class="button-link admaps-deselect">Done</button>' +
			'<button type="button" class="button-link button-link-delete admaps-delete">Delete</button></p>';

		$form.html( html );
		toggleIconFields( item );
		if ( type === 'markers' && item.icon.type === 'image' ) {
			learnImageRatio( item );
		}

		$form.find( '.admaps-color' ).wpColorPicker( {
			change: function ( event, ui ) {
				setField( this.getAttribute( 'data-field' ), ui.color.toString() );
			},
			palettes: [ '#e63946', '#f4a261', '#e9c46a', '#2a9d8f', '#457b9d', '#1d3557', '#6a4c93', '#222222' ]
		} );

		$( '#' + EDITOR_ID ).val( item.popup || '' );
		if ( window.wp && window.wp.editor && window.wp.editor.initialize ) {
			window.wp.editor.initialize( EDITOR_ID, {
				tinymce: {
					wpautop: true,
					height: 160,
					toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,alignleft,aligncenter,alignright,undo,redo',
					toolbar2: ''
				},
				quicktags: true,
				mediaButtons: true
			} );
			editorActive = true;
		}

		detachFromPostForm( $form );
	}

	/**
	 * Panel fields are serialised via #admaps-data, so keep them out of the post
	 * form: otherwise a hidden field failing min/max validation silently blocks
	 * Save Draft / Publish.
	 */
	function detachFromPostForm( $scope ) {
		$scope.find( 'input, select, textarea' ).attr( 'form', 'admaps-detached' );
	}

	function toggleIconFields( item ) {
		if ( ! item.icon ) {
			return;
		}
		var image = item.icon.type === 'image';
		$form.find( '[data-show-icon="image"]' ).toggle( image );
		$form.find( '[data-show-icon="svg"]' ).toggle( ! image );
	}

	function readEditor() {
		var item = current();
		if ( editorActive && item && window.wp && window.wp.editor ) {
			var content = window.wp.editor.getContent( EDITOR_ID );
			if ( typeof content === 'string' ) {
				item.popup = content;
			}
		}
	}

	function destroyEditor() {
		if ( ! editorActive ) {
			return;
		}
		readEditor();
		window.wp.editor.remove( EDITOR_ID );
		editorActive = false;
		sync();
	}

	function updateFormGeometry( type, item ) {
		var set = function ( name, value ) {
			var $input = $form.find( '[data-field="' + name + '"]' );
			if ( $input.length && ! $input.is( ':focus' ) ) {
				$input.val( value );
			}
		};
		if ( type === 'polygons' ) {
			$form.find( '.admaps-points' ).text( sprintf( i18n.points, item.points.length ) + ' – drag the corner handles to reshape, right-click a corner to remove it.' );
			return;
		}
		set( 'lat', item.lat );
		set( 'lng', item.lng );
		if ( type === 'circles' ) {
			set( 'radius_display', round( item.radius / UNITS[ item.unit ], 2 ) );
		}
	}

	function setField( name, value ) {
		var item = current();
		if ( ! item ) {
			return;
		}
		var type = selected.type;

		switch ( name ) {
			case 'radius_display':
				if ( ! ( value > 0 ) ) {
					return;
				}
				item.radius = round( value * UNITS[ item.unit ], 1 );
				break;
			case 'unit':
				item.unit = value;
				$form.find( '[data-field="radius_display"]' ).val( round( item.radius / UNITS[ value ], 2 ) );
				break;
			case 'lat':
			case 'lng':
				if ( isNaN( value ) || ( name === 'lat' && Math.abs( value ) > 90 ) || ( name === 'lng' && Math.abs( value ) > 180 ) ) {
					return;
				}
				item[ name ] = value;
				break;
			case 'popup_min':
			case 'popup_max':
				item[ name ] = value > 0 ? Math.round( value ) : 0;
				$form.find( '.admaps-advanced summary' ).text( 'Bubble size' + ( item.popup_min > 0 || item.popup_max > 0 ? ' (custom)' : '' ) );
				refreshPreview();
				sync();
				return;
			default:
				if ( name.indexOf( 'icon.' ) === 0 ) {
					var key = name.slice( 5 );
					if ( ( key === 'width' || key === 'height' ) && ! ( value >= 8 ) ) {
						return;
					}
					if ( key === 'width' ) {
						value = Math.min( 200, Math.round( value ) );
						item.icon.height = item.icon.type === 'image' && item.icon.url ? imageHeight( item.icon, value ) : Math.round( value * 1.5 );
						item.icon.width = value;
					} else {
						item.icon[ key ] = value;
					}
					if ( key === 'type' ) {
						toggleIconFields( item );
						if ( item.icon.type === 'image' && item.icon.url ) {
							item.icon.height = imageHeight( item.icon );
						}
					}
				} else {
					if ( name === 'weight' && isNaN( value ) ) {
						return;
					}
					item[ name ] = value;
				}
		}

		updateLayer( type, item );
		if ( name === 'label' || name.indexOf( 'color' ) !== -1 || name === 'fill' || name === 'stroke' || name === 'icon.type' ) {
			var $btn = $list.find( '[data-id="' + item.id + '"]' ).add( $form.find( '.admaps-item-form__title' ) );
			$btn.find( '.admaps-item__label, .admaps-item-form__name' ).text( displayName( type, item ) );
			$btn.find( '.admaps-item__swatch' ).replaceWith( swatchHtml( type, item ) );
		}
		if ( state.settings.legend ) {
			refreshLegend();
		}
		refreshPreview();
		sync();
	}

	/* ---------- bubble preview ---------- */

	/**
	 * Bubble HTML as the live map shows it (shortcodes aside, which only run on the front end).
	 */
	function bubbleHtml( type, item ) {
		var html = item.popup || '';
		if ( html.trim() && window.wp && window.wp.editor && window.wp.editor.autop ) {
			html = window.wp.editor.autop( html );
		}
		if ( type === 'markers' && item.directions ) {
			html += R.directionsLink( item, cfg );
		}
		return html;
	}

	function closePreview() {
		if ( previewPopup ) {
			var popup = previewPopup;
			previewPopup = null;
			map.closePopup( popup );
		}
	}

	function refreshPreview() {
		var item = current();
		var layer = item && layers[ item.id ];
		if ( ! bubblePreview || ! layer ) {
			closePreview();
			return;
		}
		readEditor();
		var type = selected.type;
		var html = bubbleHtml( type, item ) || '<p class="admaps-preview-empty">' + esc( i18n.bubbleEmpty ) + '</p>';
		var opts = R.popupOptions( item, { minWidth: POPUP_SIZE.min, maxWidth: POPUP_SIZE.max } );
		var room = Math.max( 120, map.getSize().x - 60 );
		opts.maxWidth = Math.min( opts.maxWidth, room );
		opts.minWidth = Math.min( opts.minWidth, opts.maxWidth );
		var latlng = type === 'polygons' ? layer.getBounds().getCenter() : layer.getLatLng();

		if ( previewPopup && previewPopup._source !== layer ) {
			closePreview();
		}
		if ( ! previewPopup ) {
			previewPopup = L.popup( $.extend( opts, { autoClose: false, closeOnClick: false, className: 'admaps-preview-popup' } ), layer );
			previewPopup.on( 'remove', function () {
				// Closed with its own × button: switch the preview off.
				if ( previewPopup === this ) {
					previewPopup = null;
					setBubblePreview( false );
				}
			} );
			previewPopup.setLatLng( latlng ).setContent( html ).openOn( map );
			return;
		}
		previewPopup.options.minWidth = opts.minWidth;
		previewPopup.options.maxWidth = opts.maxWidth;
		previewPopup.setLatLng( latlng ).setContent( html );
	}

	function schedulePreview() {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( refreshPreview, 200 );
	}

	function setBubblePreview( on ) {
		bubblePreview = on;
		$form.find( '.admaps-bubble-preview' ).prop( 'checked', on );
		try {
			window.localStorage.setItem( 'admapsBubblePreview', on ? '1' : '0' );
		} catch ( e ) {}
		refreshPreview();
	}

	$form.on( 'change', '.admaps-bubble-preview', function () {
		setBubblePreview( this.checked );
	} );
	$form.on( 'input', '#' + EDITOR_ID, schedulePreview );
	$( document ).on( 'tinymce-editor-init', function ( event, editor ) {
		if ( editor && editor.id === EDITOR_ID ) {
			editor.on( 'input keyup change undo redo ExecCommand', schedulePreview );
		}
	} );

	/**
	 * The map's Appearance box, applied live so the preview matches the saved result.
	 */
	function applyAppearance() {
		var el = map.getContainer();
		var site = cfg.appearance.site;
		var value = function ( key ) {
			var $input = $( '[name="admaps_settings[style][' + key + ']"]' );
			var v = $input.length ? String( $input.val() ) : '';
			return v !== '' ? v : String( site[ key ] == null ? '' : site[ key ] );
		};
		Object.keys( cfg.appearance.vars ).forEach( function ( key ) {
			var v = value( key );
			var px = cfg.appearance.px.indexOf( key ) !== -1;
			if ( v === '' || ( px && isNaN( parseFloat( v ) ) ) ) {
				el.style.removeProperty( cfg.appearance.vars[ key ] );
			} else {
				el.style.setProperty( cfg.appearance.vars[ key ], px ? parseFloat( v ) + 'px' : v );
			}
		} );
		el.classList.toggle( 'admaps-map--theme-font', value( 'theme_font' ) === '1' );
		POPUP_SIZE = {
			min: parseInt( value( 'popup_min' ), 10 ) || POPUP_SIZE.min,
			max: parseInt( value( 'popup_max' ), 10 ) || POPUP_SIZE.max
		};
		schedulePreview();
	}

	$( '#admaps-appearance' ).on( 'input change', 'input, select', applyAppearance );

	$form.on( 'input change', '[data-field]', function () {
		// Colour fields are handled by the wpColorPicker change callback.
		if ( $( this ).hasClass( 'admaps-color' ) || ( this.type === 'radio' && ! this.checked ) ) {
			return;
		}
		var value;
		if ( this.type === 'checkbox' ) {
			value = this.checked;
		} else if ( this.type === 'number' || this.type === 'range' ) {
			value = parseFloat( this.value );
			if ( this.type === 'range' ) {
				$( this ).next( 'output' ).text( Math.round( value * 100 ) + '%' );
			}
		} else {
			value = this.value;
		}
		setField( this.getAttribute( 'data-field' ), value );
	} );

	$( '#admaps-appearance .admaps-color-field' ).wpColorPicker( {
		change: function () {
			window.setTimeout( applyAppearance );
		},
		clear: function () {
			window.setTimeout( applyAppearance );
		}
	} );

	$list.on( 'click', '.admaps-item', function () {
		var type = this.getAttribute( 'data-type' );
		var id = this.getAttribute( 'data-id' );
		if ( selected && selected.id === id ) {
			return;
		}
		select( type, id, true );
	} );

	$form.on( 'click', '.admaps-delete', function () {
		var item = current();
		if ( ! item || ! window.confirm( i18n.deleteConfirm ) ) {
			return;
		}
		var type = selected.type;
		destroyEditor();
		removeLayer( item.id );
		state[ type ] = state[ type ].filter( function ( other ) {
			return other.id !== item.id;
		} );
		selected = null;
		select( null );
		refreshLegend();
		sync();
	} );

	$form.on( 'click', '.admaps-deselect, .admaps-back', backToList );

	$form.on( 'click', '.admaps-zoom-to', function () {
		var item = current();
		if ( item ) {
			map.fitBounds( R.itemBounds( selected.type, item ), { padding: [ 40, 40 ], maxZoom: 16 } );
		}
	} );

	/**
	 * Image pins keep the image's own proportions; ratios are height / width, keyed by URL.
	 */
	var imageRatios = {};

	function imageHeight( icon, width ) {
		var ratio = imageRatios[ icon.url ] || ( icon.width && icon.height ? icon.height / icon.width : 1 );
		return Math.max( 8, Math.round( ( width || icon.width ) * ratio ) );
	}

	function learnImageRatio( item ) {
		var url = item.icon.url;
		if ( ! url || imageRatios[ url ] ) {
			return;
		}
		var img = new window.Image();
		img.onload = function () {
			if ( ! img.naturalWidth || ! img.naturalHeight ) {
				return;
			}
			imageRatios[ url ] = img.naturalHeight / img.naturalWidth;
			var height = imageHeight( item.icon );
			if ( item.icon.url === url && item.icon.height !== height ) {
				item.icon.height = height;
				updateLayer( 'markers', item );
				sync();
			}
		};
		img.src = url;
	}

	var mediaFrame = null;
	$form.on( 'click', '.admaps-choose-image', function () {
		if ( ! mediaFrame ) {
			mediaFrame = window.wp.media( {
				title: i18n.chooseImage,
				button: { text: i18n.useImage },
				library: { type: 'image' },
				multiple: false
			} );
			mediaFrame.on( 'select', function () {
				var item = current();
				if ( ! item || selected.type !== 'markers' ) {
					return;
				}
				var a = mediaFrame.state().get( 'selection' ).first().toJSON();
				var width = item.icon.type === 'image' && item.icon.width ? item.icon.width : 40;
				if ( a.width && a.height ) {
					imageRatios[ a.url ] = a.height / a.width;
				}
				item.icon.type = 'image';
				item.icon.attachment_id = a.id;
				item.icon.url = a.url;
				item.icon.width = width;
				item.icon.height = imageHeight( item.icon );
				updateLayer( 'markers', item );
				sync();
				destroyEditor();
				renderForm();
				renderList();
			} );
		}
		mediaFrame.open();
	} );

	/* ---------- drawing ---------- */

	var crosshair = L.divIcon( {
		className: 'admaps-crosshair',
		html: '<span></span>',
		iconSize: [ 24, 24 ],
		iconAnchor: [ 12, 12 ]
	} );

	function startDraw( type ) {
		select( null );
		map.pm.disableDraw();
		drawingType = type;
		$( map.getContainer() ).addClass( 'is-drawing' );
		$( '[data-admaps-draw]' ).removeClass( 'is-active' );
		$( '[data-admaps-draw="' + type + '"]' ).addClass( 'is-active' );

		if ( type === 'polygon' ) {
			map.pm.enableDraw( 'Polygon', {
				pathOptions: R.shapeStyle( newItem( 'polygons', {} ) ),
				templineStyle: { color: '#1d3557' },
				hintlineStyle: { color: '#1d3557', dashArray: '5,5' },
				allowSelfIntersection: true,
				snappable: false
			} );
			notice( i18n.polygonHint, 'sticky' );
		} else {
			map.pm.enableDraw( 'Marker', {
				markerStyle: { icon: type === 'marker' ? R.markerIcon( newItem( 'markers', {} ).icon ) : crosshair },
				snappable: false
			} );
			notice( i18n.drawHint, 'sticky' );
		}
	}

	function stopDraw() {
		map.pm.disableDraw();
		drawingType = null;
		$( map.getContainer() ).removeClass( 'is-drawing' );
		$( '[data-admaps-draw]' ).removeClass( 'is-active' );
		hideNotice();
	}

	function addItem( type, item, quiet ) {
		state[ type ].push( item );
		buildLayer( type, item );
		if ( ! quiet ) {
			select( type, item.id );
			refreshLegend();
			sync();
		}
	}

	$( '[data-admaps-draw]' ).on( 'click', function () {
		var type = this.getAttribute( 'data-admaps-draw' );
		if ( drawingType === type ) {
			stopDraw();
		} else {
			startDraw( type );
		}
	} );

	map.on( 'pm:create', function ( e ) {
		var type = drawingType;
		map.removeLayer( e.layer );
		stopDraw();

		if ( type === 'polygon' ) {
			var ring = e.layer.getLatLngs()[ 0 ];
			addItem( 'polygons', newItem( 'polygons', {
				points: ring.map( function ( ll ) {
					ll = ll.wrap();
					return [ round( ll.lat ), round( ll.lng ) ];
				} )
			} ) );
		} else if ( type === 'circle' || type === 'marker' ) {
			var ll = e.layer.getLatLng().wrap();
			var listType = type === 'circle' ? 'circles' : 'markers';
			addItem( listType, newItem( listType, { lat: round( ll.lat ), lng: round( ll.lng ) } ) );
		}
	} );

	map.on( 'click', function () {
		if ( ! drawingType && selected ) {
			select( null );
		}
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && drawingType ) {
			stopDraw();
		}
	} );

	/* ---------- search ---------- */

	var $search = $( '#admaps-search-input' );
	var $results = $( '#admaps-search-results' );
	var searchResults = [];

	function search() {
		var q = $search.val().trim();
		if ( ! q ) {
			return;
		}
		$results.prop( 'hidden', false ).html( '<li class="admaps-search__status">' + esc( i18n.searching ) + '</li>' );

		var url = cfg.geocodeUrl + '?format=jsonv2&limit=5&q=' + encodeURIComponent( q ) + '&accept-language=' + encodeURIComponent( cfg.locale || 'en' );
		window.fetch( url, { headers: { Accept: 'application/json' } } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( res.status );
				}
				return res.json();
			} )
			.then( function ( rows ) {
				searchResults = rows || [];
				if ( ! searchResults.length ) {
					$results.html( '<li class="admaps-search__status">' + esc( i18n.noResults ) + '</li>' );
					return;
				}
				$results.html( searchResults.map( function ( row, i ) {
					return '<li><button type="button" class="admaps-search__go" data-index="' + i + '">' + esc( row.display_name ) + '</button>' +
						'<span class="admaps-search__add"><button type="button" class="button button-small" data-add="markers" data-index="' + i + '">+ ' + esc( i18n.marker ) + '</button>' +
						'<button type="button" class="button button-small" data-add="circles" data-index="' + i + '">+ ' + esc( i18n.circle ) + '</button></span></li>';
				} ).join( '' ) );
			} )
			.catch( function () {
				$results.html( '<li class="admaps-search__status">' + esc( i18n.searchError ) + '</li>' );
			} );
	}

	function goTo( row ) {
		var bb = row.boundingbox;
		if ( bb && bb.length === 4 ) {
			map.fitBounds( [ [ +bb[ 0 ], +bb[ 2 ] ], [ +bb[ 1 ], +bb[ 3 ] ] ], { maxZoom: 16 } );
		} else {
			map.setView( [ +row.lat, +row.lon ], 15 );
		}
	}

	$( '#admaps-search-button' ).on( 'click', search );

	$results.on( 'click', '.admaps-search__go', function () {
		goTo( searchResults[ this.getAttribute( 'data-index' ) ] );
		$results.prop( 'hidden', true );
	} );

	$results.on( 'click', '[data-add]', function () {
		var row = searchResults[ this.getAttribute( 'data-index' ) ];
		var type = this.getAttribute( 'data-add' );
		$results.prop( 'hidden', true );
		var place = String( row.display_name || '' ).split( ',' ).slice( 0, 2 ).join( ',' ).trim();
		addItem( type, newItem( type, { lat: round( +row.lat ), lng: round( +row.lon ), name: place || undefined } ) );
		var item = state[ type ][ state[ type ].length - 1 ];
		map.fitBounds( R.itemBounds( type, item ), { padding: [ 40, 40 ], maxZoom: 14 } );
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.admaps-search' ).length ) {
			$results.prop( 'hidden', true );
		}
	} );

	// Enter in any editor field must not submit the post form.
	$( '#admaps-editor' ).on( 'keydown', 'input', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			if ( this.id === 'admaps-search-input' ) {
				search();
			}
		}
	} );

	/* ---------- import ---------- */

	$( '#admaps-import-file' ).on( 'change', function () {
		var file = this.files && this.files[ 0 ];
		this.value = '';
		if ( ! file ) {
			return;
		}
		if ( /\.kmz$/i.test( file.name ) ) {
			notice( i18n.importKmz, 'error' );
			return;
		}

		var reader = new window.FileReader();
		reader.onload = function () {
			var parsed;
			try {
				parsed = window.AdmapsImport.parse( String( reader.result ), file.name );
			} catch ( err ) {
				notice( i18n.importFailed, 'error' );
				return;
			}

			select( null );
			[ 'markers', 'circles', 'polygons' ].forEach( function ( type ) {
				parsed[ type ].forEach( function ( raw ) {
					raw.keepImportedStyle = true;
					addItem( type, newItem( type, raw ), true );
				} );
			} );

			renderList();
			refreshLegend();
			fitAll();
			sync();

			var message = sprintf( i18n.importDone, parsed.markers.length, parsed.circles.length, parsed.polygons.length );
			if ( parsed.skipped ) {
				message += ' ' + sprintf( i18n.importSkipped, parsed.skipped );
			}
			notice( message, 'success' );
		};
		reader.onerror = function () {
			notice( i18n.importFailed, 'error' );
		};
		reader.readAsText( file );
	} );

	/* ---------- settings ---------- */

	$( '#admaps-save-view' ).on( 'click', function () {
		var c = map.getCenter();
		state.settings.center = [ round( c.lat, 6 ), round( c.lng, 6 ) ];
		state.settings.zoom = map.getZoom();
		sync();
		notice( i18n.viewSaved, 'success' );
	} );

	$( '[data-admaps-setting]' ).on( 'change input', function () {
		var key = this.getAttribute( 'data-admaps-setting' );
		state.settings[ key ] = this.type === 'checkbox' ? this.checked : this.value;
		if ( key === 'tiles' ) {
			setTiles();
		}
		if ( key === 'tint' ) {
			setTint();
		}
		if ( key.indexOf( 'legend' ) === 0 ) {
			refreshLegend();
		}
		sync();
	} );

	$( '#post' ).on( 'submit', function () {
		readEditor();
		sync();
	} );

	/* ---------- dynamic locations ---------- */

	var SRC = window.admapsSource || { fields: { repeaters: {}, postTypes: {} }, i18n: {} };
	var $source = $( '#admaps-source' );
	var sourceLayer = L.layerGroup().addTo( map );
	var sourceTimer = null;
	var lastPending = null;
	var sourceRequest = null;
	var sourceFitted = false;

	// Common field names, used to fill in obvious matches.
	var GUESSES = {
		name: [ 'name', 'title', 'location_name', 'depot_name', 'branch_name' ],
		lat: [ 'lat', 'latitude' ],
		lng: [ 'lng', 'lon', 'long', 'longitude' ],
		phone: [ 'phone', 'telephone', 'tel', 'phone_number', 'telephone_number' ],
		address: [ 'address', 'street_address', 'address_1' ],
		postcode: [ 'postcode', 'post_code', 'postal_code', 'zip', 'zipcode' ],
		url: [ 'url', 'link', 'website', 'page' ],
		radius: [ 'radius', 'coverage_radius', 'coverage', 'miles' ]
	};

	function sourceValues() {
		return {
			type: $( '#admaps-source-type' ).val(),
			location: $( '#admaps-source-location' ).val(),
			radius: $( '#admaps-source-radius-mode' ).val()
		};
	}

	function toggleSourceFields() {
		var values = sourceValues();
		$source.find( '[data-show]' ).each( function () {
			var show = this.getAttribute( 'data-show' ).split( ' ' ).every( function ( rule ) {
				var parts = rule.split( '=' );
				return parts[ 1 ].split( '|' ).indexOf( values[ parts[ 0 ] ] ) !== -1;
			} );
			this.hidden = ! show;
		} );
	}

	function availableFields() {
		var values = sourceValues();
		if ( values.type === 'repeater' ) {
			var repeater = SRC.fields.repeaters[ $( '#admaps-source-field' ).val() ];
			return repeater ? repeater.subs : [];
		}
		if ( values.type === 'posts' ) {
			return SRC.fields.postTypes[ $( '#admaps-source-post-type' ).val() ] || [];
		}
		return [];
	}

	function refreshFieldLists( guess ) {
		var option = function ( f ) {
			return '<option value="' + esc( f.name ) + '">' + esc( f.label ) + '</option>';
		};
		$( '#admaps-source-repeaters' ).html( Object.keys( SRC.fields.repeaters ).map( function ( name ) {
			return option( { name: name, label: SRC.fields.repeaters[ name ].label } );
		} ).join( '' ) );

		var fields = availableFields();
		$( '#admaps-source-fieldlist' ).html( fields.map( option ).join( '' ) );

		var posts = sourceValues().type === 'posts';
		$( '#admaps-source-f-name' ).attr( 'placeholder', posts ? SRC.i18n.postType : '' );
		$( '#admaps-source-f-url' ).attr( 'placeholder', posts ? SRC.i18n.postUrl : '' );

		if ( guess !== true ) {
			return;
		}
		// Fill in obvious matches for empty boxes only.
		$source.find( '[data-source-field]' ).each( function () {
			var key = this.getAttribute( 'data-source-field' );
			if ( this.value ) {
				return;
			}
			var match = fields.filter( function ( f ) {
				return key === 'map' ? f.type === 'google_map' : ( GUESSES[ key ] || [] ).indexOf( f.name.toLowerCase() ) !== -1;
			} )[ 0 ];
			if ( match && ! ( posts && key === 'name' ) ) {
				this.value = match.name;
			}
		} );
	}

	function sourceStatus( text, kind ) {
		$( '#admaps-source-status' ).text( text || '' ).attr( 'data-kind', kind || '' );
	}

	function drawSourcePreview( result ) {
		sourceLayer.clearLayers();
		sourcePreview = { markers: result.markers || [], circles: result.circles || [] };

		sourcePreview.circles.forEach( function ( item ) {
			R.circleLayer( $.extend( {}, item, { fill_opacity: item.fill_opacity * 0.6, stroke_opacity: item.stroke_opacity * 0.6 } ) )
				.setStyle( { interactive: false } )
				.addTo( sourceLayer );
		} );
		sourcePreview.markers.forEach( function ( item ) {
			var marker = R.markerLayer( item, { opacity: 0.7, keyboard: false } ).addTo( sourceLayer );
			if ( item.label ) {
				marker.bindTooltip( esc( item.label ), { direction: 'top' } );
			}
			marker.on( 'click', function ( e ) {
				if ( drawingType || map.pm.globalDrawModeEnabled() ) {
					return;
				}
				L.DomEvent.stopPropagation( e );
				var html = ( item.popup || '' ) + ( item.directions ? R.directionsLink( item, cfg ) : '' );
				if ( html ) {
					L.popup( R.popupOptions( item, { minWidth: POPUP_SIZE.min, maxWidth: POPUP_SIZE.max } ), marker )
						.setLatLng( marker.getLatLng() ).setContent( html ).openOn( map );
				}
			} );
		} );

		if ( ! sourceFitted && sourcePreview.markers.length && ! allBounds().isValid() ) {
			sourceFitted = true;
			map.fitBounds( sourceLayer.getLayers().reduce( function ( b, layer ) {
				return b.extend( layer.getBounds ? layer.getBounds() : layer.getLatLng() );
			}, L.latLngBounds( [] ) ), { padding: [ 30, 30 ], maxZoom: 13 } );
		}
		refreshLegend();
	}

	function loadSourcePreview() {
		var values = sourceValues();
		if ( sourceRequest ) {
			sourceRequest.abort();
		}
		if ( ! values.type ) {
			drawSourcePreview( {} );
			sourceStatus( '' );
			return;
		}
		sourceStatus( SRC.i18n.loading );
		sourceRequest = $.post( window.ajaxurl, $source.find( ':input' ).serialize() + '&' + $.param( {
			action: 'admaps_source_preview',
			nonce: SRC.nonce,
			post_id: $( '#post_ID' ).val()
		} ) ).done( function ( response ) {
			var data = response && response.data;
			if ( ! response || ! response.success || ! data ) {
				sourceStatus( SRC.i18n.failed, 'error' );
				return;
			}
			drawSourcePreview( data );
			if ( data.current ) {
				sourceStatus( SRC.i18n.current );
				return;
			}
			if ( data.error ) {
				sourceStatus( data.error, 'error' );
				return;
			}

			var notes  = [];
			var failed = data.failed || [];
			if ( data.pending ) {
				notes.push( sprintf( SRC.i18n.pending, data.pending ) );
			}
			if ( failed.length ) {
				notes.push( sprintf( SRC.i18n.notFound, failed.slice( 0, 10 ).join( ', ' ) + ( failed.length > 10 ? ' ' + sprintf( SRC.i18n.more, failed.length - 10 ) : '' ) ) );
			}
			if ( data.skipped ) {
				notes.push( sprintf( $source.find( '[name="admaps_source[geocode]"]:checkbox' ).prop( 'checked' ) ? SRC.i18n.skipped : SRC.i18n.noLatLng, data.skipped ) );
			}
			var lead = data.markers.length ? sprintf( SRC.i18n.showing, data.markers.length ) : ( data.pending ? '' : SRC.i18n.none );
			sourceStatus( [ lead ].concat( notes ).join( ' ' ).trim(), ! data.markers.length && ! data.pending ? 'error' : ( notes.length ? 'warning' : 'success' ) );

			// Keep going while lookups are making progress.
			if ( data.pending && data.pending !== lastPending ) {
				lastPending = data.pending;
				sourceTimer = window.setTimeout( loadSourcePreview, 1000 );
			} else {
				lastPending = null;
			}
		} ).fail( function ( xhr, status ) {
			if ( status !== 'abort' ) {
				sourceStatus( SRC.i18n.failed, 'error' );
			}
		} );
	}

	function scheduleSourcePreview() {
		lastPending = null;
		window.clearTimeout( sourceTimer );
		sourceTimer = window.setTimeout( loadSourcePreview, 500 );
	}

	if ( $source.length ) {
		$source.on( 'change', 'select', function () {
			toggleSourceFields();
			refreshFieldLists( this.id === 'admaps-source-type' || this.id === 'admaps-source-post-type' );
		} );
		$source.on( 'change', '#admaps-source-field', function () {
			refreshFieldLists( true );
		} );
		$source.on( 'input change', ':input', scheduleSourcePreview );
		$source.find( '.admaps-color-field' ).wpColorPicker( {
			change: scheduleSourcePreview,
			clear: scheduleSourcePreview
		} );
		toggleSourceFields();
		refreshFieldLists();
		loadSourcePreview();

		$( '#admaps-source-rebuild' ).on( 'click', function () {
			var $button = $( this ).prop( 'disabled', true );
			var $built  = $( '#admaps-source-built' ).text( SRC.i18n.building );
			$.post( window.ajaxurl, {
				action: 'admaps_source_rebuild',
				nonce: SRC.nonce,
				post_id: $( '#post_ID' ).val()
			} ).done( function ( response ) {
				var data = response && response.success && response.data;
				$built.text( ! data ? SRC.i18n.failed : ( data.per_page ? SRC.i18n.perPage : sprintf( SRC.i18n.rebuilt, data.count ) ) );
			} ).fail( function () {
				$built.text( SRC.i18n.failed );
			} ).always( function () {
				$button.prop( 'disabled', false );
			} );
		} );
	}

	/* ---------- boot ---------- */

	setTiles();
	setTint();
	[ 'markers', 'circles', 'polygons' ].forEach( function ( type ) {
		state[ type ].forEach( function ( item ) {
			buildLayer( type, item );
		} );
	} );
	fitAll();
	renderList();
	refreshLegend();
	sync();

	if ( 'ResizeObserver' in window ) {
		new window.ResizeObserver( function () {
			map.invalidateSize();
		} ).observe( document.getElementById( 'admaps-editor-map' ) );
	}
}( jQuery ) );
