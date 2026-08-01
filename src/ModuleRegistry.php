<?php

declare( strict_types=1 );

namespace Moksafocou;

defined( 'ABSPATH' ) || exit;

/**
 * Central module registry. Modules are lazy-loaded: a module only boots when its
 * `moksafocou_<key>_enabled` option is 'yes', so an unchecked module registers
 * no hooks and loads no code (wp.org compliance).
 *
 * Modules are appended in later phases (CouponCore, CartConditions, Bogo,
 * UrlCoupons, Scheduler, RoleRestrictions, AiAssistant, Mcp).
 *
 * @var array<string,class-string>
 */
final class ModuleRegistry {

	/** @var array<string,class-string<Modules\AbstractModule>> */
	private array $modules = [
		'ai'            => Modules\AiAssistant\Module::class,
		'conditions'    => Modules\CouponConditions\Module::class,
		'advrules'      => Modules\AdvancedRules\Module::class,
		'url'           => Modules\UrlCoupons\Module::class,
		'bogo'          => Modules\Bogo\Module::class,
		'nthitem'       => Modules\NthItem\Module::class,
		'mixmatch'      => Modules\MixMatch\Module::class,
		'autoapply'     => Modules\AutoApply\Module::class,
		'discountcap'   => Modules\DiscountCap\Module::class,
		'discounttiers' => Modules\DiscountTiers\Module::class,
		'freegift'      => Modules\FreeGift\Module::class,
		'stacking'      => Modules\StackingControl\Module::class,
		'shipping'      => Modules\ShippingOverride\Module::class,
		'savings'       => Modules\Savings\Module::class,
		'couponlist'    => Modules\CouponList\Module::class,
		'importexport'  => Modules\ImportExport\Module::class,
		'summary'       => Modules\Summary\Module::class,
		'send'          => Modules\CouponSend\Module::class,
		'reports'       => Modules\Reports\Module::class,
		'frontend'      => Modules\Frontend\Module::class,
		'myaccount'     => Modules\MyAccount\Module::class,
		'remarketing'   => Modules\Remarketing\Module::class,
		'expiry'        => Modules\ExpiryReminder\Module::class,
		'nudge'         => Modules\Nudge\Module::class,
		'templates'     => Modules\Templates\Module::class,
		'tabicons'      => Modules\TabIcons\Module::class,
		'metaboxes'     => Modules\Metaboxes\Module::class,
		'adminmenu'     => Modules\AdminMenu\Module::class,
	];

	/** @var array<string,Modules\AbstractModule> */
	private array $booted = [];

	public function boot(): void {
		foreach ( $this->modules as $key => $class ) {
			if ( ! $this->is_enabled( $key ) ) {
				continue;
			}
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$module = new $class();
			$module->boot();
			$this->booted[ $key ] = $module;
		}

		do_action( 'moksafocou_modules_booted', $this->booted );
	}

	public function is_enabled( string $key ): bool {
		$option = sprintf( 'moksafocou_%s_enabled', $key );
		return get_option( $option, 'no' ) === 'yes';
	}

	/** @return array<string,class-string<Modules\AbstractModule>> */
	public function all(): array {
		return $this->modules;
	}

	public function booted( string $key ): ?Modules\AbstractModule {
		return $this->booted[ $key ] ?? null;
	}
}
