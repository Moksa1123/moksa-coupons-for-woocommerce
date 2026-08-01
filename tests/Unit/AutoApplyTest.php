<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\AutoApply\AutoApplyMeta;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the auto-apply eligibility rule (no WooCommerce). A coupon may only
 * auto-apply when published and free of usage limits / email restrictions.
 */
final class AutoApplyTest extends TestCase {

	public function test_eligible_basic(): void {
		$this->assertTrue( AutoApplyMeta::eligible_props( 'publish', 0, 0, array() ) );
	}

	public function test_blocked_when_not_published(): void {
		$this->assertFalse( AutoApplyMeta::eligible_props( 'draft', 0, 0, array() ) );
	}

	public function test_blocked_by_usage_limit(): void {
		$this->assertFalse( AutoApplyMeta::eligible_props( 'publish', 5, 0, array() ) );
	}

	public function test_blocked_by_per_user_limit(): void {
		$this->assertFalse( AutoApplyMeta::eligible_props( 'publish', 0, 3, array() ) );
	}

	public function test_blocked_by_email_restriction(): void {
		$this->assertFalse( AutoApplyMeta::eligible_props( 'publish', 0, 0, array( 'vip@example.com' ) ) );
	}

	public function test_empty_email_entries_are_ignored(): void {
		$this->assertTrue( AutoApplyMeta::eligible_props( 'publish', 0, 0, array( '', '  ' ) ) );
	}
}
