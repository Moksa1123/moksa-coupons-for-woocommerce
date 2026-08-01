<?php

declare( strict_types=1 );

namespace Moksafocou\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for every feature module. A module must declare its slug,
 * label and category, and wire its hooks in boot().
 */
abstract class AbstractModule {

	abstract public function slug(): string;

	abstract public function label(): string;

	abstract public function category(): string;

	abstract public function boot(): void;

	public function name(): string {
		return ucfirst( str_replace( '_', ' ', $this->slug() ) );
	}

	public function tagline(): string {
		return '';
	}

	/**
	 * Module keys this one needs to be fully useful. Enabling a module whose requirement is off
	 * isn't an error (it just does less), so this drives a soft advisory rather than a hard block.
	 *
	 * @return array<int,string>
	 */
	public function requires(): array {
		return array();
	}
}
