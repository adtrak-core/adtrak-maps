/**
 * Parses KML and GeoJSON (e.g. Scribble Maps exports) into Adtrak Maps items.
 * window.AdmapsImport.parse( text, filename ) => { markers, circles, polygons, skipped }
 */
( function () {
	'use strict';

	var L = window.L;

	function result() {
		return { markers: [], circles: [], polygons: [], skipped: 0 };
	}

	/* ---------- helpers ---------- */

	function sanitizeHtml( html ) {
		if ( ! html ) {
			return '';
		}
		var doc = new window.DOMParser().parseFromString( '<body>' + html + '</body>', 'text/html' );
		var bad = doc.body.querySelectorAll( 'script,style,iframe,object,embed,link,meta,form,input,button,textarea,select,base' );
		Array.prototype.forEach.call( bad, function ( el ) {
			el.parentNode.removeChild( el );
		} );
		Array.prototype.forEach.call( doc.body.querySelectorAll( '*' ), function ( el ) {
			Array.prototype.slice.call( el.attributes ).forEach( function ( attr ) {
				var name = attr.name.toLowerCase();
				var value = attr.value.replace( /[\s\u0000-\u001f]/g, '' ).toLowerCase();
				if ( name.indexOf( 'on' ) === 0 || ( ( name === 'href' || name === 'src' || name === 'xlink:href' ) && /^(javascript|data|vbscript):/.test( value ) ) ) {
					el.removeAttribute( attr.name );
				}
			} );
		} );
		return doc.body.innerHTML.trim();
	}

	function plainText( str ) {
		if ( ! str ) {
			return '';
		}
		var doc = new window.DOMParser().parseFromString( '<body>' + str + '</body>', 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 );
	}

	function hex( value ) {
		if ( typeof value !== 'string' ) {
			return null;
		}
		value = value.trim();
		if ( /^#?[0-9a-f]{6}$/i.test( value ) ) {
			return ( value.charAt( 0 ) === '#' ? value : '#' + value ).toLowerCase();
		}
		if ( /^#[0-9a-f]{3}$/i.test( value ) ) {
			return value.toLowerCase();
		}
		var rgb = value.match( /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i );
		if ( rgb ) {
			return '#' + [ rgb[ 1 ], rgb[ 2 ], rgb[ 3 ] ].map( function ( n ) {
				return ( '0' + Math.min( 255, +n ).toString( 16 ) ).slice( -2 );
			} ).join( '' );
		}
		return null;
	}

	function num( value, fallback ) {
		var n = parseFloat( value );
		return isNaN( n ) ? fallback : n;
	}

	function round( n ) {
		return Math.round( n * 1e7 ) / 1e7;
	}

	/**
	 * Circles exported as many-sided polygons become real radii again.
	 */
	function detectCircle( points ) {
		var ring = points.slice();
		if ( ring.length > 1 && ring[ 0 ][ 0 ] === ring[ ring.length - 1 ][ 0 ] && ring[ 0 ][ 1 ] === ring[ ring.length - 1 ][ 1 ] ) {
			ring.pop();
		}
		if ( ring.length < 16 ) {
			return null;
		}
		var lat = 0;
		var lng = 0;
		ring.forEach( function ( p ) {
			lat += p[ 0 ];
			lng += p[ 1 ];
		} );
		var centre = L.latLng( lat / ring.length, lng / ring.length );
		var distances = ring.map( function ( p ) {
			return centre.distanceTo( p );
		} );
		var min = Math.min.apply( null, distances );
		var max = Math.max.apply( null, distances );
		var mean = distances.reduce( function ( a, b ) {
			return a + b;
		}, 0 ) / distances.length;

		if ( mean <= 0 || ( max - min ) / mean > 0.03 ) {
			return null;
		}
		return { lat: round( centre.lat ), lng: round( centre.lng ), radius: Math.round( mean * 10 ) / 10 };
	}

	function guessUnit( metres ) {
		var miles = metres / 1609.344;
		var km = metres / 1000;
		if ( Math.abs( km - Math.round( km * 2 ) / 2 ) < Math.abs( miles - Math.round( miles * 2 ) / 2 ) ) {
			return 'km';
		}
		return 'mi';
	}

	function addPolygon( out, points, base ) {
		var last = points[ points.length - 1 ];
		if ( points.length > 3 && points[ 0 ][ 0 ] === last[ 0 ] && points[ 0 ][ 1 ] === last[ 1 ] ) {
			points = points.slice( 0, -1 );
		}
		if ( points.length < 3 ) {
			out.skipped++;
			return;
		}
		var circle = detectCircle( points );
		if ( circle ) {
			out.circles.push( L.extend( {}, base, circle, { unit: guessUnit( circle.radius ) } ) );
		} else {
			out.polygons.push( L.extend( {}, base, { points: points } ) );
		}
	}

	/* ---------- KML ---------- */

	function kmlColor( value ) {
		value = ( value || '' ).trim().replace( /^#/, '' );
		if ( ! /^[0-9a-f]{8}$/i.test( value ) ) {
			return null;
		}
		return {
			hex: '#' + value.substr( 6, 2 ) + value.substr( 4, 2 ) + value.substr( 2, 2 ),
			alpha: Math.round( parseInt( value.substr( 0, 2 ), 16 ) / 255 * 100 ) / 100
		};
	}

	function all( el, name ) {
		return Array.prototype.slice.call( el.getElementsByTagNameNS( '*', name ) );
	}

	function child( el, name ) {
		for ( var i = 0; el && i < el.children.length; i++ ) {
			if ( el.children[ i ].localName === name ) {
				return el.children[ i ];
			}
		}
		return null;
	}

	function text( el, name ) {
		var c = child( el, name );
		return c ? ( c.textContent || '' ).trim() : '';
	}

	function readKmlStyle( el ) {
		var style = {};
		if ( ! el ) {
			return style;
		}
		var icon = child( el, 'IconStyle' );
		var line = child( el, 'LineStyle' );
		var poly = child( el, 'PolyStyle' );
		var c;

		if ( icon ) {
			c = kmlColor( text( icon, 'color' ) );
			if ( c ) {
				style.iconColor = c.hex;
			}
			var href = text( child( icon, 'Icon' ), 'href' );
			if ( href ) {
				style.iconHref = href;
			}
			style.iconScale = num( text( icon, 'scale' ), 1 );
		}
		if ( line ) {
			c = kmlColor( text( line, 'color' ) );
			if ( c ) {
				style.stroke = c.hex;
				style.stroke_opacity = c.alpha;
			}
			if ( text( line, 'width' ) ) {
				style.weight = Math.round( num( text( line, 'width' ), 2 ) );
			}
		}
		if ( poly ) {
			c = kmlColor( text( poly, 'color' ) );
			if ( c ) {
				style.fill = c.hex;
				style.fill_opacity = c.alpha;
			}
			if ( text( poly, 'fill' ) === '0' ) {
				style.fill_opacity = 0;
			}
			if ( text( poly, 'outline' ) === '0' ) {
				style.weight = 0;
			}
		}
		return style;
	}

	function kmlCoords( str ) {
		return ( str || '' ).trim().split( /\s+/ ).map( function ( tuple ) {
			var parts = tuple.split( ',' );
			var lng = parseFloat( parts[ 0 ] );
			var lat = parseFloat( parts[ 1 ] );
			return isNaN( lat ) || isNaN( lng ) ? null : [ round( lat ), round( lng ) ];
		} ).filter( Boolean );
	}

	function parseKml( textContent ) {
		var doc = new window.DOMParser().parseFromString( textContent, 'application/xml' );
		if ( doc.getElementsByTagName( 'parsererror' ).length ) {
			throw new Error( 'Invalid KML' );
		}

		var styles = {};
		all( doc, 'Style' ).forEach( function ( el ) {
			if ( el.getAttribute( 'id' ) ) {
				styles[ '#' + el.getAttribute( 'id' ) ] = readKmlStyle( el );
			}
		} );
		all( doc, 'StyleMap' ).forEach( function ( el ) {
			var id = el.getAttribute( 'id' );
			if ( ! id ) {
				return;
			}
			all( el, 'Pair' ).forEach( function ( pair ) {
				if ( text( pair, 'key' ) === 'normal' ) {
					var url = text( pair, 'styleUrl' );
					styles[ '#' + id ] = styles[ url.replace( /^[^#]*/, '' ) ] || readKmlStyle( child( pair, 'Style' ) );
				}
			} );
		} );

		var out = result();

		all( doc, 'Placemark' ).forEach( function ( pm ) {
			var styleUrl = text( pm, 'styleUrl' ).replace( /^[^#]*/, '' );
			var style = L.extend( {}, styles[ styleUrl ] || {}, readKmlStyle( child( pm, 'Style' ) ) );
			var label = plainText( text( pm, 'name' ) );
			var popup = sanitizeHtml( text( pm, 'description' ) );
			var shapeBase = {
				label: label,
				popup: popup,
				legend: !! label,
				stroke: style.stroke,
				stroke_opacity: style.stroke_opacity,
				weight: style.weight,
				fill: style.fill,
				fill_opacity: style.fill_opacity
			};

			all( pm, 'Point' ).forEach( function ( point ) {
				var c = kmlCoords( text( point, 'coordinates' ) )[ 0 ];
				if ( ! c ) {
					out.skipped++;
					return;
				}
				var icon = { type: 'svg', color: style.iconColor || '#e63946', width: 28 };
				if ( style.iconHref && /^https?:\/\//i.test( style.iconHref ) ) {
					var size = Math.round( 32 * ( style.iconScale || 1 ) );
					icon = { type: 'image', url: style.iconHref, width: size, height: size, anchor: 'bottom', color: icon.color };
				}
				out.markers.push( { lat: c[ 0 ], lng: c[ 1 ], label: label, popup: popup, legend: false, icon: icon } );
			} );

			all( pm, 'Polygon' ).forEach( function ( polygon ) {
				var outer = child( polygon, 'outerBoundaryIs' );
				var ring = outer ? all( outer, 'coordinates' )[ 0 ] : null;
				addPolygon( out, kmlCoords( ring ? ring.textContent : '' ), shapeBase );
			} );

			out.skipped += all( pm, 'LineString' ).length;
		} );

		return out;
	}

	/* ---------- GeoJSON ---------- */

	function parseGeoJson( obj ) {
		var out = result();
		var features;

		if ( obj && obj.type === 'FeatureCollection' ) {
			features = obj.features || [];
		} else if ( obj && obj.type === 'Feature' ) {
			features = [ obj ];
		} else if ( obj && obj.type ) {
			features = [ { type: 'Feature', geometry: obj, properties: {} } ];
		} else {
			throw new Error( 'Invalid GeoJSON' );
		}

		features.forEach( function ( feature ) {
			var p = L.extend( {}, feature.properties || {}, ( feature.properties && typeof feature.properties.style === 'object' ) ? feature.properties.style : {} );
			var label = plainText( p.name || p.title || p.label || '' );
			var popup = sanitizeHtml( p.description || p.popup || p.content || p.popupContent || '' );
			var weight = p[ 'stroke-width' ] != null ? p[ 'stroke-width' ] : p.weight;
			var base = {
				label: label,
				popup: popup,
				legend: !! label,
				stroke: hex( p.stroke || p.color || p.strokeColor ) || undefined,
				stroke_opacity: num( p[ 'stroke-opacity' ] != null ? p[ 'stroke-opacity' ] : ( p.opacity != null ? p.opacity : p.strokeOpacity ), undefined ),
				weight: weight != null ? Math.round( num( weight, 2 ) ) : undefined,
				fill: hex( p.fill || p.fillColor ) || undefined,
				fill_opacity: num( p[ 'fill-opacity' ] != null ? p[ 'fill-opacity' ] : p.fillOpacity, undefined )
			};

			( function handle( geom ) {
				if ( ! geom ) {
					out.skipped++;
					return;
				}
				var toLatLng = function ( c ) {
					return [ round( c[ 1 ] ), round( c[ 0 ] ) ];
				};
				switch ( geom.type ) {
					case 'Point':
						var radius = num( p.radius, 0 );
						if ( radius > 0 ) {
							var ll = toLatLng( geom.coordinates );
							out.circles.push( L.extend( {}, base, { lat: ll[ 0 ], lng: ll[ 1 ], radius: radius, unit: guessUnit( radius ) } ) );
						} else {
							var m = toLatLng( geom.coordinates );
							out.markers.push( {
								lat: m[ 0 ],
								lng: m[ 1 ],
								label: label,
								popup: popup,
								legend: false,
								icon: { type: 'svg', color: hex( p[ 'marker-color' ] || p.markerColor || p.color ) || '#e63946', width: 28 }
							} );
						}
						break;
					case 'MultiPoint':
						geom.coordinates.forEach( function ( c ) {
							handle( { type: 'Point', coordinates: c } );
						} );
						break;
					case 'Polygon':
						addPolygon( out, ( geom.coordinates[ 0 ] || [] ).map( toLatLng ), base );
						break;
					case 'MultiPolygon':
						geom.coordinates.forEach( function ( poly ) {
							addPolygon( out, ( poly[ 0 ] || [] ).map( toLatLng ), base );
						} );
						break;
					case 'GeometryCollection':
						( geom.geometries || [] ).forEach( handle );
						break;
					default:
						out.skipped++;
				}
			}( feature.geometry ) );
		} );

		return out;
	}

	function clean( out ) {
		[ 'circles', 'polygons' ].forEach( function ( type ) {
			out[ type ].forEach( function ( item ) {
				Object.keys( item ).forEach( function ( key ) {
					if ( item[ key ] === undefined ) {
						delete item[ key ];
					}
				} );
			} );
		} );
		return out;
	}

	function parse( content, filename ) {
		var name = ( filename || '' ).toLowerCase();
		var trimmed = content.replace( /^\uFEFF/, '' ).trim();
		if ( /\.kml$/.test( name ) || trimmed.charAt( 0 ) === '<' ) {
			return clean( parseKml( trimmed ) );
		}
		return clean( parseGeoJson( JSON.parse( trimmed ) ) );
	}

	window.AdmapsImport = {
		parse: parse,
		sanitizeHtml: sanitizeHtml,
		detectCircle: detectCircle
	};
}() );
