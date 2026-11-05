<?php
/**
 * JSON export/import of maps between sites.
 *
 * @package Admaps
 */

namespace Admaps;

defined( 'ABSPATH' ) || exit;

/**
 * Maps > Import / Export, plus the "Export" row and bulk actions.
 */
class Import_Export {

	const FORMAT   = 'adtrak-maps';
	const MAX_SIZE = 10485760;

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_admaps_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_admaps_import', array( __CLASS__, 'handle_import' ) );
		add_filter( 'bulk_actions-edit-' . Post_Type::NAME, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Post_Type::NAME, array( __CLASS__, 'handle_bulk' ), 10, 3 );
	}

	/**
	 * Add the submenu page.
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::NAME,
			__( 'Import / Export Maps', 'admaps' ),
			__( 'Import / Export', 'admaps' ),
			'edit_pages',
			'admaps-import-export',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Bulk action.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( array $actions ): array {
		$actions['admaps_export'] = __( 'Export', 'admaps' );
		return $actions;
	}

	/**
	 * Bulk export streams the file directly.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Post IDs.
	 * @return string
	 */
	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( 'admaps_export' === $action ) {
			self::send_export( array_map( 'absint', $ids ) );
		}
		return $redirect;
	}

	/**
	 * Export via admin-post.php.
	 */
	public static function handle_export(): void {
		check_admin_referer( 'admaps_export' );

		$ids = array();
		if ( isset( $_REQUEST['ids'] ) && 'all' !== $_REQUEST['ids'] ) {
			$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) ) ) );
		} else {
			$ids = get_posts(
				array(
					'post_type'      => Post_Type::NAME,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
		}

		self::send_export( $ids );
	}

	/**
	 * Output a JSON download and exit.
	 *
	 * @param int[] $ids Map IDs.
	 */
	private static function send_export( array $ids ): void {
		$maps = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || Post_Type::NAME !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$data = Map_Data::get( $id );
			foreach ( $data['markers'] as &$marker ) {
				$marker['icon']['attachment_id'] = 0;
			}
			unset( $marker );

			$maps[] = array(
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'data'   => $data,
			);
		}

		if ( ! $maps ) {
			wp_die( esc_html__( 'No maps to export.', 'admaps' ) );
		}

		$payload = array(
			'format'   => self::FORMAT,
			'version'  => Map_Data::SCHEMA_VERSION,
			'exported' => gmdate( 'c' ),
			'source'   => home_url(),
			'maps'     => $maps,
		);

		$filename = 1 === count( $maps )
			? 'map-' . sanitize_title( $maps[0]['title'] ) . '.json'
			: 'maps-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Handle an uploaded export file.
	 */
	public static function handle_import(): void {
		check_admin_referer( 'admaps_import' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You cannot import maps.', 'admaps' ), 403 );
		}

		$back = admin_url( 'edit.php?post_type=' . Post_Type::NAME . '&page=admaps-import-export' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file array, validated below.
		$file = isset( $_FILES['admaps_file'] ) ? $_FILES['admaps_file'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect( $back, 'error', __( 'No file was uploaded.', 'admaps' ) );
		}
		if ( (int) $file['size'] > self::MAX_SIZE ) {
			self::redirect( $back, 'error', __( 'That file is too large.', 'admaps' ) );
		}

		$payload = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $payload ) || ( $payload['format'] ?? '' ) !== self::FORMAT || empty( $payload['maps'] ) || ! is_array( $payload['maps'] ) ) {
			self::redirect( $back, 'error', __( 'That is not an Adtrak Maps export file. To bring in a KML or GeoJSON file, create a map and use "Import KML / GeoJSON" in the editor.', 'admaps' ) );
		}

		$sideload = ! empty( $_POST['admaps_sideload'] );
		$imported = 0;
		$images   = array();

		foreach ( $payload['maps'] as $map ) {
			if ( ! is_array( $map ) || ! isset( $map['data'] ) || ! is_array( $map['data'] ) ) {
				continue;
			}

			$status = in_array( $map['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $map['status'] : 'draft';
			if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
				$status = 'pending';
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => Post_Type::NAME,
					'post_status' => $status,
					'post_title'  => sanitize_text_field( (string) ( $map['title'] ?? __( 'Imported map', 'admaps' ) ) ),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			$data = $map['data'];
			if ( $sideload && ! empty( $data['markers'] ) && is_array( $data['markers'] ) ) {
				foreach ( $data['markers'] as &$marker ) {
					if ( ! is_array( $marker ) || empty( $marker['icon']['url'] ) || 'image' !== ( $marker['icon']['type'] ?? '' ) ) {
						continue;
					}
					$url = esc_url_raw( (string) $marker['icon']['url'] );
					if ( ! isset( $images[ $url ] ) ) {
						$images[ $url ] = self::sideload( $url );
					}
					if ( $images[ $url ] ) {
						$marker['icon']['attachment_id'] = $images[ $url ];
					}
				}
				unset( $marker );
			}

			Map_Data::save( $post_id, $data );
			++$imported;
		}

		/* translators: %d: number of maps */
		self::redirect( $back, 'success', sprintf( _n( 'Imported %d map.', 'Imported %d maps.', $imported, 'admaps' ), $imported ) );
	}

	/**
	 * Copy a remote image into the media library.
	 *
	 * @param string $url Image URL.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private static function sideload( string $url ): int {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( $url, 0, null, 'id' );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string $url     URL.
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	private static function redirect( string $url, string $type, string $message ): void {
		set_transient( 'admaps_notice_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		$notice = get_transient( 'admaps_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'admaps_notice_' . get_current_user_id() );
		}
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=admaps_export&ids=all' ), 'admaps_export' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export Maps', 'admaps' ); ?></h1>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice[0] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'Export', 'admaps' ); ?></h2>
				<p><?php esc_html_e( 'Download every map on this site as a JSON file. To export particular maps, use the Export link or bulk action on the Maps list.', 'admaps' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export all maps', 'admaps' ); ?></a></p>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Import', 'admaps' ); ?></h2>
				<p><?php esc_html_e( 'Upload a JSON file exported from Adtrak Maps on another site. Each map is created as a new map; nothing is overwritten.', 'admaps' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="admaps_import">
					<?php wp_nonce_field( 'admaps_import' ); ?>
					<p><input type="file" name="admaps_file" accept=".json,application/json" required></p>
					<p><label><input type="checkbox" name="admaps_sideload" value="1" checked> <?php esc_html_e( 'Copy custom pin images into this site\'s media library', 'admaps' ); ?></label></p>
					<?php submit_button( __( 'Import maps', 'admaps' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Moving from Scribble Maps or Google My Maps?', 'admaps' ); ?></h2>
				<p><?php esc_html_e( 'Most maps are quickest to rebuild: use the address search above the map editor, then "+ Pin" or "+ Radius" on a result.', 'admaps' ); ?></p>
				<p><?php esc_html_e( 'If you have a KML or GeoJSON file (Scribble Maps paid plans, Google My Maps, Google Earth), create a map and use "Import KML / GeoJSON" above the editor instead. Pins, areas and their colours are brought across, and circles are turned back into editable radii.', 'admaps' ); ?></p>
			</div>
		</div>
		<?php
	}
}
