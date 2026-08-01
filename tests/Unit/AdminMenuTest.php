<?php

declare( strict_types=1 );

namespace Moksafocou\Tests\Unit;

use Moksafocou\Modules\AdminMenu\Menu;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the AdminMenu show_in_menu resolver.
 */
final class AdminMenuTest extends TestCase {

	public function test_shop_coupon_is_hidden_from_core_placement(): void {
		// We manage shop_coupon's menu ourselves, so core must place nothing.
		$this->assertFalse( Menu::cpt_show_in_menu( 'shop_coupon', 'woocommerce' ) );
		$this->assertFalse( Menu::cpt_show_in_menu( 'shop_coupon', true ) );
		$this->assertSame( 'moksa-coupons-for-woocommerce', Menu::TOPLEVEL );
	}

	public function test_other_post_types_keep_their_menu(): void {
		$this->assertSame( 'woocommerce', Menu::cpt_show_in_menu( 'product', 'woocommerce' ) );
		$this->assertSame( 'edit.php', Menu::cpt_show_in_menu( 'post', 'edit.php' ) );
		$this->assertTrue( Menu::cpt_show_in_menu( 'page', true ) );
		$this->assertFalse( Menu::cpt_show_in_menu( 'attachment', false ) );
	}
}
