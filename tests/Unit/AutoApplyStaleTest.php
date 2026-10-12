<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\AutoApply\AutoApplyMeta;
use PHPUnit\Framework\TestCase;

/**
 * Pins AutoApplyMeta::stale() — which cart coupons auto-apply takes back out. Only codes the
 * plugin itself added, and only when they can no longer apply.
 */
final class AutoApplyStaleTest extends TestCase {

	public function test_removes_our_invalid_code(): void {
		$valid = static fn( string $c ): bool => 'gone' !== $c;
		$this->assertSame( array( 'gone' ), AutoApplyMeta::stale( array( 'gone', 'auto' ), array( 'gone', 'auto' ), $valid ) );
	}

	public function test_never_touches_a_code_the_customer_typed(): void {
		$never = static fn( string $c ): bool => false;
		$this->assertSame( array(), AutoApplyMeta::stale( array( 'typed' ), array( 'auto' ), $never ) );
	}

	public function test_keeps_our_code_while_still_valid(): void {
		$always = static fn( string $c ): bool => true;
		$this->assertSame( array(), AutoApplyMeta::stale( array( 'auto' ), array( 'auto' ), $always ) );
	}

	public function test_ignores_tracked_code_no_longer_in_cart(): void {
		$never = static fn( string $c ): bool => false;
		$this->assertSame( array(), AutoApplyMeta::stale( array(), array( 'auto' ), $never ) );
	}

	public function test_keeps_cart_order(): void {
		$never = static fn( string $c ): bool => false;
		$this->assertSame( array( 'b', 'a' ), AutoApplyMeta::stale( array( 'b', 'x', 'a' ), array( 'a', 'b' ), $never ) );
	}
}
