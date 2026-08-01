<?php

declare( strict_types=1 );

namespace Moksafocou\Support;

use Moksafocou\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces soft module dependencies. A module can declare requires() (e.g. remarketing needs the
 * 'My coupons' account page to show what it issues); enabling it without that is not an error but
 * silently does less. This finds those gaps so the settings screen can advise the admin instead of
 * leaving a feature quietly half-working.
 */
final class ModuleDependencies {

	/**
	 * For every enabled module whose declared requirements are not all enabled, report the gap.
	 *
	 * @return array<int,array{module:string,missing:array<int,string>}> Module label + missing requirement labels.
	 */
	public static function unmet( ModuleRegistry $registry ): array {
		$all = $registry->all();
		$out = array();
		foreach ( $all as $key => $class ) {
			if ( ! $registry->is_enabled( $key ) || ! class_exists( $class ) ) {
				continue;
			}
			$module  = new $class();
			$missing = array();
			foreach ( $module->requires() as $dep ) {
				if ( $registry->is_enabled( $dep ) ) {
					continue;
				}
				$dep_class = $all[ $dep ] ?? '';
				$missing[] = ( '' !== $dep_class && class_exists( $dep_class ) ) ? ( new $dep_class() )->label() : $dep;
			}
			if ( array() !== $missing ) {
				$out[] = array(
					'module'  => $module->label(),
					'missing' => $missing,
				);
			}
		}
		return $out;
	}
}
