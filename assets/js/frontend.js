/**
 * Adtrak Maps front end. Also provides window.AdmapsRender, which the admin
 * editor reuses so its preview matches the live map.
 */
( function () {
	'use strict';

	if ( typeof window.L === 'undefined' ) {
		return;
	}
	var L = window.L;

	var HEX = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;

	function color( value, fallback ) {
		return HEX.test( value || '' ) ? value : fallback;
	}

	function escapeHtml( str ) {
		return String( str == null ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function hexToRgba( hex, alpha ) {
		hex = color( hex, '#000000' ).slice( 1 );
		if ( hex.length === 3 ) {
			hex = hex.replace( /(.)/g, '$1$1' );
		}
		var n = parseInt( hex, 16 );
		return 'rgba(' + ( ( n >> 16 ) & 255 ) + ',' + ( ( n >> 8 ) & 255 ) + ',' + ( n & 255 ) + ',' + alpha + ')';
	}

	function pinSvg( fill, w, h ) {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 36" width="' + w + '" height="' + h + '" aria-hidden="true" focusable="false">' +
			'<path d="M12 .5C5.65.5.5 5.65.5 12c0 8.6 11.5 23.5 11.5 23.5S23.5 20.6 23.5 12C23.5 5.65 18.35.5 12 .5z" fill="' + fill + '" stroke="rgba(0,0,0,.35)"/>' +
			'<circle cx="12" cy="12" r="4.5" fill="#fff"/></svg>';
	}

	function markerIcon( icon ) {
		icon = icon || {};
		var w = Math.max( 8, parseInt( icon.width, 10 ) || 28 );

		if ( icon.type === 'image' && icon.url ) {
			var h = Math.max( 8, parseInt( icon.height, 10 ) || w );
			var centre = icon.anchor === 'center';
			var top = centre ? -h / 2 : -h;
			return L.icon( {
				iconUrl: icon.url,
				iconSize: [ w, h ],
				iconAnchor: [ w / 2, centre ? h / 2 : h ],
				popupAnchor: [ 0, top ],
				tooltipAnchor: [ 0, top ],
				className: 'admaps-icon-image'
			} );
		}

		var ph = Math.round( w * 1.5 );
		return L.divIcon( {
			className: 'admaps-pin',
			html: pinSvg( color( icon.color, '#e63946' ), w, ph ),
			iconSize: [ w, ph ],
			iconAnchor: [ w / 2, ph ],
			popupAnchor: [ 0, -ph ],
			tooltipAnchor: [ 0, -ph ]
		} );
	}

	function shapeStyle( item ) {
		var weight = parseInt( item.weight, 10 );
		return {
			color: color( item.stroke, '#1d3557' ),
			weight: isNaN( weight ) ? 2 : weight,
			opacity: item.stroke_opacity == null ? 1 : +item.stroke_opacity,
			fillColor: color( item.fill, '#457b9d' ),
			fillOpacity: item.fill_opacity == null ? 0.25 : +item.fill_opacity,
			stroke: weight !== 0
		};
	}

	function tileLayer( tiles ) {
		tiles = tiles || {};
		var opts = {
			attribution: tiles.attribution || '',
			maxZoom: tiles.maxZoom || 19
		};
		if ( tiles.subdomains ) {
			opts.subdomains = tiles.subdomains;
		}
		return L.tileLayer( tiles.url || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', opts );
	}

	function markerLayer( item, options ) {
		return L.marker( [ item.lat, item.lng ], L.extend( {
			icon: markerIcon( item.icon ),
			alt: item.label || '',
			keyboard: true
		}, options || {} ) );
	}

	function circleLayer( item ) {
		return L.circle( [ item.lat, item.lng ], L.extend( { radius: +item.radius }, shapeStyle( item ) ) );
	}

	function polygonLayer( item ) {
		return L.polygon( item.points, shapeStyle( item ) );
	}

	function itemBounds( type, item ) {
		if ( type === 'polygons' ) {
			return L.latLngBounds( item.points );
		}
		var ll = L.latLng( item.lat, item.lng );
		return type === 'circles' ? ll.toBounds( item.radius * 2 ) : L.latLngBounds( ll, ll );
	}

	function swatch( type, item ) {
		if ( type === 'markers' ) {
			var icon = item.icon || {};
			if ( icon.type === 'image' && icon.url ) {
				return '<img class="admaps-legend__icon" src="' + escapeHtml( icon.url ) + '" alt="">';
			}
			return '<span class="admaps-legend__icon">' + pinSvg( color( icon.color, '#e63946' ), 12, 18 ) + '</span>';
		}
		var style = shapeStyle( item );
		return '<span class="admaps-legend__swatch' + ( type === 'circles' ? ' admaps-legend__swatch--circle' : '' ) + '" style="background:' +
			hexToRgba( style.fillColor, Math.max( style.fillOpacity, 0.15 ) ) + ';border-color:' + style.color + '"></span>';
	}

	/**
	 * Legend listing labelled items. Returns null when there is nothing to show.
	 */
	function legendControl( cfg ) {
		var s = cfg.settings || {};
		var rows = [];
		var seen = {};

		[ 'polygons', 'circles', 'markers' ].forEach( function ( type ) {
			( cfg[ type ] || [] ).forEach( function ( item ) {
				if ( ! item.legend || ! item.label ) {
					return;
				}
				var key = type === 'markers'
					? 'm|' + item.label + '|' + JSON.stringify( item.icon || {} )
					: 's|' + item.label + '|' + item.stroke + '|' + item.fill + '|' + item.fill_opacity;
				if ( seen[ key ] ) {
					return;
				}
				seen[ key ] = true;
				rows.push( '<li>' + swatch( type, item ) + '<span>' + escapeHtml( item.label ) + '</span></li>' );
			} );
		} );

		if ( ! rows.length ) {
			return null;
		}

		var control = L.control( { position: s.legend_position || 'bottomright' } );
		control.onAdd = function () {
			var div = L.DomUtil.create( 'div', 'admaps-legend' );
			div.innerHTML = ( s.legend_title ? '<p class="admaps-legend__title">' + escapeHtml( s.legend_title ) + '</p>' : '' ) +
				'<ul>' + rows.join( '' ) + '</ul>';
			L.DomEvent.disableClickPropagation( div );
			L.DomEvent.disableScrollPropagation( div );
			return div;
		};
		return control;
	}

	/**
	 * Bubble widths: the item's own values, else the map's (from Settings or the map's Appearance box).
	 */
	function popupOptions( item, popup ) {
		popup = popup || {};
		var min = +item.popup_min || +popup.minWidth || 50;
		var max = +item.popup_max || +popup.maxWidth || 320;
		return { minWidth: min, maxWidth: Math.max( min, max ) };
	}

	/**
	 * Google Maps directions to a pin; opens the app on phones.
	 */
	function directionsLink( item, cfg ) {
		var text = ( cfg.labels && cfg.labels.directions ) || 'Get directions';
		var url = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent( item.lat + ',' + item.lng );
		return '<p class="admaps-directions"><a href="' + escapeHtml( url ) + '" target="_blank" rel="noopener">' + escapeHtml( text ) + '</a></p>';
	}

	function bindContent( layer, item, isShape, cfg ) {
		cfg = cfg || {};
		if ( item.label ) {
			layer.bindTooltip( escapeHtml( item.label ), { direction: 'top', sticky: !! isShape } );
		}
		var html = item.popup || '';
		if ( ! isShape && item.directions ) {
			html += directionsLink( item, cfg );
		}
		if ( html ) {
			var opts = popupOptions( item, cfg.popup );
			opts.admapsMin = opts.minWidth;
			opts.admapsMax = opts.maxWidth;
			layer.bindPopup( html, opts );
		}
	}

	/**
	 * Keep bubbles inside small maps (e.g. on phones) whatever widths were set.
	 */
	function fitPopupsToMap( map ) {
		map.on( 'popupopen', function ( e ) {
			var o = e.popup.options;
			if ( ! o.admapsMax ) {
				return;
			}
			var room = Math.max( 120, map.getSize().x - 60 );
			var max = Math.min( o.admapsMax, room );
			var min = Math.min( o.admapsMin, max );
			if ( o.maxWidth !== max || o.minWidth !== min ) {
				o.maxWidth = max;
				o.minWidth = min;
				e.popup.update();
			}
		} );
	}

	/**
	 * On phones and tablets, one finger scrolls the page and two fingers pan and zoom the map.
	 */
	function twoFingerTouch( map, el, cfg ) {
		if ( ! cfg.twoFinger || ! window.matchMedia || ! window.matchMedia( '(hover: none) and (pointer: coarse)' ).matches ) {
			return;
		}
		map.dragging.disable();

		var hint = L.DomUtil.create( 'div', 'admaps-touch-hint', el );
		hint.setAttribute( 'aria-hidden', 'true' );
		hint.textContent = ( cfg.labels && cfg.labels.twoFinger ) || 'Use two fingers to move the map';
		var timer;

		function hide() {
			el.classList.remove( 'is-touch-hint' );
		}

		el.addEventListener( 'touchmove', function ( e ) {
			if ( e.touches.length !== 1 || ( e.target.closest && e.target.closest( '.leaflet-popup, .leaflet-control' ) ) ) {
				hide();
				return;
			}
			el.classList.add( 'is-touch-hint' );
			clearTimeout( timer );
			timer = setTimeout( hide, 1500 );
		}, { passive: true } );
		el.addEventListener( 'touchstart', function ( e ) {
			if ( e.touches.length > 1 ) {
				hide();
				map.dragging.enable();
			}
		}, { passive: true } );
		[ 'touchend', 'touchcancel' ].forEach( function ( type ) {
			el.addEventListener( type, function ( e ) {
				if ( ! e.touches.length ) {
					map.dragging.disable();
				}
			}, { passive: true } );
		} );
	}

	/**
	 * Build a live map inside el.
	 */
	function createMap( el, cfg ) {
		var s = cfg.settings || {};
		var map = L.map( el, {
			zoomControl: s.zoom_control !== false,
			scrollWheelZoom: !! s.scroll_zoom
		} );

		tileLayer( cfg.tiles ).addTo( map );
		fitPopupsToMap( map );
		twoFingerTouch( map, el, cfg );

		var bounds = L.latLngBounds( [] );
		var markers = cfg.markers || [];
		var group = s.cluster && L.markerClusterGroup && markers.length > 1
			? L.markerClusterGroup( { showCoverageOnHover: false, maxClusterRadius: 50 } )
			: L.layerGroup();

		( cfg.polygons || [] ).forEach( function ( item ) {
			var layer = polygonLayer( item ).addTo( map );
			bindContent( layer, item, true, cfg );
			bounds.extend( itemBounds( 'polygons', item ) );
		} );
		( cfg.circles || [] ).forEach( function ( item ) {
			var layer = circleLayer( item ).addTo( map );
			bindContent( layer, item, true, cfg );
			bounds.extend( itemBounds( 'circles', item ) );
		} );
		markers.forEach( function ( item ) {
			var layer = markerLayer( item );
			bindContent( layer, item, false, cfg );
			group.addLayer( layer );
			bounds.extend( [ item.lat, item.lng ] );
		} );
		group.addTo( map );

		if ( s.legend ) {
			var legend = legendControl( cfg );
			if ( legend ) {
				legend.addTo( map );
			}
		}

		function resetView() {
			if ( s.fit_bounds !== false && bounds.isValid() ) {
				map.fitBounds( bounds, { padding: [ 24, 24 ], maxZoom: 15 } );
			} else {
				map.setView( s.center || [ 54, -2.5 ], s.zoom == null ? 6 : s.zoom );
			}
		}
		resetView();

		if ( ! s.scroll_zoom ) {
			map.on( 'focus click', function () {
				map.scrollWheelZoom.enable();
			} );
			map.on( 'blur mouseout', function () {
				map.scrollWheelZoom.disable();
			} );
		}

		// Maps inside tabs, accordions or popups start at zero size.
		if ( 'ResizeObserver' in window ) {
			var wasHidden = el.clientWidth === 0 || el.clientHeight === 0;
			new window.ResizeObserver( function () {
				var hidden = el.clientWidth === 0 || el.clientHeight === 0;
				map.invalidateSize();
				if ( wasHidden && ! hidden ) {
					resetView();
				}
				wasHidden = hidden;
			} ).observe( el );
		}

		return map;
	}

	function init( el ) {
		if ( el.admapsMap ) {
			return;
		}
		var cfg;
		try {
			cfg = JSON.parse( el.getAttribute( 'data-admaps' ) );
		} catch ( e ) {
			return;
		}
		el.admapsMap = createMap( el, cfg );
		el.dispatchEvent( new window.CustomEvent( 'admaps:ready', { bubbles: true, detail: { map: el.admapsMap } } ) );
	}

	function boot() {
		var els = document.querySelectorAll( '.admaps-map[data-admaps]' );
		if ( ! els.length ) {
			return;
		}
		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( els, init );
			return;
		}
		var io = new window.IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					io.unobserve( entry.target );
					init( entry.target );
				}
			} );
		}, { rootMargin: '300px 0px' } );
		Array.prototype.forEach.call( els, function ( el ) {
			io.observe( el );
		} );
	}

	window.AdmapsRender = {
		escapeHtml: escapeHtml,
		markerIcon: markerIcon,
		shapeStyle: shapeStyle,
		tileLayer: tileLayer,
		markerLayer: markerLayer,
		circleLayer: circleLayer,
		polygonLayer: polygonLayer,
		itemBounds: itemBounds,
		legendControl: legendControl,
		createMap: createMap,
		init: init,
		boot: boot
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
