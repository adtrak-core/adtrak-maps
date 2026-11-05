<?php
/**
 * The map post type and its list screen.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Registers admaps_map and customises the "Maps" list.
 */
class Post_Type {

	const NAME = 'admaps_map';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );

		if ( is_admin() ) {
			add_filter( 'manage_' . self::NAME . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . self::NAME . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
			add_action( 'admin_post_admaps_duplicate', array( __CLASS__, 'duplicate' ) );
			add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'list_assets' ) );
		}
	}

	/**
	 * Register the post type.
	 */
	public static function register(): void {
		register_post_type(
			self::NAME,
			array(
				'labels'              => array(
					'name'               => __( 'Maps', 'admaps' ),
					'singular_name'      => __( 'Map', 'admaps' ),
					'menu_name'          => __( 'Maps', 'admaps' ),
					'all_items'          => __( 'All Maps', 'admaps' ),
					'add_new'            => __( 'Add New Map', 'admaps' ),
					'add_new_item'       => __( 'Add New Map', 'admaps' ),
					'edit_item'          => __( 'Edit Map', 'admaps' ),
					'new_item'           => __( 'New Map', 'admaps' ),
					'search_items'       => __( 'Search Maps', 'admaps' ),
					'not_found'          => __( 'No maps found.', 'admaps' ),
					'not_found_in_trash' => __( 'No maps found in Trash.', 'admaps' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-location-alt',
				'menu_position'       => 26,
				'supports'            => array( 'title' ),
				/**
				 * Filter the capability type for maps. "page" means anyone who can edit pages can edit maps.
				 *
				 * @param string $type Capability type.
				 */
				'capability_type'     => apply_filters( 'admaps_capability_type', 'page' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( array $columns ): array {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['admaps_shortcode'] = __( 'Shortcode', 'admaps' );
		$columns['admaps_items']     = __( 'Contents', 'admaps' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( string $column, int $post_id ): void {
		if ( 'admaps_shortcode' === $column ) {
			self::shortcode_field( $post_id );
		} elseif ( 'admaps_items' === $column ) {
			$data = Map_Data::get( $post_id );
			echo esc_html(
				sprintf(
					/* translators: 1: pins, 2: radii, 3: areas */
					__( '%1$d pins, %2$d radii, %3$d areas', 'admaps' ),
					count( $data['markers'] ),
					count( $data['circles'] ),
					count( $data['polygons'] )
				)
			);
		}
	}

	/**
	 * Read-only shortcode input with a copy button.
	 *
	 * @param int $post_id Map ID.
	 */
	public static function shortcode_field( int $post_id ): void {
		$shortcode = sprintf( '[admaps id="%d"]', $post_id );
		printf(
			'<span class="admaps-shortcode"><input type="text" readonly value="%1$s" aria-label="%2$s" onfocus="this.select()"><button type="button" class="button button-small admaps-copy" data-copy="%1$s">%3$s</button></span>',
			esc_attr( $shortcode ),
			esc_attr__( 'Shortcode', 'admaps' ),
			esc_html__( 'Copy', 'admaps' )
		);
	}

	/**
	 * Small admin stylesheet/script for the list and edit screens.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function list_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::NAME !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'admaps-admin', ADMAPS_URL . 'assets/css/admin.css', array(), ADMAPS_VERSION );
		wp_enqueue_script( 'admaps-admin-common', ADMAPS_URL . 'assets/js/admin-common.js', array(), ADMAPS_VERSION, true );
		wp_localize_script(
			'admaps-admin-common',
			'admapsCommon',
			array( 'copied' => __( 'Copied', 'admaps' ) )
		);
	}

	/**
	 * Duplicate / Export row actions.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( self::NAME !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$duplicate = wp_nonce_url(
			admin_url( 'admin-post.php?action=admaps_duplicate&post=' . $post->ID ),
			'admaps_duplicate_' . $post->ID
		);
		$export    = wp_nonce_url(
			admin_url( 'admin-post.php?action=admaps_export&ids=' . $post->ID ),
			'admaps_export'
		);

		$actions['admaps_duplicate'] = '<a href="' . esc_url( $duplicate ) . '">' . esc_html__( 'Duplicate', 'admaps' ) . '</a>';
		$actions['admaps_export']    = '<a href="' . esc_url( $export ) . '">' . esc_html__( 'Export', 'admaps' ) . '</a>';
		return $actions;
	}

	/**
	 * Handle the duplicate action.
	 */
	public static function duplicate(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'admaps_duplicate_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || self::NAME !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot duplicate this map.', 'admaps' ), 403 );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => self::NAME,
				'post_status' => 'draft',
				/* translators: %s: original map title */
				'post_title'  => sprintf( __( '%s (copy)', 'admaps' ), $post->post_title ),
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		Map_Data::save( $new_id, Map_Data::get( $post_id ) );

		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
		exit;
	}

	/**
	 * Update messages without "View post" links (maps have no front-end URL).
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	public static function messages( array $messages ): array {
		$messages[ self::NAME ] = array(
			0  => '',
			1  => __( 'Map updated.', 'admaps' ),
			4  => __( 'Map updated.', 'admaps' ),
			5  => __( 'Map restored from revision.', 'admaps' ),
			6  => __( 'Map published.', 'admaps' ),
			7  => __( 'Map saved.', 'admaps' ),
			8  => __( 'Map submitted.', 'admaps' ),
			9  => __( 'Map scheduled.', 'admaps' ),
			10 => __( 'Map draft updated.', 'admaps' ),
		);
		return $messages;
	}
}
