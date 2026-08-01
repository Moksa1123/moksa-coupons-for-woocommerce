<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Coupon\CouponService;
use PHPUnit\Framework\TestCase;

/**
 * Pure validation/normalization tests for CouponService — no WooCommerce needed.
 */
final class CouponServiceTest extends TestCase {

	public function test_valid_create_input_normalizes(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => '  SUMMER25 ',
				'discount_type' => 'percent',
				'amount'        => '25',
				'usage_limit'   => '100',
				'free_shipping' => 'yes',
			]
		);
		$this->assertIsArray( $out );
		$this->assertSame( 'SUMMER25', $out['code'] );
		$this->assertSame( 'percent', $out['discount_type'] );
		$this->assertSame( 25.0, $out['amount'] );
		$this->assertSame( 100, $out['usage_limit'] );
		$this->assertTrue( $out['free_shipping'] );
	}

	public function test_missing_code_on_create_is_error(): void {
		$out = CouponService::normalize_and_validate( [ 'amount' => 10 ], false );
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'moksafocou_invalid_code', $out->get_error_code() );
	}

	public function test_invalid_discount_type_is_error(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => 'X',
				'discount_type' => 'banana',
				'amount'        => 1,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'moksafocou_invalid_type', $out->get_error_code() );
	}

	public function test_percent_over_100_is_error(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => 'X',
				'discount_type' => 'percent',
				'amount'        => 150,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'moksafocou_invalid_amount', $out->get_error_code() );
	}

	public function test_negative_amount_is_error(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => 'X',
				'discount_type' => 'fixed_cart',
				'amount'        => -5,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_bad_expiry_is_error(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => 'X',
				'discount_type' => 'fixed_cart',
				'amount'        => 5,
				'date_expires'  => 'not-a-date',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'moksafocou_invalid_date', $out->get_error_code() );
	}

	public function test_expiry_normalized_to_ymd(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => 'X',
				'discount_type' => 'fixed_cart',
				'amount'        => 5,
				'date_expires'  => '2026-08-31',
			]
		);
		$this->assertIsArray( $out );
		$this->assertSame( '2026-08-31', $out['date_expires'] );
	}

	public function test_email_restrictions_filtered(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'               => 'X',
				'discount_type'      => 'fixed_cart',
				'amount'             => 5,
				'email_restrictions' => [ 'a@b.com', 'not-an-email', '*@gmail.com', 'A@B.COM' ],
			]
		);
		$this->assertIsArray( $out );
		$this->assertSame( [ 'a@b.com', '*@gmail.com' ], $out['email_restrictions'] );
	}

	public function test_id_lists_deduped_and_filtered(): void {
		$out = CouponService::normalize_and_validate(
			[
				'code'          => 'X',
				'discount_type' => 'fixed_cart',
				'amount'        => 5,
				'product_ids'   => [ '12', 12, 0, 'x', 34 ],
			]
		);
		$this->assertIsArray( $out );
		$this->assertSame( [ 12, 34 ], $out['product_ids'] );
	}

	public function test_build_summary_contains_code(): void {
		$summary = CouponService::build_summary(
			[
				'code'          => 'SUMMER25',
				'discount_type' => 'percent',
				'amount'        => 25.0,
			]
		);
		$this->assertStringContainsString( 'SUMMER25', $summary );
	}

	/**
	 * @dataProvider zhe_cases
	 */
	public function test_zhe_to_percent_hint( string $message, string $expectAmount ): void {
		$hint = CouponService::zhe_to_percent_hint( $message );
		$this->assertStringContainsString( 'is ' . $expectAmount, $hint );
	}

	/**
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function zhe_cases(): array {
		return [
			[ '建立一張 9 折券', '10' ],
			[ '打 85 折', '15' ],
			[ '79 折', '21' ],
			[ '95 折', '5' ],
			[ '8.5 折', '15' ],
			[ '全館 5 折', '50' ],
		];
	}

	public function test_zhe_hint_empty_when_no_phrase(): void {
		$this->assertSame( '', CouponService::zhe_to_percent_hint( '列出啟用中的優惠券' ) );
	}

	public function test_zhe_first_amount(): void {
		$this->assertSame( 10.0, CouponService::zhe_first_amount( '建立一張 9 折券' ) );
		$this->assertSame( 15.0, CouponService::zhe_first_amount( '打 85 折' ) );
		$this->assertNull( CouponService::zhe_first_amount( 'List coupons' ) );
		$this->assertNull( CouponService::zhe_first_amount( '折 100 元' ) );
	}

	public function test_rewrite_ai_args_enforces_zhe(): void {
		$out = \Moksafocou\Modules\CouponCore\Module::rewrite_ai_args(
			[
				'code'          => 'X',
				'discount_type' => 'percent',
				'amount'        => 1,
			],
			'moksafocou/create-coupon',
			'建立一張 9 折券 X'
		);
		$this->assertSame( 'percent', $out['discount_type'] );
		$this->assertSame( 10.0, $out['amount'] );
	}

	public function test_rewrite_ai_args_ignores_non_coupon_and_no_zhe(): void {
		$same = [ 'amount' => 5 ];
		$this->assertSame( $same, \Moksafocou\Modules\CouponCore\Module::rewrite_ai_args( $same, 'moksafocou/list-coupons', '9 折' ) );
		$this->assertSame( $same, \Moksafocou\Modules\CouponCore\Module::rewrite_ai_args( $same, 'moksafocou/create-coupon', '折 100 元' ) );
	}

	public function test_build_summary_amount_formatting(): void {
		// Regression: whole-number percents must not lose their trailing zero (10 ≠ 1).
		$this->assertStringContainsString(
			'10%',
			CouponService::build_summary(
				[
					'code'          => 'A',
					'discount_type' => 'percent',
					'amount'        => 10.0,
				]
			)
		);
		$this->assertStringContainsString(
			'100%',
			CouponService::build_summary(
				[
					'code'          => 'B',
					'discount_type' => 'percent',
					'amount'        => 100.0,
				]
			)
		);
		$this->assertStringContainsString(
			'12.5%',
			CouponService::build_summary(
				[
					'code'          => 'C',
					'discount_type' => 'percent',
					'amount'        => 12.5,
				]
			)
		);
		$this->assertStringContainsString(
			'20%',
			CouponService::build_summary(
				[
					'code'          => 'D',
					'discount_type' => 'percent',
					'amount'        => 20.0,
				]
			)
		);
	}
}
