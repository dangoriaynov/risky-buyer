<?php
/**
 * Admin management page: list, add, edit (admin), delete (admin).
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PC_Admin_Page {

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
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function menu() {
		add_menu_page(
			'Проблемни клиенти',
			'Проблемни клиенти',
			'edit_shop_orders',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-warning',
			56
		);
	}

	/**
	 * Handle add/update/delete POSTs, then redirect with a notice.
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['pc_action'] ) ) {
			return;
		}
		check_admin_referer( 'pc_admin' );

		$bl     = PC_Blacklist::instance();
		$action = sanitize_key( wp_unslash( $_POST['pc_action'] ) );
		$notice = '';
		$type   = 'success';

		if ( 'add' === $action ) {
			$res = $bl->add_entry(
				array(
					'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
					'phone'  => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
					'reason' => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other',
					'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				)
			);
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = 'Клиентът е добавен в списъка.';
			}
		} elseif ( 'update' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->update_entry(
				$uuid,
				array(
					'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
					'phone'  => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
					'reason' => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'other',
					'note'   => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				)
			);
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = 'Записът е обновен.';
			}
		} elseif ( 'delete' === $action ) {
			$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
			$res  = $bl->delete_entry( $uuid );
			if ( is_wp_error( $res ) ) {
				$notice = $res->get_error_message();
				$type   = 'error';
			} else {
				$notice = 'Записът е премахнат.';
			}
		}

		$url = add_query_arg(
			array(
				'page'        => self::SLUG,
				'pc_notice'   => rawurlencode( $notice ),
				'pc_type'     => $type,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$bl      = PC_Blacklist::instance();
		$reasons = PC_Blacklist::reasons();

		// Notice from redirect.
		if ( isset( $_GET['pc_notice'] ) && '' !== $_GET['pc_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg   = sanitize_text_field( wp_unslash( $_GET['pc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ntype = ( isset( $_GET['pc_type'] ) && 'error' === $_GET['pc_type'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-' . esc_attr( $ntype ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Filters.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$freason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Edit mode (admins only).
		$edit_entry = null;
		if ( $bl->can_manage() && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit_entry = $bl->get( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$entries = $bl->all(
			array(
				'status' => 'active',
				'search' => $search,
				'reason' => $freason,
			)
		);

		echo '<div class="wrap pc-wrap">';
		echo '<h1>Проблемни клиенти</h1>';
		echo '<p class="description">Списък на клиенти с проблеми (неизкупени пратки, фалшиви поръчки и др.). Поръчките им се отбелязват автоматично. Добавяне — целият екип; редакция и триене — само администратор.</p>';

		$this->render_form( $reasons, $edit_entry, $bl->can_manage() );
		$this->render_filters( $reasons, $search, $freason );
		$this->render_table( $entries, $bl->can_manage() );

		echo '</div>';
	}

	protected function render_form( $reasons, $edit_entry, $can_manage ) {
		$is_edit = ( $edit_entry && $can_manage );
		echo '<h2>' . ( $is_edit ? 'Редакция на запис' : 'Добавяне на клиент' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" class="pc-form">';
		wp_nonce_field( 'pc_admin' );
		echo '<input type="hidden" name="pc_action" value="' . ( $is_edit ? 'update' : 'add' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="uuid" value="' . esc_attr( $edit_entry['uuid'] ) . '">';
		}
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>Име</label></th><td><input type="text" name="name" class="regular-text" value="' . esc_attr( $is_edit ? $edit_entry['name_raw'] : '' ) . '"></td></tr>';
		echo '<tr><th><label>Телефон</label></th><td><input type="text" name="phone" class="regular-text" value="' . esc_attr( $is_edit ? $edit_entry['phone_raw'] : '' ) . '"></td></tr>';
		echo '<tr><th><label>Причина</label></th><td><select name="reason">';
		$cur = $is_edit ? $edit_entry['reason_code'] : 'other';
		foreach ( $reasons as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $cur, $code, false ) . '>' . esc_html( $r['label'] ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>Бележка</label></th><td><textarea name="note" rows="2" class="large-text">' . esc_textarea( $is_edit ? (string) $edit_entry['note'] : '' ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( $is_edit ? 'Запази промените' : 'Добави' );
		if ( $is_edit ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Отказ</a>';
		}
		echo '</form>';
	}

	protected function render_filters( $reasons, $search, $freason ) {
		echo '<form method="get" class="pc-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Търсене (име, телефон, бележка)">';
		echo ' <select name="reason"><option value="">Всички причини</option>';
		foreach ( $reasons as $code => $r ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $freason, $code, false ) . '>' . esc_html( $r['label'] ) . '</option>';
		}
		echo '</select> ';
		submit_button( 'Филтрирай', 'secondary', '', false );
		echo '</form>';
	}

	protected function render_table( $entries, $can_manage ) {
		echo '<table class="wp-list-table widefat fixed striped pc-table"><thead><tr>';
		echo '<th>Име</th><th>Телефон</th><th>Причина</th><th>Бележка</th><th>Източник</th><th>Добавил</th><th>Дата</th><th>Действия</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $entries ) ) {
			echo '<tr><td colspan="8">Няма записи.</td></tr>';
		} else {
			foreach ( $entries as $e ) {
				$color = PC_Blacklist::reason_color( $e['reason_code'] );
				$label = PC_Blacklist::reason_label( $e['reason_code'] );
				$date  = ! empty( $e['created_at'] ) ? mysql2date( 'd.m.Y H:i', $e['created_at'] ) : '';
				echo '<tr>';
				echo '<td>' . esc_html( $e['name_raw'] ) . '</td>';
				echo '<td>' . esc_html( $e['phone_raw'] ) . '</td>';
				echo '<td><span class="pc-pill" style="background:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span></td>';
				echo '<td>' . esc_html( (string) $e['note'] ) . '</td>';
				echo '<td>' . esc_html( $e['source_site'] ) . '</td>';
				echo '<td>' . esc_html( $e['created_by_name'] ) . '</td>';
				echo '<td>' . esc_html( $date ) . '</td>';
				echo '<td>';
				if ( $can_manage ) {
					$edit_url = add_query_arg(
						array(
							'page' => self::SLUG,
							'edit' => rawurlencode( $e['uuid'] ),
						),
						admin_url( 'admin.php' )
					);
					echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">Редактирай</a> ';
					echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Да премахна ли този запис?\');">';
					wp_nonce_field( 'pc_admin' );
					echo '<input type="hidden" name="pc_action" value="delete">';
					echo '<input type="hidden" name="uuid" value="' . esc_attr( $e['uuid'] ) . '">';
					echo '<button type="submit" class="button button-small button-link-delete">Изтрий</button>';
					echo '</form>';
				} else {
					echo '<span class="description">само админ</span>';
				}
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}
}
