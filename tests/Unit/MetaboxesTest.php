<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\Metaboxes\Module;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the metabox-presentation module's layout CSS.
 */
final class MetaboxesTest extends TestCase {

	public function test_css_lays_out_the_vertical_tabbed_metabox(): void {
		$css = Module::css();
		// The left tab column and the right switchable panels.
		$this->assertStringContainsString( '.moksafocou-settings-tabs', $css );
		$this->assertStringContainsString( '.moksafocou-panel', $css );
		// Scoped to the coupon edit screen so it never leaks onto other post types.
		$this->assertStringContainsString( '.post-type-shop_coupon', $css );
	}
}
