<?php
/**
 * Plugin Name: Risky Buyer
 * Description: Flag problematic WooCommerce customers by phone or name (with a reason and note) and automatically mark their orders in the admin. Optional sync with a shared central list (riskybuyer.com).
 * Version: 1.0.1
 * Author: dangoriaynov
 * Author URI: https://github.com/dangoriaynov
 * Plugin URI: https://github.com/dangoriaynov/risky-buyer
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires Plugins: woocommerce
 * Text Domain: risky-buyer
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RISKYBUYER_VERSION', '1.0.1' );
define( 'RISKYBUYER_DB_VERSION', '2' );
define( 'RISKYBUYER_FILE', __FILE__ );
define( 'RISKYBUYER_DIR', plugin_dir_path( __FILE__ ) );
define( 'RISKYBUYER_URL', plugin_dir_url( __FILE__ ) );

require_once RISKYBUYER_DIR . 'includes/storage/interface-riskybuyer-storage-provider.php';
require_once RISKYBUYER_DIR . 'includes/storage/class-riskybuyer-local-table-provider.php';
require_once RISKYBUYER_DIR . 'includes/class-riskybuyer-settings.php';
require_once RISKYBUYER_DIR . 'includes/class-riskybuyer-remote-sync.php';
require_once RISKYBUYER_DIR . 'includes/class-riskybuyer-blacklist.php';
require_once RISKYBUYER_DIR . 'includes/class-riskybuyer-matcher.php';
require_once RISKYBUYER_DIR . 'includes/class-riskybuyer-ajax.php';
require_once RISKYBUYER_DIR . 'includes/admin/class-riskybuyer-orders-list.php';
require_once RISKYBUYER_DIR . 'includes/admin/class-riskybuyer-order-metabox.php';
require_once RISKYBUYER_DIR . 'includes/admin/class-riskybuyer-admin-page.php';
require_once RISKYBUYER_DIR . 'includes/class-riskybuyer-plugin.php';

register_activation_hook( __FILE__, array( 'Riskybuyer_Local_Table_Provider', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Riskybuyer_Remote_Sync', 'clear_schedule' ) );

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
		Riskybuyer_Plugin::instance()->init();
	}
);
