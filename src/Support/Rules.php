<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure engine for the advanced rule builder — an Advanced-Coupons-style free-form AND/OR
 * condition tree. A rule set is:
 *
 *   { match: all|any, groups: [ { match: all|any, rules: [ { type, op, value } ] } ] }
 *
 * The top-level `match` combines GROUPS; each group's `match` combines its RULES. evaluate()
 * takes a context (subtotal, qty, cart products/categories, country, payment, roles, history,
 * weekday, minutes, now) assembled from WooCommerce and returns whether the coupon passes.
 *
 * No WordPress/WooCommerce dependency (SiteTime is a pure sibling helper), so the whole
 * boolean engine is unit-tested — the part a merchant most needs to trust.
 *
 * Empty rule set / empty group = no constraint (passes). An unknown type or operator that
 * slips past parse() is treated leniently (passes) so a malformed rule never silently blocks
 * a legitimate coupon.
 */
final class Rules {

	/**
	 * Shared float-comparison tolerance for the whole plugin's money / threshold maths.
	 * Cart totals are summed from many line items, so a cart that "should" equal a threshold
	 * can land a hair below it; this is the single source of truth used by both the rule
	 * engine (eq/neq) and the tier selector ({@see Tiers::active_percent}).
	 */
	public const EPSILON = 0.000001;

