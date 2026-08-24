<?php
/**
 * Plugin Name: AdminClean
 * Plugin URI: https://harveyplum.com/adminclean/
 * Description: This plugin hides irrelevant notices and default plugins for Harvey Plum Hosting customers to keep their admin interfaces clean. Please deactivate it to see all notices and plugins.
 * Version: 0.5.8
 * Author: Harvey Plum
 * Author URI: https://harveyplum.com
 * GitHub Plugin URI: https://github.com/HarveyPlum/admin-clean
 * Update URI: https://github.com/HarveyPlum/admin-clean
 * Primary Branch: main
 * Release Asset: true
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: admin-clean
 *
 * @package AdminClean
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADMINCLEAN_VERSION', '0.5.8' );
define( 'ADMINCLEAN_FILE', __FILE__ );
define( 'ADMINCLEAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADMINCLEAN_URL', plugin_dir_url( __FILE__ ) );

require_once ADMINCLEAN_DIR . 'includes/class-admin-clean-plugin.php';

register_activation_hook( __FILE__, array( 'AdminClean_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		AdminClean_Plugin::instance();
	}
);
