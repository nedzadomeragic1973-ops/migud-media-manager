<?php
/**
 * Transitional adapters for the identifiers of the former plugin
 * "Fena Media Manager" (fmm).
 *
 * Every place that still accepts an fmm identifier refers to this class, so
 * the adapters can be found and removed together. The bundled scripts use the
 * new identifiers only; the adapters serve integrations (REST namespace,
 * filters), bookmarks (page slug, list URLs), pages and scripts loaded before
 * the update (forms, query prop, upload parameter) and stored browser values
 * (last folder). Remove with the next major version.
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legacy adapters: REST namespace and error codes, deprecated filters,
 * request parameters, admin-post actions and the settings page slug.
 */
class Migud_MM_Legacy {

	/**
	 * Version in which the former identifiers were deprecated.
	 */
	const DEPRECATED_SINCE = '1.0.0';

	/**
	 * Former REST namespace; same routes, permissions and responses as
	 * Migud_MM_REST::NAMESPACE_V1, error codes with the former prefix.
	 */
	const REST_NAMESPACE = 'fmm/v1';

	/**
	 * Former prefix of hooks, admin-post actions, nonces, parameters and error codes.
	 */
	const PREFIX = 'fmm_';

	/**
	 * Former folder query prop and upload parameter (scripts loaded before the update, list URLs).
	 */
	const QUERY_VAR = 'fmm_folder';

	/**
	 * Former base name of the folder fields in the attachment details.
	 */
	const ATTACHMENT_FIELD = 'fmm_folders';

	/**
	 * Former name of the import strategy radio buttons (forms rendered before the update).
	 */
	const STRATEGY_FIELD = 'fmm_strategy';

	/**
	 * Former settings page slug.
	 */
	const PAGE = 'fmm-settings';

	/**
	 * Former prefix of the browser storage key of the last selected folder;
	 * migud-mm-folder-sidebar.js moves the value to the new key once.
	 */
	const STORAGE_KEY_PREFIX = 'fmm-last-folder-';

	/**
	 * Registers the adapters.
	 */
	public static function init() {
		// The former filters run first; callbacks of the new filters get the last word.
		add_filter( 'migud_mm_default_folders', array( __CLASS__, 'default_folders' ), PHP_INT_MIN );
		add_filter( 'migud_mm_donate_url', array( __CLASS__, 'donate_url' ), PHP_INT_MIN );
		add_filter( 'migud_mm_manage_folders_capability', array( __CLASS__, 'manage_folders_capability' ), PHP_INT_MIN );
		// Core denies unknown admin pages before admin_init; this action runs right before that error.
		add_action( 'admin_page_access_denied', array( __CLASS__, 'redirect_settings_page' ) );
	}

	/**
	 * Deprecated filter `fmm_default_folders` (use `migud_mm_default_folders`).
	 *
	 * @param array $folders Default folder names.
	 * @return array
	 */
	public static function default_folders( $folders ) {
		return apply_filters_deprecated( 'fmm_default_folders', array( $folders ), self::DEPRECATED_SINCE, 'migud_mm_default_folders' );
	}

	/**
	 * Deprecated filter `fmm_donate_url` (use `migud_mm_donate_url`).
	 *
	 * @param string $url Donation URL.
	 * @return string
	 */
	public static function donate_url( $url ) {
		return apply_filters_deprecated( 'fmm_donate_url', array( $url ), self::DEPRECATED_SINCE, 'migud_mm_donate_url' );
	}

	/**
	 * Deprecated filter `fmm_manage_folders_capability` (use
	 * `migud_mm_manage_folders_capability`). Security relevant, see readme.txt.
	 *
	 * @param string $capability Capability.
	 * @return string
	 */
	public static function manage_folders_capability( $capability ) {
		return apply_filters_deprecated( 'fmm_manage_folders_capability', array( $capability ), self::DEPRECATED_SINCE, 'migud_mm_manage_folders_capability' );
	}

	/**
	 * Error of the former REST namespace: plugin error codes keep their former
	 * prefix (clients of fmm/v1 compare them). Messages and data are unchanged.
	 *
	 * @param WP_Error $error Error with migud_mm_ codes.
	 * @return WP_Error
	 */
	public static function rest_error( WP_Error $error ) {
		$mapped = new WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			$legacy = 0 === strpos( (string) $code, Migud_MM_REST::ERROR_PREFIX ) ? self::PREFIX . substr( (string) $code, strlen( Migud_MM_REST::ERROR_PREFIX ) ) : $code;
			foreach ( $error->get_error_messages( $code ) as $message ) {
				$mapped->add( $legacy, $message );
			}
			$data = $error->get_error_data( $code );
			if ( null !== $data ) {
				$mapped->add_data( $data, $legacy );
			}
		}

		return $mapped;
	}

	/**
	 * Hook name of an admin-post action under the former prefix.
	 *
	 * @param string $action Action without prefix (export, import_preview, ...).
	 * @return string
	 */
	public static function admin_post_hook( $action ) {
		return 'admin_post_' . self::PREFIX . $action;
	}

	/**
	 * Redirects the former settings page slug (bookmarks, old links) to the
	 * new page. The new page checks the capability itself.
	 */
	public static function redirect_settings_page() {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect only, nothing is changed.
		if ( 'upload.php' !== $pagenow || ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		wp_safe_redirect( admin_url( 'upload.php?page=' . Migud_MM_Settings::PAGE ) );
		exit;
	}
}
