<?php
/**
 * Removes data on uninstall when the "Remove data" setting is ticked.
 *
 * @package Admaps
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$admaps_settings = get_option( 'admaps_settings', array() );

if ( is_array( $admaps_settings ) && ! empty( $admaps_settings['delete_on_uninstall'] ) ) {
	$admaps_ids = get_posts(
		array(
			'post_type'      => 'admaps_map',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $admaps_ids as $admaps_id ) {
		wp_delete_post( $admaps_id, true );
	}
	delete_option( 'admaps_settings' );
}

// Saved postcode lookups are only a cache, so they always go.
delete_option( 'admaps_geocode' );
delete_option( 'admaps_geocode_next' );
wp_clear_scheduled_hook( 'admaps_geocode' );
