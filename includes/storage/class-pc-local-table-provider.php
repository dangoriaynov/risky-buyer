<?php
/**
 * Local storage: a custom DB table on this site.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PC_Local_Table_Provider implements PC_Storage_Provider {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pc_blacklist';
	}

	/**
	 * Create/upgrade the table (activation hook).
	 */
	public static function install() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			phone_norm VARCHAR(32) NOT NULL DEFAULT '',
			phone_raw VARCHAR(64) NOT NULL DEFAULT '',
			name_norm VARCHAR(191) NOT NULL DEFAULT '',
			name_raw VARCHAR(191) NOT NULL DEFAULT '',
			reason_code VARCHAR(32) NOT NULL DEFAULT 'other',
			note TEXT NULL,
			source_site VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by_name VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY phone_norm (phone_norm),
			KEY name_norm (name_norm),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'pc_db_version', PC_DB_VERSION );
	}

	/**
	 * Create the table on demand if missing/outdated (robust to manual uploads).
	 */
	public static function maybe_install() {
		if ( get_option( 'pc_db_version' ) !== PC_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function all( array $filters = array() ) {
		global $wpdb;
		$table = self::table();

		$where  = array();
		$args   = array();
		$status = isset( $filters['status'] ) ? $filters['status'] : 'active';
		if ( 'all' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( ! empty( $filters['reason'] ) ) {
			$where[] = 'reason_code = %s';
			$args[]  = $filters['reason'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = '( name_raw LIKE %s OR phone_raw LIKE %s OR note LIKE %s )';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 5000';

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$prepared = $args ? $wpdb->prepare( $sql, $args ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		// phpcs:enable
		return $rows ? $rows : array();
	}

	/**
	 * Helper: active rows where $column is in $values.
	 *
	 * @param string $column Column name (whitelisted).
	 * @param array  $values Values.
	 * @return array
	 */
	protected function find_in( $column, array $values ) {
		global $wpdb;
		$values = array_values( array_unique( array_filter( $values, 'strlen' ) ) );
		if ( empty( $values ) ) {
			return array();
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// $column is internal/whitelisted, never user input.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$sql  = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'active' AND {$column} IN ({$placeholders})",
			$values
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable
		return $rows ? $rows : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_by_phones( array $phones ) {
		return $this->find_in( 'phone_norm', $phones );
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_by_names( array $names ) {
		return $this->find_in( 'name_norm', $names );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( $uuid ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A );
		// phpcs:enable
		return $row ? $row : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function add( array $entry ) {
		global $wpdb;
		$data = $entry;
		if ( empty( $data['updated_at'] ) ) {
			unset( $data['updated_at'] ); // let DB default (NULL) apply.
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table(), $data );
		// phpcs:enable
		if ( ! $ok ) {
			return new WP_Error( 'pc_db', 'Грешка при запис в базата.' );
		}
		$entry['id'] = (int) $wpdb->insert_id;
		return $entry;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( $uuid, array $changes ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->update( self::table(), $changes, array( 'uuid' => $uuid ) );
		// phpcs:enable
		return false !== $ok;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( $uuid ) {
		return $this->update(
			$uuid,
			array(
				'status'     => 'removed',
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id(),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function source_site() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? $host : 'local';
	}
}
