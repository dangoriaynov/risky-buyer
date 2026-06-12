<?php
/**
 * Plugin Name: Problem Client — споделен черен списък на клиенти
 * Description: Маркира проблемни клиенти (по телефон/име) с причина и бележка; автоматично отбелязва техните поръчки в списъка. Архитектура със storage provider за бъдеща централизация между сайтове.
 * Version: 0.2.0
 * Author: dangoriaynov
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: problem-client
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PC_VERSION', '0.2.0' );
define( 'PC_DB_VERSION', '1' );
define( 'PC_FILE', __FILE__ );
define( 'PC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PC_URL', plugin_dir_url( __FILE__ ) );

require_once PC_DIR . 'includes/storage/interface-pc-storage-provider.php';
require_once PC_DIR . 'includes/storage/class-pc-local-table-provider.php';
require_once PC_DIR . 'includes/class-pc-blacklist.php';
require_once PC_DIR . 'includes/class-pc-matcher.php';
require_once PC_DIR . 'includes/class-pc-ajax.php';
require_once PC_DIR . 'includes/admin/class-pc-orders-list.php';
require_once PC_DIR . 'includes/admin/class-pc-order-metabox.php';
require_once PC_DIR . 'includes/admin/class-pc-admin-page.php';
require_once PC_DIR . 'includes/class-pc-plugin.php';

register_activation_hook( __FILE__, array( 'PC_Local_Table_Provider', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		PC_Plugin::instance()->init();
	}
);
