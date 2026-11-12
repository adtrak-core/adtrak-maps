<?php
/**
 * Postcode and address lookup for dynamic locations.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a position for locations that have a postcode or address but no
 * latitude and longitude. UK postcodes use postcodes.io (in batches); other
 * addresses use OpenStreetMap's Nominatim, at most one request a second as its
 * usage policy asks. Results are saved, so each postcode or address is only
 * looked up once; ones that can't be found are tried again a day later.
 *
 * Lookups only happen when locations are built from the admin side: a few
 * while saving, the rest in the background, or more in the editor. A build
 * triggered by someone viewing the site (CACHED) only uses saved results and
 * never contacts either service.
 */
class Geocoder {

	const OPTION      = 'admaps_geocode';
	const HOOK        = 'admaps_geocode';
	const CACHED      = 0;
	const SAVE        = 1;
	const ADMIN       = 8;
	const BACKGROUND  = 40;
	const RETRY_AFTER = 86400;
	const MAX_ENTRIES = 5000;

	/**
	 * Saved results: key => array{p: ?array, t: int}.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Lookups answered (found or not found) during this request.
	 *
	 * @var int
	 */
	public static $answered = 0;

	/**
	 * What to look up for a location, or null when there's nothing to go on.
	 *
	 * @param string $postcode Postcode.
	 * @param string $address  Address.
	 * @param string $country  Two-letter country code to search in, or ''.
	 * @return array|null {key, postcode, query, country}
	 */
	public static function request( string $postcode, string $address, string $country ): ?array {
		$postcode = trim( $postcode );
		$address  = trim( preg_replace( '/\s*[\r\n]+\s*/', ', ', $address ) );
		if ( '' === $postcode && '' === $address ) {
			return null;
		}

		$query = $address;
		if ( '' !== $postcode && false === stripos( str_replace( ' ', '', $address ), str_replace( ' ', '', $postcode ) ) ) {
			$query = trim( $address . ', ' . $postcode, ', ' );
		}

		$uk = '';
		if ( in_array( $country, array( '', 'gb' ), true ) && preg_match( '/^([A-Z]{1,2}[0-9][A-Z0-9]?)\s*([0-9][A-Z]{2})$/i', $postcode, $m ) ) {
			$uk = strtoupper( $m[1] . ' ' . $m[2] );
		}

		return array(
			'key'      => '' !== $uk ? 'uk:' . $uk : 'q:' . md5( strtolower( $query ) . '|' . $country ),
			'postcode' => $uk,
			'query'    => $query,
			'country'  => $country,
		);
	}

	/**
	 * A saved result.
	 *
	 * @param array $request From request().
	 * @return array|false|null [lat, lng], false when it couldn't be found, null when not looked up yet.
	 */
	public static function find( array $request ) {
		$entry = self::cache()[ $request['key'] ] ?? null;
		if ( ! $entry ) {
			return null;
		}
		if ( is_array( $entry['p'] ) ) {
			return $entry['p'];
		}
		return $entry['t'] > time() - self::RETRY_AFTER ? false : null;
	}

	/**
	 * Look up anything not already saved.
	 *
	 * @param array[] $requests From request().
	 * @param int     $budget   CACHED (no requests at all), or the most Nominatim requests to make (SAVE, ADMIN or BACKGROUND).
	 */
	public static function lookup( array $requests, int $budget ): void {
		if ( $budget <= self::CACHED ) {
			return;
		}
		$todo = array();
		foreach ( $requests as $request ) {
			if ( null === self::find( $request ) ) {
				$todo[ $request['key'] ] = $request;
			}
		}
		if ( ! $todo ) {
			return;
		}

		$slow = array();
		$uk   = array();
		foreach ( $todo as $key => $request ) {
			if ( '' !== $request['postcode'] ) {
				$uk[ $request['postcode'] ] = $request;
			} else {
				$slow[ $key ] = $request;
			}
		}

		foreach ( array_chunk( $uk, 100, true ) as $chunk ) {
			$found = self::postcodes_io( array_keys( $chunk ) );
			if ( null === $found ) {
				break;
			}
			foreach ( $chunk as $postcode => $request ) {
				if ( isset( $found[ $postcode ] ) ) {
					self::store( $request['key'], $found[ $postcode ] );
				} else {
					// Not a current postcode (new or retired): try the address instead.
					$slow[ $request['key'] ] = $request;
				}
			}
		}

		$wait = $budget > self::SAVE;
		foreach ( array_slice( $slow, 0, max( 0, $budget ), true ) as $key => $request ) {
			$result = self::nominatim( $request['query'], $request['country'], $wait );
			if ( null === $result ) {
				break;
			}
			self::store( $key, $result );
		}

		self::save();
	}

