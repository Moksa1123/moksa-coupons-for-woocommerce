<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\TabIcons\Icons;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the coupon-settings tab icon CSS generation.
 */
final class TabIconsTest extends TestCase {

	private const EXPECTED_TABS = array(
		'moksafocou_schedule',
		'moksafocou_roles',
		'moksafocou_cart',
		'moksafocou_customer',
		'moksafocou_products',
		'moksafocou_daytime',
		'moksafocou_shipregion',
		'moksafocou_payment',
		'moksafocou_url',
		'moksafocou_bogo',
		'moksafocou_gift',
		'moksafocou_stacking',
		'moksafocou_shipping',
		'moksafocou_frontend',
		'moksafocou_tiers',
		'moksafocou_advrules',
	);

	public function test_every_settings_tab_has_a_nonempty_icon(): void {
		$map = Icons::map();
		foreach ( self::EXPECTED_TABS as $tab ) {
			$this->assertArrayHasKey( $tab, $map, "missing icon for {$tab}" );
			$this->assertStringContainsString( '<', $map[ $tab ], "icon for {$tab} has no SVG markup" );
		}
		$this->assertCount( count( self::EXPECTED_TABS ), $map );
	}

	public function test_data_uri_is_css_safe(): void {
		$uri = Icons::data_uri( "<circle cx='12' cy='12' r='10'/>" );
		$this->assertStringStartsWith( 'data:image/svg+xml,', $uri );
		// rawurlencode must have escaped the angle brackets, quotes and hash.
		$this->assertStringNotContainsString( '<', $uri );
		$this->assertStringNotContainsString( '"', $uri );
		$this->assertStringNotContainsString( '#', $uri );
	}

	public function test_css_targets_each_tab_with_a_mask(): void {
		$css = Icons::css();
		foreach ( self::EXPECTED_TABS as $tab ) {
			$this->assertStringContainsString( "li.{$tab}_options a::before", $css );
		}
		$this->assertStringContainsString( 'mask-image:url("data:image/svg+xml,', $css );
		$this->assertStringContainsString( 'background-color:currentColor', $css );
		// Uniform, not colour-coded: no hex fills leak into the stylesheet.
		$this->assertStringNotContainsString( '#2271b1', $css );
	}

	public function test_css_also_targets_each_consolidated_metabox_tab(): void {
		$css = Icons::css();
		// Shared base rule also covers our consolidated-metabox left tabs.
		$this->assertStringContainsString( '.moksafocou-settings-tabs li[class*="moksafocou_"][class*="_options"] a::before', $css );
		foreach ( self::EXPECTED_TABS as $key ) {
			$this->assertStringContainsString( ".moksafocou-settings-tabs li.{$key}_options a::before", $css );
		}
	}
}
