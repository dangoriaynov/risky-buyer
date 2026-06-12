<?php
/**
 * Blacklist service — normalization, permissions, CRUD and matching.
 *
 * The rest of the plugin uses this service and never the provider directly,
 * so permission checks and normalization live in one place.
 *
 * @package ProblemClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PC_Blacklist {

	/** @var PC_Storage_Provider */
	protected $provider;

	/** @var array|null Cached active index. */
	protected $idx = null;

	/** @var PC_Blacklist|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$provider = new PC_Local_Table_Provider();
		/**
		 * Swap the storage backend (e.g. a future central/remote provider).
		 *
		 * @param PC_Storage_Provider $provider Default local provider.
		 */
		$this->provider = apply_filters( 'pc_storage_provider', $provider );
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
				'label' => 'Неизкупена пратка',
				'color' => '#e08a00',
			),
			'fake'        => array(
				'label' => 'Фалшива поръчка',
				'color' => '#d63638',
			),
			'abusive'     => array(
				'label' => 'Проблемен / обиди',
				'color' => '#9b1c1c',
			),
			'other'       => array(
				'label' => 'Друго',
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
			return new WP_Error( 'pc_forbidden', 'Нямате права да добавяте.' );
		}

		$phone_raw  = isset( $data['phone'] ) ? trim( (string) $data['phone'] ) : '';
		$name_raw   = isset( $data['name'] ) ? trim( (string) $data['name'] ) : '';
		$phone_norm = self::normalize_phone( $phone_raw );
		$name_norm  = self::normalize_name( $name_raw );

		if ( '' === $phone_norm && '' === $name_norm ) {
			return new WP_Error( 'pc_empty', 'Трябва валиден телефон или име.' );
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
			return new WP_Error( 'pc_forbidden', 'Само администратор може да редактира.' );
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
			return new WP_Error( 'pc_forbidden', 'Само администратор може да трие.' );
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
		foreach ( $this->provider->all( array( 'status' => 'active' ) ) as $e ) {
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
}
