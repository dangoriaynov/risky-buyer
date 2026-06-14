<?php
/**
 * Blacklist service — normalization, permissions, CRUD and matching.
 *
 * The rest of the plugin uses this service and never the provider directly,
 * so permission checks and normalization live in one place.
 *
 * @package RiskyBuyer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Riskybuyer_Blacklist {

	/** @var Riskybuyer_Storage_Provider */
	protected $provider;

	/** @var array|null Cached active index. */
	protected $idx = null;

	/** @var Riskybuyer_Blacklist|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$provider = new Riskybuyer_Local_Table_Provider();
		/**
		 * Swap the storage backend (e.g. a future central/remote provider).
		 *
		 * @param Riskybuyer_Storage_Provider $provider Default local provider.
		 */
		$this->provider = apply_filters( 'riskybuyer_storage_provider', $provider );
	}

	public function provider() {
		return $this->provider;
	}

	/* --------------------------------------------------------------------- */
	/* Reasons                                                               */
	/* --------------------------------------------------------------------- */

	public static function reasons() {
		return array(
			'uncollected' => array(
				'label' => __( 'Uncollected shipment', 'risky-buyer' ),
				'color' => '#e08a00',
			),
			'fake'        => array(
				'label' => __( 'Fake order', 'risky-buyer' ),
				'color' => '#d63638',
			),
			'abusive'     => array(
				'label' => __( 'Problematic / abusive', 'risky-buyer' ),
				'color' => '#9b1c1c',
			),
			'other'       => array(
				'label' => __( 'Other', 'risky-buyer' ),
				'color' => '#6b7280',
			),
		);
	}

	public static function reason_label( $code ) {
		$r = self::reasons();
		return isset( $r[ $code ] ) ? $r[ $code ]['label'] : $r['other']['label'];
	}

	public static function reason_color( $code ) {
		$r = self::reasons();
		return isset( $r[ $code ] ) ? $r[ $code ]['color'] : $r['other']['color'];
	}

	public static function valid_reason( $code ) {
		return array_key_exists( (string) $code, self::reasons() ) ? $code : 'other';
	}

	/* --------------------------------------------------------------------- */
	/* Normalization (pure — no WP deps — unit-testable)                     */
	/* --------------------------------------------------------------------- */

	public static function normalize_phone( $s ) {
		$digits = preg_replace( '/\D+/', '', (string) $s );
		if ( strlen( $digits ) < 9 ) {
			return '';
		}
		return substr( $digits, -9 );
	}

	public static function normalize_name( $s ) {
		$s = (string) $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		return ( mb_strlen( $s ) >= 3 ) ? $s : '';
	}

	/* --------------------------------------------------------------------- */
	/* Permissions                                                           */
	/* --------------------------------------------------------------------- */

	public function can_add() {
		return current_user_can( 'edit_shop_orders' );
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/* --------------------------------------------------------------------- */
	/* CRUD                                                                  */
	/* --------------------------------------------------------------------- */

	public function add_entry( $data ) {
		if ( ! $this->can_add() ) {
			return new WP_Error( 'riskybuyer_forbidden', __( 'You do not have permission to add.', 'risky-buyer' ) );
		}

		$phone_raw  = isset( $data['phone'] ) ? trim( (string) $data['phone'] ) : '';
		$name_raw   = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		$phone_norm = self::normalize_phone( $phone_raw );
		$name_norm  = self::normalize_name( $name_raw );

		if ( '' === $phone_norm && '' === $name_norm ) {
			return new WP_Error( 'riskybuyer_empty', __( 'A valid phone or name is required.', 'risky-buyer' ) );
		}

		$user  = wp_get_current_user();
		$entry = array(
			'uuid'            => wp_generate_uuid4(),
			'phone_norm'      => $phone_norm,
			'phone_raw'       => $phone_raw,
			'name_norm'       => $name_norm,
			'name_raw'        => $name_raw,
			'reason_code'     => self::valid_reason( isset( $data['reason'] ) ? $data['reason'] : 'other' ),
			'note'            => isset( $data['note'] ) ? wp_strip_all_tags( $data['note'] ) : '',
			'source_site'     => $this->provider->source_site(),
			'status'          => 'active',
			'created_by'      => $user ? (int) $user->ID : 0,
			'created_by_name' => $user ? $user->display_name : '',
			'created_at'      => current_time( 'mysql' ),
			'updated_by'      => 0,
			'updated_at'      => null,
		);

		$this->idx = null;
		return $this->provider->add( $entry );
	}

	public function update_entry( $uuid, $changes ) {
		if ( ! $this->can_manage() ) {
			return new WP_Error( 'riskybuyer_forbidden', __( 'Only an administrator can edit.', 'risky-buyer' ) );
		}

		$allowed = array();
		if ( isset( $changes['reason'] ) ) {
			$allowed['reason_code'] = self::valid_reason( $changes['reason'] );
		}
		if ( isset( $changes['note'] ) ) {
			$allowed['note'] = wp_strip_all_tags( $changes['note'] );
		}
		if ( isset( $changes['name'] ) ) {
			$allowed['name_raw']  = trim( (string) $changes['name'] );
			$allowed['name_norm'] = self::normalize_name( $changes['name'] );
		}
		if ( isset( $changes['phone'] ) ) {
			$allowed['phone_raw']  = trim( (string) $changes['phone'] );
			$allowed['phone_norm'] = self::normalize_phone( $changes['phone'] );
		}
		if ( empty( $allowed ) ) {
			return false;
		}

		$allowed['updated_by'] = get_current_user_id();
		$allowed['updated_at'] = current_time( 'mysql' );

		$this->idx = null;
		return $this->provider->update( $uuid, $allowed );
	}

	public function delete_entry( $uuid ) {
		if ( ! $this->can_manage() ) {
			return new WP_Error( 'riskybuyer_forbidden', __( 'Only an administrator can delete.', 'risky-buyer' ) );
		}
		$this->idx = null;
		return $this->provider->delete( $uuid );
	}

	public function all( $filters = array() ) {
		return $this->provider->all( $filters );
	}

	public function get( $uuid ) {
		return $this->provider->get( $uuid );
	}

	/* --------------------------------------------------------------------- */
	/* Matching                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Active entries used for matching: local always, plus the cached remote
	 * entries when sync is enabled. Deduplicated by uuid, newest first.
	 *
	 * @return array<int,array>
	 */
	public function active_for_match() {
		$local = $this->provider->all( array( 'status' => 'active' ) );

		if ( ! class_exists( 'Riskybuyer_Settings' ) || ! Riskybuyer_Settings::is_sync_enabled() ) {
			return $local;
		}
		$remote = Riskybuyer_Remote_Sync::instance()->cached_active();
		if ( empty( $remote ) ) {
			return $local;
		}

		$seen = array();
		$out  = array();
		foreach ( array_merge( $local, $remote ) as $e ) {
			$uuid = isset( $e['uuid'] ) ? $e['uuid'] : '';
			if ( '' !== $uuid ) {
				if ( isset( $seen[ $uuid ] ) ) {
					continue;
				}
				$seen[ $uuid ] = true;
			}
			$out[] = $e;
		}

		usort(
			$out,
			function ( $a, $b ) {
				$da = ! empty( $a['created_at'] ) ? $a['created_at'] : ( ! empty( $a['updated_at'] ) ? $a['updated_at'] : '1970-01-01' );
				$db = ! empty( $b['created_at'] ) ? $b['created_at'] : ( ! empty( $b['updated_at'] ) ? $b['updated_at'] : '1970-01-01' );
				return strtotime( $db ) <=> strtotime( $da );
			}
		);
		return $out;
	}

	/**
	 * Build an in-memory index of active entries for fast bulk matching.
	 *
	 * @return array{phones:array,names:array}
	 */
	public function index() {
		if ( null !== $this->idx ) {
			return $this->idx;
		}
		$phones = array();
		$names  = array();
		foreach ( $this->active_for_match() as $e ) {
			if ( ! empty( $e['phone_norm'] ) ) {
				$phones[ $e['phone_norm'] ] = $e;
			}
			if ( ! empty( $e['name_norm'] ) ) {
				$names[ $e['name_norm'] ] = $e;
			}
		}
		$this->idx = array(
			'phones' => $phones,
			'names'  => $names,
		);
		return $this->idx;
	}

	/**
	 * Match by normalized phone/name. Phone takes precedence over name.
	 *
	 * @param string $phone_norm Normalized phone.
	 * @param string $name_norm  Normalized name.
	 * @return array|null Matched entry or null.
	 */
	public function match( $phone_norm, $name_norm ) {
		$idx = $this->index();
		if ( '' !== $phone_norm && isset( $idx['phones'][ $phone_norm ] ) ) {
			return $idx['phones'][ $phone_norm ];
		}
		if ( '' !== $name_norm && isset( $idx['names'][ $name_norm ] ) ) {
			return $idx['names'][ $name_norm ];
		}
		return null;
	}

	/**
	 * Match a WooCommerce order against the blacklist.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null Matched entry or null.
	 */
	public function match_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$phone = self::normalize_phone( $order->get_billing_phone() );
		$name  = self::normalize_name( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
		if ( '' === $name ) {
			$name = self::normalize_name( trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) );
		}
		return $this->match( $phone, $name );
	}

	/**
	 * Whether an active entry already exists for this phone or name.
	 *
	 * @param string $phone_norm Normalized phone.
	 * @param string $name_norm  Normalized name.
	 * @return bool
	 */
	public function exists( $phone_norm, $name_norm ) {
		$idx = $this->index();
		if ( '' !== $phone_norm && isset( $idx['phones'][ $phone_norm ] ) ) {
			return true;
		}
		if ( '' !== $name_norm && isset( $idx['names'][ $name_norm ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Partial ("possible") matches for manual verification — substring on the
	 * last digits of the phone and on name tokens. Filtered in PHP over the
	 * (small, curated) active list.
	 *
	 * @param string $phone        Raw phone typed.
	 * @param string $name         Raw name typed.
	 * @param string $exclude_uuid Exact-match uuid to exclude.
	 * @return array<int,array>
	 */
	public function possible_matches( $phone, $name, $exclude_uuid = '', $op = 'AND' ) {
		// Phone needle: the typed digits (>= 3) matched as a substring of the stored phone.
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		$pneedle = strlen( $digits ) >= 3 ? $digits : '';

		// Name needle: normalized typed text (without the >= 3 min-length gate), plus tokens.
		$nname = mb_strtolower( (string) $name, 'UTF-8' );
		$nname = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $nname );
		$nname = trim( preg_replace( '/\s+/u', ' ', $nname ) );

		$words = array();
		if ( '' !== $nname ) {
			foreach ( explode( ' ', $nname ) as $w ) {
				if ( mb_strlen( $w ) >= 2 ) {
					$words[] = $w;
				}
			}
		}

		if ( '' === $pneedle && '' === $nname ) {
			return array();
		}
		$op = ( 'OR' === strtoupper( (string) $op ) ) ? 'OR' : 'AND';

		// active_for_match() is newest-first, so results stay newest-first.
		$out = array();
		foreach ( $this->active_for_match() as $e ) {
			if ( $exclude_uuid && isset( $e['uuid'] ) && $e['uuid'] === $exclude_uuid ) {
				continue;
			}

			$phone_hit = ( '' !== $pneedle && ! empty( $e['phone_norm'] ) && false !== strpos( $e['phone_norm'], $pneedle ) );
			$name_hit  = false;
			if ( '' !== $nname && ! empty( $e['name_norm'] ) ) {
				if ( false !== strpos( $e['name_norm'], $nname ) ) {
					$name_hit = true;
				} else {
					foreach ( $words as $w ) {
						if ( false !== strpos( $e['name_norm'], $w ) ) {
							$name_hit = true;
							break;
						}
					}
				}
			}

			if ( 'AND' === $op ) {
				// All provided criteria must match.
				$ok = true;
				if ( '' !== $pneedle && ! $phone_hit ) {
					$ok = false;
				}
				if ( '' !== $nname && ! $name_hit ) {
					$ok = false;
				}
			} else {
				$ok = ( $phone_hit || $name_hit );
			}

			if ( $ok ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * Bulk add: one client per line; fields split by , ; tab or |.
	 * A token with >=6 digits is treated as the phone, the rest as the name.
	 * Skips lines already in the list (by phone or name) and invalid lines.
	 *
	 * @param string $text   Raw textarea content.
	 * @param string $reason Reason applied to the whole batch.
	 * @param string $note   Note applied to the whole batch.
	 * @return array{added:int,skipped:int,invalid:int}|WP_Error
	 */
	public function bulk_add( $text, $reason, $note ) {
		if ( ! $this->can_add() ) {
			return new WP_Error( 'riskybuyer_forbidden', __( 'You do not have permission to add.', 'risky-buyer' ) );
		}
		$reason = self::valid_reason( $reason );
		$note   = wp_strip_all_tags( (string) $note );

		// Seed a local "seen" set once, then keep it up to date in-batch.
		$idx         = $this->index();
		$seen_phones = $idx['phones'];
		$seen_names  = $idx['names'];

		$added   = 0;
		$skipped = 0;
		$invalid = 0;

		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = preg_split( '/[,;\t|]+/', $line );
			$phone = '';
			$name  = array();
			foreach ( $parts as $p ) {
				$p = trim( $p );
				if ( '' === $p ) {
					continue;
				}
				$d = preg_replace( '/\D+/', '', $p );
				if ( '' === $phone && strlen( $d ) >= 6 ) {
					$phone = $p;
				} else {
					$name[] = $p;
				}
			}
			$name_str   = trim( implode( ' ', $name ) );
			$phone_norm = self::normalize_phone( $phone );
			$name_norm  = self::normalize_name( $name_str );

			if ( '' === $phone_norm && '' === $name_norm ) {
				++$invalid;
				continue;
			}
			if ( ( '' !== $phone_norm && isset( $seen_phones[ $phone_norm ] ) ) ||
				( '' !== $name_norm && isset( $seen_names[ $name_norm ] ) ) ) {
				++$skipped;
				continue;
			}

			$res = $this->add_entry(
				array(
					'name'   => $name_str,
					'phone'  => $phone,
					'reason' => $reason,
					'note'   => $note,
				)
			);
			if ( is_wp_error( $res ) ) {
				++$invalid;
			} else {
				++$added;
				if ( '' !== $phone_norm ) {
					$seen_phones[ $phone_norm ] = true;
				}
				if ( '' !== $name_norm ) {
					$seen_names[ $name_norm ] = true;
				}
			}
		}

		$this->idx = null;
		return array(
			'added'   => $added,
			'skipped' => $skipped,
			'invalid' => $invalid,
		);
	}
}
