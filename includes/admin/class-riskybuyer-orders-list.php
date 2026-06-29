<?php
/**
 * Marks orders of blacklisted clients in the orders list table.
 *
 * Visual language is intentionally different from any "duplicate" marker:
 * a warning badge + a left colour bar (no full-row outline). The badge/bar
 * styles live in assets/admin.css; the row-marking logic in assets/orders-list.js.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Orders_List {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'current_screen', array( $this, 'maybe_boot' ) );
	}

	public function maybe_boot( $screen ) {
		if ( ! $screen ) {
			return;
		}
		if ( 'woocommerce_page_wc-orders' !== $screen->id && 'edit-shop_order' !== $screen->id ) {
			return;
		}
		// Skip the single-order edit screen (it shares the HPOS screen id).
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if ( 'edit' === $action ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$map = Riskybuyer_Order_Matcher::build_marked_map();
		if ( empty( $map ) ) {
			return;
		}

		// Shared admin styles (which include the .rb-flag / .rb-badge rules) are
		// already enqueued by Riskybuyer_Plugin on this screen.
		wp_enqueue_script( 'riskybuyer-orders-list', RISKYBUYER_URL . 'assets/orders-list.js', array(), RISKYBUYER_VERSION, true );
		wp_localize_script(
			'riskybuyer-orders-list',
			'RiskybuyerOrdersList',
			array(
				'map'   => $map,
				'label' => __( 'Risky buyer', 'riskybuyer' ),
			)
		);
	}
}
