<?php
/**
 * Admin management page with tabs: List / Add / Settings.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Admin_Page {

	const SLUG = 'risky-buyer';

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
			__( 'Risky buyers', 'risky-buyer' ),
			__( 'Risky buyers', 'risky-buyer' ),
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
		if ( ! isset( $_POST['riskybuyer_action'] ) ) {
			return;
		}
		check_admin_referer( 'riskybuyer_admin' );

		$bl     = Riskybuyer_Blacklist::instance();
		$action = sanitize_key( wp_unslash( $_POST['riskybuyer_action'] ) );
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
				$notice = __( 'Client added to the list.', 'risky-buyer' );
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
				$notice = sprintf( __( 'Added: %1$d · skipped (already in list): %2$d · invalid: %3$d', 'risky-buyer' ), $res['added'], $res['skipped'], $res['invalid'] );
				$tab    = 'add';
			}
		} elseif ( 'update' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->update_entry( $uuid, $this->posted_entry() );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Entry updated.', 'risky-buyer' );
			}
		} elseif ( 'delete' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->delete_entry( $uuid );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Entry removed.', 'risky-buyer' );
			}
		} elseif ( 'save_settings' === $action ) {
			$tab = 'settings';
			if ( ! $bl->can_manage() ) {
				$notice = __( 'Only an administrator can change settings.', 'risky-buyer' );
				$type   = 'error';
			} else {
				Riskybuyer_Settings::update(
					array(
						'sync_enabled' => isset( $_POST['sync_enabled'] ) ? 1 : 0,
						'server_url'   => isset( $_POST['server_url'] ) ? esc_url_raw( wp_unslash( $_POST['server_url'] ) ) : '',
						'api_key'      => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
					)
				);
				Riskybuyer_Remote_Sync::instance()->maybe_schedule();
				$notice = __( 'Settings saved.', 'risky-buyer' );
			}
		} elseif ( 'sync_now' === $action ) {
			$tab = 'settings';
			if ( ! $bl->can_manage() ) {
				$notice = __( 'Only an administrator can change settings.', 'risky-buyer' );
				$type   = 'error';
			} else {
				$ok = Riskybuyer_Remote_Sync::instance()->pull();
				$st = Riskybuyer_Settings::state();
				if ( $ok ) {
					/* translators: %d: number of cached entries */
					$notice = sprintf( __( 'Sync done: %d entries cached.', 'risky-buyer' ), (int) $st['cached'] );
				} else {
					$type = 'error';
					/* translators: %s: error message */
					$notice = sprintf( __( 'Sync error: %s', 'risky-buyer' ), $st['last_error'] );
				}
			}
		} elseif ( 'push_all' === $action ) {
			$tab = 'settings';
			if ( ! $bl->can_manage() ) {
				$notice = __( 'Only an administrator can change settings.', 'risky-buyer' );
				$type   = 'error';
			} else {
				$r = Riskybuyer_Remote_Sync::instance()->push_all();
				if ( is_wp_error( $r ) ) {
					$type = 'error';
					/* translators: %s: error message */
					$notice = sprintf( __( 'Push error: %s', 'risky-buyer' ), $r->get_error_message() );
				} else {
					/* translators: %d: number of entries pushed */
					$notice = sprintf( __( 'Pushed %d entries to the server.', 'risky-buyer' ), (int) $r );
				}
			}
		}

		$url = add_query_arg(
			array(
				'riskybuyer_notice' => rawurlencode( $notice ),
				'riskybuyer_type'   => $type,
			),
			$this->base_url( $tab )
		);
		wp_safe_redirect( $url );
		exit;
	}

	protected function posted_entry() {
		// Nonce is verified by handle_actions() (check_admin_referer) before this runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$data = array(
			'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'phone'  => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'reason' => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other',
			'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		return $data;
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$bl = Riskybuyer_Blacklist::instance();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $tab, array( 'list', 'add', 'settings' ), true ) ) {
			$tab = 'list';
		}
		// Editing an entry happens in the "add" tab with a prefilled form.
		if ( $bl->can_manage() && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			$tab = 'add';
		}

		echo '<div class="wrap rb-wrap">';
		echo '<h1>' . esc_html__( 'Risky buyers', 'risky-buyer' ) . '</h1>';

		// Notice.
		if ( isset( $_GET['riskybuyer_notice'] ) && '' !== $_GET['riskybuyer_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			$msg   = sanitize_text_field( wp_unslash( $_GET['riskybuyer_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			$ntype = ( isset( $_GET['riskybuyer_type'] ) && 'error' === $_GET['riskybuyer_type'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-' . esc_attr( $ntype ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Tabs.
		$tabs = array(
			'list' => __( 'List', 'risky-buyer' ),
			'add'  => __( 'Add', 'risky-buyer' ),
		);
		if ( $bl->can_manage() ) {
			$tabs['settings'] = __( 'Settings', 'risky-buyer' );
		}
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$cls = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $this->base_url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'add' === $tab ) {
			$this->render_add_tab( $bl );
		} elseif ( 'settings' === $tab ) {
			$this->render_settings_tab( $bl );
		} else {
			$this->render_list_tab( $bl );
		}

		echo '</div>';
	}

	/* --------------------------------------------------------------------- */
	/* Tab: Add                                                              */
	/* --------------------------------------------------------------------- */

	protected function render_add_tab( $bl ) {
		$edit_entry = null;
		if ( $bl->can_manage() && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			$edit_entry = $bl->get( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		}

		$this->render_form( $edit_entry, $bl->can_manage() );

		if ( ! $edit_entry ) {
			$this->render_bulk_form();
		}
	}

	/**
	 * Render the reason <select> options.
	 *
	 * @param string $current Selected reason code.
	 */
	protected function reason_options( $current = 'other' ) {
		foreach ( Riskybuyer_Blacklist::reasons() as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $r['label'] ) . '</option>';
		}
	}

	protected function render_form( $edit_entry, $can_manage ) {
		$is_edit = ( $edit_entry && $can_manage );
		echo '<h2>' . ( $is_edit ? esc_html__( 'Edit entry', 'risky-buyer' ) : esc_html__( 'Add one client', 'risky-buyer' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" class="rb-form">';
		wp_nonce_field( 'riskybuyer_admin' );
		echo '<input type="hidden" name="riskybuyer_action" value="' . ( $is_edit ? 'update' : 'add' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="uuid" value="' . esc_attr( $edit_entry['uuid'] ) . '">';
		}

		echo '<p class="rb-field"><label for="rb-name">' . esc_html__( 'Name', 'risky-buyer' ) . '</label>';
		echo '<input type="text" id="rb-name" name="name" value="' . esc_attr( $is_edit ? $edit_entry['name_raw'] : '' ) . '"></p>';

		echo '<p class="rb-field"><label for="rb-phone">' . esc_html__( 'Phone', 'risky-buyer' ) . '</label>';
		echo '<input type="text" id="rb-phone" name="phone" value="' . esc_attr( $is_edit ? $edit_entry['phone_raw'] : '' ) . '"></p>';

		echo '<p class="rb-field"><label for="rb-reason">' . esc_html__( 'Reason', 'risky-buyer' ) . '</label>';
		echo '<select id="rb-reason" name="reason">';
		$this->reason_options( $is_edit ? $edit_entry['reason_code'] : 'other' );
		echo '</select></p>';

		echo '<p class="rb-field"><label for="rb-note">' . esc_html__( 'Note', 'risky-buyer' ) . '</label>';
		echo '<textarea id="rb-note" name="note" rows="2">' . esc_textarea( $is_edit ? (string) $edit_entry['note'] : '' ) . '</textarea></p>';

		echo '<p class="rb-actions">';
		submit_button( $is_edit ? __( 'Save changes', 'risky-buyer' ) : __( 'Add client', 'risky-buyer' ), 'primary', 'submit', false );
		if ( $is_edit ) {
			echo ' <a class="button" href="' . esc_url( $this->base_url( 'list' ) ) . '">' . esc_html__( 'Cancel', 'risky-buyer' ) . '</a>';
		}
		echo '</p>';
		echo '</form>';
	}

	protected function render_bulk_form() {
		echo '<h2 class="rb-bulk-title">' . esc_html__( 'Bulk add', 'risky-buyer' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" class="rb-form">';
		wp_nonce_field( 'riskybuyer_admin' );
		echo '<input type="hidden" name="riskybuyer_action" value="bulk_add">';

		echo '<p class="rb-field"><label for="rb-bulk">' . esc_html__( 'Clients (one per line)', 'risky-buyer' ) . '</label>';
		echo '<textarea id="rb-bulk" name="bulk" rows="8" class="code" placeholder="0888123456, Ivan Ivanov&#10;0877000111&#10;Maria Petrova"></textarea>';
		echo '<span class="description">' . esc_html__( 'Fields separated by comma / tab / semicolon. A value with 6+ digits is treated as the phone, the rest as the name. The reason and note below apply to the whole list. Existing entries (by phone or name) are skipped.', 'risky-buyer' ) . '</span></p>';

		echo '<p class="rb-field"><label for="rb-bulk-reason">' . esc_html__( 'Reason', 'risky-buyer' ) . '</label>';
		echo '<select id="rb-bulk-reason" name="reason">';
		$this->reason_options( 'other' );
		echo '</select></p>';

		echo '<p class="rb-field"><label for="rb-bulk-note">' . esc_html__( 'Note', 'risky-buyer' ) . '</label>';
		echo '<textarea id="rb-bulk-note" name="note" rows="2"></textarea></p>';

		echo '<p class="rb-actions">';
		submit_button( __( 'Add in bulk', 'risky-buyer' ), 'primary', 'submit', false );
		echo '</p>';
		echo '</form>';
	}

	/* --------------------------------------------------------------------- */
	/* Tab: List                                                             */
	/* --------------------------------------------------------------------- */

	protected function render_list_tab( $bl ) {
		$reasons = Riskybuyer_Blacklist::reasons();
		$entries = $bl->all( array( 'status' => 'active' ) );

		// Instant in-browser filter (all rows are rendered; JS hides non-matching ones).
		echo '<div class="rb-filters" id="rb-filterbar">';
		echo '<input type="search" id="rb-fphone" placeholder="' . esc_attr__( 'Phone', 'risky-buyer' ) . '" autocomplete="off">';
		echo '<input type="search" id="rb-fname" placeholder="' . esc_attr__( 'Name', 'risky-buyer' ) . '" autocomplete="off">';
		echo '<select id="rb-op" title="' . esc_attr__( 'Combine criteria', 'risky-buyer' ) . '">';
		echo '<option value="AND">' . esc_html__( 'All (AND)', 'risky-buyer' ) . '</option>';
		echo '<option value="OR">' . esc_html__( 'Any (OR)', 'risky-buyer' ) . '</option>';
		echo '</select>';
		echo '<select id="rb-freason"><option value="">' . esc_html__( 'All reasons', 'risky-buyer' ) . '</option>';
		foreach ( $reasons as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $r['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<button type="button" class="button-link" id="rb-clear">' . esc_html__( 'Clear', 'risky-buyer' ) . '</button>';
		echo '<span class="rb-count description">' . esc_html__( 'Showing', 'risky-buyer' ) . ' <span id="rb-count"></span></span>';
		echo '</div>';

		$this->render_entries_table( $entries, $bl->can_manage() );
	}

	/* --------------------------------------------------------------------- */
	/* Tab: Settings                                                         */
	/* --------------------------------------------------------------------- */

	protected function render_settings_tab( $bl ) {
		if ( ! $bl->can_manage() ) {
			echo '<p><em>' . esc_html__( 'Only an administrator can change settings.', 'risky-buyer' ) . '</em></p>';
			return;
		}
		$s       = Riskybuyer_Settings::get();
		$state   = Riskybuyer_Settings::state();
		$last    = $state['last_sync'] ? date_i18n( 'd.m.Y H:i', (int) $state['last_sync'] ) : __( 'never', 'risky-buyer' );
		$enabled = ! empty( $s['sync_enabled'] );

		echo '<div class="rb-settings">';
		echo '<h2>' . esc_html__( 'Synchronization with the central server', 'risky-buyer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'When enabled, your checks are extended with phone numbers from the shared server (created by other sites). Your own entries always stay on your site. Disable to use the local list only.', 'risky-buyer' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Data sent to the server (only if you have a write key): phone, name, reason, note, and your site domain.', 'risky-buyer' ) . '</p>';

		// Settings save automatically (no page reload, no Save button).
		echo '<p><label><input type="checkbox" id="rb-sync-enabled"' . checked( $enabled, true, false ) . '> <strong>' . esc_html__( 'Enable sync with the central server', 'risky-buyer' ) . '</strong></label> <span id="rb-save-status" class="description"></span></p>';

		echo '<div id="rb-sync-fields"' . ( $enabled ? '' : ' style="display:none"' ) . '>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="rb-server-url">' . esc_html__( 'Server URL', 'risky-buyer' ) . '</label></th><td><input type="url" id="rb-server-url" class="regular-text" value="' . esc_attr( $s['server_url'] ) . '"></td></tr>';
		echo '<tr><th><label for="rb-api-key">' . esc_html__( 'API key', 'risky-buyer' ) . '</label></th><td><input type="text" id="rb-api-key" class="regular-text" value="' . esc_attr( $s['api_key'] ) . '"> <span id="rb-key-status" class="description"></span><p class="description">' . esc_html__( 'Only needed to write your entries to the server. Reading the shared list is open.', 'risky-buyer' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<p><button type="button" class="button" id="rb-sync-now">' . esc_html__( 'Sync now', 'risky-buyer' ) . '</button> ';
		echo '<button type="button" class="button" id="rb-push" style="display:none">' . esc_html__( 'Push my list to the server', 'risky-buyer' ) . '</button></p>';

		echo '<p id="rb-sync-state">' . esc_html__( 'Last sync:', 'risky-buyer' ) . ' <strong>' . esc_html( $last ) . '</strong> &nbsp; ' . esc_html__( 'Cached entries:', 'risky-buyer' ) . ' <strong>' . (int) $state['cached'] . '</strong></p>';
		if ( ! empty( $state['last_error'] ) ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'Last error:', 'risky-buyer' ) . ' ' . esc_html( $state['last_error'] ) . '</p>';
		}
		echo '</div></div>';
	}

	/**
	 * Shared entries table. $with_actions adds edit/delete (admins).
	 *
	 * @param array $entries      Entries.
	 * @param bool  $with_actions Show actions column.
	 */
	protected function render_entries_table( $entries, $with_actions ) {
		echo '<table id="rb-list" class="wp-list-table widefat fixed striped rb-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'risky-buyer' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'risky-buyer' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'risky-buyer' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'risky-buyer' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'risky-buyer' ) . '</th>';
		echo '<th>' . esc_html__( 'Added by', 'risky-buyer' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'risky-buyer' ) . '</th>';
		if ( $with_actions ) {
			echo '<th>' . esc_html__( 'Actions', 'risky-buyer' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$cols = $with_actions ? 8 : 7;
		if ( empty( $entries ) ) {
			echo '<tr><td colspan="' . (int) $cols . '">' . esc_html__( 'No entries.', 'risky-buyer' ) . '</td></tr>';
		} else {
			foreach ( $entries as $e ) {
				$color  = Riskybuyer_Blacklist::reason_color( $e['reason_code'] );
				$label  = Riskybuyer_Blacklist::reason_label( $e['reason_code'] );
				$date   = ! empty( $e['created_at'] ) ? mysql2date( 'd.m.Y H:i', $e['created_at'] ) : '';
				$dname  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $e['name_raw'], 'UTF-8' ) : strtolower( (string) $e['name_raw'] );
				$dphone = preg_replace( '/\D+/', '', (string) $e['phone_raw'] );
				echo '<tr class="rb-row" data-name="' . esc_attr( $dname ) . '" data-phone="' . esc_attr( $dphone ) . '" data-reason="' . esc_attr( $e['reason_code'] ) . '">';
				echo '<td>' . esc_html( $e['name_raw'] ) . '</td>';
				echo '<td>' . esc_html( $e['phone_raw'] ) . '</td>';
				echo '<td><span class="rb-pill" style="background:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span></td>';
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
					echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'risky-buyer' ) . '</a> ';
					echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove this entry?', 'risky-buyer' ) ) . '\');">';
					wp_nonce_field( 'riskybuyer_admin' );
					echo '<input type="hidden" name="riskybuyer_action" value="delete">';
					echo '<input type="hidden" name="uuid" value="' . esc_attr( $e['uuid'] ) . '">';
					echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Delete', 'risky-buyer' ) . '</button>';
					echo '</form>';
					echo '</td>';
				}
				echo '</tr>';
			}
			echo '<tr class="rb-nomatch" style="display:none"><td colspan="' . (int) $cols . '">' . esc_html__( 'No matches for the current filter.', 'risky-buyer' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
