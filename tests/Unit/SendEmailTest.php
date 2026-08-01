<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponSend\SendService;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the email-restriction merge (lowercase, trim, de-dupe).
 */
final class SendEmailTest extends TestCase {

	public function test_adds_new_email(): void {
		$this->assertSame( array( 'a@b.com' ), SendService::merge_email_restriction( array(), 'a@b.com' ) );
	}

	public function test_normalizes_case_and_whitespace(): void {
		$this->assertSame( array( 'a@b.com' ), SendService::merge_email_restriction( array(), '  A@B.CoM ' ) );
	}

	public function test_dedupes_against_existing(): void {
		$this->assertSame(
			array( 'a@b.com', 'c@d.com' ),
			SendService::merge_email_restriction( array( 'A@b.com', ' c@d.com ' ), 'a@b.com' )
		);
	}

	public function test_preserves_existing_and_appends(): void {
		$this->assertSame(
			array( 'x@y.com', 'a@b.com' ),
			SendService::merge_email_restriction( array( 'x@y.com' ), 'a@b.com' )
		);
	}

	public function test_empty_email_returns_existing_normalized(): void {
		$this->assertSame( array( 'x@y.com' ), SendService::merge_email_restriction( array( 'X@Y.com', '' ), '   ' ) );
	}
}
