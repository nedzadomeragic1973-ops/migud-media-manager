<?php
/**
 * Folder filter for the media library list view.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a folder dropdown to upload.php?mode=list and applies it to the main query.
 */
class Migud_MM_List_Mode {

	/**
	 * Prefix of the bulk actions and result query args.
	 */
	const PREFIX = 'migud_mm_';

	/**
	 * Sanitized filter value of the current request ('' = all).
	 *
	 * @var string
	 */
	private static $current = '';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_main_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_dropdown' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * Adds "Add to folder" / "Remove from folder" option groups to the
	 * native bulk actions dropdown.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$folders = Migud_MM_Taxonomy::get_folder_tree();
		if ( empty( $folders ) || ! Migud_MM_Taxonomy::can_assign_any() ) {
			return $actions;
		}

		// Core prints bulk action labels unescaped, so they are escaped here.
		$add    = array();
		$remove = array();
		foreach ( $folders as $folder ) {
			$add[ self::PREFIX . 'add_' . $folder['id'] ]       = esc_html( Migud_MM_Taxonomy::tree_label( $folder ) );
			$remove[ self::PREFIX . 'remove_' . $folder['id'] ] = esc_html( Migud_MM_Taxonomy::tree_label( $folder ) );
		}

		$actions[ __( 'Add to folder', 'migud-media-manager' ) ]      = $add;
		$actions[ __( 'Remove from folder', 'migud-media-manager' ) ] = $remove;

