/* global jQuery, moksafocouConditional */
/**
 * Hides a feature section's dependent fields while its enable checkbox is unchecked, so each
 * panel only shows what's relevant. Convention-based (no per-module markup needed): for each
 * enable-checkbox id, everything AFTER its .form-field wrapper within the same panel is treated
 * as dependent and toggled with the checkbox. The leading description (before the checkbox)
 * stays visible so the feature is still explained when off.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafocouConditional || {};
	var ids = cfg.toggles || [];

	$( function () {
		ids.forEach( function ( id ) {
			var $cb = $( '#' + id );
			if ( ! $cb.length ) {
				return;
			}
			var $wrap = $cb.closest( '.form-field' );
			if ( ! $wrap.length ) {
				$wrap = $cb.closest( 'p, div' );
			}
			function sync() {
				$wrap.nextAll().toggle( $cb.is( ':checked' ) );
			}
			$cb.on( 'change', sync );
			sync();
		} );
	} );
}( jQuery ) );
