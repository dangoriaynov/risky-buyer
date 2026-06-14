<?php
/**
 * AJAX handlers for marking/unmarking a client from the order screen.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Probclient_Ajax {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'wp_ajax_probclient_mark', array( $this, 'mark' ) );
		add_action( 'wp_ajax_probclient_unmark', array( $this, 'unmark' ) );
		add_action( 'wp_ajax_probclient_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_probclient_validate_key', array( $this, 'validate_key' ) );
		add_action( 'wp_ajax_probclient_sync_now', array( $this, 'sync_now' ) );
		add_action( 'wp_ajax_probclient_push', array( $this, 'push' ) );
	}

	/**
	 * Shared guard for settings AJAX: nonce + admin capability.
	 */
	protected function guard_manage() {
		check_ajax_referer( 'probclient_ajax', 'nonce' );
		if ( ! Probclient_Blacklist::instance()->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Only an administrator can change settings.', 'problem-client' ) ), 403 );
		}
	}

	public function save_settings() {
		$this->guard_manage();
		Probclient_Settings::update(
			array(
				'sync_enabled' => empty( $_POST['sync_enabled'] ) ? 0 : 1,
				'server_url'   => isset( $_POST['server_url'] ) ? wp_unslash( $_POST['server_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'api_key'      => isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			)
		);
		Probclient_Remote_Sync::instance()->maybe_schedule();
		wp_send_json_success( array( 'enabled' => Probclient_Settings::is_sync_enabled() ) );
	}

	public function validate_key() {
		$this->guard_manage();
		$url = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '';
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		wp_send_json_success( Probclient_Remote_Sync::instance()->validate_key( $url, $key ) );
	}

	public function sync_now() {
		$this->guard_manage();
		$ok = Probclient_Remote_Sync::instance()->pull();
		$st = Probclient_Settings::state();
		if ( $ok ) {
			/* translators: %d: number of cached entries */
			wp_send_json_success( array( 'message' => sprintf( __( 'Sync done: %d entries cached.', 'problem-client' ), (int) $st['cached'] ) ) );
		}
		/* translators: %s: error message */
		wp_send_json_error( array( 'message' => sprintf( __( 'Sync error: %s', 'problem-client' ), $st['last_error'] ) ) );
	}

	public function push() {
		$this->guard_manage();
		$r = Probclient_Remote_Sync::instance()->push_all();
		if ( is_wp_error( $r ) ) {
			/* translators: %s: error message */
			wp_send_json_error( array( 'message' => sprintf( __( 'Push error: %s', 'problem-client' ), $r->get_error_message() ) ) );
		}
		/* translators: %d: number of entries pushed */
		wp_send_json_success( array( 'message' => sprintf( __( 'Pushed %d entries to the server.', 'problem-client' ), (int) $r ) ) );
	}

	/**
	 * Mark a client (from an order) as problematic.
	 */
	public function mark() {
		check_ajax_referer( 'probclient_ajax', 'nonce' );

		$bl = Probclient_Blacklist::instance();
		if ( ! $bl->can_add() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'problem-client' ) ), 403 );
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
				'label'  => Probclient_Blacklist::reason_label( $result['reason_code'] ),
				'color'  => Probclient_Blacklist::reason_color( $result['reason_code'] ),
				'note'   => $result['note'],
				'name'   => $result['name_raw'],
				'phone'  => $result['phone_raw'],
				'message' => __( 'Client marked.', 'problem-client' ),
			)
		);
	}

	/**
	 * Remove a blacklist entry (admins only).
	 */
	public function unmark() {
		check_ajax_referer( 'probclient_ajax', 'nonce' );

		$bl = Probclient_Blacklist::instance();
		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( '' === $uuid ) {
			wp_send_json_error( array( 'message' => __( 'Missing identifier.', 'problem-client' ) ), 400 );
		}

		$result = $bl->delete_entry( $uuid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}

		wp_send_json_success( array( 'message' => __( 'Marker removed.', 'problem-client' ) ) );
	}
}
