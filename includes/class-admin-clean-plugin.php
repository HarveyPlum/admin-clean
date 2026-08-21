<?php
/**
 * Core plugin class.
 *
 * @package AdminClean
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hides protected plugins from non-manager administrators.
 */
final class AdminClean_Plugin {
	const OPTION_NAME       = 'adminclean_settings';
	const MENU_SLUG         = 'adminclean';
	const SETTINGS_SLUG     = 'adminclean-settings';
	const NONCE_ACTION      = 'adminclean_save_settings';
	const NONCE_FIELD       = 'adminclean_nonce';
	const EXPORT_ACTION     = 'adminclean_export_config';
	const IMPORT_ACTION     = 'adminclean_import_config';
	const DEFAULT_CAPABILITY = 'manage_options';
	private const AGENCY_EMAIL_DOMAIN = 'harveyplum.com';
	private const DEFAULT_PROTECTED_PLUGINS_TEXT = <<<'ADMINCLEAN_DEFAULT_PROTECTED_PLUGINS'
advanced-nocaptcha-recaptcha/advanced-nocaptcha-recaptcha.php | CAPTCHA 4WP | c4wp-admin-captcha
advanced-db-cleaner.php | advanced-db-cleaner.php
cloudflare/cloudflare.php | cloudflare/cloudflare.php | cloudflare
edit-author-slug/edit-author-slug.php | edit-author-slug/edit-author-slug.php
disable-comments/disable-comments.php | disable-comments/disable-comments.php
index-wp-mysql-for-speed/index-wp-mysql-for-speed.php | Index MySQL for Speed | imfs_settings
index-wp-users-for-speed/index-wp-users-for-speed.php | Index MySQL for Speed | index-wp-users-for-speed
relevanssi/relevanssi.php | relevanssi/relevanssi.php
relevanssi-light/relevanssi-light.php | relevanssi-light.php
remove-footer-credit/remove-footer-credit.php | Remove Footer Credit | remove-footer-credit
sg-security/sg-security.php | Security Optimizer | sg-security
simple-local-avatars/simple-local-avatars.php | simple-local-avatars/simple-local-avatars.php
stop-user-enumeration/stop-user-enumeration.php | Stop User Enumeration | stop-user-enumeration
stop-user-enumeration/stop-user-enumeration.php | Stop User Enumeration | ffpl-opt-in-SUE
wp-mail-smtp/wp_mail_smtp.php | WP Mail SMTP | wp-mail-smtp
wp-phpmyadmin-extension.php | wp-phpmyadmin-extension.php
wp-phpmyadmin/wp-phpmyadmin.php | wp-phpmyadmin/wp-phpmyadmin.php
wp-asset-clean-up/wpacu.php | Asset CleanUp | wpassetcleanup_getting_started
amazon-s3-and-cloudfront/wordpress-s3.php | amazon-s3-and-cloudfront/wordpress-s3.php | amazon-s3-and-cloudfront
admin-clean/admin-clean.php | AdminClean | adminclean
ADMINCLEAN_DEFAULT_PROTECTED_PLUGINS;

	/**
	 * Singleton instance.
	 *
	 * @var AdminClean_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Current settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings = array();

	/**
	 * LiteSpeed Cache notice callbacks wrapped for output filtering.
	 *
	 * @var array<string,array{function:mixed,accepted_args:int}>
	 */
	private $litespeed_notice_callbacks = array();

	/**
	 * Closure hashes for AdminClean LiteSpeed notice wrappers.
	 *
	 * @var array<string,bool>
	 */
	private $litespeed_notice_wrapper_hashes = array();

