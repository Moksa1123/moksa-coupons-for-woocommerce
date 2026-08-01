<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Support\Rules;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the advanced rule-builder engine — the AND/OR boolean logic a merchant
 * most needs to trust. Covers parse/validate, canonical JSON, group + rule combinators,
 * every operator family, and the payment-method deferral.
 */
final class RulesTest extends TestCase {

	/** @param array<int,array{type:string,op:string,value:mixed}> $rules */
	private function set( string $match, array $groups ): array {
		return Rules::parse(
			array(
				'match'  => $match,
				'groups' => $groups,
			)
		);
	}

	public function test_empty_ruleset_passes(): void {
		$this->assertTrue( Rules::evaluate( Rules::parse( '' ), array() ) );
		$this->assertTrue( Rules::evaluate( Rules::parse( array( 'groups' => array() ) ), array( 'subtotal' => 0 ) ) );
	}

	public function test_parse_drops_invalid_rules_and_groups(): void {
		$set = Rules::parse(
			array(
				'match'  => 'any',
				'groups' => array(
					array(
						'match' => 'all',
						'rules' => array(
							array(
								'type'  => 'subtotal',
								'op'    => 'gte',
								'value' => '1000',
							),
							array(
								'type'  => 'subtotal',
								'op'    => 'bogus',
								'value' => '1',
							), // bad op
							array(
								'type'  => 'nope',
								'op'    => 'gte',
								'value' => '1',
							),        // bad type
							array(
								'type'  => 'quantity',
								'op'    => 'gte',
								'value' => '',
							),     // empty value
						),
					),
					array(
						'match' => 'all',
						'rules' => array(),
					),                       // empty group
				),
			)
		);
		$this->assertSame( 'any', $set['match'] );
		$this->assertCount( 1, $set['groups'] );
		$this->assertCount( 1, $set['groups'][0]['rules'] );
		$this->assertSame( 'subtotal', $set['groups'][0]['rules'][0]['type'] );
	}

	public function test_canonical_json_round_trips_and_empties(): void {
		$json = Rules::canonical_json(
			array(
				'match'  => 'all',
				'groups' => array(
					array(
						'match' => 'any',
						'rules' => array(
							array(
								'type'  => 'subtotal',
								'op'    => 'gte',
								'value' => '500',
							),
						),
					),
				),
			)
		);
		$set  = Rules::parse( $json );
		$this->assertSame( 'all', $set['match'] );
		$this->assertSame( 'gte', $set['groups'][0]['rules'][0]['op'] );
		$this->assertSame( '', Rules::canonical_json( array( 'groups' => array() ) ) );
		$this->assertSame( '', Rules::canonical_json( 'garbage' ) );
	}

