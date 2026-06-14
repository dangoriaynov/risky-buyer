<?php
/**
 * Order edit screen: shows a warning if the client is blacklisted and a
 * "mark client" form otherwise.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Order_Metabox {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'admin_notices', array( $this, 'order_notice' ) );
	}

	public function add_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$maybe = wc_get_page_screen_id( 'shop-order' );
			if ( $maybe ) {
				$screens[] = $maybe;
			}
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'riskybuyer_metabox',
				'⚠ ' . __( 'Risky buyer', 'risky-buyer' ),
				array( $this, 'render_box' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Resolve the order id from the current order-edit request (HPOS or legacy).
	 *
	 * @return int
	 */
	protected function current_order_id() {
		if ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			return absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			return absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		}
		return 0;
	}

	/**
	 * Prominent banner at the top of the order-edit screen for a blacklisted client.
	 */
	public function order_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$is_edit = ( 'woocommerce_page_wc-orders' === $screen->id && 'edit' === $action ) || 'shop_order' === $screen->id;
		if ( ! $is_edit || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order_id = $this->current_order_id();
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$entry = Riskybuyer_Blacklist::instance()->match_order( $order );
		if ( ! $entry ) {
			return;
		}

		$color = Riskybuyer_Blacklist::reason_color( $entry['reason_code'] );
		$label = Riskybuyer_Blacklist::reason_label( $entry['reason_code'] );
		echo '<div class="notice" style="border-left:5px solid ' . esc_attr( $color ) . ';background:#fff6f6;">';
		echo '<p style="font-size:14px;margin:.6em 0;"><strong style="color:' . esc_attr( $color ) . ';">⛔ ' . esc_html__( 'Risky buyer', 'risky-buyer' ) . ' — ' . esc_html( $label ) . '</strong>';
		if ( ! empty( $entry['note'] ) ) {
			echo ' · ' . esc_html( $entry['note'] );
		}
		$by = ! empty( $entry['created_by_name'] ) ? $entry['created_by_name'] : ( ! empty( $entry['source_site'] ) ? $entry['source_site'] : '' );
		if ( $by ) {
			echo ' <span style="color:#666;">(' . esc_html__( 'Added by', 'risky-buyer' ) . ' ' . esc_html( $by ) . ')</span>';
		}
		echo '</p></div>';
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Order context.
	 */
	public function render_box( $post_or_order ) {
		$order = null;
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} elseif ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$order = wc_get_order( $post_or_order->ID );
		}
		if ( ! $order ) {
			return;
		}

		$bl    = Riskybuyer_Blacklist::instance();
		$entry = $bl->match_order( $order );

		echo '<div class="rb-metabox" data-order-id="' . esc_attr( $order->get_id() ) . '">';

		if ( $entry ) {
			$color = Riskybuyer_Blacklist::reason_color( $entry['reason_code'] );
			$label = Riskybuyer_Blacklist::reason_label( $entry['reason_code'] );
			echo '<div class="rb-warn" style="border-left:4px solid ' . esc_attr( $color ) . ';">';
			echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</strong>';
			if ( ! empty( $entry['note'] ) ) {
				echo '<p class="rb-note">' . esc_html( $entry['note'] ) . '</p>';
			}
			$by   = ! empty( $entry['created_by_name'] ) ? $entry['created_by_name'] : '—';
			$src  = ! empty( $entry['source_site'] ) ? $entry['source_site'] : '';
			$date = ! empty( $entry['created_at'] ) ? mysql2date( 'd.m.Y', $entry['created_at'] ) : '';
			echo '<p class="rb-meta">' . esc_html__( 'Added by:', 'risky-buyer' ) . ' ' . esc_html( $by );
			if ( $date ) {
				echo ' · ' . esc_html( $date );
			}
			if ( $src ) {
				echo ' · ' . esc_html( $src );
			}
			echo '</p>';

			if ( $bl->can_manage() ) {
				echo '<button type="button" class="button rb-unmark-btn" data-uuid="' . esc_attr( $entry['uuid'] ) . '">' . esc_html__( 'Remove from list', 'risky-buyer' ) . '</button>';
			} else {
				echo '<p class="rb-meta"><em>' . esc_html__( 'Only an administrator can remove.', 'risky-buyer' ) . '</em></p>';
			}
			echo '</div>';
		} elseif ( $bl->can_add() ) {
			echo '<p>' . esc_html__( 'Mark this client as problematic (by name and phone from the order).', 'risky-buyer' ) . '</p>';
			echo '<p><label>' . esc_html__( 'Reason', 'risky-buyer' ) . '<br><select class="rb-reason widefat">';
			foreach ( Riskybuyer_Blacklist::reasons() as $code => $r ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $r['label'] ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p><label>' . esc_html__( 'Note (optional)', 'risky-buyer' ) . '<br><textarea class="rb-note-input widefat" rows="2"></textarea></label></p>';
			echo '<button type="button" class="button button-primary rb-mark-btn">' . esc_html__( 'Mark client', 'risky-buyer' ) . '</button>';
		} else {
			echo '<p><em>' . esc_html__( 'You do not have permission to mark clients.', 'risky-buyer' ) . '</em></p>';
		}

		echo '</div>';
	}
}
