<?php

declare( strict_types=1 );

namespace Moksafocou\Modules\DiscountTiers;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the tiered-discount script into the block cart and checkout, and only there.
 *
 * Only ever instantiated from {@see StoreApi::register_integration()}, after it has checked that
 * the interface exists — this file names a WooCommerce Blocks interface in its declaration, so it
 * must never be autoloaded on a site without Blocks.
 */
final class BlocksIntegration implements IntegrationInterface {

	public const NAME = 'moksafocou-tiers';

	private const HANDLE = 'moksafocou-tiers-blocks';

	public function get_name(): string {
		return self::NAME;
	}

	public function initialize(): void {
		// Beside the module, like the admin tiers.js: the release build ships src/ lib/ languages/
		// and nothing else, so a script under the repo's root assets/ folder would load as a 404 on
		// every shop that installed the plugin — silently, since the cart still renders without it.
		$rel  = 'src/Modules/DiscountTiers/assets/js/tiers-blocks.js';
		$path = \MOKSAFOCOU_PLUGIN_DIR . $rel;

		wp_register_script(
			self::HANDLE,
			\MOKSAFOCOU_PLUGIN_URL . $rel,
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wp-data', 'wp-plugins', 'wp-element' ),
			file_exists( $path ) ? (string) filemtime( $path ) : \MOKSAFOCOU_VERSION,
			true
		);

		// The bar and the row borrow the block theme's own totals spacing; only the bar itself
		// needs drawing. Attached to the script's own style handle so it exists only where the
		// script does.
		wp_register_style( self::HANDLE, false, array(), MOKSAFOCOU_VERSION );
		wp_add_inline_style(
			self::HANDLE,
			'.moksafocou-tier-nudge{display:block;padding:12px 0 14px;font-size:.875em;line-height:1.5}'
			. '.moksafocou-tier-nudge__bar{display:block;width:100%;height:6px;margin-top:6px;border-radius:3px;background:rgba(0,0,0,.1);overflow:hidden}'
			. '.moksafocou-tier-nudge__fill{display:block;height:100%;border-radius:3px;background:currentColor;opacity:.75;transition:width .3s ease}'
		);
		wp_enqueue_style( self::HANDLE );
	}

	/** @return array<int,string> */
	public function get_script_handles(): array {
		return array( self::HANDLE );
	}

	/** @return array<int,string> */
	public function get_editor_script_handles(): array {
		return array();
	}

	/** @return array<string,mixed> */
	public function get_script_data(): array {
		return array();
	}
}