	/**
	 * Look up UK postcodes in one request.
	 *
	 * @param string[] $postcodes Normalised postcodes (up to 100).
	 * @return array|null Postcode => [lat, lng] for the ones found, or null when the service didn't answer.
	 */
	private static function postcodes_io( array $postcodes ): ?array {
		if ( get_transient( 'admaps_geocode_wait_postcodes' ) ) {
			return null;
		}
		$response = wp_remote_post(
			'https://api.postcodes.io/postcodes',
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => self::user_agent(),
				),
				'body'    => wp_json_encode( array( 'postcodes' => array_values( $postcodes ) ) ),
			)
		);
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! isset( $body['result'] ) || ! is_array( $body['result'] ) ) {
			set_transient( 'admaps_geocode_wait_postcodes', 1, 10 * MINUTE_IN_SECONDS );
			return null;
		}

		$found = array();
		foreach ( $body['result'] as $item ) {
			$pos = isset( $item['result'] ) && is_array( $item['result'] )
				? Map_Data::sanitize_latlng( array( $item['result']['latitude'] ?? null, $item['result']['longitude'] ?? null ) )
				: null;
			$key = strtoupper( (string) ( $item['query'] ?? '' ) );
			if ( $pos && in_array( $key, $postcodes, true ) ) {
				$found[ $key ] = $pos;
			}
		}
		return $found;
	}

	/**
	 * Look up one address with Nominatim.
	 *
	 * @param string $query   Address.
	 * @param string $country Two-letter country code, or ''.
	 * @param bool   $wait    Wait for the one-a-second limit rather than giving up.
	 * @return array|false|null [lat, lng], false when not found, null when it couldn't ask.
	 */
	private static function nominatim( string $query, string $country, bool $wait ) {
		if ( get_transient( 'admaps_geocode_wait_nominatim' ) ) {
			return null;
		}
		$next = (float) get_option( 'admaps_geocode_next', 0 );
		$now  = microtime( true );
		if ( $next > $now ) {
			if ( ! $wait || $next - $now > 2 ) {
				return null;
			}
			usleep( (int) ( ( $next - $now ) * 1000000 ) );
		}
		update_option( 'admaps_geocode_next', microtime( true ) + 1.1, false );

		$args = array(
			'q'      => $query,
			'format' => 'jsonv2',
			'limit'  => 1,
		);
		if ( '' !== $country ) {
			$args['countrycodes'] = $country;
		}
		$response = wp_remote_get(
			add_query_arg( rawurlencode_deep( $args ), 'https://nominatim.openstreetmap.org/search' ),
			array(
				'timeout' => $wait ? 10 : 4,
				'headers' => array(
					'User-Agent' => self::user_agent(),
					'Referer'    => home_url( '/' ),
				),
			)
		);
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) ) {
			set_transient( 'admaps_geocode_wait_nominatim', 1, 15 * MINUTE_IN_SECONDS );
			return null;
		}
		if ( ! $body ) {
			return false;
		}
		return Map_Data::sanitize_latlng( array( $body[0]['lat'] ?? null, $body[0]['lon'] ?? null ) ) ?? false;
	}

	/**
	 * Identify the plugin and site, as the lookup services ask.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		return 'AdtrakMaps/' . ADMAPS_VERSION . ' (WordPress; ' . home_url( '/' ) . ')';
	}

	/**
	 * Saved results.
	 *
	 * @return array
	 */
	private static function cache(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache;
	}

	/**
	 * Remember a result.
	 *
	 * @param string      $key    Request key.
	 * @param array|false $result [lat, lng], or false when not found.
	 */
	private static function store( string $key, $result ): void {
		self::cache();
		self::$cache[ $key ] = array(
			'p' => is_array( $result ) ? $result : null,
			't' => time(),
		);
		++self::$answered;
	}

	/**
	 * Write saved results, keeping the newest MAX_ENTRIES.
	 */
	private static function save(): void {
		if ( null === self::$cache ) {
			return;
		}
		if ( count( self::$cache ) > self::MAX_ENTRIES ) {
			uasort(
				self::$cache,
				static function ( $a, $b ) {
					return $b['t'] <=> $a['t'];
				}
			);
			self::$cache = array_slice( self::$cache, 0, self::MAX_ENTRIES, true );
		}
		update_option( self::OPTION, self::$cache, false );
	}

	/**
	 * Forget every saved result.
	 */
	public static function clear(): void {
		self::$cache = array();
		delete_option( self::OPTION );
	}

	/**
	 * Look up positions in the background shortly.
	 *
	 * @param int $context Post just saved (for maps reading "the page showing the map"), or 0.
	 * @param int $delay   Seconds from now.
	 */
	public static function queue( int $context, int $delay = 0 ): void {
		if ( ! wp_next_scheduled( self::HOOK, array( $context ) ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, array( $context ) );
		}
	}
}
