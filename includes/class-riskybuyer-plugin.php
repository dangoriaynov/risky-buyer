<?php
/**
 * Plugin bootstrap — wires the pieces and registers shared admin assets.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Plugin {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'Riskybuyer_Local_Table_Provider', 'maybe_install' ) );

		Riskybuyer_Ajax::instance()->hooks();
		Riskybuyer_Remote_Sync::instance()->hooks();

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			Riskybuyer_Admin_Page::instance()->hooks();
			Riskybuyer_Order_Metabox::instance()->hooks();
			Riskybuyer_Orders_List::instance()->hooks();
		}
	}

	/**
	 * Screens where our admin CSS/JS is needed.
	 *
	 * @param object|null $screen Current screen.
	 * @return bool
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'risky-buyer', false, dirname( plugin_basename( RISKYBUYER_FILE ) ) . '/languages' );
	}

	public static function is_relevant_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}
		$ids = array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' );
		if ( in_array( $screen->id, $ids, true ) ) {
			return true;
		}
		return false !== strpos( (string) $screen->id, 'risky-buyer' );
	}

	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::is_relevant_screen( $screen ) ) {
			return;
		}

		wp_enqueue_style( 'riskybuyer-admin', RISKYBUYER_URL . 'assets/admin.css', array(), RISKYBUYER_VERSION );
		wp_enqueue_script( 'riskybuyer-admin', RISKYBUYER_URL . 'assets/admin.js', array( 'jquery' ), RISKYBUYER_VERSION, true );

		$bl = Riskybuyer_Blacklist::instance();
		wp_localize_script(
			'riskybuyer-admin',
			'RiskyBuyerData',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'riskybuyer_ajax' ),
				'reasons'    => Riskybuyer_Blacklist::reasons(),
				'can_add'    => $bl->can_add(),
				'can_manage' => $bl->can_manage(),
				'i18n'       => array(
					'confirm_remove' => __( 'Remove this client from the list?', 'risky-buyer' ),
					'error'          => __( 'An error occurred.', 'risky-buyer' ),
					'saving'         => __( 'Saving…', 'risky-buyer' ),
					'saved'          => __( 'Saved', 'risky-buyer' ),
					'checking'       => __( 'Checking…', 'risky-buyer' ),
					'key_invalid'    => __( 'Invalid or unreachable key', 'risky-buyer' ),
				),
			)
		);
	}
}