	/**
	 * Return singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Seed settings on activation.
	 */
	public static function activate(): void {
		$settings = get_option( self::OPTION_NAME );

		if ( is_array( $settings ) ) {
			return;
		}

		$current_user_id = get_current_user_id();
		$manager_ids     = $current_user_id > 0 ? array( $current_user_id ) : array();

		add_option(
			self::OPTION_NAME,
			array(
				'manager_user_ids'       => $manager_ids,
				'enable_hiding'          => true,
				'hide_plugin_rows'       => true,
				'hide_admin_menus'       => true,
				'block_hidden_pages'     => true,
				'suppress_admin_notices' => true,
				'protected_plugins_text' => self::DEFAULT_PROTECTED_PLUGINS_TEXT,
			),
			'',
			false
		);
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		$this->settings = $this->get_settings();

		add_action( 'admin_menu', array( $this, 'register_manager_menu' ), 1 );
		add_action( 'admin_menu', array( $this, 'hide_protected_menus' ), 9999 );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_init', array( $this, 'block_hidden_admin_pages' ) );
		add_action( 'admin_init', array( $this, 'block_protected_plugin_actions' ) );
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), PHP_INT_MAX );
		add_action( 'admin_notices', array( $this, 'suppress_admin_notices' ), 0 );
		add_action( 'all_admin_notices', array( $this, 'suppress_admin_notices' ), 0 );
		add_action( 'network_admin_notices', array( $this, 'suppress_admin_notices' ), 0 );
		add_action( 'user_admin_notices', array( $this, 'suppress_admin_notices' ), 0 );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_filter( 'all_plugins', array( $this, 'filter_plugins_list' ) );
		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'filter_adminclean_plugin_row_meta' ), 10, 4 );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_settings(): array {
		$defaults = array(
			'manager_user_ids'       => array(),
			'enable_hiding'          => true,
			'hide_plugin_rows'       => true,
			'hide_admin_menus'       => true,
			'block_hidden_pages'     => true,
			'suppress_admin_notices' => true,
			'protected_plugins_text' => self::DEFAULT_PROTECTED_PLUGINS_TEXT,
		);

		$stored_settings = get_option( self::OPTION_NAME, array() );
		$settings        = $stored_settings;

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, $defaults );

		if (
			is_array( $stored_settings )
			&& ! array_key_exists( 'suppress_admin_notices', $stored_settings )
			&& array_key_exists( 'hide_admin_notices', $stored_settings )
		) {
			$settings['suppress_admin_notices'] = ! empty( $stored_settings['hide_admin_notices'] ) && ! empty( $settings['enable_hiding'] );
		}

		if ( ! is_array( $settings['manager_user_ids'] ) ) {
			$settings['manager_user_ids'] = $this->parse_ids( (string) $settings['manager_user_ids'] );
		}

		$settings['manager_user_ids'] = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $settings['manager_user_ids'] )
				)
			)
		);

		$settings['enable_hiding']          = (bool) $settings['enable_hiding'];
		$settings['hide_plugin_rows']       = (bool) $settings['hide_plugin_rows'];
		$settings['hide_admin_menus']       = (bool) $settings['hide_admin_menus'];
		$settings['block_hidden_pages']     = (bool) $settings['block_hidden_pages'];
		$settings['suppress_admin_notices'] = (bool) $settings['suppress_admin_notices'];

		return $settings;
	}

	/**
	 * Register the private manager UI.
	 */
	public function register_manager_menu(): void {
		if ( ! $this->can_access_adminclean_ui() ) {
			return;
		}

		add_menu_page(
			__( 'AdminClean', 'admin-clean' ),
			__( 'AdminClean', 'admin-clean' ),
			self::DEFAULT_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-shield',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Managed Plugins', 'admin-clean' ),
			__( 'Managed Plugins', 'admin-clean' ),
			self::DEFAULT_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'admin-clean' ),
			__( 'Settings', 'admin-clean' ),
			self::DEFAULT_CAPABILITY,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Hide protected plugin rows on the Plugins screen.
	 *
	 * @param array<string,array<string,mixed>> $plugins Plugins keyed by plugin file.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_plugins_list( array $plugins ): array {
		if ( ! $this->should_apply_hiding() || empty( $this->settings['hide_plugin_rows'] ) ) {
			return $plugins;
		}

		foreach ( $this->get_protected_plugins() as $protected_plugin ) {
			if ( $this->is_adminclean_plugin_file( $protected_plugin['plugin_file'] ) ) {
				continue;
			}

			unset( $plugins[ $protected_plugin['plugin_file'] ] );
		}

		return $plugins;
	}

	/**
	 * Remove deactivate/delete links for protected plugins for non-managers.
	 *
	 * @param array<string,string> $actions     Action links.
	 * @param string              $plugin_file Plugin file.
	 * @param array<string,mixed>  $plugin_data Plugin data.
	 * @param string              $context     Current context.
	 * @return array<string,string>
	 */
	public function filter_plugin_action_links( array $actions, string $plugin_file, array $plugin_data, string $context ): array {
		unset( $plugin_data, $context );

		if ( ! $this->should_apply_hiding() || ! $this->is_protected_plugin_file( $plugin_file ) ) {
			return $actions;
		}

		unset( $actions['deactivate'], $actions['delete'] );

		return $actions;
	}

	/**
	 * Replace AdminClean's plugin row author byline with two brand links.
	 *
	 * @param array<int,string>      $plugin_meta Plugin row meta fragments.
	 * @param string                 $plugin_file Plugin file.
	 * @param array<string,mixed>    $plugin_data Plugin data.
	 * @param string                 $status      Plugin status.
	 * @return array<int,string>
	 */
	public function filter_adminclean_plugin_row_meta( array $plugin_meta, string $plugin_file, array $plugin_data, string $status ): array {
		unset( $plugin_data, $status );

		if ( ! $this->is_adminclean_plugin_file( $plugin_file ) ) {
			return $plugin_meta;
		}

		$author_links = sprintf(
			'<a href="%1$s">%2$s</a> | <a href="%3$s">%4$s</a>',
			esc_url( 'https://harveyplum.com' ),
			esc_html__( 'Harvey Plum', 'admin-clean' ),
			esc_url( 'https://harveyplum.com/adminclean/' ),
			esc_html__( 'AdminClean', 'admin-clean' )
		);
		$byline       = sprintf(
			/* translators: %s is linked author text. */
			__( 'By %s', 'admin-clean' ),
			$author_links
		);

		foreach ( $plugin_meta as $index => $meta ) {
			if ( 0 === strpos( wp_strip_all_tags( $meta ), 'By ' ) ) {
				$plugin_meta[ $index ] = $byline;

				return $plugin_meta;
			}
		}

		array_unshift( $plugin_meta, $byline );

		return $plugin_meta;
	}

	/**
	 * Remove protected admin menu pages for non-managers.
	 */
	public function hide_protected_menus(): void {
		if ( ! $this->should_apply_hiding() || empty( $this->settings['hide_admin_menus'] ) ) {
			return;
		}

		foreach ( $this->get_hidden_menu_slugs() as $slug ) {
			remove_menu_page( $slug );
			remove_submenu_page( $slug, $slug );

			foreach ( $GLOBALS['submenu'] ?? array() as $parent_slug => $items ) {
				if ( ! is_array( $items ) ) {
					continue;
				}

				remove_submenu_page( (string) $parent_slug, $slug );
			}
		}
	}

	/**
	 * Stop direct navigation to hidden plugin pages.
	 */
	public function block_hidden_admin_pages(): void {
		if ( ! $this->should_apply_hiding() || empty( $this->settings['block_hidden_pages'] ) ) {
			return;
		}

		$current_page = isset( $_GET['page'] ) ? $this->sanitize_menu_slug( wp_unslash( $_GET['page'] ) ) : '';

		if ( '' === $current_page || ! in_array( $current_page, $this->get_hidden_menu_slugs(), true ) ) {
			return;
		}

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Download the protected plugin list as JSON.
	 */
	public function handle_export(): void {
		if ( ! $this->can_access_adminclean_ui() ) {
			wp_die( esc_html__( 'You do not have permission to export AdminClean settings.', 'admin-clean' ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		$payload = array(
			'plugin'                 => 'AdminClean',
			'schema_version'         => 1,
			'exported_at'            => gmdate( 'c' ),
			'protected_plugins'      => $this->get_protected_plugins(),
			'protected_plugins_text' => (string) $this->settings['protected_plugins_text'],
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="adminclean-protected-plugins.json"' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import a protected plugin list from JSON or plain text.
	 */
	public function handle_import(): void {
		if ( ! $this->can_access_adminclean_ui() ) {
			wp_die( esc_html__( 'You do not have permission to import AdminClean settings.', 'admin-clean' ) );
		}

		check_admin_referer( self::IMPORT_ACTION );

		$redirect_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );

		if ( empty( $_FILES['adminclean_import_file'] ) || ! is_array( $_FILES['adminclean_import_file'] ) ) {
			$this->redirect_with_import_notice( $redirect_url, 'missing' );
		}

		$file = $_FILES['adminclean_import_file'];

		if ( UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) ) {
			$this->redirect_with_import_notice( $redirect_url, 'upload-error' );
		}

		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$this->redirect_with_import_notice( $redirect_url, 'upload-error' );
		}

		$extension = isset( $file['name'] ) ? strtolower( (string) pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) : '';
		if ( ! in_array( $extension, array( 'json', 'txt' ), true ) ) {
			$this->redirect_with_import_notice( $redirect_url, 'invalid' );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > 262144 ) {
			$this->redirect_with_import_notice( $redirect_url, 'too-large' );
		}

		$contents = file_get_contents( (string) $file['tmp_name'] );

		if ( false === $contents || '' === trim( $contents ) ) {
			$this->redirect_with_import_notice( $redirect_url, 'empty' );
		}

		$protected_plugins = $this->parse_import_contents( $contents );

		if ( empty( $protected_plugins ) ) {
			$this->redirect_with_import_notice( $redirect_url, 'invalid' );
		}

		$this->settings['protected_plugins_text'] = $this->format_protected_plugins_text( $protected_plugins );

		update_option( self::OPTION_NAME, $this->settings, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::SETTINGS_SLUG,
					'adminclean_imported'  => count( $protected_plugins ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stop direct activate/deactivate/delete requests for protected plugins.
	 */
	public function block_protected_plugin_actions(): void {
		global $pagenow;

		if ( ! $this->should_apply_hiding() || 'plugins.php' !== $pagenow ) {
			return;
		}

		$action      = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$plugin_file = isset( $_REQUEST['plugin'] ) ? $this->sanitize_plugin_file( wp_unslash( $_REQUEST['plugin'] ) ) : '';

		if ( ! in_array( $action, array( 'activate', 'deactivate', 'delete-selected' ), true ) || '' === $plugin_file ) {
			return;
		}

		if ( ! $this->is_protected_plugin_file( $plugin_file ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Remove standard admin notice hooks for customer admin users.
	 */
	public function suppress_admin_notices(): void {
		if ( empty( $this->settings['suppress_admin_notices'] ) || $this->is_hiding_exempt_user() ) {
			return;
		}

		$notice_hooks = array(
			'admin_notices',
			'all_admin_notices',
			'network_admin_notices',
			'user_admin_notices',
		);
		$current_hook = current_filter();

		if ( in_array( $current_hook, $notice_hooks, true ) ) {
			$this->filter_admin_notice_callbacks( $current_hook );
			return;
		}

		foreach ( $notice_hooks as $notice_hook ) {
			$this->filter_admin_notice_callbacks( $notice_hook );
		}
	}

	/**
	 * Remove admin notice callbacks except allowed customer-facing exceptions.
	 *
	 * @param string $hook_name Admin notice hook name.
	 */
	private function filter_admin_notice_callbacks( string $hook_name ): void {
		global $wp_filter;

		if (
			empty( $wp_filter[ $hook_name ] )
			|| ! is_object( $wp_filter[ $hook_name ] )
			|| empty( $wp_filter[ $hook_name ]->callbacks )
			|| ! is_array( $wp_filter[ $hook_name ]->callbacks )
		) {
			return;
		}

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}

			foreach ( $callbacks as $callback ) {
				if ( empty( $callback['function'] ) ) {
					continue;
				}

				if ( $this->is_litespeed_cache_callback( $callback['function'] ) ) {
					$this->wrap_litespeed_notice_callback( $hook_name, (int) $priority, $callback );
					continue;
				}

				if ( $this->should_keep_admin_notice_callback( $callback['function'] ) ) {
					continue;
				}

				remove_action( $hook_name, $callback['function'], (int) $priority );
			}
		}
	}

	/**
	 * Replace a LiteSpeed Cache notice callback with an output-filtering wrapper.
	 *
	 * @param string              $hook_name Hook name.
	 * @param int                 $priority  Hook priority.
	 * @param array<string,mixed> $callback  WP hook callback entry.
	 */
	private function wrap_litespeed_notice_callback( string $hook_name, int $priority, array $callback ): void {
		$callback_id = $this->get_notice_callback_id( $hook_name, $priority, $callback['function'] );

		if ( isset( $this->litespeed_notice_callbacks[ $callback_id ] ) ) {
			return;
		}

		$accepted_args = isset( $callback['accepted_args'] ) ? absint( $callback['accepted_args'] ) : 0;

		$this->litespeed_notice_callbacks[ $callback_id ] = array(
			'function'      => $callback['function'],
			'accepted_args' => $accepted_args,
		);

		remove_action( $hook_name, $callback['function'], $priority );

		$wrapper = function () use ( $callback_id ) {
			return $this->render_filtered_litespeed_notice( $callback_id, func_get_args() );
		};

		$this->litespeed_notice_wrapper_hashes[ spl_object_hash( $wrapper ) ] = true;

		add_action( $hook_name, $wrapper, $priority, $accepted_args );
	}

	/**
	 * Run a LiteSpeed Cache notice callback and show only approved notices.
	 *
	 * @param string           $callback_id Stored callback ID.
	 * @param array<int,mixed> $args        Hook arguments.
	 * @return mixed
	 */
	private function render_filtered_litespeed_notice( string $callback_id, array $args ) {
		if ( empty( $this->litespeed_notice_callbacks[ $callback_id ] ) ) {
			return null;
		}

		$callback = $this->litespeed_notice_callbacks[ $callback_id ];

		ob_start();
		$result = call_user_func_array( $callback['function'], array_slice( $args, 0, $callback['accepted_args'] ) );
		$output = ob_get_clean();
		$text   = is_string( $output ) ? wp_strip_all_tags( $output ) : '';

		if ( '' === $text || $this->is_hidden_litespeed_notice_text( $text ) || ! $this->is_allowed_litespeed_notice_text( $text ) ) {
			return $result;
		}

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Original plugin notice output is preserved unchanged.

		return $result;
	}

	/**
	 * Whether LiteSpeed notice text should always be hidden.
	 *
	 * @param string $text Notice text without markup.
	 */
	private function is_hidden_litespeed_notice_text( string $text ): bool {
		$hidden_messages = array(
			'Please consider disabling the following detected plugins, as they may conflict with LiteSpeed Cache:',
		);

		foreach ( $hidden_messages as $message ) {
			if ( false !== strpos( $text, $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether LiteSpeed notice text is approved for customer admins.
	 *
	 * @param string $text Notice text without markup.
	 */
	private function is_allowed_litespeed_notice_text( string $text ): bool {
		$allowed_messages = array(
			'Communicated with Cloudflare successfully.',
			'Unable to communicate with Cloudflare.',
			'No available Cloudflare zone',
			'Notified Cloudflare to purge all successfully.',
			'Purged all caches successfully.',
		);

		foreach ( $allowed_messages as $message ) {
			if ( false !== strpos( $text, $message ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an admin notice callback should remain visible to customer admins.
	 *
	 * @param mixed $callback Hook callback.
	 */
	private function should_keep_admin_notice_callback( $callback ): bool {
		if (
			is_array( $callback )
			&& isset( $callback[0], $callback[1] )
			&& $callback[0] === $this
			&& 'suppress_admin_notices' === $callback[1]
		) {
			return true;
		}

		return $this->is_litespeed_notice_wrapper( $callback );
	}

	/**
	 * Whether a callback is an AdminClean LiteSpeed notice wrapper.
	 *
	 * @param mixed $callback Hook callback.
	 */
	private function is_litespeed_notice_wrapper( $callback ): bool {
		return $callback instanceof Closure && ! empty( $this->litespeed_notice_wrapper_hashes[ spl_object_hash( $callback ) ] );
	}

	/**
	 * Whether a callback appears to belong to the LiteSpeed Cache plugin.
	 *
	 * @param mixed $callback Hook callback.
	 */
	private function is_litespeed_cache_callback( $callback ): bool {
		$markers = array(
			'litespeed-cache',
			'litespeed_cache',
			'litespeed',
			'lscache',
		);

		$values = array( $this->get_callback_file( $callback ) );

		if ( is_string( $callback ) ) {
			$values[] = $callback;
		} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$values[] = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$values[] = (string) $callback[1];
		} elseif ( is_object( $callback ) ) {
			$values[] = get_class( $callback );
		}

		foreach ( $values as $value ) {
			$value = strtolower( (string) $value );

			foreach ( $markers as $marker ) {
				if ( false !== strpos( $value, $marker ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the source file for a hook callback when reflection can resolve it.
	 *
	 * @param mixed $callback Hook callback.
	 */
	private function get_callback_file( $callback ): string {
		try {
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && method_exists( $callback[0], (string) $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return '';
			}
		} catch ( ReflectionException $exception ) {
			return '';
		}

		$file = $reflection->getFileName();

		return is_string( $file ) ? $file : '';
	}

	/**
	 * Build a stable storage key for a notice callback.
	 *
	 * @param string $hook_name Hook name.
	 * @param int    $priority  Hook priority.
	 * @param mixed  $callback  Hook callback.
	 */
	private function get_notice_callback_id( string $hook_name, int $priority, $callback ): string {
		return md5( $hook_name . '|' . $priority . '|' . $this->describe_callback( $callback ) );
	}

	/**
	 * Describe a callback without invoking it.
	 *
	 * @param mixed $callback Hook callback.
	 */
	private function describe_callback( $callback ): string {
		if ( is_string( $callback ) ) {
			return 'function:' . $callback;
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$target = is_object( $callback[0] ) ? get_class( $callback[0] ) . ':' . spl_object_hash( $callback[0] ) : (string) $callback[0];

			return 'method:' . $target . '::' . (string) $callback[1];
		}

		if ( $callback instanceof Closure ) {
			return 'closure:' . spl_object_hash( $callback );
		}

		if ( is_object( $callback ) ) {
			return 'object:' . get_class( $callback ) . ':' . spl_object_hash( $callback );
		}

		return 'unknown:' . gettype( $callback );
	}

	/**
	 * Save settings posted from the settings page.
	 */
	public function maybe_save_settings(): void {
		if ( empty( $_POST['adminclean_submit'] ) ) {
			return;
		}

		if ( ! $this->can_access_adminclean_ui() ) {
			wp_die( esc_html__( 'You do not have permission to save AdminClean settings.', 'admin-clean' ) );
		}

		if (
			empty( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'AdminClean settings could not be saved. Please refresh and try again.', 'admin-clean' ) );
		}

		$manager_ids_text       = isset( $_POST['manager_user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['manager_user_ids'] ) ) : '';
		$protected_plugins_text = isset( $_POST['protected_plugins_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['protected_plugins_text'] ) ) : '';

		$settings = array(
			'manager_user_ids'       => $this->parse_ids( $manager_ids_text ),
			'enable_hiding'          => ! empty( $_POST['enable_hiding'] ),
			'hide_plugin_rows'       => ! empty( $_POST['hide_plugin_rows'] ),
			'hide_admin_menus'       => ! empty( $_POST['hide_admin_menus'] ),
			'block_hidden_pages'     => ! empty( $_POST['block_hidden_pages'] ),
			'suppress_admin_notices' => ! empty( $_POST['suppress_admin_notices'] ),
			'protected_plugins_text' => $protected_plugins_text,
		);

		update_option( self::OPTION_NAME, $settings, false );

		$this->settings = $this->get_settings();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SETTINGS_SLUG,
					'adminclean_saved'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Load admin CSS only on AdminClean screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'adminclean-admin',
			ADMINCLEAN_URL . 'assets/admin.css',
			array(),
			ADMINCLEAN_VERSION
		);
	}

	/**
	 * Show Harvey Plum support details on AdminClean pages.
	 *
	 * @param mixed $text Existing footer text.
	 */
	public function filter_admin_footer_text( $text ): string {
		$text = is_string( $text ) ? $text : '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( $screen->id, self::MENU_SLUG ) ) {
			return $text;
		}

		return sprintf(
			wp_kses(
				__( 'Need support? Email <a href="%s">support@harveyplum.com</a>.', 'admin-clean' ),
				array(
					'a' => array(
						'href' => array(),
					),
				)
			),
			esc_url( 'mailto:support@harveyplum.com' )
		);
	}

	/**
	 * Render managed plugin dashboard.
	 */
	public function render_dashboard_page(): void {
		if ( ! $this->can_access_adminclean_ui() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'admin-clean' ) );
		}

		$protected_plugins = $this->get_protected_plugins();
		$installed_plugins = $this->get_installed_plugins();
		$current_orderby   = $this->get_dashboard_orderby();
		$current_order     = $this->get_dashboard_order();
		$protected_plugins = $this->sort_protected_plugins( $protected_plugins, $installed_plugins, $current_orderby, $current_order );
		?>
		<div class="wrap adminclean-wrap">
			<h1><?php esc_html_e( 'Managed Plugins', 'admin-clean' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'These plugins are hidden from customer admin users and collected here for trusted managers.', 'admin-clean' ); ?>
			</p>

			<?php if ( empty( $protected_plugins ) ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s is the settings page URL. */
								__( 'No managed plugins have been configured yet. Add them on the <a href="%s">AdminClean settings</a> page.', 'admin-clean' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) )
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="widefat striped adminclean-table">
					<thead>
						<tr>
							<th scope="col"><?php $this->render_sortable_column_header( __( 'Plugin', 'admin-clean' ), 'plugin', $current_orderby, $current_order ); ?></th>
							<th scope="col"><?php $this->render_sortable_column_header( __( 'Plugin File', 'admin-clean' ), 'plugin_file', $current_orderby, $current_order ); ?></th>
							<th scope="col"><?php $this->render_sortable_column_header( __( 'Status', 'admin-clean' ), 'status', $current_orderby, $current_order ); ?></th>
							<th scope="col"><?php $this->render_sortable_column_header( __( 'Hidden Menus', 'admin-clean' ), 'menus', $current_orderby, $current_order ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $protected_plugins as $protected_plugin ) : ?>
							<?php
							$plugin_file = $protected_plugin['plugin_file'];
							$plugin_data = $installed_plugins[ $plugin_file ] ?? null;
							$is_active   = is_plugin_active( $plugin_file );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $protected_plugin['label'] ); ?></strong>
									<?php if ( is_array( $plugin_data ) && ! empty( $plugin_data['Version'] ) ) : ?>
										<span class="adminclean-muted"><?php echo esc_html( 'v' . $plugin_data['Version'] ); ?></span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $plugin_file ); ?></code></td>
								<td>
									<?php if ( ! is_array( $plugin_data ) ) : ?>
										<span class="adminclean-badge adminclean-badge-missing"><?php esc_html_e( 'Not installed', 'admin-clean' ); ?></span>
									<?php elseif ( $is_active ) : ?>
										<span class="adminclean-badge adminclean-badge-active"><?php esc_html_e( 'Active', 'admin-clean' ); ?></span>
									<?php else : ?>
										<span class="adminclean-badge adminclean-badge-inactive"><?php esc_html_e( 'Inactive', 'admin-clean' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( empty( $protected_plugin['menu_slugs'] ) ) : ?>
										<span class="adminclean-muted"><?php esc_html_e( 'None configured', 'admin-clean' ); ?></span>
									<?php else : ?>
										<?php foreach ( $protected_plugin['menu_slugs'] as $slug ) : ?>
											<code class="adminclean-inline-code"><?php echo esc_html( $slug ); ?></code>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the requested dashboard sort column.
	 */
	private function get_dashboard_orderby(): string {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'plugin';
		$allowed = array( 'plugin', 'plugin_file', 'status', 'menus' );

		return in_array( $orderby, $allowed, true ) ? $orderby : 'plugin';
	}

	/**
	 * Get the requested dashboard sort direction.
	 */
	private function get_dashboard_order(): string {
		$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'asc';

		return 'desc' === $order ? 'desc' : 'asc';
	}

	/**
	 * Render a linked table header for Managed Plugins sorting.
	 *
	 * @param string $label           Column label.
	 * @param string $orderby         Column key.
	 * @param string $current_orderby Current column key.
	 * @param string $current_order   Current sort direction.
	 */
	private function render_sortable_column_header( string $label, string $orderby, string $current_orderby, string $current_order ): void {
		$is_current = $orderby === $current_orderby;
		$next_order = $is_current && 'asc' === $current_order ? 'desc' : 'asc';
		$url        = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'orderby' => $orderby,
				'order'   => $next_order,
			),
			admin_url( 'admin.php' )
		);

		?>
		<a class="adminclean-sort-link" href="<?php echo esc_url( $url ); ?>">
			<span><?php echo esc_html( $label ); ?></span>
			<?php if ( $is_current ) : ?>
				<span class="adminclean-sort-direction"><?php echo esc_html( strtoupper( $current_order ) ); ?></span>
			<?php endif; ?>
		</a>
		<?php
	}

	/**
	 * Sort protected plugin rows for the dashboard.
	 *
	 * @param array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}> $plugins           Protected plugins.
	 * @param array<string,array<string,mixed>>                                           $installed_plugins Installed plugin data.
	 * @param string                                                                      $orderby           Column key.
	 * @param string                                                                      $order             Sort direction.
	 * @return array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}>
	 */
	private function sort_protected_plugins( array $plugins, array $installed_plugins, string $orderby, string $order ): array {
		usort(
			$plugins,
			function ( array $a, array $b ) use ( $installed_plugins, $orderby, $order ): int {
				$a_value = $this->get_sort_value( $a, $installed_plugins, $orderby );
				$b_value = $this->get_sort_value( $b, $installed_plugins, $orderby );
				$result  = strnatcasecmp( $a_value, $b_value );

				if ( 0 === $result ) {
					$result = strnatcasecmp( $a['label'], $b['label'] );
				}

				return 'desc' === $order ? -$result : $result;
			}
		);

		return $plugins;
	}

	/**
	 * Get comparable dashboard sort value for a plugin row.
	 *
	 * @param array{plugin_file:string,label:string,menu_slugs:array<int,string>} $plugin            Plugin row.
	 * @param array<string,array<string,mixed>>                                  $installed_plugins Installed plugin data.
	 * @param string                                                             $orderby           Column key.
	 */
	private function get_sort_value( array $plugin, array $installed_plugins, string $orderby ): string {
		switch ( $orderby ) {
			case 'plugin_file':
				return $plugin['plugin_file'];

			case 'status':
				return $this->get_plugin_status_label( $plugin['plugin_file'], $installed_plugins );

			case 'menus':
				return implode( ', ', $plugin['menu_slugs'] );

			case 'plugin':
			default:
				return $plugin['label'];
		}
	}

	/**
	 * Get a display status label for a plugin.
	 *
	 * @param string                            $plugin_file       Plugin file.
	 * @param array<string,array<string,mixed>> $installed_plugins Installed plugin data.
	 */
	private function get_plugin_status_label( string $plugin_file, array $installed_plugins ): string {
		if ( ! isset( $installed_plugins[ $plugin_file ] ) ) {
			return __( 'Not installed', 'admin-clean' );
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return __( 'Active', 'admin-clean' );
		}

		return __( 'Inactive', 'admin-clean' );
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! $this->can_access_adminclean_ui() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'admin-clean' ) );
		}

		$manager_ids_text = implode( ', ', $this->settings['manager_user_ids'] );
		?>
		<div class="wrap adminclean-wrap">
			<h1><?php esc_html_e( 'AdminClean Settings', 'admin-clean' ); ?></h1>

			<?php if ( ! empty( $_GET['adminclean_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'admin-clean' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_import_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Hiding status', 'admin-clean' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enable_hiding" value="1" <?php checked( $this->settings['enable_hiding'] ); ?> />
								<?php esc_html_e( 'Enable AdminClean hiding', 'admin-clean' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Turn this off to disable protected plugin hiding, redirects, and protected plugin action blocking for every user.', 'admin-clean' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="manager_user_ids"><?php esc_html_e( 'Trusted manager user IDs', 'admin-clean' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="manager_user_ids"
								name="manager_user_ids"
								value="<?php echo esc_attr( $manager_ids_text ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'These administrator users can see AdminClean and the protected plugins. Separate IDs with commas. Administrator users with @harveyplum.com email addresses are always exempt from hiding.', 'admin-clean' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer admin behavior', 'admin-clean' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="hide_plugin_rows" value="1" <?php checked( $this->settings['hide_plugin_rows'] ); ?> />
								<?php esc_html_e( 'Hide protected plugins on the Plugins screen', 'admin-clean' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="hide_admin_menus" value="1" <?php checked( $this->settings['hide_admin_menus'] ); ?> />
								<?php esc_html_e( 'Hide protected plugin admin menu items', 'admin-clean' ); ?>
							</label>
							<br />
							<label>
								<input type="checkbox" name="block_hidden_pages" value="1" <?php checked( $this->settings['block_hidden_pages'] ); ?> />
								<?php esc_html_e( 'Redirect customer admins away from hidden plugin pages', 'admin-clean' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin notices', 'admin-clean' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="suppress_admin_notices" value="1" <?php checked( $this->settings['suppress_admin_notices'] ); ?> />
								<?php esc_html_e( 'Suppress admin notices for customer admins', 'admin-clean' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'This setting is independent from the main hiding switch. Trusted managers and @harveyplum.com administrators still see admin notices.', 'admin-clean' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="protected_plugins_text"><?php esc_html_e( 'Protected plugins', 'admin-clean' ); ?></label>
						</th>
						<td>
							<textarea
								class="large-text code adminclean-textarea"
								id="protected_plugins_text"
								name="protected_plugins_text"
								rows="12"
								placeholder="wordfence/wordfence.php | Security | Wordfence,WordfenceWAF&#10;wp-rocket/wp-rocket.php | Performance | wp-rocket"
							><?php echo esc_textarea( (string) $this->settings['protected_plugins_text'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Use one plugin per line: plugin-file.php | Label | menu-slug-1,menu-slug-2. The label and menu slugs are optional.', 'admin-clean' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" name="adminclean_submit" value="1">
						<?php esc_html_e( 'Save Settings', 'admin-clean' ); ?>
					</button>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Import and Export', 'admin-clean' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Export the protected plugin list from one site, then import it on the next site. Trusted manager IDs and behavior toggles stay local to each site.', 'admin-clean' ); ?>
			</p>

			<div class="adminclean-tools">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" />
					<?php wp_nonce_field( self::EXPORT_ACTION ); ?>
					<button type="submit" class="button">
						<?php esc_html_e( 'Export Protected Plugin List', 'admin-clean' ); ?>
					</button>
				</form>

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
					<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
					<label for="adminclean_import_file" class="screen-reader-text">
						<?php esc_html_e( 'Import protected plugin list', 'admin-clean' ); ?>
					</label>
					<input type="file" id="adminclean_import_file" name="adminclean_import_file" accept=".json,.txt,text/plain,application/json" required />
					<button type="submit" class="button">
						<?php esc_html_e( 'Import List', 'admin-clean' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render import success/error notices.
	 */
	private function render_import_notice(): void {
		if ( ! empty( $_GET['adminclean_imported'] ) ) {
			$count = absint( $_GET['adminclean_imported'] );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d is the number of imported plugins. */
						esc_html__( 'Imported %d protected plugin entries.', 'admin-clean' ),
						$count
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		if ( empty( $_GET['adminclean_import_error'] ) ) {
			return;
		}

		$error = sanitize_key( wp_unslash( $_GET['adminclean_import_error'] ) );

		$messages = array(
			'missing'      => __( 'Choose a file to import.', 'admin-clean' ),
			'upload-error' => __( 'The import file could not be uploaded.', 'admin-clean' ),
			'too-large'    => __( 'The import file is too large. Keep it under 256 KB.', 'admin-clean' ),
			'empty'        => __( 'The import file is empty.', 'admin-clean' ),
			'invalid'      => __( 'No valid protected plugin entries were found in the import file.', 'admin-clean' ),
		);

		$message = $messages[ $error ] ?? __( 'The import could not be completed.', 'admin-clean' );
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Redirect back to settings with an import error notice.
	 *
	 * @param string $redirect_url Settings URL.
	 * @param string $error        Error key.
	 */
	private function redirect_with_import_notice( string $redirect_url, string $error ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'adminclean_import_error' => $error,
				),
				$redirect_url
			)
		);
		exit;
	}

	/**
	 * Whether the current user can manage AdminClean.
	 */
	private function is_manager(): bool {
		return $this->can_access_adminclean_ui();
	}

	/**
	 * Whether hiding should apply to the current user.
	 */
	private function should_apply_hiding(): bool {
		if ( empty( $this->settings['enable_hiding'] ) ) {
			return false;
		}

		return ! $this->is_hiding_exempt_user();
	}

	/**
	 * Whether the current user should bypass all hiding behavior.
	 */
	private function is_hiding_exempt_user(): bool {
		return $this->can_access_adminclean_ui();
	}

	/**
	 * Whether the current user can access AdminClean screens.
	 */
	private function can_access_adminclean_ui(): bool {
		return current_user_can( self::DEFAULT_CAPABILITY ) && $this->current_user_has_email_domain( self::AGENCY_EMAIL_DOMAIN );
	}

	/**
	 * Whether the current user has an email address at a domain.
	 *
	 * @param string $domain Email domain without @.
	 */
	private function current_user_has_email_domain( string $domain ): bool {
		$user = wp_get_current_user();

		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$email  = strtolower( trim( (string) $user->user_email ) );
		$domain = '@' . strtolower( ltrim( $domain, '@' ) );

		return substr( $email, -strlen( $domain ) ) === $domain;
	}

	/**
	 * Parse protected plugin configuration from the settings textarea.
	 *
	 * @return array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}>
	 */
	private function get_protected_plugins(): array {
		return $this->parse_protected_plugins_text( (string) $this->settings['protected_plugins_text'] );
	}

	/**
	 * Parse protected plugin configuration text.
	 *
	 * @param string $text Protected plugin text.
	 * @return array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}>
	 */
	private function parse_protected_plugins_text( string $text ): array {
		$lines   = preg_split( '/\r\n|\r|\n/', $text );
		$plugins = array();

		if ( ! is_array( $lines ) ) {
			return $plugins;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			$parts       = array_map( 'trim', explode( '|', $line ) );
			$plugin_file = $this->sanitize_plugin_file( $parts[0] ?? '' );

			if ( '' === $plugin_file ) {
				continue;
			}

			$label      = sanitize_text_field( $parts[1] ?? '' );
			$menu_slugs = $this->parse_csv_slugs( $parts[2] ?? '' );

			$plugins[] = array(
				'plugin_file' => $plugin_file,
				'label'       => '' !== $label ? $label : $plugin_file,
				'menu_slugs'  => $menu_slugs,
			);
		}

		return $plugins;
	}

	/**
	 * Parse imported JSON or plain-text plugin configuration.
	 *
	 * @param string $contents File contents.
	 * @return array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}>
	 */
	private function parse_import_contents( string $contents ): array {
		$decoded = json_decode( $contents, true );

		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['protected_plugins_text'] ) && is_string( $decoded['protected_plugins_text'] ) ) {
				return $this->parse_protected_plugins_text( $decoded['protected_plugins_text'] );
			}

			if ( isset( $decoded['protected_plugins'] ) && is_array( $decoded['protected_plugins'] ) ) {
				return $this->normalize_imported_plugins( $decoded['protected_plugins'] );
			}

			return $this->normalize_imported_plugins( $decoded );
		}

		return $this->parse_protected_plugins_text( $contents );
	}

	/**
	 * Normalize imported plugin rows.
	 *
	 * @param array<int|string,mixed> $plugins Imported plugin rows.
	 * @return array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}>
	 */
	private function normalize_imported_plugins( array $plugins ): array {
		$normalized = array();

		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) ) {
				$normalized = array_merge( $normalized, $this->parse_protected_plugins_text( $plugin ) );
				continue;
			}

			if ( ! is_array( $plugin ) ) {
				continue;
			}

			$plugin_file = $this->sanitize_plugin_file( (string) ( $plugin['plugin_file'] ?? '' ) );

			if ( '' === $plugin_file ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $plugin['label'] ?? $plugin_file ) );
			$slugs = array();

			if ( isset( $plugin['menu_slugs'] ) && is_array( $plugin['menu_slugs'] ) ) {
				foreach ( $plugin['menu_slugs'] as $slug ) {
					$slugs[] = $this->sanitize_menu_slug( $slug );
				}
			} elseif ( isset( $plugin['menu_slugs'] ) && is_string( $plugin['menu_slugs'] ) ) {
				$slugs = $this->parse_csv_slugs( $plugin['menu_slugs'] );
			}

			$normalized[] = array(
				'plugin_file' => $plugin_file,
				'label'       => '' !== $label ? $label : $plugin_file,
				'menu_slugs'  => array_values( array_unique( array_filter( $slugs ) ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Format plugin rows as settings text.
	 *
	 * @param array<int,array{plugin_file:string,label:string,menu_slugs:array<int,string>}> $plugins Plugin rows.
	 */
	private function format_protected_plugins_text( array $plugins ): string {
		$lines = array();

		foreach ( $plugins as $plugin ) {
			$line = $plugin['plugin_file'];

			if ( ! empty( $plugin['label'] ) || ! empty( $plugin['menu_slugs'] ) ) {
				$line .= ' | ' . $plugin['label'];
			}

			if ( ! empty( $plugin['menu_slugs'] ) ) {
				$line .= ' | ' . implode( ',', $plugin['menu_slugs'] );
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get all hidden menu slugs.
	 *
	 * @return array<int,string>
	 */
	private function get_hidden_menu_slugs(): array {
		$slugs = array();

		foreach ( $this->get_protected_plugins() as $protected_plugin ) {
			$slugs = array_merge( $slugs, $protected_plugin['menu_slugs'] );
		}

		$slugs = array_diff( $slugs, array( self::MENU_SLUG, self::SETTINGS_SLUG ) );

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * Whether a plugin file is protected.
	 *
	 * @param string $plugin_file Plugin file.
	 */
	private function is_protected_plugin_file( string $plugin_file ): bool {
		foreach ( $this->get_protected_plugins() as $protected_plugin ) {
			if ( $plugin_file === $protected_plugin['plugin_file'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a plugin file points to AdminClean itself.
	 */
	private function is_adminclean_plugin_file( string $plugin_file ): bool {
		$adminclean_plugin_file = defined( 'ADMINCLEAN_FILE' ) ? plugin_basename( ADMINCLEAN_FILE ) : 'admin-clean/admin-clean.php';

		return $plugin_file === $adminclean_plugin_file;
	}

	/**
	 * Get installed plugins.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * Parse a comma-separated ID list.
	 *
	 * @param string $value Raw value.
	 * @return array<int,int>
	 */
	private function parse_ids( string $value ): array {
		$ids = preg_split( '/[\s,]+/', $value );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids )
				)
			)
		);
	}

	/**
	 * Parse comma-separated menu slugs.
	 *
	 * @param string $value Raw value.
	 * @return array<int,string>
	 */
	private function parse_csv_slugs( string $value ): array {
		$slugs = array_map( 'trim', explode( ',', $value ) );
		$slugs = array_map( array( $this, 'sanitize_menu_slug' ), $slugs );

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * Sanitize a WordPress plugin file path.
	 *
	 * @param string $value Raw plugin file.
	 */
	private function sanitize_plugin_file( string $value ): string {
		$value = wp_normalize_path( trim( $value ) );
		$value = preg_replace( '#[^A-Za-z0-9_\-/\.]#', '', $value );

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = ltrim( $value, '/' );

		if ( false !== strpos( $value, '..' ) || false === strpos( $value, '.php' ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitize a menu slug while preserving case for plugins that rely on it.
	 *
	 * @param mixed $value Raw menu slug.
	 */
	private function sanitize_menu_slug( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		$value = sanitize_text_field( $value );
		$value = preg_replace( '#[^A-Za-z0-9_\-/\.=&:%]#', '', $value );

		return is_string( $value ) ? $value : '';
	}
}
