<?php
/**
 * Built dynamic locations: stored when locations are saved, read when a map is shown.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * A map's dynamic pins and radii are built once and stored, so showing a map
 * never reads the repeater or the location posts. They are rebuilt when:
 *
 * - the map is saved (or imported, or duplicated);
 * - an ACF options page is saved, for maps reading an options page;
 * - a location post is saved, trashed or deleted, for maps reading that post type;
 * - a page is saved, for maps reading a repeater on that page;
 * - background postcode lookups finish;
 * - someone clicks "Rebuild locations" on the map.
 *
 * Results are stored on the map (meta `_admaps_locations`), or on the page for
 * maps reading "the page showing the map" (meta `_admaps_locations_{map ID}`).
 * Each is keyed by the source settings, so a shortcode pointing the map at a
 * different repeater gets its own copy.
 */
class Locations {

	const META         = '_admaps_locations';
	const MAX_VARIANTS = 10;

	/**
	 * Saves seen this request: arrays of [kind, post ID, post type].
	 *
	 * @var array
	 */
	private static $changes = array();

	/**
	 * Maps whose own settings were saved this request.
	 *
	 * @var array<int, true>
	 */
	private static $maps_saved = array();

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'acf/save_post', array( __CLASS__, 'acf_saved' ), 20 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'post_saved' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_saved' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_saved' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'rebuild_changed' ) );
		add_action( Geocoder::HOOK, array( __CLASS__, 'background' ) );
	}

	/**
	 * A map's dynamic locations, from storage (built now if there's nothing stored).
	 *
	 * @param int   $map_id  Map ID.
	 * @param array $source  Source settings, after any shortcode override.
	 * @param int   $context Post showing the map.
	 * @return array As Sources::items().
	 */
	public static function get( int $map_id, array $source, int $context ): array {
		list( $holder, $key ) = self::holder( $map_id, $source, $context );
		if ( '' === $source['type'] || ! $holder ) {
			return Sources::items( $source, $context );
		}
		$entries = self::entries( $holder, $key );
		$hash    = self::hash( $source );
		if ( isset( $entries[ $hash ]['result'] ) ) {
			return $entries[ $hash ]['result'];
		}
		// Someone is viewing the site: use saved lookups only, and never queue any.
		return self::build( $holder, $key, $source, $context, Geocoder::CACHED, false );
	}

	/**
	 * Rebuild a map from scratch ("Rebuild locations").
	 *
	 * @param int $map_id Map ID.
	 * @return array As Sources::items(), plus `per_page` for maps built on each page.
	 */
	public static function rebuild( int $map_id ): array {
		self::forget( $map_id );
		$source = Map_Data::get( $map_id )['source'];
		if ( self::per_page( $source ) ) {
			return array( 'per_page' => true );
		}
		return self::build( $map_id, self::META, $source, 0, Geocoder::ADMIN );
	}

	/**
	 * When the map's own locations were last built.
	 *
	 * @param int $map_id Map ID.
	 * @return int Timestamp, or 0.
	 */
	public static function built_at( int $map_id ): int {
		$source = Map_Data::get( $map_id )['source'];
		return (int) ( self::entries( $map_id, self::META )[ self::hash( $source ) ]['built'] ?? 0 );
	}

	/**
	 * Where a source's result is stored.
	 *
	 * @param int   $map_id  Map ID.
	 * @param array $source  Source settings.
	 * @param int   $context Post showing the map.
	 * @return array [holder post ID or 0, meta key]
	 */
	private static function holder( int $map_id, array $source, int $context ): array {
		if ( ! self::per_page( $source ) ) {
			return array( $map_id, self::META );
		}
		return $context && get_post( $context ) ? array( $context, self::META . '_' . $map_id ) : array( 0, '' );
	}

	/**
	 * Does this source read a repeater on the page showing the map?
	 *
	 * @param array $source Source settings.
	 * @return bool
	 */
	private static function per_page( array $source ): bool {
		return 'repeater' === $source['type'] && 'current' === $source['location'];
	}

	/**
	 * Storage key for a source's settings (and plugin version, as the output can change between versions).
	 *
	 * @param array $source Source settings.
	 * @return string
	 */
	private static function hash( array $source ): string {
		return md5( ADMAPS_VERSION . wp_json_encode( $source ) );
	}

	/**
	 * Stored results on a post.
	 *
	 * @param int    $holder Post ID.
	 * @param string $key    Meta key.
	 * @return array Hash => {source, result, built}.
	 */
	private static function entries( int $holder, string $key ): array {
		$stored = get_post_meta( $holder, $key, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Build and store a source's locations.
	 *
	 * @param int    $holder  Post to store on.
	 * @param string $key     Meta key.
	 * @param array  $source  Source settings.
	 * @param int    $context Post showing the map.
	 * @param int    $budget  Slow lookups allowed (see Geocoder).
	 * @param bool   $queue   Finish pending lookups in the background.
	 * @return array As Sources::items().
	 */
	private static function build( int $holder, string $key, array $source, int $context, int $budget, bool $queue = true ): array {
		$per_page = self::per_page( $source );
		$result   = Sources::items( $source, $per_page ? $context : 0, $budget );

		$entries                        = self::entries( $holder, $key );
		$entries[ self::hash( $source ) ] = array(
			'source' => $source,
			'result' => $result,
			'built'  => time(),
		);
		if ( count( $entries ) > self::MAX_VARIANTS ) {
			uasort(
				$entries,
				static function ( $a, $b ) {
					return $b['built'] <=> $a['built'];
				}
			);
			$entries = array_slice( $entries, 0, self::MAX_VARIANTS, true );
		}
		update_post_meta( $holder, $key, wp_slash( $entries ) );

		if ( $queue && $result['pending'] ) {
			Geocoder::queue( $per_page ? $context : 0 );
		}
		return $result;
	}

	/**
	 * Drop everything stored for a map.
	 *
	 * @param int $map_id Map ID.
	 */
	private static function forget( int $map_id ): void {
		delete_post_meta( $map_id, self::META );
		delete_post_meta_by_key( self::META . '_' . $map_id );
	}

	/**
	 * After an ACF save: an options page, or fields saved without the post itself
	 * (e.g. a front-end ACF form).
	 *
	 * @param int|string $post_id What ACF saved.
	 */
	public static function acf_saved( $post_id ): void {
		if ( is_numeric( $post_id ) ) {
			$post = get_post( (int) $post_id );
			if ( $post ) {
				self::post_saved( $post->ID, $post );
			}
		} elseif ( 0 !== strpos( (string) $post_id, 'user_' ) && 0 !== strpos( (string) $post_id, 'term_' ) ) {
			self::$changes['options'] = array( 'options', 0, '' );
		}
	}

	/**
	 * After any post is saved, trashed or restored.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function post_saved( int $post_id, \WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( Post_Type::NAME === $post->post_type ) {
			self::forget( $post_id );
			self::$maps_saved[ $post_id ] = true;
			return;
		}
		self::$changes[ 'post' . $post_id ] = array( 'post', $post_id, $post->post_type );
	}

	/**
	 * After a post is deleted for good.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function post_deleted( int $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( Post_Type::NAME === $post->post_type ) {
			self::forget( $post_id );
			unset( self::$maps_saved[ $post_id ] );
			return;
		}
		self::post_saved( $post_id, $post );
	}

	/**
	 * A map's settings were saved (editor, import or duplicate): start again.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function meta_saved( $meta_id, $object_id, $meta_key ): void {
		if ( Map_Data::META_KEY === $meta_key ) {
			self::forget( (int) $object_id );
			self::$maps_saved[ (int) $object_id ] = true;
		}
	}

	/**
	 * At the end of a request, rebuild whatever the saves affected (once, however many posts were saved).
	 */
	public static function rebuild_changed(): void {
		if ( ! self::$changes && ! self::$maps_saved ) {
			return;
		}
		$changes          = self::$changes;
		$saved            = self::$maps_saved;
		self::$changes    = array();
		self::$maps_saved = array();

		foreach ( self::dynamic_maps() as $map_id => $source ) {
			if ( isset( $saved[ $map_id ] ) ) {
				if ( ! self::per_page( $source ) ) {
					self::build( $map_id, self::META, $source, 0, Geocoder::SAVE );
				}
				continue;
			}
			foreach ( self::entries( $map_id, self::META ) as $entry ) {
				if ( isset( $entry['source'] ) && self::affected( $entry['source'], $changes ) ) {
					self::build( $map_id, self::META, $entry['source'], 0, Geocoder::SAVE );
				}
			}
			foreach ( $changes as $change ) {
				if ( 'post' !== $change[0] ) {
					continue;
				}
				foreach ( self::entries( $change[1], self::META . '_' . $map_id ) as $entry ) {
					if ( isset( $entry['source'] ) ) {
						self::build( $change[1], self::META . '_' . $map_id, $entry['source'], $change[1], Geocoder::SAVE );
					}
				}
			}
		}
	}

	/**
	 * Does a save affect a source?
	 *
	 * @param array $source  Source settings.
	 * @param array $changes Saves seen.
	 * @return bool
	 */
	private static function affected( array $source, array $changes ): bool {
		foreach ( $changes as $change ) {
			if ( 'options' === $change[0] ) {
				if ( 'repeater' === $source['type'] && 'options' === $source['location'] ) {
					return true;
				}
			} elseif ( 'repeater' === $source['type'] ) {
				if ( 'post' === $source['location'] && $change[1] === $source['post_id'] ) {
					return true;
				}
			} elseif ( $change[2] === $source['post_type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Background run after postcode lookups were queued: rebuild anything still
	 * waiting on a lookup, and come back later while any are still waiting.
	 *
	 * @param int $context Page whose own locations are waiting, or 0.
	 */
	public static function background( $context = 0 ): void {
		$context  = (int) $context;
		$pending  = 0;
		$answered = Geocoder::$answered;
		foreach ( array_keys( self::dynamic_maps() ) as $map_id ) {
			$holders = array( array( $map_id, self::META ) );
			if ( $context ) {
				$holders[] = array( $context, self::META . '_' . $map_id );
			}
			foreach ( $holders as list( $holder, $key ) ) {
				foreach ( self::entries( $holder, $key ) as $entry ) {
					if ( ! empty( $entry['result']['pending'] ) && isset( $entry['source'] ) ) {
						$pending += self::build( $holder, $key, $entry['source'], $context, Geocoder::BACKGROUND, false )['pending'];
					}
				}
			}
		}
		// Lookups only stay pending while a service is busy or down, so keep trying, slowly when stuck.
		if ( $pending ) {
			Geocoder::queue( $context, Geocoder::$answered > $answered ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Maps with dynamic locations.
	 *
	 * @return array Map ID => source settings.
	 */
	private static function dynamic_maps(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Type::NAME,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$source = Map_Data::get( (int) $id )['source'];
			if ( '' !== $source['type'] ) {
				$out[ (int) $id ] = $source;
			}
		}
		return $out;
	}
}
