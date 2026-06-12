<?php
/**
 * AJAX handlers for marking/unmarking a client from the order screen.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PC_Ajax {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'wp_ajax_pc_mark', array( $this, 'mark' ) );
		add_action( 'wp_ajax_pc_unmark', array( $this, 'unmark' ) );
	}

	/**
	 * Mark a client (from an order) as problematic.
	 */
	public function mark() {
		check_ajax_referer( 'pc_ajax', 'nonce' );

		$bl = PC_Blacklist::instance();
		if ( ! $bl->can_add() ) {
			wp_send_json_error( array( 'message' => 'Нямате права.' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$name  = '';
		$phone = '';
		if ( $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				if ( '' === $name ) {
					$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
				}
				$phone = $order->get_billing_phone();
			}
		}

		// Allow explicit overrides (e.g. manual add).
		if ( isset( $_POST['name'] ) && '' !== trim( (string) wp_unslash( $_POST['name'] ) ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( isset( $_POST['phone'] ) && '' !== trim( (string) wp_unslash( $_POST['phone'] ) ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
		}

		$result = $bl->add_entry(
			array(
				'name'   => $name,
				'phone'  => $phone,
				'reason' => $reason,
				'note'   => $note,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'uuid'   => $result['uuid'],
				'label'  => PC_Blacklist::reason_label( $result['reason_code'] ),
				'color'  => PC_Blacklist::reason_color( $result['reason_code'] ),
				'note'   => $result['note'],
				'name'   => $result['name_raw'],
				'phone'  => $result['phone_raw'],
				'message' => 'Клиентът е маркиран.',
			)
		);
	}

	/**
	 * Remove a blacklist entry (admins only).
	 */
	public function unmark() {
		check_ajax_referer( 'pc_ajax', 'nonce' );

		$bl = PC_Blacklist::instance();
		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( '' === $uuid ) {
			wp_send_json_error( array( 'message' => 'Липсва идентификатор.' ), 400 );
		}

		$result = $bl->delete_entry( $uuid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}

		wp_send_json_success( array( 'message' => 'Маркировката е премахната.' ) );
	}
}
