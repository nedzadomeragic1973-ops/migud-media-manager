<?php
/**
 * One-time creation of the default folders.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seeds the editable example folders exactly once per site.
 */
class Migud_MM_Installer {

	/**
	 * Option that marks the defaults as created. Never deleted by the plugin,
	 * so deleted default folders do not come back after updates or reactivation.
	 */
	const OPTION = 'migud_mm_defaults_seeded';

	/**
	 * Claims the seed marker. INSERT IGNORE succeeds for exactly one request.
	 *
	 * @return bool Whether this request created the marker.
	 */
	private static function claim() {
		global $wpdb;

		$autoload = function_exists( 'wp_determine_option_autoload_value' ) ? wp_determine_option_autoload_value( self::OPTION, MIGUD_MM_VERSION, MIGUD_MM_VERSION, 'yes' ) : 'yes';
		$result   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", self::OPTION, MIGUD_MM_VERSION, $autoload ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim.

		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return 1 === $result;
	}

	/**
	 * Creates the default folders on the first admin request after activation.
	 */
	public static function maybe_seed() {
		// No example folders while the folders of the former plugin still wait for the migration.
		if ( Migud_MM_Maintenance::is_active() || false !== get_option( self::OPTION ) || ! taxonomy_exists( Migud_MM_Taxonomy::NAME ) ) {
			return;
		}

		$existing = (int) wp_count_terms(
			array(
				'taxonomy'   => Migud_MM_Taxonomy::NAME,
				'hide_empty' => false,
			)
		);

		// The marker doubles as a lock against concurrent requests. add_option()
		// is not enough: its upsert reports success for the losing request too
		// when the database client uses FOUND_ROWS, so the claim is atomic here.
		if ( ! self::claim() || $existing > 0 ) {
			return;
		}

		$defaults = array(
			__( 'Hero Images', 'migud-media-manager' ),
			__( 'Hero Slider', 'migud-media-manager' ),
			__( 'Blog Images', 'migud-media-manager' ),
			__( 'Backgrounds', 'migud-media-manager' ),
			__( 'Logos & Icons', 'migud-media-manager' ),
			__( 'Videos', 'migud-media-manager' ),
		);

		foreach ( (array) apply_filters( 'migud_mm_default_folders', $defaults ) as $name ) {
			$name = Migud_MM_Taxonomy::sanitize_name( $name );
			if ( true === Migud_MM_Taxonomy::validate_name( $name ) ) {
				wp_insert_term( $name, Migud_MM_Taxonomy::NAME );
			}
		}
	}
}
