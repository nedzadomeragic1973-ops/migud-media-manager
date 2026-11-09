<?php
/**
 * Maintenance state while the stored data still belongs to Fena Media Manager.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pauses the folder features as long as the data migration
 * (Migud_MM_Migration) is required, running, paused, failed or rolled back.
 * Nothing is written in this state: no example folders, no folder changes,
 * no assignments, no settings and no imports. The migration itself is never
 * started here; it only runs when it is called explicitly.
 */
class Migud_MM_Maintenance {

	/**
	 * Cached state of this request.
	 *
	 * @var bool|null
	 */
	private static $active = null;

	/**
	 * Whether the folder features are paused. Determined once per request.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( null === self::$active ) {
			self::$active = self::detect();
		}

		return self::$active;
	}

	/**
	 * Ready: the migration completed, or the site has no data of the former
	 * plugin (new installation). Paused: any other migration state, or data
	 * of the former plugin that was not migrated yet. Reads only.
	 *
	 * @return bool
	 */
	private static function detect() {
		if ( Migud_MM_Migration::is_complete() ) {
			return false;
		}

		if ( array() !== Migud_MM_Migration::state() ) {
			return true;
		}

		return self::has_legacy_data();
	}

	/**
	 * Whether options or folders of the former plugin exist.
	 *
	 * @return bool
	 */
	private static function has_legacy_data() {
		global $wpdb;

		foreach ( array_keys( Migud_MM_Migration::OPTIONS ) as $option ) {
			if ( false !== get_option( $option ) ) {
				return true;
			}
		}

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", Migud_MM_Migration::SOURCE_TAXONOMY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read per request; the taxonomy of the former plugin is not registered.
	}

	/**
	 * Registers the admin notice.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Explanation shown in the admin, on the settings page and in errors.
	 *
	 * @return string
	 */
	public static function message() {
		return __( 'MIGUD Media Manager is paused: the folders of Fena Media Manager have not been taken over yet. Nothing has been changed and your folders are kept. The folder features are available again once the data update is complete.', 'migud-media-manager' );
	}

	/**
	 * Admin notice for administrators (the settings page shows its own).
	 */
	public static function notice() {
		if ( current_user_can( 'activate_plugins' ) && ! Migud_MM_Settings::is_current_page() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( self::message() ) . '</p></div>';
		}
	}

	/**
	 * REST error while paused.
	 *
	 * @return WP_Error
	 */
	public static function error() {
		return new WP_Error( Migud_MM_REST::ERROR_PREFIX . 'maintenance', self::message(), array( 'status' => 503 ) );
	}
}
