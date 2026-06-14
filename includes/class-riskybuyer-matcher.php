<?php
/**
 * Matches the orders currently being listed against the blacklist.
 *
 * Reads minimal order fields (id, phone, name) for the current status filter —
 * capped — so marking is consistent across pagination.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Order_Matcher {

	/**
	 * Build map: order_id => [ uuid, reason, label, color, note ].
	 *
	 * @return array
	 */
	public static function build_marked_map() {
		$orders = self::fetch_orders();
		if ( empty( $orders ) ) {
			return array();
		}

		$bl  = Riskybuyer_Blacklist::instance();
		$map = array();
		foreach ( $orders as $id => $o ) {
			$entry = $bl->match( $o['phone'], $o['name'] );
			if ( $entry ) {
				$map[ (string) $id ] = array(
					'uuid'   => $entry['uuid'],
					'reason' => $entry['reason_code'],
					'label'  => Riskybuyer_Blacklist::reason_label( $entry['reason_code'] ),
					'color'  => Riskybuyer_Blacklist::reason_color( $entry['reason_code'] ),
					'note'   => isset( $entry['note'] ) ? $entry['note'] : '',
				);
			}
		}
		return $map;
	}

	/**
	 * Fetch [ id => [ phone(normalized), name(normalized) ] ] for current view.
	 *
	 * @return array
	 */
	protected static function fetch_orders() {
		global $wpdb;

		$limit = (int) apply_filters( 'riskybuyer_orders_scan_limit', 1500 );

		$status = '';
		if ( isset( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_text_field( wp_unslash( $_GET['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = sanitize_text_field( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 'all' === $status ) {
			$status = '';
		}

		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		$rows = array();

		if ( $hpos ) {
			$ot   = $wpdb->prefix . 'wc_orders';
			$at   = $wpdb->prefix . 'wc_order_addresses';
			$args = array();
			$cond = "o.type = 'shop_order'";
			if ( '' !== $status ) {
				$st     = ( 0 === strpos( $status, 'wc-' ) ) ? $status : 'wc-' . $status;
				$cond  .= ' AND o.status = %s';
				$args[] = $st;
			} else {
				$cond .= " AND o.status NOT IN ('trash','auto-draft','wc-checkout-draft')";
			}
			$args[] = $limit;

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$sql = $wpdb->prepare(
				"SELECT o.id,
				        ba.first_name b_first, ba.last_name b_last, ba.phone b_phone,
				        sa.first_name s_first, sa.last_name s_last
				   FROM {$ot} o
				   LEFT JOIN {$at} ba ON ba.order_id = o.id AND ba.address_type = 'billing'
				   LEFT JOIN {$at} sa ON sa.order_id = o.id AND sa.address_type = 'shipping'
				  WHERE {$cond}
				  ORDER BY o.date_created_gmt DESC
				  LIMIT %d",
				$args
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			// phpcs:enable
		} else {
			$q = array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
				'type'    => 'shop_order',
			);
			if ( '' !== $status ) {
				$q['status'] = ( 0 === strpos( $status, 'wc-' ) ) ? substr( $status, 3 ) : $status;
			}
			$objs = function_exists( 'wc_get_orders' ) ? wc_get_orders( $q ) : array();
			foreach ( $objs as $o ) {
				$rows[] = array(
					'id'      => $o->get_id(),
					'b_first' => $o->get_billing_first_name(),
					'b_last'  => $o->get_billing_last_name(),
					'b_phone' => $o->get_billing_phone(),
					's_first' => $o->get_shipping_first_name(),
					's_last'  => $o->get_shipping_last_name(),
				);
			}
		}

		$out = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$id = (int) $r['id'];
				if ( ! $id ) {
					continue;
				}
				$name = Riskybuyer_Blacklist::normalize_name( trim( ( $r['b_first'] ?? '' ) . ' ' . ( $r['b_last'] ?? '' ) ) );
				if ( '' === $name ) {
					$name = Riskybuyer_Blacklist::normalize_name( trim( ( $r['s_first'] ?? '' ) . ' ' . ( $r['s_last'] ?? '' ) ) );
				}
				$out[ $id ] = array(
					'phone' => Riskybuyer_Blacklist::normalize_phone( $r['b_phone'] ?? '' ),
					'name'  => $name,
				);
			}
		}
		return $out;
	}
}
