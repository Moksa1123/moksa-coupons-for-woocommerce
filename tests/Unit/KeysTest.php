<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Coupon\Meta\Keys;
use PHPUnit\Framework\TestCase;

/**
 * Guards the meta-key copy contract used by the duplicate-coupon ability: the
 * unique slug override must never be copied (two coupons can't own one /coupon/slug).
 */
final class KeysTest extends TestCase {

	public function test_all_includes_url_keys(): void {
		$all = Keys::all();
		$this->assertContains( Keys::URL_ENABLED, $all );
		$this->assertContains( Keys::URL_SLUG, $all );
		$this->assertContains( Keys::URL_REDIRECT, $all );
		$this->assertContains( Keys::SCHEDULE_ENABLED, $all );
	}

	public function test_copyable_excludes_unique_slug(): void {
		$copyable = Keys::copyable();
		$this->assertNotContains( Keys::URL_SLUG, $copyable, 'slug override must not be duplicated' );
		// Everything else from all() is copyable.
		$this->assertContains( Keys::URL_ENABLED, $copyable );
		$this->assertContains( Keys::ROLE_LIST, $copyable );
		$this->assertSame( count( Keys::all() ) - count( Keys::COPY_EXCLUDE ), count( $copyable ) );
	}

	public function test_keys_are_unique_and_prefixed(): void {
		$all = Keys::all();
		$this->assertSame( $all, array_values( array_unique( $all ) ), 'no duplicate keys' );
		foreach ( $all as $key ) {
			$this->assertStringStartsWith( '_moksafocou_', $key );
		}
	}
}
