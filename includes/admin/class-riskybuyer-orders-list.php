<?php
/**
 * Marks orders of blacklisted clients in the orders list table.
 *
 * Visual language is intentionally different from any "duplicate" marker:
 * a warning badge + a left colour bar (no full-row outline).
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
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $action ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		add_action( 'admin_footer', array( $this, 'render' ) );
	}

	public function render() {
		$map = Riskybuyer_Order_Matcher::build_marked_map();
		if ( empty( $map ) ) {
			return;
		}
		?>
		<style id="rb-orders-list-css">
			.wp-list-table tbody tr.rb-flag > *:first-child { box-shadow: inset 5px 0 0 0 var(--rb-bd); }
			.wp-list-table tbody tr.rb-flag td,
			.wp-list-table tbody tr.rb-flag th { background-color: var(--rb-bg) !important; }
			.rb-badge { display: inline-block; margin-top: 5px; padding: 1px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; line-height: 1.7; color: #fff; white-space: nowrap; }
		</style>
		<script id="rb-orders-list-js">
		( function () {
			var MAP = <?php echo wp_json_encode( $map ); ?>;
			var RB_LABEL = <?php echo wp_json_encode( __( 'Risky buyer', 'risky-buyer' ) ); ?>;

			function rgba( hex, a ) {
				var h = hex.replace( '#', '' );
				return 'rgba(' + parseInt( h.substr( 0, 2 ), 16 ) + ',' + parseInt( h.substr( 2, 2 ), 16 ) + ',' + parseInt( h.substr( 4, 2 ), 16 ) + ',' + a + ')';
			}

			function run() {
				var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
				Array.prototype.forEach.call( rows, function ( tr ) {
					if ( tr.getAttribute( 'data-rb' ) ) { return; }
					var cb = tr.querySelector( '.check-column input[type=checkbox]' );
					if ( ! cb || ! cb.value ) { return; }
					var info = MAP[ String( cb.value ) ];
					if ( ! info ) { return; }

					tr.setAttribute( 'data-rb', '1' );
					tr.classList.add( 'rb-flag' );
					tr.style.setProperty( '--rb-bd', info.color );
					tr.style.setProperty( '--rb-bg', rgba( info.color, 0.08 ) );

					var cell = tr.querySelector( 'td.column-order_number, td.order_number' ) || tr.querySelectorAll( 'td' )[0];
					if ( cell ) {
						var b = document.createElement( 'span' );
						b.className = 'rb-badge';
						b.style.background = info.color;
						b.textContent = '⚠ ' + RB_LABEL + ' · ' + info.label;
						if ( info.note ) { b.title = info.note; }
						cell.appendChild( document.createElement( 'br' ) );
						cell.appendChild( b );
					}
				} );
			}

			run();
			if ( window.MutationObserver ) {
				var tbody = document.querySelector( '.wp-list-table tbody' );
				if ( tbody ) {
					new MutationObserver( function () { run(); } ).observe( tbody, { childList: true } );
				}
			}
		} )();
		</script>
		<?php
	}
}