	public function test_numeric_operators(): void {
		$ctx = array( 'subtotal' => 1000.0 );
		$this->assertTrue(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'subtotal',
									'op'    => 'gte',
									'value' => '1000',
								),
							),
						),
					)
				),
				$ctx
			)
		);
		$this->assertFalse(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'subtotal',
									'op'    => 'gt',
									'value' => '1000',
								),
							),
						),
					)
				),
				$ctx
			)
		);
		$this->assertTrue(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'subtotal',
									'op'    => 'lte',
									'value' => '1000',
								),
							),
						),
					)
				),
				$ctx
			)
		);
		$this->assertTrue(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'subtotal',
									'op'    => 'eq',
									'value' => '1000',
								),
							),
						),
					)
				),
				$ctx
			)
		);
		$this->assertFalse(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'subtotal',
									'op'    => 'neq',
									'value' => '1000',
								),
							),
						),
					)
				),
				$ctx
			)
		);
	}

	public function test_group_and_top_level_and_or(): void {
		// group: subtotal>=1000 AND qty>=3
		$g   = array(
			'match' => 'all',
			'rules' => array(
				array(
					'type'  => 'subtotal',
					'op'    => 'gte',
					'value' => '1000',
				),
				array(
					'type'  => 'quantity',
					'op'    => 'gte',
					'value' => '3',
				),
			),
		);
		$set = $this->set( 'all', array( $g ) );
		$this->assertTrue(
			Rules::evaluate(
				$set,
				array(
					'subtotal' => 1200,
					'qty'      => 3,
				)
			)
		);
		$this->assertFalse(
			Rules::evaluate(
				$set,
				array(
					'subtotal' => 1200,
					'qty'      => 2,
				)
			)
		);

		// ANY of two groups: (role in wholesale) OR (subtotal>=2000)
		$two = $this->set(
			'any',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'user_role',
							'op'    => 'in',
							'value' => array( 'wholesale' ),
						),
					),
				),
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'subtotal',
							'op'    => 'gte',
							'value' => '2000',
						),
					),
				),
			)
		);
		$this->assertTrue(
			Rules::evaluate(
				$two,
				array(
					'roles'    => array( 'customer' ),
					'subtotal' => 2500,
				)
			),
			'second group passes'
		);
		$this->assertTrue(
			Rules::evaluate(
				$two,
				array(
					'roles'    => array( 'wholesale' ),
					'subtotal' => 10,
				)
			),
			'first group passes'
		);
		$this->assertFalse(
			Rules::evaluate(
				$two,
				array(
					'roles'    => array( 'customer' ),
					'subtotal' => 10,
				)
			),
			'neither passes'
		);
	}

	public function test_membership_and_set_rules(): void {
		$cart = array(
			'products'   => array( 5, 9 ),
			'categories' => array( 12 ),
			'country'    => 'TW',
			'roles'      => array( 'customer' ),
		);
		$this->assertTrue(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'product_in_cart',
									'op'    => 'in',
									'value' => array( 9, 99 ),
								),
							),
						),
					)
				),
				$cart
			)
		);
		$this->assertTrue(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'product_in_cart',
									'op'    => 'not_in',
									'value' => array( 100 ),
								),
							),
						),
					)
				),
				$cart
			)
		);
		$this->assertTrue(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'shipping_country',
									'op'    => 'in',
									'value' => array( 'TW', 'JP' ),
								),
							),
						),
					)
				),
				$cart
			)
		);
		$this->assertFalse(
			Rules::evaluate(
				$this->set(
					'all',
					array(
						array(
							'match' => 'all',
							'rules' => array(
								array(
									'type'  => 'shipping_country',
									'op'    => 'in',
									'value' => array( 'US' ),
								),
							),
						),
					)
				),
				$cart
			)
		);
	}

	public function test_payment_method_deferred_at_cart_enforced_at_checkout(): void {
		$set = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'payment_method',
							'op'    => 'in',
							'value' => array( 'bacs' ),
						),
					),
				),
			)
		);
		// cart time: payment unknown → defer → passes (don't block early).
		$this->assertTrue( Rules::evaluate( $set, array( 'payment' => null ), true ) );
		// checkout: cod chosen, not allowed → blocks.
		$this->assertFalse( Rules::evaluate( $set, array( 'payment' => 'cod' ), false ) );
		$this->assertTrue( Rules::evaluate( $set, array( 'payment' => 'bacs' ), false ) );
	}

	public function test_cart_weight_and_hours_rules(): void {
		$w = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'cart_weight',
							'op'    => 'gte',
							'value' => '5',
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $w, array( 'weight' => 6.0 ) ) );
		$this->assertFalse( Rules::evaluate( $w, array( 'weight' => 4.0 ) ) );

		// new customer: registered within 24h.
		$h = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'hours_since_registered',
							'op'    => 'lte',
							'value' => '24',
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $h, array( 'hours_since_registered' => 3.0 ) ) );
		$this->assertFalse( Rules::evaluate( $h, array( 'hours_since_registered' => 48.0 ) ) );
	}

	public function test_pair_rules_product_quantity_and_category_spent(): void {
		// product 5 quantity >= 3 in cart.
		$pq = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'product_quantity',
							'op'    => 'gte',
							'value' => array(
								'a' => 5,
								'b' => '3',
							),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $pq, array( 'product_qty' => array( 5 => 4 ) ) ) );
		$this->assertFalse( Rules::evaluate( $pq, array( 'product_qty' => array( 5 => 2 ) ) ) );
		$this->assertFalse( Rules::evaluate( $pq, array( 'product_qty' => array( 9 => 10 ) ) ), 'different product' );

		// spend on category 12 >= 1000.
		$cs = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'category_spent',
							'op'    => 'gte',
							'value' => array(
								'a' => 12,
								'b' => '1000',
							),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $cs, array( 'category_spent' => array( 12 => 1500.0 ) ) ) );
		$this->assertFalse( Rules::evaluate( $cs, array( 'category_spent' => array( 12 => 500.0 ) ) ) );

		// a pair rule missing its id is dropped by parse.
		$bad = Rules::parse(
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'  => 'product_quantity',
								'op'    => 'gte',
								'value' => array( 'b' => '3' ),
							),
						),
					),
				),
			)
		);
		$this->assertSame( array(), $bad['groups'] );
	}

	public function test_ordered_coupon_stock_zone_rules(): void {
		$op = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'ordered_product',
							'op'    => 'in',
							'value' => array( 5 ),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $op, array( 'ordered_products' => array( 5, 9 ) ) ) );
		$this->assertFalse( Rules::evaluate( $op, array( 'ordered_products' => array( 9 ) ) ) );

		$ca = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'coupon_applied',
							'op'    => 'in',
							'value' => array( 'vip' ),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $ca, array( 'applied_coupons' => array( 'vip', 'summer' ) ) ) );
		$this->assertFalse( Rules::evaluate( $ca, array( 'applied_coupons' => array( 'summer' ) ) ) );

		$ss = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'stock_status',
							'op'    => 'not_in',
							'value' => array( 'outofstock' ),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $ss, array( 'stock_statuses' => array( 'instock' ) ) ) );
		$this->assertFalse( Rules::evaluate( $ss, array( 'stock_statuses' => array( 'instock', 'outofstock' ) ) ) );

		$sz = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'shipping_zone',
							'op'    => 'in',
							'value' => array( 2 ),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $sz, array( 'shipping_zone' => '2' ) ) );
		$this->assertFalse( Rules::evaluate( $sz, array( 'shipping_zone' => '5' ) ) );
	}

	public function test_custom_taxonomy_user_meta_and_cart_item_meta(): void {
		// custom taxonomy: a cart product is in term 7 of taxonomy "brand".
		$tax = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'custom_taxonomy',
							'op'    => 'in',
							'value' => array(
								'tax'   => 'brand',
								'terms' => array( 7 ),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $tax, array( 'taxonomy_terms' => array( 'brand' => array( 7, 9 ) ) ) ) );
		$this->assertFalse( Rules::evaluate( $tax, array( 'taxonomy_terms' => array( 'brand' => array( 9 ) ) ) ) );

		// custom user meta: membership_level == gold.
		$um = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'custom_user_meta',
							'op'    => 'eq',
							'value' => array(
								'key'   => 'membership_level',
								'value' => 'gold',
							),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $um, array( 'user_meta' => array( 'membership_level' => 'gold' ) ) ) );
		$this->assertFalse( Rules::evaluate( $um, array( 'user_meta' => array( 'membership_level' => 'silver' ) ) ) );
		$neq = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'custom_user_meta',
							'op'    => 'neq',
							'value' => array(
								'key'   => 'membership_level',
								'value' => 'gold',
							),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $neq, array( 'user_meta' => array( 'membership_level' => 'silver' ) ) ) );

		// custom cart item meta: any item has engraving == yes.
		$cm = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'custom_cart_item_meta',
							'op'    => 'in',
							'value' => array(
								'key'   => 'engraving',
								'value' => 'yes',
							),
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $cm, array( 'cart_item_meta' => array( 'engraving' => array( 'yes' ) ) ) ) );
		$this->assertFalse( Rules::evaluate( $cm, array( 'cart_item_meta' => array( 'engraving' => array( 'no' ) ) ) ) );

		// parse drops a tax rule with no terms and a kv rule with no key.
		$bad = Rules::parse(
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'  => 'custom_taxonomy',
								'op'    => 'in',
								'value' => array( 'tax' => 'brand' ),
							),
							array(
								'type'  => 'custom_user_meta',
								'op'    => 'eq',
								'value' => array( 'value' => 'x' ),
							),
						),
					),
				),
			)
		);
		$this->assertSame( array(), $bad['groups'] );
	}

	public function test_value_refs_collects_taxonomies_and_meta_keys(): void {
		$set  = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'custom_taxonomy',
							'op'    => 'in',
							'value' => array(
								'tax'   => 'brand',
								'terms' => array( 7 ),
							),
						),
						array(
							'type'  => 'custom_user_meta',
							'op'    => 'eq',
							'value' => array(
								'key'   => 'vip',
								'value' => '1',
							),
						),
						array(
							'type'  => 'custom_cart_item_meta',
							'op'    => 'in',
							'value' => array(
								'key'   => 'gift',
								'value' => 'yes',
							),
						),
					),
				),
			)
		);
		$refs = Rules::value_refs( $set );
		$this->assertSame( array( 'brand' ), $refs['taxonomies'] );
		$this->assertSame( array( 'vip' ), $refs['user_meta'] );
		$this->assertSame( array( 'gift' ), $refs['cart_meta'] );
	}

	public function test_types_used_lists_every_type(): void {
		$set   = $this->set(
			'any',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'cart_weight',
							'op'    => 'gte',
							'value' => '5',
						),
					),
				),
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'ordered_product',
							'op'    => 'in',
							'value' => array( 5 ),
						),
					),
				),
			)
		);
		$types = Rules::types_used( $set );
		$this->assertContains( 'cart_weight', $types );
		$this->assertContains( 'ordered_product', $types );
	}

	public function test_time_and_date_rules(): void {
		// time_of_day >= 18:00
		$set = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'time_of_day',
							'op'    => 'gte',
							'value' => '18:00',
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $set, array( 'minutes' => 19 * 60 ) ) );
		$this->assertFalse( Rules::evaluate( $set, array( 'minutes' => 17 * 60 ) ) );

		// date >= 2026-07-01 (UTC in tests). now after → passes.
		$dset   = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'date',
							'op'    => 'gte',
							'value' => '2026-07-01 00:00',
						),
					),
				),
			)
		);
		$after  = (int) gmmktime( 0, 0, 0, 7, 2, 2026 );
		$before = (int) gmmktime( 0, 0, 0, 6, 1, 2026 );
		$this->assertTrue( Rules::evaluate( $dset, array( 'now' => $after ) ) );
		$this->assertFalse( Rules::evaluate( $dset, array( 'now' => $before ) ) );
	}

	public function test_types_registry_exposes_all_types_with_shapes(): void {
		$types = Rules::types();
		$this->assertCount( 26, $types );
		// Each entry carries kind + a non-empty op list + a value-shape hint.
		foreach ( $types as $type => $spec ) {
			$this->assertArrayHasKey( 'kind', $spec, $type );
			$this->assertArrayHasKey( 'ops', $spec, $type );
			$this->assertArrayHasKey( 'value_shape', $spec, $type );
			$this->assertNotEmpty( $spec['ops'], $type );
			$this->assertNotSame( '', $spec['value_shape'], $type );
		}
		// Spot-check a representative of each value-shape family.
		$this->assertSame( 'num', $types['subtotal']['kind'] );
		$this->assertSame( 'pair', $types['product_quantity']['kind'] );
		$this->assertSame( 'ids', $types['product_in_cart']['kind'] );
		$this->assertSame( 'codes', $types['payment_method']['kind'] );
		$this->assertSame( 'tax', $types['custom_taxonomy']['kind'] );
		$this->assertSame( 'kv', $types['custom_user_meta']['kind'] );
		$this->assertContains( 'not_in', $types['shipping_country']['ops'] );
	}

	public function test_first_n_customers_via_coupon_usage_count(): void {
		// "前 100 名顧客" = the coupon's global usage_count is still below 100.
		$set = $this->set(
			'all',
			array(
				array(
					'match' => 'all',
					'rules' => array(
						array(
							'type'  => 'coupon_usage_count',
							'op'    => 'lt',
							'value' => '100',
						),
					),
				),
			)
		);
		$this->assertTrue( Rules::evaluate( $set, array( 'coupon_usage_count' => 99 ) ), 'the 100th use still qualifies' );
		$this->assertFalse( Rules::evaluate( $set, array( 'coupon_usage_count' => 100 ) ), 'the 101st use is blocked' );
	}

	public function test_type_keys_matches_registry_and_drives_enum(): void {
		$keys = Rules::type_keys();
		$this->assertCount( 26, $keys );
		$this->assertSame( array_keys( Rules::types() ), $keys );
		// The exact keys an AI / REST consumer may send as a rule "type".
		$this->assertContains( 'cart_weight', $keys );
		$this->assertContains( 'custom_cart_item_meta', $keys );
		$this->assertNotContains( 'nope', $keys );
	}
}
