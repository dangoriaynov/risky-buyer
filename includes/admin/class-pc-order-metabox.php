<?php
/**
 * Order edit screen: shows a warning if the client is blacklisted and a
 * "mark client" form otherwise.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Probclient_Order_Metabox {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
	}

	public function add_box() {
		$screen = 'shop_order';
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$maybe = wc_get_page_screen_id( 'shop-order' );
			if ( $maybe ) {
				$screen = $maybe;
			}
		}
		add_meta_box(
			'probclient_metabox',
			'⚠ Проблемен клиент',
			array( $this, 'render_box' ),
			$screen,
			'side',
			'high'
		);
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

		$bl    = Probclient_Blacklist::instance();
		$entry = $bl->match_order( $order );

		echo '<div class="pc-metabox" data-order-id="' . esc_attr( $order->get_id() ) . '">';

		if ( $entry ) {
			$color = Probclient_Blacklist::reason_color( $entry['reason_code'] );
			$label = Probclient_Blacklist::reason_label( $entry['reason_code'] );
			echo '<div class="pc-warn" style="border-left:4px solid ' . esc_attr( $color ) . ';">';
			echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</strong>';
			if ( ! empty( $entry['note'] ) ) {
				echo '<p class="pc-note">' . esc_html( $entry['note'] ) . '</p>';
			}
			$by   = ! empty( $entry['created_by_name'] ) ? $entry['created_by_name'] : '—';
			$src  = ! empty( $entry['source_site'] ) ? $entry['source_site'] : '';
			$date = ! empty( $entry['created_at'] ) ? mysql2date( 'd.m.Y', $entry['created_at'] ) : '';
			echo '<p class="pc-meta">Добавил: ' . esc_html( $by );
			if ( $date ) {
				echo ' · ' . esc_html( $date );
			}
			if ( $src ) {
				echo ' · ' . esc_html( $src );
			}
			echo '</p>';

			if ( $bl->can_manage() ) {
				echo '<button type="button" class="button pc-unmark-btn" data-uuid="' . esc_attr( $entry['uuid'] ) . '">Премахни от списъка</button>';
			} else {
				echo '<p class="pc-meta"><em>Само администратор може да премахне.</em></p>';
			}
			echo '</div>';
		} elseif ( $bl->can_add() ) {
			echo '<p>Маркирай този клиент като проблемен (по име и телефон от поръчката).</p>';
			echo '<p><label>Причина<br><select class="pc-reason widefat">';
			foreach ( Probclient_Blacklist::reasons() as $code => $r ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $r['label'] ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p><label>Бележка (по избор)<br><textarea class="pc-note-input widefat" rows="2"></textarea></label></p>';
			echo '<button type="button" class="button button-primary pc-mark-btn">Маркирай клиента</button>';
		} else {
			echo '<p><em>Нямате права да маркирате клиенти.</em></p>';
		}

		echo '</div>';
	}
}
