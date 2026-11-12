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
	 * Default color of the "New Folder" button (--migud-mm-primary in migud-mm-tokens.css).
	 */
	const DEFAULT_BUTTON_COLOR = '#1e293b';

	/**
	 * Minimum contrast ratio of the button text and icon (WCAG AA, normal text).
	 */
	const MIN_CONTRAST = 4.5;

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
			'default_sort'            => 'name_asc',
			'tree_expanded'           => false,
			'restore_last_folder'     => false,
			'upload_folder'           => 0,
			'new_folder_button_color' => '',
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
		// An invalid color keeps the saved one.
		if ( $result['invalid_color'] ) {
			$result['values']['new_folder_button_color'] = $previous['values']['new_folder_button_color'];
			if ( ! in_array( 'migud_mm_invalid_button_color', wp_list_pluck( get_settings_errors( self::OPTION ), 'code' ), true ) ) {
				add_settings_error( self::OPTION, 'migud_mm_invalid_button_color', __( 'The New Folder button color must be a hex value such as #1e293b. The previous color was kept.', 'migud-media-manager' ) );
			}
		}

		return $result['values'];
	}

	/**
	 * Validates settings against the defaults.
	 *
	 * @param mixed $input    Raw settings.
	 * @param array $defaults Defaults.
	 * @return array { values: array, invalid_folder: bool, invalid_color: bool }
	 */
	private static function normalize( $input, $defaults ) {
		$input   = is_array( $input ) ? $input : array();
		$values  = $defaults;
		$invalid = false;
		$color   = isset( $input['new_folder_button_color'] ) && is_string( $input['new_folder_button_color'] ) ? self::hex_color( $input['new_folder_button_color'] ) : '';

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

		// The default color is stored as '' so the button keeps the stylesheet colors.
		if ( null !== $color && self::DEFAULT_BUTTON_COLOR !== $color ) {
			$values['new_folder_button_color'] = $color;
		}

		return array(
			'values'         => $values,
			'invalid_folder' => $invalid,
			'invalid_color'  => null === $color,
		);
	}

	/**
	 * Normalizes a #rrggbb color (case and the leading # are optional).
	 *
	 * @param string $raw Raw value.
	 * @return string|null Lowercase #rrggbb, '' for empty, null when invalid.
	 */
	private static function hex_color( $raw ) {
		$value = strtolower( trim( $raw ) );

		if ( '' === $value ) {
			return '';
		}
		$value = '#' === $value[0] ? $value : '#' . $value;

		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : null;
	}

	/**
	 * Relative luminance of a #rrggbb color (WCAG 2).
	 *
	 * @param string $hex Lowercase #rrggbb.
	 * @return float
	 */
	private static function luminance( $hex ) {
		$channels = array_map(
			static function ( $pair ) {
				$value = hexdec( $pair ) / 255;
				return $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
			},
			str_split( substr( $hex, 1 ), 2 )
		);

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Contrast ratio of two #rrggbb colors (WCAG 2).
	 *
	 * @param string $a Lowercase #rrggbb.
	 * @param string $b Lowercase #rrggbb.
	 * @return float 1 to 21.
	 */
	private static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Mixes two #rrggbb colors.
	 *
	 * @param string $from   Lowercase #rrggbb.
	 * @param string $to     Lowercase #rrggbb.
	 * @param float  $amount Share of $to (0 to 1).
	 * @return string Lowercase #rrggbb.
	 */
	private static function mix( $from, $to, $amount ) {
		$a   = str_split( substr( $from, 1 ), 2 );
		$b   = str_split( substr( $to, 1 ), 2 );
		$out = '#';
		foreach ( array( 0, 1, 2 ) as $i ) {
			$out .= sprintf( '%02x', (int) round( hexdec( $a[ $i ] ) + ( hexdec( $b[ $i ] ) - hexdec( $a[ $i ] ) ) * $amount ) );
		}

		return $out;
	}

	/**
	 * Text and icon color for a button background, from its measured contrast:
	 * white whenever it reaches MIN_CONTRAST, otherwise the design's dark text,
	 * otherwise black (always at least 4.58:1 where white falls short).
	 *
	 * @param string $background Lowercase #rrggbb.
	 * @return string Lowercase #rrggbb.
	 */
	private static function readable_text( $background ) {
		foreach ( array( '#ffffff', '#0f172a' ) as $text ) {
			if ( self::contrast( $background, $text ) >= self::MIN_CONTRAST ) {
				return $text;
			}
		}

		return '#000000';
	}

	/**
	 * Inline CSS custom properties for the "New Folder" button of the folder
	 * sidebar; '' for the default color (the stylesheet values apply). Like
	 * the default (#1e293b -> #334155), hover moves the background slightly
	 * towards the text color, or away from it when the text contrast would
	 * drop below MIN_CONTRAST. Very light colors get the form field border so
	 * the button stays visible on the white sidebar.
	 *
	 * @return string CSS built from validated hex values only.
	 */
	public static function new_folder_button_css() {
		$color = (string) self::get( 'new_folder_button_color' );

		if ( '' === $color ) {
			return '';
		}

		$text  = self::readable_text( $color );
		$floor = min( self::MIN_CONTRAST, self::contrast( $color, $text ) );
		$hover = self::mix( $color, $text, 0.12 );
		if ( self::contrast( $hover, $text ) < $floor ) {
			$hover = self::mix( $color, '#ffffff' === $text ? '#000000' : '#ffffff', 0.12 );
		}

		$css = '--migud-mm-new-folder:' . $color . ';--migud-mm-new-folder-hover:' . $hover . ';--migud-mm-on-new-folder:' . $text . ';';
		if ( self::contrast( $color, '#ffffff' ) < 3 ) {
			$css .= '--migud-mm-new-folder-border:#8392a8;';
		}

		return '.migud-mm-sidebar{' . $css . '}';
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
				<img class="migud-mm-hero__image" src="<?php echo esc_url( add_query_arg( 'ver', Migud_MM_Library::asset_version( 'assets/img/migud-mm-folder-header.png' ), MIGUD_MM_URL . 'assets/img/migud-mm-folder-header.png' ) ); ?>" width="42" height="42" alt="" decoding="async">
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
		$button    = (string) self::get( 'new_folder_button_color' );
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

					<div class="migud-mm-field">
						<div class="migud-mm-field__label">
							<label for="migud-mm-new-folder-color"><?php esc_html_e( 'New Folder button color', 'migud-media-manager' ); ?></label>
						</div>
						<div class="migud-mm-field__control">
							<?php // Without JavaScript only the hex field is shown; empty = default color. ?>
							<div class="migud-mm-color" data-migud-mm-default="<?php echo esc_attr( self::DEFAULT_BUTTON_COLOR ); ?>">
								<label class="screen-reader-text" for="migud-mm-new-folder-color-picker"><?php esc_html_e( 'Choose the New Folder button color', 'migud-media-manager' ); ?></label>
								<input type="color" id="migud-mm-new-folder-color-picker" class="migud-mm-color__picker" value="<?php echo esc_attr( '' !== $button ? $button : self::DEFAULT_BUTTON_COLOR ); ?>" hidden />
								<input type="text" id="migud-mm-new-folder-color" class="migud-mm-color__hex" name="<?php echo esc_attr( $name ); ?>[new_folder_button_color]" value="<?php echo esc_attr( $button ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_BUTTON_COLOR ); ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}" title="<?php esc_attr_e( 'Hex value such as #1e293b', 'migud-media-manager' ); ?>" spellcheck="false" autocomplete="off" aria-describedby="migud-mm-new-folder-color-help" />
								<button type="button" class="button migud-mm-color__reset" hidden><?php esc_html_e( 'Reset to default', 'migud-media-manager' ); ?></button>
							</div>
							<p class="migud-mm-field__help" id="migud-mm-new-folder-color-help"><?php esc_html_e( 'Leave empty for the default color. Text and icon colors adjust automatically for good contrast. Folder colors are not affected.', 'migud-media-manager' ); ?></p>
							<span class="screen-reader-text migud-mm-color__status" aria-live="polite" data-migud-mm-message="<?php esc_attr_e( 'Default color restored. Save the changes to apply it.', 'migud-media-manager' ); ?>"></span>
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