	/**
	 * type => [ kind, ops ]. kind drives value coercion + comparison; ops is the allow-list.
	 *   num    : numeric compare (ctx scalar vs value).      ops gte/lte/gt/lt/eq/neq
	 *   ids    : cart-list membership (value ids vs ctx ids). ops in/not_in
	 *   codes  : scalar-in-set OR list membership (string).  ops in/not_in
	 *   roles  : list membership (value vs ctx list).         ops in/not_in
	 *   pair   : { a:id, b:threshold } — numeric compare of a ctx map[ a ] against b. ops gte/lte/gt/lt/eq/neq
	 *   tax    : { tax:slug, terms:ids } — any term present among the cart's terms. ops in/not_in
	 *   kv     : { key, value } — user-meta exact (eq/neq) or cart-item-meta membership (in/not_in).
	 *   time   : minutes-of-day compare.                      ops gte/lte
	 *   date   : site-tz datetime compare.                    ops gte/lte
	 *
	 * @var array<string,array{0:string,1:array<int,string>}>
	 */
	private const TYPES = array(
		'subtotal'               => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'quantity'               => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'cart_weight'            => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'order_count'            => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'coupon_usage_count'     => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'total_spent'            => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'hours_since_registered' => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'hours_since_last_order' => array( 'num', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'product_quantity'       => array( 'pair', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'category_spent'         => array( 'pair', array( 'gte', 'lte', 'gt', 'lt', 'eq', 'neq' ) ),
		'product_in_cart'        => array( 'ids', array( 'in', 'not_in' ) ),
		'category_in_cart'       => array( 'ids', array( 'in', 'not_in' ) ),
		'ordered_product'        => array( 'ids', array( 'in', 'not_in' ) ),
		'ordered_category'       => array( 'ids', array( 'in', 'not_in' ) ),
		'shipping_zone'          => array( 'ids', array( 'in', 'not_in' ) ),
		'shipping_country'       => array( 'codes', array( 'in', 'not_in' ) ),
		'payment_method'         => array( 'codes', array( 'in', 'not_in' ) ),
		'coupon_applied'         => array( 'codes', array( 'in', 'not_in' ) ),
		'stock_status'           => array( 'codes', array( 'in', 'not_in' ) ),
		'custom_taxonomy'        => array( 'tax', array( 'in', 'not_in' ) ),
		'custom_user_meta'       => array( 'kv', array( 'eq', 'neq' ) ),
		'custom_cart_item_meta'  => array( 'kv', array( 'in', 'not_in' ) ),
		'weekday'                => array( 'codes', array( 'in', 'not_in' ) ),
		'user_role'              => array( 'roles', array( 'in', 'not_in' ) ),
		'time_of_day'            => array( 'time', array( 'gte', 'lte' ) ),
		'date'                   => array( 'date', array( 'gte', 'lte' ) ),
	);

	/** Value-shape hint per kind, for schema docs + the list-rule-types discovery ability. */
	private const KIND_VALUE = array(
		'num'   => 'string (numeric, e.g. "1000")',
		'pair'  => 'object { a: int id, b: string numeric threshold }',
		'ids'   => 'array of integer ids',
		'codes' => 'array of strings',
		'roles' => 'array of role slugs',
		'tax'   => 'object { tax: taxonomy slug, terms: array of term ids }',
		'kv'    => 'object { key: string, value: string }',
		'time'  => 'string "HH:MM"',
		'date'  => 'string "Y-m-d H:i" (site timezone)',
	);

	/**
	 * The full rule-type registry: type => { kind, ops, value_shape }. Public so the REST /
	 * ability schema and the list-rule-types discovery tool share ONE source of truth.
	 *
	 * @return array<string,array{kind:string,ops:array<int,string>,value_shape:string}>
	 */
	public static function types(): array {
		$out = array();
		foreach ( self::TYPES as $type => $spec ) {
			$out[ $type ] = array(
				'kind'        => $spec[0],
				'ops'         => $spec[1],
				'value_shape' => self::KIND_VALUE[ $spec[0] ] ?? 'string',
			);
		}
		return $out;
	}

	/** @return array<int,string> Every valid rule-type key. */
	public static function type_keys(): array {
		return array_keys( self::TYPES );
	}

	/* ---------------- parse / canonicalise ---------------- */

	/**
	 * Coerce raw input (JSON string or array) into a clean rule set. Invalid rules / empty
	 * groups are dropped.
	 *
	 * @param mixed $raw
	 * @return array{match:string,groups:array<int,array{match:string,rules:array<int,array{type:string,op:string,value:mixed}>}>}
	 */
	public static function parse( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array(
				'match'  => 'all',
				'groups' => array(),
			);
		}

		$groups = array();
		foreach ( ( $raw['groups'] ?? array() ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$rules = array();
			foreach ( ( $group['rules'] ?? array() ) as $rule ) {
				$clean = is_array( $rule ) ? self::parse_rule( $rule ) : null;
				if ( null !== $clean ) {
					$rules[] = $clean;
				}
			}
			if ( array() !== $rules ) {
				$groups[] = array(
					'match' => self::match( $group['match'] ?? 'all' ),
					'rules' => $rules,
				);
			}
		}

		return array(
			'match'  => self::match( $raw['match'] ?? 'all' ),
			'groups' => $groups,
		);
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array{type:string,op:string,value:mixed}|null
	 */
	private static function parse_rule( array $rule ): ?array {
		$type = isset( $rule['type'] ) ? (string) $rule['type'] : '';
		$op   = isset( $rule['op'] ) ? (string) $rule['op'] : '';
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return null;
		}
		list( $kind, $ops ) = self::TYPES[ $type ];
		if ( ! in_array( $op, $ops, true ) ) {
			return null;
		}
		$value = self::clean_value( $kind, $rule['value'] ?? '' );
		if ( null === $value ) {
			return null;
		}
		return array(
			'type'  => $type,
			'op'    => $op,
			'value' => $value,
		);
	}

	/**
	 * @param string $kind  Value kind (ids|codes|roles|date|time|num).
	 * @param mixed  $value  Raw value.
	 * @return mixed Cleaned value, or null when empty / invalid (→ rule dropped).
	 */
	private static function clean_value( string $kind, $value ) {
		switch ( $kind ) {
			case 'ids':
				$ids = array();
				foreach ( (array) $value as $v ) {
					$id = (int) $v;
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
				return array() === $ids ? null : $ids;
			case 'codes':
			case 'roles':
				$out = array();
				foreach ( (array) $value as $v ) {
					$s = trim( (string) $v );
					if ( '' !== $s && ! in_array( $s, $out, true ) ) {
						$out[] = $s;
					}
				}
				return array() === $out ? null : $out;
			case 'pair':
				$arr = is_array( $value ) ? $value : array();
				$a   = (int) ( $arr['a'] ?? ( $arr[0] ?? 0 ) );
				$b   = trim( (string) ( $arr['b'] ?? ( $arr[1] ?? '' ) ) );
				return ( $a > 0 && '' !== $b && is_numeric( $b ) )
					? array(
						'a' => $a,
						'b' => $b,
					)
					: null;
			case 'tax':
				// The named keys may arrive index-coerced ([0]=tax, [1]=terms) via the REST schema.
				$arr   = is_array( $value ) ? $value : array();
				$tax   = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $arr['tax'] ?? ( $arr[0] ?? '' ) ) ) ?? '' );
				$terms = array();
				foreach ( (array) ( $arr['terms'] ?? ( $arr[1] ?? array() ) ) as $t ) {
					$id = (int) $t;
					if ( $id > 0 && ! in_array( $id, $terms, true ) ) {
						$terms[] = $id;
					}
				}
				return ( '' !== $tax && array() !== $terms )
					? array(
						'tax'   => $tax,
						'terms' => $terms,
					)
					: null;
			case 'kv':
				$arr = is_array( $value ) ? $value : array();
				$key = trim( (string) ( $arr['key'] ?? ( $arr[0] ?? '' ) ) );
				return ( '' !== $key )
					? array(
						'key'   => $key,
						'value' => (string) ( $arr['value'] ?? ( $arr[1] ?? '' ) ),
					)
					: null;
			case 'date':
				$norm = SiteTime::normalize( (string) ( is_array( $value ) ? '' : $value ) );
				return '' === $norm ? null : $norm;
			case 'time':
				$s = trim( (string) ( is_array( $value ) ? '' : $value ) );
				return preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $s ) ? $s : null;
			default: // num
				$s = trim( (string) ( is_array( $value ) ? '' : $value ) );
				return ( '' === $s || ! is_numeric( $s ) ) ? null : $s;
		}
	}

	private static function match( $value ): string {
		return 'any' === $value ? 'any' : 'all';
	}

	/**
	 * Canonical JSON for storage; '' when there are no usable groups (clears the meta).
	 *
	 * @param mixed $raw
	 */
	public static function canonical_json( $raw ): string {
		$set = self::parse( $raw );
		if ( array() === $set['groups'] ) {
			return '';
		}
		$encoded = wp_json_encode( $set );
		return is_string( $encoded ) ? $encoded : '';
	}

	/* ---------------- evaluate ---------------- */

	/**
	 * Evaluate a (parsed) rule set against a context. An empty rule set passes.
	 *
	 * @param array{match:string,groups:array<int,mixed>} $ruleset
	 * @param array<string,mixed>                         $ctx
	 * @param bool                                        $defer_payment When true (cart-apply
	 *        time), payment_method rules pass (the gateway is only known at checkout).
	 */
	public static function evaluate( array $ruleset, array $ctx, bool $defer_payment = false ): bool {
		$groups = $ruleset['groups'] ?? array();
		if ( array() === $groups ) {
			return true;
		}
		$group_results = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$rule_results = array();
			foreach ( ( $group['rules'] ?? array() ) as $rule ) {
				if ( is_array( $rule ) ) {
					$rule_results[] = self::eval_rule( $rule, $ctx, $defer_payment );
				}
			}
			$group_results[] = self::combine( self::match( $group['match'] ?? 'all' ), $rule_results );
		}
		return self::combine( self::match( $ruleset['match'] ?? 'all' ), $group_results );
	}

	/**
	 * @param string          $mode    'all' (AND) | 'any' (OR).
	 * @param array<int,bool> $results Child results.
	 */
	private static function combine( string $mode, array $results ): bool {
		if ( array() === $results ) {
			return true;
		}
		return 'any' === $mode
			? in_array( true, $results, true )
			: ! in_array( false, $results, true );
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $ctx
	 */
	private static function eval_rule( array $rule, array $ctx, bool $defer_payment ): bool {
		$type  = (string) ( $rule['type'] ?? '' );
		$op    = (string) ( $rule['op'] ?? '' );
		$value = $rule['value'] ?? '';

		switch ( $type ) {
			case 'subtotal':
				return self::num( $op, (float) ( $ctx['subtotal'] ?? 0 ), (float) $value );
			case 'quantity':
				return self::num( $op, (float) ( $ctx['qty'] ?? 0 ), (float) $value );
			case 'cart_weight':
				return self::num( $op, (float) ( $ctx['weight'] ?? 0 ), (float) $value );
			case 'order_count':
				return self::num( $op, (float) ( $ctx['order_count'] ?? 0 ), (float) $value );
			case 'coupon_usage_count':
				return self::num( $op, (float) ( $ctx['coupon_usage_count'] ?? 0 ), (float) $value );
			case 'total_spent':
				return self::num( $op, (float) ( $ctx['total_spent'] ?? 0 ), (float) $value );
			case 'hours_since_registered':
				return self::num( $op, (float) ( $ctx['hours_since_registered'] ?? -1 ), (float) $value );
			case 'hours_since_last_order':
				return self::num( $op, (float) ( $ctx['hours_since_last_order'] ?? -1 ), (float) $value );
			case 'product_quantity':
				return self::pair( $op, $value, (array) ( $ctx['product_qty'] ?? array() ) );
			case 'category_spent':
				return self::pair( $op, $value, (array) ( $ctx['category_spent'] ?? array() ) );
			case 'product_in_cart':
				return self::in_list( $op, (array) $value, (array) ( $ctx['products'] ?? array() ) );
			case 'category_in_cart':
				return self::in_list( $op, (array) $value, (array) ( $ctx['categories'] ?? array() ) );
			case 'ordered_product':
				return self::in_list( $op, (array) $value, (array) ( $ctx['ordered_products'] ?? array() ) );
			case 'ordered_category':
				return self::in_list( $op, (array) $value, (array) ( $ctx['ordered_categories'] ?? array() ) );
			case 'coupon_applied':
				return self::in_list( $op, (array) $value, (array) ( $ctx['applied_coupons'] ?? array() ) );
			case 'stock_status':
				return self::in_list( $op, (array) $value, (array) ( $ctx['stock_statuses'] ?? array() ) );
			case 'custom_taxonomy':
				$tax   = is_array( $value ) ? (string) ( $value['tax'] ?? '' ) : '';
				$terms = is_array( $value ) ? (array) ( $value['terms'] ?? array() ) : array();
				$cart  = (array) ( ( $ctx['taxonomy_terms'] ?? array() )[ $tax ] ?? array() );
				return self::in_list( $op, $terms, $cart );
			case 'custom_user_meta':
				$key    = is_array( $value ) ? (string) ( $value['key'] ?? '' ) : '';
				$want   = is_array( $value ) ? (string) ( $value['value'] ?? '' ) : '';
				$actual = (string) ( ( $ctx['user_meta'] ?? array() )[ $key ] ?? '' );
				return 'neq' === $op ? ( $actual !== $want ) : ( $actual === $want );
			case 'custom_cart_item_meta':
				$key  = is_array( $value ) ? (string) ( $value['key'] ?? '' ) : '';
				$want = is_array( $value ) ? (string) ( $value['value'] ?? '' ) : '';
				$vals = (array) ( ( $ctx['cart_item_meta'] ?? array() )[ $key ] ?? array() );
				return self::in_list( $op, array( $want ), $vals );
			case 'user_role':
				return self::in_list( $op, (array) $value, (array) ( $ctx['roles'] ?? array() ) );
			case 'shipping_zone':
				return self::in_set( $op, (array) $value, (string) ( $ctx['shipping_zone'] ?? '' ) );
			case 'shipping_country':
				return self::in_set( $op, (array) $value, (string) ( $ctx['country'] ?? '' ) );
			case 'weekday':
				return self::in_set( $op, (array) $value, (string) ( $ctx['weekday'] ?? '' ) );
			case 'payment_method':
				if ( $defer_payment && null === ( $ctx['payment'] ?? null ) ) {
					return true; // Unknown at cart time — enforced again at checkout.
				}
				return self::in_set( $op, (array) $value, (string) ( $ctx['payment'] ?? '' ) );
			case 'time_of_day':
				return self::num( $op, (float) ( $ctx['minutes'] ?? -1 ), (float) self::hhmm( (string) $value ) );
			case 'date':
				$ts = SiteTime::to_timestamp( (string) $value );
				return null === $ts ? true : self::num( $op, (float) ( $ctx['now'] ?? 0 ), (float) $ts );
			default:
				return true;
		}
	}

	private static function num( string $op, float $a, float $b ): bool {
		switch ( $op ) {
			case 'gte':
				return $a >= $b;
			case 'lte':
				return $a <= $b;
			case 'gt':
				return $a > $b;
			case 'lt':
				return $a < $b;
			case 'eq':
				return abs( $a - $b ) < self::EPSILON;
			case 'neq':
				return abs( $a - $b ) >= self::EPSILON;
			default:
				return true;
		}
	}

	/**
	 * Pair rule: numeric-compare a context map's entry for id `a` against threshold `b`.
	 * (e.g. product_quantity: cart qty of product a vs b; category_spent: spend on cat a vs b.)
	 *
	 * @param string              $op    Numeric operator.
	 * @param mixed               $value { a:id, b:threshold } (already validated by parse()).
	 * @param array<int|string,mixed> $map Context map id => amount.
	 */
	private static function pair( string $op, $value, array $map ): bool {
		if ( ! is_array( $value ) || ! isset( $value['a'], $value['b'] ) ) {
			return true;
		}
		$id     = (int) $value['a'];
		$actual = (float) ( $map[ $id ] ?? 0 );
		return self::num( $op, $actual, (float) $value['b'] );
	}

	/**
	 * Flat list of every rule type used in a (parsed) rule set — lets the runtime compute
	 * expensive context (purchase history, weight, zone) only when a rule needs it.
	 *
	 * @param array{groups:array<int,mixed>} $ruleset
	 * @return array<int,string>
	 */
	public static function types_used( array $ruleset ): array {
		$types = array();
		foreach ( ( $ruleset['groups'] ?? array() ) as $group ) {
			foreach ( ( is_array( $group ) ? ( $group['rules'] ?? array() ) : array() ) as $rule ) {
				if ( is_array( $rule ) && isset( $rule['type'] ) && ! in_array( $rule['type'], $types, true ) ) {
					$types[] = (string) $rule['type'];
				}
			}
		}
		return $types;
	}

	/**
	 * The taxonomy slugs and meta keys referenced by a (parsed) rule set, so the runtime can
	 * resolve only the custom taxonomies / user-meta / cart-item-meta a rule actually names.
	 *
	 * @param array{groups:array<int,mixed>} $ruleset
	 * @return array{taxonomies:array<int,string>,user_meta:array<int,string>,cart_meta:array<int,string>}
	 */
	public static function value_refs( array $ruleset ): array {
		$tax = array();
		$um  = array();
		$cm  = array();
		foreach ( ( $ruleset['groups'] ?? array() ) as $group ) {
			foreach ( ( is_array( $group ) ? ( $group['rules'] ?? array() ) : array() ) as $rule ) {
				if ( ! is_array( $rule ) || ! is_array( $rule['value'] ?? null ) ) {
					continue;
				}
				$type = (string) ( $rule['type'] ?? '' );
				if ( 'custom_taxonomy' === $type && ! empty( $rule['value']['tax'] ) ) {
					$tax[] = (string) $rule['value']['tax'];
				} elseif ( 'custom_user_meta' === $type && ! empty( $rule['value']['key'] ) ) {
					$um[] = (string) $rule['value']['key'];
				} elseif ( 'custom_cart_item_meta' === $type && ! empty( $rule['value']['key'] ) ) {
					$cm[] = (string) $rule['value']['key'];
				}
			}
		}
		return array(
			'taxonomies' => array_values( array_unique( $tax ) ),
			'user_meta'  => array_values( array_unique( $um ) ),
			'cart_meta'  => array_values( array_unique( $cm ) ),
		);
	}

	/**
	 * Scalar-in-set: ctx scalar present in the value set. 'not_in' negates.
	 *
	 * @param string           $op  'in' | 'not_in'.
	 * @param array<int,mixed> $set Configured value set.
	 * @param string           $val Context scalar.
	 */
	private static function in_set( string $op, array $set, string $val ): bool {
		$hit = in_array( $val, array_map( 'strval', $set ), true );
		return 'not_in' === $op ? ! $hit : $hit;
	}

	/**
	 * List membership: any of the needles present in the haystack. 'not_in' negates.
	 *
	 * @param string           $op       'in' | 'not_in'.
	 * @param array<int,mixed> $needles  Configured values.
	 * @param array<int,mixed> $haystack Context list.
	 */
	private static function in_list( string $op, array $needles, array $haystack ): bool {
		$hit = array() !== array_intersect( array_map( 'strval', $needles ), array_map( 'strval', $haystack ) );
		return 'not_in' === $op ? ! $hit : $hit;
	}

	/** 'HH:MM' → minutes-of-day, or -1 when invalid (so it never spuriously matches). */
	private static function hhmm( string $value ): int {
		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $value ), $m )
			? ( (int) $m[1] * 60 ) + (int) $m[2]
			: -1;
	}
}
