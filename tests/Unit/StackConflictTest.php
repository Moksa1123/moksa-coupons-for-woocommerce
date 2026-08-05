<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\StackingControl\StackConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the stacking verdict (stack_conflict) and code-list parsing.
 */
final class StackConflictTest extends TestCase {

	/**
	 * @param array<int,string> $allowed
	 * @param array<int,string> $disallowed
	 * @return array{exclude:bool,allowed:array<int,string>,disallowed:array<int,string>}
	 */
	private function rules( bool $exclude = false, array $allowed = array(), array $disallowed = array() ): array {
		return array(
			'no_stack'   => $exclude,
			'allowed'    => $allowed,
			'disallowed' => $disallowed,
		);
	}

	/**
	 * @param array<int,string> $allowed
	 * @param array<int,string> $disallowed
	 * @return array{code:string,exclude:bool,allowed:array<int,string>,disallowed:array<int,string>}
	 */
	private function other( string $code, bool $exclude = false, array $allowed = array(), array $disallowed = array() ): array {
		return array(
			'code'       => $code,
			'no_stack'   => $exclude,
			'allowed'    => $allowed,
			'disallowed' => $disallowed,
		);
	}

	public function test_no_rules_allows_combination(): void {
		$this->assertNull( StackConfig::stack_conflict( 'a', $this->rules(), array( $this->other( 'b' ) ) ) );
	}

	public function test_self_disallows_other(): void {
		$this->assertSame( 'b', StackConfig::stack_conflict( 'a', $this->rules( false, array(), array( 'b' ) ), array( $this->other( 'b' ) ) ) );
	}

	public function test_other_disallows_self(): void {
		$this->assertSame( 'b', StackConfig::stack_conflict( 'a', $this->rules(), array( $this->other( 'b', false, array(), array( 'a' ) ) ) ) );
	}

	public function test_self_exclude_blocks_non_allowed(): void {
		$this->assertSame( 'b', StackConfig::stack_conflict( 'a', $this->rules( true ), array( $this->other( 'b' ) ) ) );
	}

	public function test_self_exclude_permits_allowed(): void {
		$this->assertNull( StackConfig::stack_conflict( 'a', $this->rules( true, array( 'b' ) ), array( $this->other( 'b' ) ) ) );
	}

	public function test_other_exclude_blocks_non_allowed(): void {
		$this->assertSame( 'b', StackConfig::stack_conflict( 'a', $this->rules(), array( $this->other( 'b', true ) ) ) );
	}

	public function test_other_exclude_permits_allowed(): void {
		$this->assertNull( StackConfig::stack_conflict( 'a', $this->rules(), array( $this->other( 'b', true, array( 'a' ) ) ) ) );
	}

	public function test_first_conflict_wins_across_multiple(): void {
		$others = array( $this->other( 'b' ), $this->other( 'c', false, array(), array( 'a' ) ) );
		$this->assertSame( 'c', StackConfig::stack_conflict( 'a', $this->rules(), $others ) );
	}

	public function test_parse_codes_normalizes_dedupes(): void {
		// No WC in unit context → normalize() falls back to strtolower.
		$this->assertSame( array( 'a', 'b', 'c' ), StackConfig::parse_codes( "A, b\nC,,a" ) );
		$this->assertSame( array(), StackConfig::parse_codes( '   ' ) );
	}
}
