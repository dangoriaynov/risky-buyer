<?php
/**
 * Plugin bootstrap — wires the pieces and registers shared admin assets.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Probclient_Plugin {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'Probclient_Local_Table_Provider', 'maybe_install' ) );

		Probclient_Ajax::instance()->hooks();

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			Probclient_Admin_Page::instance()->hooks();
			Probclient_Order_Metabox::instance()->hooks();
			Probclient_Orders_List::instance()->hooks();
		}
	}

	/**
	 * Screens where our admin CSS/JS is needed.
	 *
	 * @param object|null $screen Current screen.
	 * @return bool
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'problem-client', false, dirname( plugin_basename( PROBCLIENT_FILE ) ) . '/languages' );
	}

	public static function is_relevant_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		$ids = array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' );
		if ( in_array( $screen->id, $ids, true ) ) {
			return true;
		}
		return false !== strpos( (string) $screen->id, 'problem-client' );
	}

	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::is_relevant_screen( $screen ) ) {
			return;
		}

		wp_enqueue_style( 'probclient-admin', PROBCLIENT_URL . 'assets/admin.css', array(), PROBCLIENT_VERSION );
		wp_enqueue_script( 'probclient-admin', PROBCLIENT_URL . 'assets/admin.js', array( 'jquery' ), PROBCLIENT_VERSION, true );

		$bl = Probclient_Blacklist::instance();
		wp_localize_script(
			'probclient-admin',
			'ProbclientData',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'probclient_ajax' ),
				'reasons'    => Probclient_Blacklist::reasons(),
				'can_add'    => $bl->can_add(),
				'can_manage' => $bl->can_manage(),
				'i18n'       => array(
					'confirm_remove' => 'Да премахна ли този клиент от списъка?',
					'error'          => 'Възникна грешка.',
				),
			)
		);
	}
}
