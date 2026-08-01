<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Settings\SettingsScreen;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the settings schema that drives both render and save. The actual
 * option writes touch get/update_option and are verified live, not here.
 */
final class SettingsScreenTest extends TestCase {

	/** @return array<int,array<string,mixed>> Flattened field list across all groups. */
	private static function fields(): array {
		$fields = array();
		foreach ( SettingsScreen::groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				$fields[] = $field;
			}
		}
		return $fields;
	}

	public function test_every_group_has_title_and_fields(): void {
		$groups = SettingsScreen::groups();
		$this->assertNotEmpty( $groups );
		foreach ( $groups as $group ) {
			$this->assertNotSame( '', (string) ( $group['title'] ?? '' ) );
			$this->assertNotEmpty( $group['fields'] ?? array() );
		}
	}

	public function test_field_ids_are_unique_and_option_namespaced(): void {
		$ids = array();
		foreach ( self::fields() as $field ) {
			$id = (string) ( $field['id'] ?? '' );
			$this->assertNotSame( '', $id );
			$this->assertStringStartsWith( 'moksafocou_', $id, "field id {$id} is not option-namespaced" );
			$ids[] = $id;
		}
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'a setting id appears twice' );
	}

	public function test_field_types_are_known_and_well_formed(): void {
		foreach ( self::fields() as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			$this->assertContains( $type, array( 'checkbox', 'text', 'select' ), "field {$field['id']} has unknown type {$type}" );
			$this->assertNotSame( '', (string) ( $field['title'] ?? '' ), "field {$field['id']} has no title" );

			if ( 'checkbox' === $type ) {
				$this->assertSame( 'no', $field['default'] ?? null, "toggle {$field['id']} must default to 'no'" );
			}
			if ( 'select' === $type ) {
				$this->assertIsArray( $field['options'] ?? null, "select {$field['id']} needs options" );
				$this->assertArrayHasKey(
					(string) ( $field['default'] ?? '' ),
					$field['options'],
					"select {$field['id']} default not in options"
				);
			}
		}
	}

	public function test_mcp_destructive_gate_is_present_and_defaults_off(): void {
		// Guard the destructive-MCP gate: it must exist and default to 'no' (read-only first).
		$found = null;
		foreach ( self::fields() as $field ) {
			if ( 'moksafocou_mcp_expose_destructive' === ( $field['id'] ?? '' ) ) {
				$found = $field;
				break;
			}
		}
		$this->assertNotNull( $found, 'destructive-MCP gate missing from settings' );
		$this->assertSame( 'no', $found['default'] );
	}
}
