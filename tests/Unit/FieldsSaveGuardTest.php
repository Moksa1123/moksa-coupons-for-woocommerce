<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Admin\FieldsSaveGuard;
use PHPUnit\Framework\TestCase;

/**
 * Minimal consumer of the shared FieldsSaveGuard trait, mirroring how every coupon-settings
 * Fields class wires it (a CAP constant + an action() nonce-action method).
 */
final class FieldsSaveGuardConsumer {

	use FieldsSaveGuard;

	private const CAP = 'manage_woocommerce';

	private function action( int $id ): string {
		return 'mfc_act_' . $id;
	}

	public function check( int $post_id, string $nonce_key ): bool {
		return $this->verify_save( $post_id, $nonce_key );
	}
}

/**
 * Tests the cap + nonce guard that previously lived inline (untested) in 12 modules. The
 * bootstrap doubles current_user_can() (via $GLOBALS['__mfc_test_can']) and wp_verify_nonce()
 * (valid iff the token equals "valid:<action>").
 */
final class FieldsSaveGuardTest extends TestCase {

	private FieldsSaveGuardConsumer $consumer;

	protected function setUp(): void {
		$this->consumer            = new FieldsSaveGuardConsumer();
		$GLOBALS['__mfc_test_can'] = true;
		$_POST                     = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['__mfc_test_can'] );
		$_POST = array();
	}

	public function test_fails_when_user_lacks_capability(): void {
		$GLOBALS['__mfc_test_can'] = false;
		$_POST['nk']               = 'valid:mfc_act_7'; // even a correct nonce must not pass.
		$this->assertFalse( $this->consumer->check( 7, 'nk' ) );
	}

	public function test_fails_when_nonce_missing(): void {
		$this->assertFalse( $this->consumer->check( 7, 'nk' ) );
	}

	public function test_fails_when_nonce_wrong(): void {
		$_POST['nk'] = 'valid:mfc_act_999'; // right shape, wrong coupon id.
		$this->assertFalse( $this->consumer->check( 7, 'nk' ) );
	}

	public function test_fails_when_nonce_empty_string(): void {
		$_POST['nk'] = '';
		$this->assertFalse( $this->consumer->check( 7, 'nk' ) );
	}

	public function test_passes_with_capability_and_matching_nonce(): void {
		$_POST['nk'] = 'valid:mfc_act_7';
		$this->assertTrue( $this->consumer->check( 7, 'nk' ) );
	}
}
