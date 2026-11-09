<?php
/**
 * Settings page (Media > MIGUD Media Manager).
 *
 * @package MIGUD_Media_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in one option; every read is re-validated.
 */
class Migud_MM_Settings {

	/**
	 * Option name (also the settings group).
	 */
	const OPTION = 'migud_mm_settings';

	/**
	 * Settings page slug.
	 */
	const PAGE = 'migud-mm-settings';

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MIGUD_MM_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_sort'        => 'name_asc',
			'tree_expanded'       => false,
			'restore_last_folder' => false,
			'upload_folder'       => 0,
		);
	}

	/**
	 * A validated setting value. Stored values are never trusted.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::normalize( get_option( self::OPTION, array() ), self::defaults() );

		return isset( $settings['values'][ $key ] ) ? $settings['values'][ $key ] : null;
	}

	/**
	 * Sanitizes the submitted settings (register_setting callback).
	 *
	 * @param mixed $input Submitted value.
	 * @return array Only the known settings.
	 */
	public static function sanitize( $input ) {
		$previous = self::normalize( get_option( self::OPTION, array() ), self::defaults() );

		if ( ! is_array( $input ) ) {
			return $previous['values'];
		}

		$result = self::normalize( $input, self::defaults() );
		// The first save runs this callback twice (update_option() falls back to add_option()).
		if ( $result['invalid_folder'] && ! in_array( 'migud_mm_invalid_upload_folder', wp_list_pluck( get_settings_errors( self::OPTION ), 'code' ), true ) ) {
			add_settings_error( self::OPTION, 'migud_mm_invalid_upload_folder', __( 'The selected upload folder does not exist.', 'migud-media-manager' ) );
		}

		return $result['values'];
	}

	/**
	 * Validates settings against the defaults.
	 *
	 * @param mixed $input    Raw settings.
	 * @param array $defaults Defaults.
	 * @return array { values: array, invalid_folder: bool }
	 */
	private static function normalize( $input, $defaults ) {
		$input   = is_array( $input ) ? $input : array();
		$values  = $defaults;
		$invalid = false;

		if ( isset( $input['default_sort'] ) && is_string( $input['default_sort'] ) && in_array( $input['default_sort'], Migud_MM_Taxonomy::SORT_MODES, true ) ) {
			$values['default_sort'] = $input['default_sort'];
		}

		$values['tree_expanded']       = ! empty( $input['tree_expanded'] );
		$values['restore_last_folder'] = ! empty( $input['restore_last_folder'] );

		$folder = isset( $input['upload_folder'] ) && is_scalar( $input['upload_folder'] ) ? absint( $input['upload_folder'] ) : 0;
		if ( $folder && Migud_MM_Taxonomy::get_folder( $folder ) ) {
			$values['upload_folder'] = $folder;
		} elseif ( $folder ) {
			$invalid = true;
		}

		return array(
			'values'         => $values,
			'invalid_folder' => $invalid,
		);
	}

	/**
	 * Registers the setting. Not while the data migration is pending, so
	 * options.php rejects saving instead of creating new settings.
	 */
	public static function register() {
		if ( Migud_MM_Maintenance::is_active() ) {
			return;
		}

		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Adds the page below Media.
	 */
	public static function add_page() {
		$hook = add_submenu_page(
			'upload.php',
			__( 'MIGUD Media Manager', 'migud-media-manager' ),
			__( 'MIGUD Media Manager', 'migud-media-manager' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		self::$page_hook = (string) $hook;
	}

	/**
	 * Whether the current admin screen is the settings page.
	 *
	 * @return bool
	 */
	public static function is_current_page() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return '' !== self::$page_hook && $screen instanceof WP_Screen && self::$page_hook === $screen->id;
	}

	/**
	 * Loads the page styles on the settings page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( '' === self::$page_hook || self::$page_hook !== $hook_suffix ) {
			return;
		}

		Migud_MM_Library::register_picker();
		wp_enqueue_style( 'migud-mm-admin', MIGUD_MM_URL . 'assets/css/migud-mm-admin.css', array( 'migud-mm-tokens', 'migud-mm-components' ), Migud_MM_Library::asset_version( 'assets/css/migud-mm-admin.css' ) );
		wp_enqueue_script( 'migud-mm-admin', MIGUD_MM_URL . 'assets/js/migud-mm-admin.js', array( 'jquery', 'migud-mm-picker' ), Migud_MM_Library::asset_version( 'assets/js/migud-mm-admin.js' ), true );
		add_action( 'admin_footer', array( 'Migud_MM_Icons', 'print_sprite' ) );
	}

	/**
	 * Adds the "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'upload.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'migud-media-manager' ) )
			);
		}

		return $links;
	}

	/**
	 * Adds the "Support" e-mail link below the plugin description on the plugins screen.
	 *
	 * @param string[] $meta Row meta links.
	 * @param string   $file Plugin file relative to the plugins directory.
	 * @return string[]
	 */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( MIGUD_MM_FILE ) === $file ) {
			$meta[] = sprintf( '<a href="%s">%s</a>', esc_url( 'mailto:omeragicnedzad@icloud.com' ), esc_html__( 'Support', 'migud-media-manager' ) );
		}

		return $meta;
	}

	/**
	 * Validated donation link: a https://paypal.me/<name> URL from the
	 * `migud_mm_donate_url` filter, otherwise ''.
	 *
	 * @return string URL or ''.
	 */
	public static function donate_url() {
		$url = apply_filters( 'migud_mm_donate_url', 'https://www.paypal.me/nedzadomeragic' );

		if ( ! is_string( $url ) || '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		if ( ! isset( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || ! in_array( $host, array( 'paypal.me', 'www.paypal.me' ), true ) || '' === $path ) {
			return '';
		}

		return $url;
	}

	/**
	 * Renders the donation card. Outside the settings form; no URL = disabled button.
	 */
	private static function render_donate_card() {
		$url = self::donate_url();
		?>
		<section class="migud-mm-card migud-mm-donate" aria-labelledby="migud-mm-settings-donate">
			<h2 id="migud-mm-settings-donate">
				<svg class="migud-mm-icon migud-mm-donate__heading-icon" aria-hidden="true" focusable="false"><use href="#migud-mm-i-heart"></use></svg>
				<?php esc_html_e( 'Support MIGUD Media Manager', 'migud-media-manager' ); ?>
			</h2>
			<p><?php esc_html_e( 'MIGUD Media Manager is free to use. If you enjoy the plugin, you can support its continued development with a voluntary donation.', 'migud-media-manager' ); ?></p>
			<div class="migud-mm-donate__row">
				<?php if ( '' !== $url ) : ?>
					<a class="button button-primary migud-mm-donate__button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
						<svg class="migud-mm-icon" aria-hidden="true" focusable="false"><use href="#migud-mm-i-heart"></use></svg>
						<span><?php esc_html_e( 'Donate via PayPal', 'migud-media-manager' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'migud-media-manager' ); ?></span></span>
						<svg class="migud-mm-icon migud-mm-donate__external" aria-hidden="true" focusable="false"><use href="#migud-mm-i-arrow-top-right-on-square"></use></svg>
					</a>
				<?php else : ?>
					<button type="button" class="migud-mm-donate__button" disabled aria-disabled="true">
						<svg class="migud-mm-icon" aria-hidden="true" focusable="false"><use href="#migud-mm-i-heart"></use></svg>
						<span><?php esc_html_e( 'Donate via PayPal', 'migud-media-manager' ); ?></span>
						<svg class="migud-mm-icon migud-mm-donate__external" aria-hidden="true" focusable="false"><use href="#migud-mm-i-arrow-top-right-on-square"></use></svg>
					</button>
				<?php endif; ?>
				<span class="migud-mm-donate__note"><?php esc_html_e( 'Optional support. All features remain free.', 'migud-media-manager' ); ?></span>
			</div>
		</section>
		<?php
	}

	/**
	 * Sort mode labels (same as the folder sidebar).
	 *
	 * @return string[]
	 */
	private static function sort_labels() {
		return array(
			'name_asc'  => __( 'Name (A–Z)', 'migud-media-manager' ),
			'name_desc' => __( 'Name (Z–A)', 'migud-media-manager' ),
			'newest'    => __( 'Newest first', 'migud-media-manager' ),
			'oldest'    => __( 'Oldest first', 'migud-media-manager' ),
			'manual'    => __( 'Manual order', 'migud-media-manager' ),
		);
	}

	/**
	 * Renders the page header card.
	 */
	private static function render_header() {
		?>
			<div class="migud-mm-card migud-mm-hero">
				<img class="migud-mm-hero__image" src="<?php echo esc_url( add_query_arg( 'ver', Migud_MM_Library::asset_version( 'assets/img/migud-mm-folder-header.svg' ), MIGUD_MM_URL . 'assets/img/migud-mm-folder-header.svg' ) ); ?>" width="42" height="28" alt="" decoding="async">
				<h1 class="migud-mm-hero__title"><?php esc_html_e( 'MIGUD Media Manager', 'migud-media-manager' ); ?></h1>
			</div>
			<?php // Core moves admin notices below this marker instead of into the header card. ?>
			<hr class="wp-header-end">
		<?php
	}

	/**
	 * Renders the settings page. While the data migration is pending only
	 * the explanation is shown: no settings form and no import.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( Migud_MM_Maintenance::is_active() ) {
			?>
			<div class="wrap migud-mm migud-mm-settings">
				<?php self::render_header(); ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( Migud_MM_Maintenance::message() ); ?></p></div>
			</div>
			<?php
			return;
		}

		$sort      = self::get( 'default_sort' );
		$expanded  = (bool) self::get( 'tree_expanded' );
		$restore   = (bool) self::get( 'restore_last_folder' );
		$upload_to = (int) self::get( 'upload_folder' );
		$name      = self::OPTION;
		?>
		<div class="wrap migud-mm migud-mm-settings">
			<?php self::render_header(); ?>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<section class="migud-mm-card" aria-labelledby="migud-mm-settings-general">
					<h2 id="migud-mm-settings-general"><?php esc_html_e( 'Folders', 'migud-media-manager' ); ?></h2>

					<div class="migud-mm-field">
						<div class="migud-mm-field__label">
							<label for="migud-mm-default-sort"><?php esc_html_e( 'Default sort order', 'migud-media-manager' ); ?></label>
						</div>
						<div class="migud-mm-field__control">
							<select id="migud-mm-default-sort" name="<?php echo esc_attr( $name ); ?>[default_sort]" aria-describedby="migud-mm-default-sort-help">
								<?php foreach ( self::sort_labels() as $mode => $label ) : ?>
									<option value="<?php echo esc_attr( $mode ); ?>"<?php selected( $sort, $mode ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="migud-mm-field__help" id="migud-mm-default-sort-help"><?php esc_html_e( 'Used for users who have not chosen a sort order in the folder sidebar.', 'migud-media-manager' ); ?></p>
						</div>
					</div>

					<fieldset class="migud-mm-field">
						<legend class="migud-mm-field__label"><?php esc_html_e( 'Folder tree on load', 'migud-media-manager' ); ?></legend>
						<div class="migud-mm-field__control">
							<label class="migud-mm-choice">
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[tree_expanded]" value="" aria-describedby="migud-mm-tree-help"<?php checked( ! $expanded ); ?> />
								<?php esc_html_e( 'Collapsed', 'migud-media-manager' ); ?>
							</label>
							<label class="migud-mm-choice">
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[tree_expanded]" value="1" aria-describedby="migud-mm-tree-help"<?php checked( $expanded ); ?> />
								<?php esc_html_e( 'Expanded', 'migud-media-manager' ); ?>
							</label>
							<p class="migud-mm-field__help" id="migud-mm-tree-help"><?php esc_html_e( 'Applies to the folder sidebar in the media library grid.', 'migud-media-manager' ); ?></p>
						</div>
					</fieldset>

					<fieldset class="migud-mm-field">
						<legend class="migud-mm-field__label"><?php esc_html_e( 'Restore last folder', 'migud-media-manager' ); ?></legend>
						<div class="migud-mm-field__control">
							<label class="migud-mm-choice" for="migud-mm-restore-last">
								<input type="checkbox" id="migud-mm-restore-last" name="<?php echo esc_attr( $name ); ?>[restore_last_folder]" value="1" aria-describedby="migud-mm-restore-help"<?php checked( $restore ); ?> />
								<?php esc_html_e( 'Open the folder that was selected last', 'migud-media-manager' ); ?>
							</label>
							<p class="migud-mm-field__help" id="migud-mm-restore-help"><?php esc_html_e( 'Remembered per user in this browser.', 'migud-media-manager' ); ?></p>
						</div>
					</fieldset>

					<div class="migud-mm-field">
						<div class="migud-mm-field__label">
							<label for="migud-mm-upload-folder"><?php esc_html_e( 'Default upload folder', 'migud-media-manager' ); ?></label>
						</div>
						<div class="migud-mm-field__control">
							<select id="migud-mm-upload-folder" name="<?php echo esc_attr( $name ); ?>[upload_folder]" aria-describedby="migud-mm-upload-folder-help">
								<option value="0"><?php esc_html_e( 'None', 'migud-media-manager' ); ?></option>
								<?php foreach ( Migud_MM_Taxonomy::get_folder_tree() as $folder ) : ?>
									<option value="<?php echo esc_attr( $folder['id'] ); ?>" data-depth="<?php echo esc_attr( $folder['depth'] ); ?>" data-name="<?php echo esc_attr( $folder['name'] ); ?>" data-color="<?php echo esc_attr( $folder['color'] ); ?>" data-icon="<?php echo esc_attr( $folder['icon'] ); ?>"<?php selected( $upload_to, $folder['id'] ); ?>><?php echo esc_html( Migud_MM_Taxonomy::tree_label( $folder ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="migud-mm-field__help" id="migud-mm-upload-folder-help"><?php esc_html_e( 'Uploads in the media library or media modal go into this folder when “All Media” is selected. A selected folder always takes precedence; uploads in “Unassigned” stay unassigned.', 'migud-media-manager' ); ?></p>
						</div>
					</div>

					<div class="migud-mm-card__footer">
						<?php submit_button( __( 'Save Changes', 'migud-media-manager' ), 'primary', 'submit', false ); ?>
					</div>
				</section>
			</form>
			<?php Migud_MM_Transfer::render_section(); ?>
			<?php self::render_donate_card(); ?>
		</div>
		<?php
	}
}
