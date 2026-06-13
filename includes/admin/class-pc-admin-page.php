<?php
/**
 * Admin management page with tabs: Check / List / Add.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Probclient_Admin_Page {

	const SLUG = 'problem-client';

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_menu', array( $this, 'reorder_menu' ), 100 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Problem clients', 'problem-client' ),
			__( 'Problem clients', 'problem-client' ),
			'edit_shop_orders',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Place our item just before WooCommerce → Settings (slug "wc-settings").
	 */
	public function reorder_menu() {
		global $submenu;
		if ( empty( $submenu['woocommerce'] ) ) {
			return;
		}

		$items = $submenu['woocommerce'];
		$ours  = null;
		foreach ( $items as $i => $it ) {
			if ( isset( $it[2] ) && self::SLUG === $it[2] ) {
				$ours = $it;
				unset( $items[ $i ] );
				break;
			}
		}
		if ( null === $ours ) {
			return;
		}

		$rebuilt  = array();
		$inserted = false;
		foreach ( $items as $it ) {
			if ( ! $inserted && isset( $it[2] ) && 'wc-settings' === $it[2] ) {
				$rebuilt[] = $ours;
				$inserted  = true;
			}
			$rebuilt[] = $it;
		}
		if ( ! $inserted ) {
			$rebuilt[] = $ours;
		}

		$submenu['woocommerce'] = array_values( $rebuilt );
	}

	protected function base_url( $tab = '' ) {
		$args = array( 'page' => self::SLUG );
		if ( $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Handle add/update/delete/bulk POSTs, then redirect with a notice.
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['probclient_action'] ) ) {
			return;
		}
		check_admin_referer( 'probclient_admin' );

		$bl     = Probclient_Blacklist::instance();
		$action = sanitize_key( wp_unslash( $_POST['probclient_action'] ) );
		$notice = '';
		$type   = 'success';
		$tab    = 'list';

		if ( 'add' === $action ) {
			$res = $bl->add_entry( $this->posted_entry() );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
				$tab    = 'add';
			} else {
				$notice = __( 'Client added to the list.', 'problem-client' );
			}
		} elseif ( 'bulk_add' === $action ) {
			$text   = isset( $_POST['bulk'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk'] ) ) : '';
			$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other';
			$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$res    = $bl->bulk_add( $text, $reason, $note );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
				$tab    = 'add';
			} else {
				/* translators: 1: added count, 2: skipped count, 3: invalid count */
				$notice = sprintf( __( 'Added: %1$d · skipped (already in list): %2$d · invalid: %3$d', 'problem-client' ), $res['added'], $res['skipped'], $res['invalid'] );
				$tab    = 'add';
			}
		} elseif ( 'update' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->update_entry( $uuid, $this->posted_entry() );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Entry updated.', 'problem-client' );
			}
		} elseif ( 'delete' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->delete_entry( $uuid );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Entry removed.', 'problem-client' );
			}
		}

		$url = add_query_arg(
			array(
				'probclient_notice' => rawurlencode( $notice ),
				'probclient_type'   => $type,
			),
			$this->base_url( $tab )
		);
		wp_safe_redirect( $url );
		exit;
	}

	protected function posted_entry() {
		return array(
			'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'phone'  => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'reason' => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other',
			'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$bl = Probclient_Blacklist::instance();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'check'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'check', 'list', 'add' ), true ) ) {
			$tab = 'check';
		}
		// Editing an entry happens in the "add" tab with a prefilled form.
		if ( $bl->can_manage() && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tab = 'add';
		}

		echo '<div class="wrap pc-wrap">';
		echo '<h1>' . esc_html__( 'Problem clients', 'problem-client' ) . '</h1>';

		// Notice.
		if ( isset( $_GET['probclient_notice'] ) && '' !== $_GET['probclient_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg   = sanitize_text_field( wp_unslash( $_GET['probclient_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ntype = ( isset( $_GET['probclient_type'] ) && 'error' === $_GET['probclient_type'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-' . esc_attr( $ntype ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Tabs.
		$tabs = array(
			'check' => __( 'Check', 'problem-client' ),
			'list'  => __( 'List', 'problem-client' ),
			'add'   => __( 'Add', 'problem-client' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$cls = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $this->base_url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'check' === $tab ) {
			$this->render_check_tab( $bl );
		} elseif ( 'add' === $tab ) {
			$this->render_add_tab( $bl );
		} else {
			$this->render_list_tab( $bl );
		}

		echo '</div>';
	}

	/* --------------------------------------------------------------------- */
	/* Tab: Check                                                            */
	/* --------------------------------------------------------------------- */

	protected function render_check_tab( $bl ) {
		$cphone = isset( $_GET['cphone'] ) ? sanitize_text_field( wp_unslash( $_GET['cphone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cname  = isset( $_GET['cname'] ) ? sanitize_text_field( wp_unslash( $_GET['cname'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<p class="description">' . esc_html__( 'Enter a phone and/or name (e.g. when a client calls) to check whether they are in the list. Partial matches are shown too, newest first.', 'problem-client' ) . '</p>';
		echo '<form method="get" class="pc-check-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="check">';
		echo '<input type="text" name="cphone" value="' . esc_attr( $cphone ) . '" placeholder="' . esc_attr__( 'Phone', 'problem-client' ) . '"> ';
		echo '<input type="text" name="cname" value="' . esc_attr( $cname ) . '" placeholder="' . esc_attr__( 'Name', 'problem-client' ) . '"> ';
		submit_button( __( 'Look up', 'problem-client' ), 'primary', '', false );
		echo '</form>';

		if ( '' === $cphone && '' === $cname ) {
			return;
		}

		$exact = $bl->match( Probclient_Blacklist::normalize_phone( $cphone ), Probclient_Blacklist::normalize_name( $cname ) );

		if ( $exact ) {
			echo '<div class="pc-result pc-result-yes">';
			echo '<h2>⛔ ' . esc_html__( 'YES — this client is in the list', 'problem-client' ) . '</h2>';
			$this->render_entry_card( $exact );
			echo '</div>';
		} else {
			echo '<div class="pc-result pc-result-no"><h2>✓ ' . esc_html__( 'No exact match', 'problem-client' ) . '</h2>';
			echo '<p>' . esc_html__( 'Not found by exact phone/name. Check the possible matches below.', 'problem-client' ) . '</p></div>';
		}

		$possible = $bl->possible_matches( $cphone, $cname, $exact ? $exact['uuid'] : '' );
		if ( ! empty( $possible ) ) {
			echo '<h3>' . esc_html__( 'Possible matches (for manual verification)', 'problem-client' ) . '</h3>';
			$this->render_entries_table( $possible, false );
		}
	}

	protected function render_entry_card( $e ) {
		$color = Probclient_Blacklist::reason_color( $e['reason_code'] );
		$label = Probclient_Blacklist::reason_label( $e['reason_code'] );
		$date  = ! empty( $e['created_at'] ) ? mysql2date( 'd.m.Y H:i', $e['created_at'] ) : '';
		echo '<table class="pc-card">';
		echo '<tr><th>' . esc_html__( 'Name', 'problem-client' ) . '</th><td>' . esc_html( $e['name_raw'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Phone', 'problem-client' ) . '</th><td>' . esc_html( $e['phone_raw'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Reason', 'problem-client' ) . '</th><td><span class="pc-pill" style="background:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span></td></tr>';
		if ( ! empty( $e['note'] ) ) {
			echo '<tr><th>' . esc_html__( 'Note', 'problem-client' ) . '</th><td>' . esc_html( $e['note'] ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Source', 'problem-client' ) . '</th><td>' . esc_html( $e['source_site'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Added by', 'problem-client' ) . '</th><td>' . esc_html( $e['created_by_name'] ) . ( $date ? ' · ' . esc_html( $date ) : '' ) . '</td></tr>';
		echo '</table>';
	}

	/* --------------------------------------------------------------------- */
	/* Tab: Add                                                              */
	/* --------------------------------------------------------------------- */

	protected function render_add_tab( $bl ) {
		$edit_entry = null;
		if ( $bl->can_manage() && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit_entry = $bl->get( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$this->render_form( $edit_entry, $bl->can_manage() );

		if ( ! $edit_entry ) {
			$this->render_bulk_form();
		}
	}

	protected function render_form( $edit_entry, $can_manage ) {
		$reasons = Probclient_Blacklist::reasons();
		$is_edit = ( $edit_entry && $can_manage );
		echo '<h2>' . ( $is_edit ? esc_html__( 'Edit entry', 'problem-client' ) : esc_html__( 'Add one client', 'problem-client' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" class="pc-form">';
		wp_nonce_field( 'probclient_admin' );
		echo '<input type="hidden" name="probclient_action" value="' . ( $is_edit ? 'update' : 'add' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="uuid" value="' . esc_attr( $edit_entry['uuid'] ) . '">';
		}
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Name', 'problem-client' ) . '</label></th><td><input type="text" name="name" class="regular-text" value="' . esc_attr( $is_edit ? $edit_entry['name_raw'] : '' ) . '"></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Phone', 'problem-client' ) . '</label></th><td><input type="text" name="phone" class="regular-text" value="' . esc_attr( $is_edit ? $edit_entry['phone_raw'] : '' ) . '"></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Reason', 'problem-client' ) . '</label></th><td><select name="reason">';
		$cur = $is_edit ? $edit_entry['reason_code'] : 'other';
		foreach ( $reasons as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $cur, $code, false ) . '>' . esc_html( $r['label'] ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Note', 'problem-client' ) . '</label></th><td><textarea name="note" rows="2" class="large-text">' . esc_textarea( $is_edit ? (string) $edit_entry['note'] : '' ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( $is_edit ? __( 'Save changes', 'problem-client' ) : __( 'Add client', 'problem-client' ) );
		if ( $is_edit ) {
			echo ' <a class="button" href="' . esc_url( $this->base_url( 'list' ) ) . '">' . esc_html__( 'Cancel', 'problem-client' ) . '</a>';
		}
		echo '</form>';
	}

	protected function render_bulk_form() {
		$reasons = Probclient_Blacklist::reasons();
		echo '<hr><h2>' . esc_html__( 'Bulk add', 'problem-client' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One client per line. Fields separated by comma / tab / semicolon. A value with 6+ digits is treated as the phone, the rest as the name. The reason and note apply to the whole list. Existing entries (by phone or name) are skipped.', 'problem-client' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" class="pc-form">';
		wp_nonce_field( 'probclient_admin' );
		echo '<input type="hidden" name="probclient_action" value="bulk_add">';
		echo '<p><textarea name="bulk" rows="8" class="large-text code" placeholder="0888123456, Ivan Ivanov&#10;0877000111&#10;Maria Petrova"></textarea></p>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Reason', 'problem-client' ) . '</label></th><td><select name="reason">';
		foreach ( $reasons as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( 'other', $code, false ) . '>' . esc_html( $r['label'] ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Note', 'problem-client' ) . '</label></th><td><textarea name="note" rows="2" class="large-text"></textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Add in bulk', 'problem-client' ) );
		echo '</form>';
	}

	/* --------------------------------------------------------------------- */
	/* Tab: List                                                             */
	/* --------------------------------------------------------------------- */

	protected function render_list_tab( $bl ) {
		$reasons = Probclient_Blacklist::reasons();
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$freason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Filters.
		echo '<form method="get" class="pc-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="list">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search (name, phone, note)', 'problem-client' ) . '">';
		echo ' <select name="reason"><option value="">' . esc_html__( 'All reasons', 'problem-client' ) . '</option>';
		foreach ( $reasons as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $freason, $code, false ) . '>' . esc_html( $r['label'] ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'problem-client' ), 'secondary', '', false );
		echo '</form>';

		$entries = $bl->all(
			array(
				'status' => 'active',
				'search' => $search,
				'reason' => $freason,
			)
		);
		$this->render_entries_table( $entries, $bl->can_manage() );
	}

	/**
	 * Shared entries table. $with_actions adds edit/delete (admins).
	 *
	 * @param array $entries      Entries.
	 * @param bool  $with_actions Show actions column.
	 */
	protected function render_entries_table( $entries, $with_actions ) {
		echo '<table class="wp-list-table widefat fixed striped pc-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'problem-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'problem-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'problem-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'problem-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'problem-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Added by', 'problem-client' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'problem-client' ) . '</th>';
		if ( $with_actions ) {
			echo '<th>' . esc_html__( 'Actions', 'problem-client' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$cols = $with_actions ? 8 : 7;
		if ( empty( $entries ) ) {
			echo '<tr><td colspan="' . (int) $cols . '">' . esc_html__( 'No entries.', 'problem-client' ) . '</td></tr>';
		} else {
			foreach ( $entries as $e ) {
				$color = Probclient_Blacklist::reason_color( $e['reason_code'] );
				$label = Probclient_Blacklist::reason_label( $e['reason_code'] );
				$date  = ! empty( $e['created_at'] ) ? mysql2date( 'd.m.Y H:i', $e['created_at'] ) : '';
				echo '<tr>';
				echo '<td>' . esc_html( $e['name_raw'] ) . '</td>';
				echo '<td>' . esc_html( $e['phone_raw'] ) . '</td>';
				echo '<td><span class="pc-pill" style="background:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span></td>';
				echo '<td>' . esc_html( (string) $e['note'] ) . '</td>';
				echo '<td>' . esc_html( $e['source_site'] ) . '</td>';
				echo '<td>' . esc_html( $e['created_by_name'] ) . '</td>';
				echo '<td>' . esc_html( $date ) . '</td>';
				if ( $with_actions ) {
					echo '<td>';
					$edit_url = add_query_arg(
						array(
							'page' => self::SLUG,
							'tab'  => 'add',
							'edit' => rawurlencode( $e['uuid'] ),
						),
						admin_url( 'admin.php' )
					);
					echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'problem-client' ) . '</a> ';
					echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove this entry?', 'problem-client' ) ) . '\');">';
					wp_nonce_field( 'probclient_admin' );
					echo '<input type="hidden" name="probclient_action" value="delete">';
					echo '<input type="hidden" name="uuid" value="' . esc_attr( $e['uuid'] ) . '">';
					echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Delete', 'problem-client' ) . '</button>';
					echo '</form>';
					echo '</td>';
				}
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}
}
