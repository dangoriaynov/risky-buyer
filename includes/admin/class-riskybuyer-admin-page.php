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

	const SLUG = 'riskybuyer';

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
		// Uses the runtime basename so it works whatever the folder/main-file is named.
		add_filter( 'plugin_action_links_' . plugin_basename( RISKYBUYER_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$link = '<a href="' . esc_url( $this->base_url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'riskybuyer' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Risky buyers', 'riskybuyer' ),
			__( 'Risky buyers', 'riskybuyer' ),
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
		$open   = '';

		if ( 'add' === $action ) {
			$tab  = 'add';
			$open = 'single';
			$res  = $bl->add_entry( $this->posted_entry() );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Client added to the list.', 'riskybuyer' );
				$this->remember_reason( $res['reason_code'] );
			}
		} elseif ( 'bulk_add' === $action ) {
			$tab    = 'add';
			$open   = 'bulk';
			$text   = isset( $_POST['bulk'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bulk'] ) ) : '';
			$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other';
			$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
			$res    = $bl->bulk_add( $text, $reason, $note );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				/* translators: 1: added count, 2: skipped count, 3: invalid count */
				$notice = sprintf( __( 'Added: %1$d · skipped (already in list): %2$d · invalid: %3$d', 'riskybuyer' ), $res['added'], $res['skipped'], $res['invalid'] );
				$this->remember_reason( $reason );
			}
		} elseif ( 'update' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->update_entry( $uuid, $this->posted_entry() );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Entry updated.', 'riskybuyer' );
			}
		} elseif ( 'delete' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->delete_entry( $uuid );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = __( 'Entry removed.', 'riskybuyer' );
			}
		} elseif ( 'save_settings' === $action ) {
			$tab = 'settings';
			if ( ! $bl->can_manage() ) {
				$notice = __( 'Only an administrator can change settings.', 'riskybuyer' );
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
				$notice = __( 'Settings saved.', 'riskybuyer' );
			}
		} elseif ( 'sync_now' === $action ) {
			$tab = 'settings';
			if ( ! $bl->can_manage() ) {
				$notice = __( 'Only an administrator can change settings.', 'riskybuyer' );
				$type   = 'error';
			} else {
				$ok = Riskybuyer_Remote_Sync::instance()->pull();
				$st = Riskybuyer_Settings::state();
				if ( $ok ) {
					/* translators: %d: number of cached entries */
					$notice = sprintf( __( 'Sync done: %d entries cached.', 'riskybuyer' ), (int) $st['cached'] );
				} else {
					$type = 'error';
					/* translators: %s: error message */
					$notice = sprintf( __( 'Sync error: %s', 'riskybuyer' ), $st['last_error'] );
				}
			}
		} elseif ( 'push_all' === $action ) {
			$tab = 'settings';
			if ( ! $bl->can_manage() ) {
				$notice = __( 'Only an administrator can change settings.', 'riskybuyer' );
				$type   = 'error';
			} else {
				$r = Riskybuyer_Remote_Sync::instance()->push_all();
				if ( is_wp_error( $r ) ) {
					$type = 'error';
					/* translators: %s: error message */
					$notice = sprintf( __( 'Push error: %s', 'riskybuyer' ), $r->get_error_message() );
				} else {
					/* translators: %d: number of entries pushed */
					$notice = sprintf( __( 'Pushed %d entries to the server.', 'riskybuyer' ), (int) $r );
				}
			}
		}

		$args = array(
			'riskybuyer_notice' => rawurlencode( $notice ),
			'riskybuyer_type'   => $type,
		);
		if ( '' !== $open ) {
			$args['rb_open'] = $open;
		}
		wp_safe_redirect( add_query_arg( $args, $this->base_url( $tab ) ) );
		exit;
	}

	/**
	 * Remember the last reason this user picked, to pre-select it next time.
	 *
	 * @param string $code Reason code.
	 */
	protected function remember_reason( $code ) {
		$code    = sanitize_key( $code );
		$reasons = Riskybuyer_Blacklist::reasons();
		if ( isset( $reasons[ $code ] ) ) {
			update_user_meta( get_current_user_id(), 'riskybuyer_last_reason', $code );
		}
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
		echo '<h1>' . esc_html__( 'Risky buyers', 'riskybuyer' ) . '</h1>';

		// Notice.
		if ( isset( $_GET['riskybuyer_notice'] ) && '' !== $_GET['riskybuyer_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			$msg   = sanitize_text_field( wp_unslash( $_GET['riskybuyer_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			$ntype = ( isset( $_GET['riskybuyer_type'] ) && 'error' === $_GET['riskybuyer_type'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-' . esc_attr( $ntype ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Tabs.
		$tabs = array(
			'list' => __( 'List', 'riskybuyer' ),
			'add'  => __( 'Add', 'riskybuyer' ),
		);
		if ( $bl->can_manage() ) {
			$tabs['settings'] = __( 'Settings', 'riskybuyer' );
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

		// Editing: just the prefilled form.
		if ( $edit_entry ) {
			$this->render_form( $edit_entry, $bl->can_manage() );
			return;
		}

		$this->render_customer_search();

		// Which form to keep open (after a submit we re-open the one just used).
		$open = isset( $_GET['rb_open'] ) ? sanitize_key( wp_unslash( $_GET['rb_open'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		echo '<div class="rb-add-toggles">';
		echo '<button type="button" class="button rb-toggle" data-target="rb-single-wrap"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Add one client', 'riskybuyer' ) . '</button>';
		echo '<button type="button" class="button rb-toggle" data-target="rb-bulk-wrap"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>' . esc_html__( 'Bulk add', 'riskybuyer' ) . '</button>';
		echo '</div>';

		echo '<div id="rb-single-wrap" class="rb-collapse"' . ( 'single' === $open ? '' : ' style="display:none"' ) . '>';
		$this->render_form( null, $bl->can_manage() );
		echo '</div>';

		echo '<div id="rb-bulk-wrap" class="rb-collapse"' . ( 'bulk' === $open ? '' : ' style="display:none"' ) . '>';
		$this->render_bulk_form();
		echo '</div>';
	}

	/**
	 * Reason pre-selected on the Add forms: the last reason this user picked,
	 * otherwise the first (top) reason in the list.
	 *
	 * @return string
	 */
	protected function default_reason() {
		$reasons = Riskybuyer_Blacklist::reasons();
		$last    = (string) get_user_meta( get_current_user_id(), 'riskybuyer_last_reason', true );
		if ( $last && isset( $reasons[ $last ] ) ) {
			return $last;
		}
		$keys = array_keys( $reasons );
		return $keys ? $keys[0] : 'other';
	}

	/**
	 * Search box (outside the add form, so Enter can't submit it) to pull a
	 * customer from existing orders and prefill the name + phone fields.
	 */
	protected function render_customer_search() {
		echo '<div class="rb-form rb-cust-search">';
		echo '<label for="rb-cust-q">' . esc_html__( 'Find a customer from your orders', 'riskybuyer' ) . '</label>';
		echo '<div class="rb-cust-box">';
		echo '<input type="search" id="rb-cust-q" autocomplete="off" placeholder="' . esc_attr__( 'Type a name or phone…', 'riskybuyer' ) . '">';
		echo '<div id="rb-cust-results" class="rb-cust-results" data-empty="' . esc_attr__( 'No matching customers.', 'riskybuyer' ) . '"></div>';
		echo '</div>';
		echo '<p class="description rb-cust-hint">' . esc_html__( 'Pick someone who already ordered to fill in the name and phone below.', 'riskybuyer' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the reason <select> options.
	 *
	 * @param string $current Selected reason code.
	 */
	protected function reason_options( $current = 'other' ) {
		foreach ( Riskybuyer_Blacklist::reasons() as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '" data-color="' . esc_attr( $r['color'] ) . '"'
				. selected( $current, $code, false )
				. ' style="background-color:' . esc_attr( $r['color'] ) . ';color:#fff">'
				. esc_html( $r['label'] ) . '</option>';
		}
	}

	protected function render_form( $edit_entry, $can_manage ) {
		$is_edit = ( $edit_entry && $can_manage );
		if ( $is_edit ) {
			echo '<h2>' . esc_html__( 'Edit entry', 'riskybuyer' ) . '</h2>';
		}
		echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" class="rb-form">';
		wp_nonce_field( 'riskybuyer_admin' );
		echo '<input type="hidden" name="riskybuyer_action" value="' . ( $is_edit ? 'update' : 'add' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="uuid" value="' . esc_attr( $edit_entry['uuid'] ) . '">';
		}

		echo '<p class="rb-field"><label for="rb-name">' . esc_html__( 'Name', 'riskybuyer' ) . '</label>';
		echo '<input type="text" id="rb-name" name="name" value="' . esc_attr( $is_edit ? $edit_entry['name_raw'] : '' ) . '"></p>';

		echo '<p class="rb-field"><label for="rb-phone">' . esc_html__( 'Phone', 'riskybuyer' ) . '</label>';
		echo '<input type="text" id="rb-phone" name="phone" value="' . esc_attr( $is_edit ? $edit_entry['phone_raw'] : '' ) . '"></p>';

		echo '<p class="rb-field"><label for="rb-reason">' . esc_html__( 'Reason', 'riskybuyer' ) . '</label>';
		echo '<select id="rb-reason" name="reason" class="rb-reason-color">';
		$this->reason_options( $is_edit ? $edit_entry['reason_code'] : $this->default_reason() );
		echo '</select></p>';

		echo '<p class="rb-field"><label for="rb-note">' . esc_html__( 'Note', 'riskybuyer' ) . '</label>';
		echo '<textarea id="rb-note" name="note" rows="2">' . esc_textarea( $is_edit ? (string) $edit_entry['note'] : '' ) . '</textarea></p>';

		echo '<p class="rb-actions">';
		submit_button( $is_edit ? __( 'Save changes', 'riskybuyer' ) : __( 'Add client', 'riskybuyer' ), 'primary', 'submit', false );
		if ( $is_edit ) {
			echo ' <a class="button" href="' . esc_url( $this->base_url( 'list' ) ) . '">' . esc_html__( 'Cancel', 'riskybuyer' ) . '</a>';
		}
		echo '</p>';
		echo '</form>';
	}

	protected function render_bulk_form() {
		echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" class="rb-form">';
		wp_nonce_field( 'riskybuyer_admin' );
		echo '<input type="hidden" name="riskybuyer_action" value="bulk_add">';

		echo '<p class="rb-field"><label for="rb-bulk">' . esc_html__( 'Clients (one per line)', 'riskybuyer' ) . '</label>';
		echo '<textarea id="rb-bulk" name="bulk" rows="8" class="code" placeholder="0888123456, Ivan Ivanov&#10;0877000111&#10;Maria Petrova"></textarea>';
		echo '<span class="description">' . esc_html__( 'Fields separated by comma / tab / semicolon. A value with 6+ digits is treated as the phone, the rest as the name. The reason and note below apply to the whole list. Existing entries (by phone or name) are skipped.', 'riskybuyer' ) . '</span></p>';

		echo '<p class="rb-field"><label for="rb-bulk-reason">' . esc_html__( 'Reason', 'riskybuyer' ) . '</label>';
		echo '<select id="rb-bulk-reason" name="reason" class="rb-reason-color">';
		$this->reason_options( $this->default_reason() );
		echo '</select></p>';

		echo '<p class="rb-field"><label for="rb-bulk-note">' . esc_html__( 'Note', 'riskybuyer' ) . '</label>';
		echo '<textarea id="rb-bulk-note" name="note" rows="2"></textarea></p>';

		echo '<p class="rb-actions">';
		submit_button( __( 'Add in bulk', 'riskybuyer' ), 'primary', 'submit', false );
		echo '</p>';
		echo '</form>';
	}

	/* --------------------------------------------------------------------- */
	/* Tab: List                                                             */
	/* --------------------------------------------------------------------- */

	protected function render_list_tab( $bl ) {
		$entries = $bl->all( array( 'status' => 'active' ) );

		// Instant in-browser filter (all rows are rendered; JS hides non-matching ones).
		echo '<div class="rb-filters" id="rb-filterbar">';
		echo '<input type="search" id="rb-fphone" placeholder="' . esc_attr__( 'Phone', 'riskybuyer' ) . '" autocomplete="off">';
		echo '<input type="search" id="rb-fname" placeholder="' . esc_attr__( 'Name', 'riskybuyer' ) . '" autocomplete="off">';
		echo '<select id="rb-op" title="' . esc_attr__( 'Combine criteria', 'riskybuyer' ) . '">';
		echo '<option value="AND">' . esc_html__( 'All (AND)', 'riskybuyer' ) . '</option>';
		echo '<option value="OR">' . esc_html__( 'Any (OR)', 'riskybuyer' ) . '</option>';
		echo '</select>';
		echo '<select id="rb-freason" class="rb-reason-color"><option value="" data-color="">' . esc_html__( 'All reasons', 'riskybuyer' ) . '</option>';
		$this->reason_options( '' );
		echo '</select>';
		echo '<button type="button" class="button-link" id="rb-clear">' . esc_html__( 'Clear', 'riskybuyer' ) . '</button>';
		echo '<span class="rb-count description">' . esc_html__( 'Showing', 'riskybuyer' ) . ' <span id="rb-count"></span></span>';
		echo '</div>';

		$this->render_entries_table( $entries, $bl->can_manage() );
	}

	/* --------------------------------------------------------------------- */
	/* Tab: Settings                                                         */
	/* --------------------------------------------------------------------- */

	protected function render_settings_tab( $bl ) {
		if ( ! $bl->can_manage() ) {
			echo '<p><em>' . esc_html__( 'Only an administrator can change settings.', 'riskybuyer' ) . '</em></p>';
			return;
		}
		$s       = Riskybuyer_Settings::get();
		$state   = Riskybuyer_Settings::state();
		$last    = $state['last_sync'] ? date_i18n( 'd.m.Y H:i', (int) $state['last_sync'] ) : __( 'never', 'riskybuyer' );
		$enabled = ! empty( $s['sync_enabled'] );
		$has_key = '' !== $s['api_key'];

		$sync_label = __( 'Sync now', 'riskybuyer' );
		$push_label = __( 'Push my list to the server', 'riskybuyer' );
		$clear_lbl  = __( 'Clear key', 'riskybuyer' );
		$sent_text  = __( 'Data sent to the server: phone, name, reason, note, and your site domain.', 'riskybuyer' );

		echo '<div class="rb-settings">';
		echo '<h2>' . esc_html__( 'Synchronization with the central server', 'riskybuyer' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'When enabled, your checks are extended with phone numbers from the shared server (created by other sites). Your own entries always stay on your site. Disable to use the local list only.', 'riskybuyer' ) . '</p>';

		// Settings save automatically (no page reload, no Save button).
		$status_html = esc_html__( 'Last update from the shared list:', 'riskybuyer' ) . ' <strong id="rb-last-sync">' . esc_html( $last ) . '</strong><br>'
			. esc_html__( 'Phone numbers downloaded:', 'riskybuyer' ) . ' <strong id="rb-cached-count">' . (int) $state['cached'] . '</strong><br>'
			. esc_html__( 'New in last sync:', 'riskybuyer' ) . ' <strong id="rb-added-count">' . (int) $state['last_added'] . '</strong>';
		$status_aria = wp_strip_all_tags( str_replace( '<br>', ' · ', $status_html ) );

		echo '<p class="rb-enable-row"><label><input type="checkbox" id="rb-sync-enabled"' . checked( $enabled, true, false ) . '> <strong>' . esc_html__( 'Enable sync with the central server', 'riskybuyer' ) . '</strong></label>';
		echo '<span class="rb-info" tabindex="0" role="img" aria-label="' . esc_attr( $sent_text ) . '"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span class="rb-tip">' . esc_html( $sent_text ) . '</span></span>';
		echo '<span class="rb-info" tabindex="0" role="img" aria-label="' . esc_attr( $status_aria ) . '"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span><span class="rb-tip">' . wp_kses( $status_html, array( 'strong' => array( 'id' => array() ), 'br' => array() ) ) . '</span></span>';
		echo ' <span id="rb-save-status" class="description"></span></p>';

		echo '<div id="rb-sync-fields"' . ( $enabled ? '' : ' style="display:none"' ) . '>';
		echo '<table class="form-table"><tbody>';

		// Server URL with inline action icons (sync = read, push = write/key only).
		echo '<tr><th><label for="rb-server-url">' . esc_html__( 'Server URL', 'riskybuyer' ) . '</label></th><td>';
		echo '<span class="rb-url-row">';
		echo '<input type="url" id="rb-server-url" class="regular-text" value="' . esc_attr( $s['server_url'] ) . '">';
		echo '<button type="button" id="rb-sync-now" class="button rb-iconbtn rb-iconbtn-sync" title="' . esc_attr( $sync_label ) . '" aria-label="' . esc_attr( $sync_label ) . '"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>';
		echo '<button type="button" id="rb-push" class="button rb-iconbtn rb-iconbtn-push" title="' . esc_attr( $push_label ) . '" aria-label="' . esc_attr( $push_label ) . '" style="display:none"><span class="dashicons dashicons-upload" aria-hidden="true"></span></button>';
		echo '</span> <span id="rb-sync-msg" class="description"></span>';
		echo '</td></tr>';

		// API key — locked (grey, read-only) once a key is set; red ✕ clears it.
		echo '<tr><th><label for="rb-api-key">' . esc_html__( 'API key', 'riskybuyer' ) . '</label></th><td>';
		echo '<span class="rb-key-wrap"><input type="text" id="rb-api-key" class="regular-text' . ( $has_key ? ' rb-locked' : '' ) . '" value="' . esc_attr( $s['api_key'] ) . '"' . ( $has_key ? ' readonly' : '' ) . '>';
		echo '<button type="button" id="rb-key-clear" class="rb-key-clear" title="' . esc_attr( $clear_lbl ) . '" aria-label="' . esc_attr( $clear_lbl ) . '"' . ( $has_key ? '' : ' style="display:none"' ) . '>✕</button></span> ';
		echo '<span id="rb-key-status" class="description"></span>';
		echo '<p class="description">' . esc_html__( 'Only needed to write your entries to the server. Reading the shared list is open.', 'riskybuyer' ) . '</p></td></tr>';
		echo '</tbody></table>';

		if ( ! empty( $state['last_error'] ) ) {
			echo '<p style="color:#b32d2e">' . esc_html__( 'Last error:', 'riskybuyer' ) . ' ' . esc_html( $state['last_error'] ) . '</p>';
		}
		echo '</div></div>';
	}

	/**
	 * Shared entries table. $with_actions adds edit/delete (admins).
	 *
	 * @param array $entries      Entries.
	 * @param bool  $with_actions Show actions column.
	 */
	/**
	 * A sortable column header (click to sort client-side).
	 *
	 * @param string $label Header label.
	 * @param string $key   Sort key.
	 */
	protected function sortable_th( $label, $key ) {
		echo '<th class="rb-sortable" data-sort="' . esc_attr( $key ) . '"><span class="rb-th-label">' . esc_html( $label ) . '</span><span class="rb-sort-ind" aria-hidden="true"></span></th>';
	}

	protected function render_entries_table( $entries, $with_actions ) {
		echo '<table id="rb-list" class="wp-list-table widefat fixed striped rb-table"><thead><tr>';
		$this->sortable_th( __( 'Name', 'riskybuyer' ), 'name' );
		$this->sortable_th( __( 'Phone', 'riskybuyer' ), 'phone' );
		$this->sortable_th( __( 'Reason', 'riskybuyer' ), 'reason' );
		$this->sortable_th( __( 'Note', 'riskybuyer' ), 'note' );
		$this->sortable_th( __( 'Source', 'riskybuyer' ), 'source' );
		$this->sortable_th( __( 'Added by', 'riskybuyer' ), 'addedby' );
		$this->sortable_th( __( 'Date', 'riskybuyer' ), 'date' );
		if ( $with_actions ) {
			echo '<th>' . esc_html__( 'Actions', 'riskybuyer' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$cols = $with_actions ? 8 : 7;
		if ( empty( $entries ) ) {
			echo '<tr><td colspan="' . (int) $cols . '">' . esc_html__( 'No entries.', 'riskybuyer' ) . '</td></tr>';
		} else {
			foreach ( $entries as $e ) {
				$color  = Riskybuyer_Blacklist::reason_color( $e['reason_code'] );
				$label  = Riskybuyer_Blacklist::reason_label( $e['reason_code'] );
				$date   = ! empty( $e['created_at'] ) ? mysql2date( 'd.m.Y H:i', $e['created_at'] ) : '';
				$dname  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $e['name_raw'], 'UTF-8' ) : strtolower( (string) $e['name_raw'] );
				$dphone = preg_replace( '/\D+/', '', (string) $e['phone_raw'] );
				echo '<tr class="rb-row" data-name="' . esc_attr( $dname ) . '" data-phone="' . esc_attr( $dphone ) . '" data-reason="' . esc_attr( $e['reason_code'] ) . '" data-ts="' . esc_attr( (string) $e['created_at'] ) . '">';
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
					$edit_label = __( 'Edit', 'riskybuyer' );
					$del_label  = __( 'Delete', 'riskybuyer' );
					echo '<a class="button button-small rb-icon" href="' . esc_url( $edit_url ) . '" title="' . esc_attr( $edit_label ) . '" aria-label="' . esc_attr( $edit_label ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></a> ';
					echo '<form method="post" action="' . esc_url( $this->base_url() ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove this entry?', 'riskybuyer' ) ) . '\');">';
					wp_nonce_field( 'riskybuyer_admin' );
					echo '<input type="hidden" name="riskybuyer_action" value="delete">';
					echo '<input type="hidden" name="uuid" value="' . esc_attr( $e['uuid'] ) . '">';
					echo '<button type="submit" class="button button-small button-link-delete rb-icon" title="' . esc_attr( $del_label ) . '" aria-label="' . esc_attr( $del_label ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
					echo '</form>';
					echo '</td>';
				}
				echo '</tr>';
			}
			echo '<tr class="rb-nomatch" style="display:none"><td colspan="' . (int) $cols . '">' . esc_html__( 'No matches for the current filter.', 'riskybuyer' ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
