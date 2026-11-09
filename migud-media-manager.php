<?php
/**
 * Plugin Name: MIGUD Media Manager
 * Description: Virtual folders for the WordPress media library.
 * Version: 1.0.0
 * Author: MIGUD Studio
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: migud-media-manager
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/*
 * Another copy of MIGUD Media Manager (for example in a second plugin folder) is
 * already loaded: this copy stays inactive instead of registering everything twice.
 */
if ( defined( 'MIGUD_MM_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'MIGUD Media Manager is not running because another copy of MIGUD Media Manager is active. Deactivate one of the copies.', 'migud-media-manager' ) . '</p></div>';
			}
		}
	);
	return;
}

/*
 * Legacy adapter (remove with the next major version): the former plugin
 * "Fena Media Manager" (constant FMM_VERSION, main file fena-media-manager.php)
 * works on the same folders. While it is loaded or active, in any load order,
 * this plugin stays inactive so the folders are never managed twice. Its
 * stored data is taken over by the separate migration step.
 */
$migud_mm_legacy_active = defined( 'FMM_VERSION' );
if ( ! $migud_mm_legacy_active ) {
	$migud_mm_active_plugins = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$migud_mm_active_plugins = array_merge( $migud_mm_active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( $migud_mm_active_plugins as $migud_mm_active_plugin ) {
		if ( 'fena-media-manager.php' === basename( (string) $migud_mm_active_plugin ) ) {
			$migud_mm_legacy_active = true;
			break;
		}
	}
	unset( $migud_mm_active_plugins, $migud_mm_active_plugin );
}
if ( $migud_mm_legacy_active ) {
	unset( $migud_mm_legacy_active );
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'MIGUD Media Manager is not running because the former plugin Fena Media Manager is active. Deactivate Fena Media Manager to use MIGUD Media Manager. Your folders are kept.', 'migud-media-manager' ) . '</p></div>';
			}
		}
	);
	return;
}
unset( $migud_mm_legacy_active );

define( 'MIGUD_MM_VERSION', '1.0.0' );
define( 'MIGUD_MM_FILE', __FILE__ );
define( 'MIGUD_MM_PATH', plugin_dir_path( MIGUD_MM_FILE ) );
define( 'MIGUD_MM_URL', plugin_dir_url( MIGUD_MM_FILE ) );

require_once MIGUD_MM_PATH . 'includes/class-migud-mm-plugin.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-legacy.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-migration.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-maintenance.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-taxonomy.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-settings.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-transfer.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-installer.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-rest.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-attachment-fields.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-icons.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-library.php';
require_once MIGUD_MM_PATH . 'includes/class-migud-mm-list-mode.php';

add_action( 'plugins_loaded', array( 'Migud_MM_Plugin', 'init' ) );
