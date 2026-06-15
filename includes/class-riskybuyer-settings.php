<?php
/**
 * Plugin settings (sync option) + sync state.
 *
 * Sync is OFF by default (opt-in) — required for WordPress.org compliance:
 * the plugin must not contact an external server without explicit consent.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Settings {

	const OPTION = 'riskybuyer_settings';
	const STATE  = 'riskybuyer_sync_state';

	public static function defaults() {
		return array(
			'sync_enabled' => 0,
			'server_url'   => 'https://riskybuyer.com/v1',
			'api_key'      => '',
		);
	}

	public static function get() {
		$o = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $o ) ? $o : array(), self::defaults() );
	}

	public static function is_sync_enabled() {
		$s = self::get();
		return ! empty( $s['sync_enabled'] ) && '' !== trim( (string) $s['server_url'] );
	}

	public static function server_url() {
		return untrailingslashit( trim( (string) self::get()['server_url'] ) );
	}

	public static function api_key() {
		return trim( (string) self::get()['api_key'] );
	}

	/**
	 * Sanitize + save. Returns the saved settings.
	 *
	 * @param array $data Raw input (sync_enabled, server_url, api_key).
	 * @return array
	 */
	public static function update( $data ) {
		$clean = array(
			'sync_enabled' => empty( $data['sync_enabled'] ) ? 0 : 1,
			'server_url'   => isset( $data['server_url'] ) ? esc_url_raw( trim( (string) $data['server_url'] ) ) : '',
			'api_key'      => isset( $data['api_key'] ) ? sanitize_text_field( $data['api_key'] ) : '',
		);
		update_option( self::OPTION, $clean );
		return $clean;
	}

	public static function state() {
		$d = array(
			'last_sync'    => 0,
			'last_since'   => '',
			'cached'       => 0,
			'last_added'   => 0,
			'last_updated' => 0,
			'last_error'   => '',
		);
		$s = get_option( self::STATE, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), $d );
	}

	public static function set_state( $state ) {
		update_option( self::STATE, $state );
	}
}
