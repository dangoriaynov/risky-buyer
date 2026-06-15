<?php
/**
 * AJAX handlers for marking/unmarking a client from the order screen.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Ajax {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'wp_ajax_riskybuyer_mark', array( $this, 'mark' ) );
		add_action( 'wp_ajax_riskybuyer_unmark', array( $this, 'unmark' ) );
		add_action( 'wp_ajax_riskybuyer_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_riskybuyer_validate_key', array( $this, 'validate_key' ) );
		add_action( 'wp_ajax_riskybuyer_sync_now', array( $this, 'sync_now' ) );
		add_action( 'wp_ajax_riskybuyer_push', array( $this, 'push' ) );
		add_action( 'wp_ajax_riskybuyer_search_customers', array( $this, 'search_customers' ) );
	}

	/**
	 * Autocomplete: find existing shop customers (from orders) by name or phone.
	 */
	public function search_customers() {
		check_ajax_referer( 'riskybuyer_ajax', 'nonce' );
		if ( ! Riskybuyer_Blacklist::instance()->can_add() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'risky-buyer' ) ), 403 );
		}
		$q = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
		if ( mb_strlen( $q ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}
		wp_send_json_success( array( 'results' => $this->search_order_customers( $q ) ) );
	}

	/**
	 * Distinct billing customers from orders matching $q (name or phone).
	 *
	 * @param string $q Search term.
	 * @return array<int,array{name:string,phone:string,label:string}>
	 */
	protected function search_order_customers( $q ) {
		global $wpdb;
		$like   = '%' . $wpdb->esc_like( $q ) . '%';
		$digits = preg_replace( '/\D+/', '', $q );
		$plike  = '' !== $digits ? '%' . $wpdb->esc_like( $digits ) . '%' : $like;
		$hpos   = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// Pull recent matching billing rows per order, then group in PHP.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		if ( $hpos ) {
			$addr = $wpdb->prefix . 'wc_order_addresses';
			$ord  = $wpdb->prefix . 'wc_orders';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.order_id AS oid, a.first_name, a.last_name, a.phone
					FROM {$addr} a
					INNER JOIN {$ord} o ON o.id = a.order_id
					WHERE a.address_type = 'billing' AND o.type = 'shop_order'
					AND ( CONCAT_WS(' ', a.first_name, a.last_name) LIKE %s
						OR REPLACE(REPLACE(REPLACE(a.phone,' ',''),'-',''),'+','') LIKE %s )
					ORDER BY a.order_id DESC
					LIMIT 80",
					$like,
					$plike
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id AS oid,
						MAX(CASE WHEN meta_key = '_billing_first_name' THEN meta_value END) AS first_name,
						MAX(CASE WHEN meta_key = '_billing_last_name' THEN meta_value END) AS last_name,
						MAX(CASE WHEN meta_key = '_billing_phone' THEN meta_value END) AS phone
					FROM {$wpdb->postmeta}
					WHERE meta_key IN ('_billing_first_name', '_billing_last_name', '_billing_phone')
					GROUP BY post_id
					HAVING ( CONCAT_WS(' ', first_name, last_name) LIKE %s OR phone LIKE %s )
					ORDER BY post_id DESC
					LIMIT 80",
					$like,
					$plike
				),
				ARRAY_A
			);
		}
		// phpcs:enable

		// Group into distinct customers (by normalized phone, else normalized name);
		// keep the most recent name and collect their order ids.
		$groups = array();
		$order  = array();
		foreach ( (array) $rows as $r ) {
			$name  = trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
			$phone = trim( (string) $r['phone'] );
			if ( '' === $name && '' === $phone ) {
				continue;
			}
			$pn  = Riskybuyer_Blacklist::normalize_phone( $phone );
			$key = '' !== $pn ? 'p:' . $pn : 'n:' . mb_strtolower( $name );

			if ( ! isset( $groups[ $key ] ) ) {
				if ( count( $groups ) >= 10 ) {
					continue;
				}
				$groups[ $key ] = array(
					'name'   => $name,
					'phone'  => $phone,
					'orders' => array(),
				);
				$order[]        = $key;
			}
			if ( count( $groups[ $key ]['orders'] ) < 8 ) {
				$groups[ $key ]['orders'][] = (int) $r['oid'];
			}
		}

		$out = array();
		foreach ( $order as $key ) {
			$g     = $groups[ $key ];
			$phone = Riskybuyer_Blacklist::canonical_phone( $g['phone'] );
			$nums  = array();
			foreach ( $g['orders'] as $oid ) {
				$o = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : null;
				if ( $o ) {
					$nums[] = '#' . $o->get_order_number();
				}
			}
			$label = $g['name'];
			if ( '' !== $phone ) {
				$label .= ' · ' . $phone;
			}
			if ( $nums ) {
				$label .= ' (' . implode( ', ', $nums ) . ')';
			}
			$out[] = array(
				'name'  => $g['name'],
				'phone' => $phone,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Shared guard for settings AJAX: nonce + admin capability.
	 */
	protected function guard_manage() {
		check_ajax_referer( 'riskybuyer_ajax', 'nonce' );
		if ( ! Riskybuyer_Blacklist::instance()->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Only an administrator can change settings.', 'risky-buyer' ) ), 403 );
		}
	}

	public function save_settings() {
		$this->guard_manage();
		Riskybuyer_Settings::update(
			array(
				'sync_enabled' => empty( $_POST['sync_enabled'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'server_url'   => isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'api_key'      => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		Riskybuyer_Remote_Sync::instance()->maybe_schedule();
		wp_send_json_success( array( 'enabled' => Riskybuyer_Settings::is_sync_enabled() ) );
	}

	public function validate_key() {
		$this->guard_manage();
		$url = isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( Riskybuyer_Remote_Sync::instance()->validate_key( $url, $key ) );
	}

	public function sync_now() {
		$this->guard_manage();
		$ok = Riskybuyer_Remote_Sync::instance()->pull();
		$st = Riskybuyer_Settings::state();
		if ( $ok ) {
			wp_send_json_success(
				array(
					/* translators: 1: new entries this sync, 2: total downloaded */
					'message' => sprintf( __( 'Sync done: %1$d new (%2$d total downloaded).', 'risky-buyer' ), (int) $st['last_added'], (int) $st['cached'] ),
					'cached'  => (int) $st['cached'],
					'added'   => (int) $st['last_added'],
				)
			);
		}
		/* translators: %s: error message */
		wp_send_json_error( array( 'message' => sprintf( __( 'Sync error: %s', 'risky-buyer' ), $st['last_error'] ) ) );
	}

	public function push() {
		$this->guard_manage();
		$r = Riskybuyer_Remote_Sync::instance()->push_all();
		if ( is_wp_error( $r ) ) {
			/* translators: %s: error message */
			wp_send_json_error( array( 'message' => sprintf( __( 'Push error: %s', 'risky-buyer' ), $r->get_error_message() ) ) );
		}
		/* translators: %d: number of entries pushed */
		wp_send_json_success( array( 'message' => sprintf( __( 'Pushed %d entries to the server.', 'risky-buyer' ), (int) $r ) ) );
	}

	/**
	 * Mark a client (from an order) as problematic.
	 */
	public function mark() {
		check_ajax_referer( 'riskybuyer_ajax', 'nonce' );

		$bl = Riskybuyer_Blacklist::instance();
		if ( ! $bl->can_add() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'risky-buyer' ) ), 403 );
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
		if ( isset( $_POST['name'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( isset( $_POST['phone'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) ) {
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
				'label'  => Riskybuyer_Blacklist::reason_label( $result['reason_code'] ),
				'color'  => Riskybuyer_Blacklist::reason_color( $result['reason_code'] ),
				'note'   => $result['note'],
				'name'   => $result['name_raw'],
				'phone'  => $result['phone_raw'],
				'message' => __( 'Client marked.', 'risky-buyer' ),
			)
		);
	}

	/**
	 * Remove a blacklist entry (admins only).
	 */
	public function unmark() {
		check_ajax_referer( 'riskybuyer_ajax', 'nonce' );

		$bl = Riskybuyer_Blacklist::instance();
		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( '' === $uuid ) {
			wp_send_json_error( array( 'message' => __( 'Missing identifier.', 'risky-buyer' ) ), 400 );
		}

		$result = $bl->delete_entry( $uuid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
		}

		wp_send_json_success( array( 'message' => __( 'Marker removed.', 'risky-buyer' ) ) );
	}
}
