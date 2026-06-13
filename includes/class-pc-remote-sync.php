<?php
/**
 * Remote synchronization with the central server.
 *
 * PULL: fetches entries from the server into a local cache table, which the
 * matcher merges into its index (read-extend). Works even when the server is
 * unreachable — it degrades gracefully to the local list.
 *
 * PUSH: sends this site's own entries to the server (writers only).
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Probclient_Remote_Sync {

	const CRON = 'probclient_sync_event';

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( self::CRON, array( $this, 'pull' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Keep the hourly cron in sync with the enabled flag.
	 */
	public function maybe_schedule() {
		$scheduled = wp_next_scheduled( self::CRON );
		if ( Probclient_Settings::is_sync_enabled() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( self::next_run_ts(), 'daily', self::CRON );
			}
		} elseif ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON );
		}
	}

	/**
	 * Next 03:00 in the site timezone (as a UTC timestamp).
	 *
	 * @return int
	 */
	protected static function next_run_ts() {
		try {
			$tz   = wp_timezone();
			$now  = new DateTime( 'now', $tz );
			$next = new DateTime( 'today 03:00', $tz );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			return $next->getTimestamp();
		} catch ( Exception $e ) {
			return time() + DAY_IN_SECONDS;
		}
	}

	public static function clear_schedule() {
		$ts = wp_next_scheduled( self::CRON );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
	}

	/**
	 * Common request headers (adds the API key if present).
	 */
	protected function headers() {
		$h   = array( 'Accept' => 'application/json' );
		$key = Probclient_Settings::api_key();
		if ( '' !== $key ) {
			$h['Authorization'] = 'Bearer ' . $key;
		}
		return $h;
	}

	protected function record_error( $msg ) {
		$st               = Probclient_Settings::state();
		$st['last_error'] = (string) $msg;
		Probclient_Settings::set_state( $st );
	}

	/**
	 * Pull entries from the server into the cache. Returns true on success.
	 *
	 * @return bool
	 */
	public function pull() {
		if ( ! Probclient_Settings::is_sync_enabled() ) {
			return false;
		}

		$state = Probclient_Settings::state();
		$url   = Probclient_Settings::server_url() . '/entries';
		if ( ! empty( $state['last_since'] ) ) {
			$url = add_query_arg( 'since', rawurlencode( $state['last_since'] ), $url );
		}

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => $this->headers(),
			)
		);
		if ( is_wp_error( $res ) ) {
			$this->record_error( $res->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			$this->record_error( 'HTTP ' . $code );
			return false;
		}

		$body    = json_decode( wp_remote_retrieve_body( $res ), true );
		$entries = array();
		if ( isset( $body['entries'] ) && is_array( $body['entries'] ) ) {
			$entries = $body['entries'];
		} elseif ( is_array( $body ) ) {
			$entries = $body;
		}
		$this->upsert_cache( $entries );

		$st              = Probclient_Settings::state();
		$st['last_sync'] = time();
		$st['last_error'] = '';
		if ( ! empty( $body['now'] ) ) {
			$st['last_since'] = $body['now'];
		}
		$st['cached'] = $this->cache_count();
		Probclient_Settings::set_state( $st );
		return true;
	}

	/**
	 * Upsert server entries into the cache table (by uuid).
	 *
	 * @param array $entries Server entries.
	 */
	protected function upsert_cache( $entries ) {
		global $wpdb;
		$t = Probclient_Local_Table_Provider::cache_table();

		foreach ( $entries as $e ) {
			$uuid = isset( $e['uuid'] ) ? sanitize_text_field( $e['uuid'] ) : '';
			if ( '' === $uuid ) {
				continue;
			}
			$phone = isset( $e['phone'] ) ? $e['phone'] : ( isset( $e['phone_raw'] ) ? $e['phone_raw'] : ( isset( $e['phone_norm'] ) ? $e['phone_norm'] : '' ) );
			$name  = isset( $e['name'] ) ? $e['name'] : ( isset( $e['name_raw'] ) ? $e['name_raw'] : ( isset( $e['name_norm'] ) ? $e['name_norm'] : '' ) );
			$row   = array(
				'uuid'        => $uuid,
				'phone_norm'  => Probclient_Blacklist::normalize_phone( $phone ),
				'phone_raw'   => sanitize_text_field( (string) $phone ),
				'name_norm'   => Probclient_Blacklist::normalize_name( $name ),
				'name_raw'    => sanitize_text_field( (string) $name ),
				'reason_code' => Probclient_Blacklist::valid_reason( isset( $e['reason'] ) ? $e['reason'] : ( isset( $e['reason_code'] ) ? $e['reason_code'] : 'other' ) ),
				'note'        => isset( $e['note'] ) ? wp_strip_all_tags( $e['note'] ) : '',
				'source_site' => isset( $e['source_site'] ) ? sanitize_text_field( $e['source_site'] ) : '',
				'status'      => isset( $e['status'] ) ? sanitize_key( $e['status'] ) : 'active',
				'updated_at'  => isset( $e['updated_at'] ) ? sanitize_text_field( $e['updated_at'] ) : current_time( 'mysql' ),
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE uuid = %s", $uuid ) );
			if ( $exists ) {
				$wpdb->update( $t, $row, array( 'uuid' => $uuid ) );
			} else {
				$wpdb->insert( $t, $row );
			}
			// phpcs:enable
		}
	}

	/**
	 * Active cached entries (for merging into the matcher).
	 *
	 * @return array<int,array>
	 */
	public function cached_active() {
		global $wpdb;
		$t = Probclient_Local_Table_Provider::cache_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE status = 'active' ORDER BY updated_at DESC LIMIT 5000", ARRAY_A );
		// phpcs:enable
		return $rows ? $rows : array();
	}

	public function cache_count() {
		global $wpdb;
		$t = Probclient_Local_Table_Provider::cache_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'active'" );
	}

	public function clear_cache() {
		global $wpdb;
		$t = Probclient_Local_Table_Provider::cache_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$t}" );
		$st           = Probclient_Settings::state();
		$st['cached'] = 0;
		Probclient_Settings::set_state( $st );
	}

	/**
	 * Push this site's active local entries to the server (writers only).
	 * Returns the number sent, or WP_Error.
	 *
	 * @return int|WP_Error
	 */
	public function push_all() {
		if ( ! Probclient_Settings::is_sync_enabled() ) {
			return new WP_Error( 'probclient_sync_off', __( 'Sync is disabled.', 'problem-client' ) );
		}
		if ( '' === Probclient_Settings::api_key() ) {
			return new WP_Error( 'probclient_no_key', __( 'An API key is required to write to the server.', 'problem-client' ) );
		}

		$local = Probclient_Blacklist::instance()->all( array( 'status' => 'active' ) );
		if ( empty( $local ) ) {
			return 0;
		}

		$payload = array();
		foreach ( $local as $e ) {
			$payload[] = array(
				'uuid'        => $e['uuid'],
				'phone'       => $e['phone_raw'],
				'name'        => $e['name_raw'],
				'reason'      => $e['reason_code'],
				'note'        => $e['note'],
				'source_site' => $e['source_site'],
				'status'      => $e['status'],
				'updated_at'  => ! empty( $e['updated_at'] ) ? $e['updated_at'] : $e['created_at'],
			);
		}

		$res = wp_remote_post(
			Probclient_Settings::server_url() . '/entries',
			array(
				'timeout' => 20,
				'headers' => array_merge( $this->headers(), array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( array( 'entries' => $payload ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'probclient_http', 'HTTP ' . $code . ' ' . wp_remote_retrieve_body( $res ) );
		}
		return count( $payload );
	}
}
