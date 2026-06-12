<?php
/**
 * Storage provider contract.
 *
 * The whole plugin talks to the blacklist through this interface only, so the
 * backing store can be swapped without touching the rest of the code:
 *   - PC_Local_Table_Provider  — current (a custom DB table on this site).
 *   - (future) PC_Remote_Api_Provider — a shared central service where many
 *     sites contribute to and read the same list. See docs/PLAN.md for the
 *     planned REST contract, auth and conflict-resolution (central = source of
 *     truth; local cache + pull; add = push; edit/delete gated by ownership).
 *
 * An "entry" is an associative array with the keys:
 *   uuid, phone_norm, phone_raw, name_norm, name_raw, reason_code, note,
 *   source_site, status, created_by, created_by_name, created_at,
 *   updated_by, updated_at.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PC_Storage_Provider {

	/**
	 * Return entries. $filters: status ('active'|'removed'|'all'), reason, search.
	 *
	 * @param array $filters Optional filters.
	 * @return array<int,array> List of entry arrays.
	 */
	public function all( array $filters = array() );

	/**
	 * Active entries whose phone_norm is in the given list.
	 *
	 * @param array $phones Normalized phones.
	 * @return array<int,array>
	 */
	public function find_by_phones( array $phones );

	/**
	 * Active entries whose name_norm is in the given list.
	 *
	 * @param array $names Normalized names.
	 * @return array<int,array>
	 */
	public function find_by_names( array $names );

	/**
	 * Fetch one entry by uuid (or null).
	 *
	 * @param string $uuid Entry uuid.
	 * @return array|null
	 */
	public function get( $uuid );

	/**
	 * Persist a new entry. Returns the stored entry (with id) or WP_Error.
	 *
	 * @param array $entry Full entry array.
	 * @return array|WP_Error
	 */
	public function add( array $entry );

	/**
	 * Apply changes to an entry.
	 *
	 * @param string $uuid    Entry uuid.
	 * @param array  $changes Column => value.
	 * @return bool
	 */
	public function update( $uuid, array $changes );

	/**
	 * Remove an entry (soft delete — status=removed — to keep sync history).
	 *
	 * @param string $uuid Entry uuid.
	 * @return bool
	 */
	public function delete( $uuid );

	/**
	 * Identifier of the site that owns locally-created entries (e.g. domain).
	 *
	 * @return string
	 */
	public function source_site();
}
