<?php
/**
 * Coupon lookup index (private taxonomy): term naming, and the option registry used by uninstall.
 *
 * @package Moksafocou\Tests
 */

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Coupon\Meta\Keys;
use Moksafocou\Modules\MyAccount\CouponQuery;
use Moksafocou\Support\CouponIndex;
use Moksafocou\Support\OptionRegistry;
use PHPUnit\Framework\TestCase;

final class CouponIndexTest extends TestCase {

	public function test_terms_from_meta(): void {
		$names = CouponIndex::names_from_meta(
			array(
				Keys::AUTO_APPLY         => 'yes',
				Keys::URL_SLUG           => 'Summer',
				Keys::SHOW_IN_LIST       => 'yes',
				Keys::CAMPAIGN           => 'Anniversary',
				CouponIndex::OWNER_META  => '12',
				'customer_email'         => array( 'Amy@Example.com' ),
				'discount_type'          => 'percent',
			)
		);
		$this->assertContains( 'auto_apply:1', $names );
		$this->assertContains( 'url_slug:summer', $names, 'slug is lower-cased' );
		$this->assertContains( 'list_visible:1', $names );
		$this->assertContains( 'campaign:1', $names );
		$this->assertContains( 'owner:12', $names );
		$this->assertContains( 'personal:1', $names );
		$this->assertContains( 'discount_type:percent', $names );
		$this->assertContains( CouponIndex::email_term( 'amy@example.com' ), $names );
	}

	public function test_empty_or_off_values_make_no_terms(): void {
		$names = CouponIndex::names_from_meta(
			array(
				Keys::AUTO_APPLY        => 'no',
				Keys::URL_SLUG          => '  ',
				Keys::SHOW_IN_LIST      => '',
				Keys::CAMPAIGN          => ' ',
				CouponIndex::OWNER_META => '0',
				'customer_email'        => array(),
				'discount_type'         => '',
			)
		);
		$this->assertSame( array(), $names );
	}

	public function test_email_is_stored_as_a_hash_only(): void {
		$term = CouponIndex::email_term( ' Amy@Example.com ' );
		$this->assertSame( CouponIndex::email_term( 'amy@example.com' ), $term, 'case and spaces do not matter' );
		$this->assertStringNotContainsString( 'amy', $term );
		$this->assertStringNotContainsString( 'example', $term );
		$this->assertSame( '', CouponIndex::email_term( '' ) );
	}

	public function test_names_fit_the_terms_column(): void {
		$this->assertSame( 190, mb_strlen( CouponIndex::name( 'url_slug', str_repeat( 'a', 300 ) ) ) );
	}

	public function test_owner_meta_matches_my_account(): void {
		$this->assertSame( CouponQuery::OWNER_META, CouponIndex::OWNER_META );
	}

	public function test_option_registry_records_only_our_options(): void {
		$this->assertTrue( OptionRegistry::ours( 'moksafocou_mcp_server_enabled' ) );
		$this->assertTrue( OptionRegistry::ours( 'moforcoupon_legacy_flag' ), 'pre-rename prefix' );
		$this->assertFalse( OptionRegistry::ours( 'moksafocou_option_registry' ), 'not the registry itself' );
		$this->assertFalse( OptionRegistry::ours( '_transient_moksafocou_cache' ) );
		$this->assertFalse( OptionRegistry::ours( 'woocommerce_currency' ) );
		$this->assertContains( 'moksafocou_coupon_ix_ready', OptionRegistry::known_unautoloaded() );
	}
}
