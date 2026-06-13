<?php
/**
 * Plugin Name: Problem Client
 * Description: Flag problematic WooCommerce customers by phone or name (with a reason and note) and automatically mark their orders in the admin. Storage-provider architecture, ready for a future shared/central list across sites.
 * Version: 0.3.0
 * Author: dangoriaynov
 * Author URI: https://github.com/dangoriaynov
 * Plugin URI: https://github.com/dangoriaynov/problem-client
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires Plugins: woocommerce
 * Text Domain: problem-client
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROBCLIENT_VERSION', '0.3.0' );
define( 'PROBCLIENT_DB_VERSION', '1' );
define( 'PROBCLIENT_FILE', __FILE__ );
define( 'PROBCLIENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROBCLIENT_URL', plugin_dir_url( __FILE__ ) );

require_once PROBCLIENT_DIR . 'includes/storage/interface-pc-storage-provider.php';
require_once PROBCLIENT_DIR . 'includes/storage/class-pc-local-table-provider.php';
require_once PROBCLIENT_DIR . 'includes/class-pc-blacklist.php';
require_once PROBCLIENT_DIR . 'includes/class-pc-matcher.php';
require_once PROBCLIENT_DIR . 'includes/class-pc-ajax.php';
require_once PROBCLIENT_DIR . 'includes/admin/class-pc-orders-list.php';
require_once PROBCLIENT_DIR . 'includes/admin/class-pc-order-metabox.php';
require_once PROBCLIENT_DIR . 'includes/admin/class-pc-admin-page.php';
require_once PROBCLIENT_DIR . 'includes/class-pc-plugin.php';

register_activation_hook( __FILE__, array( 'Probclient_Local_Table_Provider', 'install' ) );

// Declare WooCommerce HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		Probclient_Plugin::instance()->init();
	}
);
