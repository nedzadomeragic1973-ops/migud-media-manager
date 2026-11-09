<?php
/**
 * Media library integration: assets, folder summary and the AJAX query filter.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the folder UI wherever the media modal is loaded and applies the
 * folder filter to media library queries.
 */
class Migud_MM_Library {

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue_media' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_upload_screen' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_ajax_query' ) );
		add_action( 'add_attachment', array( __CLASS__, 'assign_upload' ) );
	}

	/**
	 * Media uploader (async-upload.php, action upload-attachment): assigns the
	 * new attachment to the folder that was active when the upload started.
	 * The uploader sends it as `migud_mm_folder` ('none' for "Unassigned", which
	 * stays unassigned); when it sends nothing ("All Media"), the default
	 * upload folder setting applies. REST uploads, sideloads, media-new.php
	 * and programmatic inserts are not affected.
	 *
	 * @param int $attachment_id New attachment ID.
	 */
	public static function assign_upload( $attachment_id ) {
		if ( ! wp_doing_ajax() || ! isset( $_POST['action'] ) || 'upload-attachment' !== $_POST['action'] || ! check_ajax_referer( 'media-form', false, false ) ) {
			return;
		}

		// Legacy adapter: an uploader loaded before the update still sends the former parameter name; the new one wins.
		$field = isset( $_POST[ Migud_MM_Taxonomy::NAME ] ) ? Migud_MM_Taxonomy::NAME : Migud_MM_Legacy::QUERY_VAR;

		if ( isset( $_POST[ $field ] ) ) {
			$folder     = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			$folder_ids = Migud_MM_Taxonomy::is_digits( $folder ) ? Migud_MM_Taxonomy::filter_folder_ids( array( (int) $folder ) ) : array();
		} else {
			$default    = (int) Migud_MM_Settings::get( 'upload_folder' );
			$folder_ids = $default ? array( $default ) : array();
		}

		if ( $folder_ids && Migud_MM_Taxonomy::can_assign( $attachment_id ) ) {
			wp_set_object_terms( (int) $attachment_id, $folder_ids, Migud_MM_Taxonomy::NAME, true );
		}
	}

	/**
	 * Asset version for cache busting. Released builds use the plugin
	 * version (bump MIGUD_MM_VERSION with every release); local and development
	 * environments or SCRIPT_DEBUG append the file modification time, so
	 * edited files are reloaded while unchanged ones stay cached.
	 *
	 * @param string $file Path relative to the plugin directory.
	 * @return string
	 */
	public static function asset_version( $file ) {
		$dev = in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

		if ( $dev && file_exists( MIGUD_MM_PATH . $file ) ) {
			return MIGUD_MM_VERSION . '.' . filemtime( MIGUD_MM_PATH . $file );
		}

		return MIGUD_MM_VERSION;
	}

	/**
	 * Registers the shared design token stylesheet (dependency of the
	 * plugin styles; every token is scoped to the .migud-mm class).
	 */
	public static function register_tokens() {
		if ( ! wp_style_is( 'migud-mm-tokens', 'registered' ) ) {
			wp_register_style( 'migud-mm-tokens', MIGUD_MM_URL . 'assets/css/migud-mm-tokens.css', array(), self::asset_version( 'assets/css/migud-mm-tokens.css' ) );
		}
	}

	/**
	 * Registers the shared UI components (dropdown script, component styles) used by the folder
	 * sidebar and the settings page.
	 */
	public static function register_picker() {
		self::register_tokens();
		if ( ! wp_style_is( 'migud-mm-components', 'registered' ) ) {
			wp_register_style( 'migud-mm-components', MIGUD_MM_URL . 'assets/css/migud-mm-components.css', array( 'migud-mm-tokens' ), self::asset_version( 'assets/css/migud-mm-components.css' ) );
		}
		if ( ! wp_script_is( 'migud-mm-picker', 'registered' ) ) {
			wp_register_script( 'migud-mm-picker', MIGUD_MM_URL . 'assets/js/migud-mm-picker.js', array( 'jquery', 'underscore', 'wp-i18n' ), self::asset_version( 'assets/js/migud-mm-picker.js' ), true );
			wp_set_script_translations( 'migud-mm-picker', 'migud-media-manager', MIGUD_MM_PATH . 'languages' );
		}
	}

	/**
	 * Enqueues the folder store and the modal folder filter. Runs inside
	 * wp_enqueue_media(), so it covers the media library, the Customizer,
	 * the block editor and every meta box that opens the media modal.
	 */
	public static function enqueue_media() {
		if ( ! Migud_MM_Taxonomy::can_read() || wp_script_is( 'migud-mm-media-filter', 'enqueued' ) ) {
			return;
		}

		self::register_tokens();
		wp_enqueue_style( 'migud-mm-media', MIGUD_MM_URL . 'assets/css/migud-mm-media.css', array( 'migud-mm-tokens' ), self::asset_version( 'assets/css/migud-mm-media.css' ) );
		wp_enqueue_script(
			'migud-mm-media-filter',
			MIGUD_MM_URL . 'assets/js/migud-mm-media-filter.js',
			array( 'jquery', 'underscore', 'backbone', 'media-views', 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ),
			self::asset_version( 'assets/js/migud-mm-media-filter.js' ),
			true
		);
		wp_set_script_translations( 'migud-mm-media-filter', 'migud-media-manager', MIGUD_MM_PATH . 'languages' );
		wp_localize_script(
			'migud-mm-media-filter',
			'migudMMMediaSettings',
			array_merge(
				self::get_summary(),
				array(
					'canManage'    => Migud_MM_Taxonomy::can_manage(),
					'treeExpanded' => (bool) Migud_MM_Settings::get( 'tree_expanded' ),
					'restoreLast'  => (bool) Migud_MM_Settings::get( 'restore_last_folder' ),
					'storageKey'   => 'migud-mm-last-folder-' . get_current_blog_id() . '-' . get_current_user_id(),
					// Legacy adapter: value stored by the former scripts, moved to storageKey once.
					'legacyKey'    => Migud_MM_Legacy::STORAGE_KEY_PREFIX . get_current_blog_id() . '-' . get_current_user_id(),
				)
			)
		);
	}

	/**
	 * Enqueues the folder sidebar and the upload progress panel on the media library screen. The script
	 * only activates when the grid view fires `wp-media-grid-ready`.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_upload_screen( $hook_suffix ) {
		// The folder store is only registered once wp_enqueue_media() ran (grid mode); the list view has no sidebar.
		if ( 'upload.php' !== $hook_suffix || ! Migud_MM_Taxonomy::can_read() || ! wp_script_is( 'migud-mm-media-filter', 'registered' ) ) {
			return;
		}

		self::register_tokens();
		self::register_picker();
		wp_enqueue_style( 'migud-mm-components' );
		wp_enqueue_style( 'migud-mm-media', MIGUD_MM_URL . 'assets/css/migud-mm-media.css', array( 'migud-mm-tokens', 'migud-mm-components' ), self::asset_version( 'assets/css/migud-mm-media.css' ) );
		wp_enqueue_script(
			'migud-mm-folder-sidebar',
			MIGUD_MM_URL . 'assets/js/migud-mm-folder-sidebar.js',
			array( 'migud-mm-media-filter', 'migud-mm-picker' ),
			self::asset_version( 'assets/js/migud-mm-folder-sidebar.js' ),
			true
		);
		wp_set_script_translations( 'migud-mm-folder-sidebar', 'migud-media-manager', MIGUD_MM_PATH . 'languages' );
		wp_enqueue_script(
			'migud-mm-upload-progress',
			MIGUD_MM_URL . 'assets/js/migud-mm-upload-progress.js',
			array( 'migud-mm-media-filter' ),
			self::asset_version( 'assets/js/migud-mm-upload-progress.js' ),
			true
		);
		wp_set_script_translations( 'migud-mm-upload-progress', 'migud-media-manager', MIGUD_MM_PATH . 'languages' );
		add_action( 'admin_footer', array( 'Migud_MM_Icons', 'print_sprite' ) );
	}

	/**
	 * Folders (in the sort mode of the current user) plus the "All Media" and "Unassigned" counts.
	 *
	 * @return array
	 */
	public static function get_summary() {
		return array(
			'folders'    => Migud_MM_Taxonomy::get_folders(),
			'sort'       => Migud_MM_Taxonomy::sort_mode(),
			'total'      => self::count_attachments(),
			'unassigned' => self::count_attachments( array( self::tax_clause( 'none' ) ) ),
		);
	}

	/**
	 * Counts library attachments with the same status rules as the media
	 * library query (wp_ajax_query_attachments).
	 *
	 * @param array $tax_query Optional tax query.
	 * @return int
	 */
	private static function count_attachments( $tax_query = array() ) {
		$status = 'inherit';
		if ( current_user_can( get_post_type_object( 'attachment' )->cap->read_private_posts ) ) {
			$status .= ',private';
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => $status,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Indexed term relationship lookup.
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Builds the tax query clause for a folder filter value.
	 *
	 * @param mixed $value '' / null = all media, 'none' = unassigned, ID = folder.
	 * @return array|null
	 */
	public static function tax_clause( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( 'none' === $value ) {
			return array(
				'taxonomy' => Migud_MM_Taxonomy::NAME,
				'operator' => 'NOT EXISTS',
			);
		}

		if ( Migud_MM_Taxonomy::is_digits( $value ) && (int) $value > 0 ) {
			return array(
				'taxonomy'         => Migud_MM_Taxonomy::NAME,
				'field'            => 'term_id',
				'terms'            => array( (int) $value ),
				'include_children' => false,
			);
		}

		return null;
	}

	/**
	 * Adds a folder clause to existing query args (keeps other tax queries).
	 *
	 * @param array      $tax_query Existing tax query.
	 * @param array|null $clause    Folder clause.
	 * @return array
	 */
	public static function merge_tax_query( $tax_query, $clause ) {
		$tax_query = is_array( $tax_query ) ? $tax_query : array();
		if ( $clause ) {
			$tax_query[] = $clause;
			if ( ! isset( $tax_query['relation'] ) ) {
				$tax_query['relation'] = 'AND';
			}
		}
		return $tax_query;
	}

	/**
	 * Media grid / media modal: core whitelists the taxonomy query var; it is
	 * replaced by an explicit term_id clause because the query var expects slugs.
	 *
	 * @param array $query Query args.
	 * @return array
	 */
	public static function filter_ajax_query( $query ) {
		if ( ! array_key_exists( Migud_MM_Taxonomy::NAME, $query ) ) {
			$legacy = self::legacy_query_value();
			if ( null === $legacy ) {
				return $query;
			}
			$query[ Migud_MM_Taxonomy::NAME ] = $legacy;
		}

		$clause = self::tax_clause( $query[ Migud_MM_Taxonomy::NAME ] );
		unset( $query[ Migud_MM_Taxonomy::NAME ] );

		if ( $clause ) {
			$query['tax_query'] = self::merge_tax_query( isset( $query['tax_query'] ) ? $query['tax_query'] : array(), $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Folder filter.
		}

		return $query;
	}

	/**
	 * Legacy adapter: folder filter value sent as the former query prop
	 * `fmm_folder` (media library loaded before the update). Core drops it from the query args
	 * because it is no longer a registered query var, so it is read from the
	 * request of the query-attachments call.
	 *
	 * @return string|null Value, or null when it was not sent.
	 */
	private static function legacy_query_value() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter value of core's query-attachments request, which has no nonce; capabilities are checked by core.
		$value = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) && isset( $_REQUEST['query'][ Migud_MM_Legacy::QUERY_VAR ] ) && is_scalar( $_REQUEST['query'][ Migud_MM_Legacy::QUERY_VAR ] )
			? sanitize_text_field( wp_unslash( $_REQUEST['query'][ Migud_MM_Legacy::QUERY_VAR ] ) )
			: null;
		// phpcs:enable

		return $value;
	}
}