		return $actions;
	}

	/**
	 * Handles the folder bulk actions. Core has already verified the
	 * bulk-media nonce; every attachment is checked with can_assign().
	 *
	 * @param string $sendback Redirect URL.
	 * @param string $action   Bulk action.
	 * @param int[]  $post_ids Selected attachment IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $sendback, $action, $post_ids ) {
		// Legacy adapter: a list screen loaded before the update still sends the former action names.
		if ( ! preg_match( '/^(?:' . preg_quote( self::PREFIX, '/' ) . '|' . preg_quote( Migud_MM_Legacy::PREFIX, '/' ) . ')(add|remove)_(\d+)$/', (string) $action, $matches ) ) {
			return $sendback;
		}

		$folder = Migud_MM_Taxonomy::get_folder( (int) $matches[2] );
		if ( ! $folder || ! Migud_MM_Taxonomy::can_assign_any() ) {
			return add_query_arg( self::PREFIX . 'bulk_error', 1, $sendback );
		}

		$result = Migud_MM_Taxonomy::assign( $post_ids, array( (int) $folder->term_id ), 'add' === $matches[1] );

		return add_query_arg(
			array(
				self::PREFIX . 'bulk'    => $matches[1],
				self::PREFIX . 'target'  => (int) $folder->term_id,
				self::PREFIX . 'updated' => count( $result['updated'] ),
				self::PREFIX . 'skipped' => count( $result['skipped'] ),
			),
			$sendback
		);
	}

	/**
	 * Result notice after a folder bulk action (display only, no state change).
	 */
	public static function bulk_notice() {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result message after a nonce-checked redirect.
		if ( 'upload.php' !== $pagenow || ( ! isset( $_GET[ self::PREFIX . 'bulk' ] ) && ! isset( $_GET[ self::PREFIX . 'bulk_error' ] ) ) ) {
			return;
		}

		if ( isset( $_GET[ self::PREFIX . 'bulk_error' ] ) ) {
			wp_admin_notice( __( 'The folder could not be found.', 'migud-media-manager' ), array( 'type' => 'error' ) );
			return;
		}

		$folder  = Migud_MM_Taxonomy::get_folder( isset( $_GET[ self::PREFIX . 'target' ] ) ? absint( $_GET[ self::PREFIX . 'target' ] ) : 0 );
		$name    = $folder ? wp_specialchars_decode( $folder->name, ENT_QUOTES ) : '';
		$updated = isset( $_GET[ self::PREFIX . 'updated' ] ) ? absint( $_GET[ self::PREFIX . 'updated' ] ) : 0;
		$skipped = isset( $_GET[ self::PREFIX . 'skipped' ] ) ? absint( $_GET[ self::PREFIX . 'skipped' ] ) : 0;
		$add     = 'add' === sanitize_key( wp_unslash( $_GET[ self::PREFIX . 'bulk' ] ) );
		// phpcs:enable

		$message = $add
			/* translators: 1: number of media files, 2: folder name. */
			? sprintf( _n( '%1$d media file added to “%2$s”.', '%1$d media files added to “%2$s”.', $updated, 'migud-media-manager' ), $updated, $name )
			/* translators: 1: number of media files, 2: folder name. */
			: sprintf( _n( '%1$d media file removed from “%2$s”.', '%1$d media files removed from “%2$s”.', $updated, 'migud-media-manager' ), $updated, $name );

		if ( $skipped ) {
			/* translators: %d: number of media files. */
			$message .= ' ' . sprintf( _n( '%d media file could not be changed (missing permission).', '%d media files could not be changed (missing permission).', $skipped, 'migud-media-manager' ), $skipped );
		}

		wp_admin_notice(
			esc_html( $message ),
			array(
				'type'        => $skipped ? 'warning' : 'success',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Removes the result parameters from the URL after display.
	 *
	 * @param string[] $args Removable query args.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		return array_merge( $args, array( self::PREFIX . 'bulk', self::PREFIX . 'bulk_error', self::PREFIX . 'target', self::PREFIX . 'updated', self::PREFIX . 'skipped' ) );
	}

	/**
	 * Replaces the slug-based query var with a term_id clause on the media list screen.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_main_query( $query ) {
		global $pagenow;

		if ( ! is_admin() || ! $query->is_main_query() || 'upload.php' !== $pagenow ) {
			return;
		}

		$value = $query->get( Migud_MM_Taxonomy::NAME );
		if ( ( '' === $value || null === $value ) && isset( $_GET[ Migud_MM_Legacy::QUERY_VAR ] ) && is_scalar( $_GET[ Migud_MM_Legacy::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			// Legacy adapter: list URLs with the former query var (bookmarks, links).
			$value = sanitize_text_field( wp_unslash( $_GET[ Migud_MM_Legacy::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		}
		if ( '' === $value || null === $value ) {
			return;
		}

		$clause = Migud_MM_Library::tax_clause( $value );
		$query->set( Migud_MM_Taxonomy::NAME, '' );

		if ( $clause ) {
			self::$current = (string) $value;
			$query->set( 'tax_query', Migud_MM_Library::merge_tax_query( $query->get( 'tax_query' ), $clause ) );
		}
	}

	/**
	 * Renders the folder dropdown next to the date filter.
	 *
	 * @param string $post_type Post type of the list table.
	 */
	public static function render_dropdown( $post_type ) {
		if ( 'attachment' !== $post_type || ! Migud_MM_Taxonomy::can_read() ) {
			return;
		}

		$summary = Migud_MM_Library::get_summary();
		?>
		<label for="migud-mm-folder-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by folder', 'migud-media-manager' ); ?></label>
		<select name="<?php echo esc_attr( Migud_MM_Taxonomy::NAME ); ?>" id="migud-mm-folder-filter">
			<option value=""><?php esc_html_e( 'All folders', 'migud-media-manager' ); ?></option>
			<option value="none" <?php selected( self::$current, 'none' ); ?>>
				<?php
				/* translators: %d: number of media items. */
				echo esc_html( sprintf( __( 'Unassigned (%d)', 'migud-media-manager' ), $summary['unassigned'] ) );
				?>
			</option>
			<?php foreach ( Migud_MM_Taxonomy::get_folder_tree() as $folder ) : ?>
				<option class="level-<?php echo (int) $folder['depth']; ?>" value="<?php echo esc_attr( $folder['id'] ); ?>" <?php selected( self::$current, (string) $folder['id'] ); ?>>
					<?php echo esc_html( sprintf( '%s (%d)', Migud_MM_Taxonomy::tree_label( $folder ), $folder['count'] ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
