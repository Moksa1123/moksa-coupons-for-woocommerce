<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\CouponCore\ToolsAbility;
use PHPUnit\Framework\TestCase;

/**
 * Guards the no-argument ability input schema. The WordPress AI Client passes an
 * ability's input_schema straight into each LLM function declaration; an empty PHP
 * array for `properties` serializes to JSON `[]`, which providers reject with
 * "[] is not of type 'object'" and 400 the whole assistant request. `properties`
 * must therefore serialize to `{}` (an object), not `[]`.
 */
final class ToolsAbilitySchemaTest extends TestCase {

	/** @return array<string,mixed> */
	private function empty_input(): array {
		$method = new \ReflectionMethod( ToolsAbility::class, 'empty_input' );
		$method->setAccessible( true );
		/** @var array<string,mixed> $schema */
		$schema = $method->invoke( null );
		return $schema;
	}

	public function test_no_input_properties_serialize_as_json_object(): void {
		$schema = $this->empty_input();

		$this->assertSame( 'object', $schema['type'] );
		// The crux: properties must NOT be a sequential array (json `[]`).
		$this->assertIsObject( $schema['properties'] );
		$this->assertSame( '{}', (string) wp_json_encode( $schema['properties'] ) );
	}

	public function test_full_schema_json_has_object_properties_not_array(): void {
		$json = (string) wp_json_encode( $this->empty_input() );

		$this->assertStringContainsString( '"properties":{}', $json );
		$this->assertStringNotContainsString( '"properties":[]', $json );
	}
}
