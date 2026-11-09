<?php
/**
 * Plugin bootstrap.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires all plugin hooks.
 */
class Migud_MM_Plugin {

	/**
	 * Registers the plugin hooks. While the stored data still waits for the
	 * migration (Migud_MM_Maintenance), the folder UI, uploads assignment,
	 * list filter and example folders stay off; REST routes, settings page
	 * and import handlers answer with an explanation and write nothing.
	 */
	public static function init() {
		$ready = ! Migud_MM_Maintenance::is_active();

		add_action( 'init', array( __CLASS__, 'on_init' ), 5 );
		if ( $ready ) {
			add_action( 'admin_init', array( 'Migud_MM_Installer', 'maybe_seed' ) );
		}
		add_action( 'rest_api_init', array( 'Migud_MM_REST', 'register_routes' ) );
		add_action( 'set_object_terms', array( 'Migud_MM_Taxonomy', 'restrict_to_attachments' ), 10, 4 );
		if ( $ready ) {
			Migud_MM_Attachment_Fields::init();
			Migud_MM_Library::init();
			Migud_MM_List_Mode::init();
		} else {
			Migud_MM_Maintenance::init();
		}
		Migud_MM_Settings::init();
		Migud_MM_Transfer::init();
		Migud_MM_Legacy::init();
	}

	/**
	 * Registers the folder taxonomy.
	 */
	public static function on_init() {
		Migud_MM_Taxonomy::register();
	}
}
