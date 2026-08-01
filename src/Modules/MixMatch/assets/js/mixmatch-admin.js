/* global jQuery */
( function ( $ ) {
	'use strict';

	function toggleType() {
		var isMix = $( '#discount_type' ).val() === 'moksafocou_mixmatch';
		$( 'li.moksafocou_mixmatch_tab' ).toggle( isMix );
		if ( ! isMix ) {
			$( '#moksafocou_mixmatch' ).hide();
		}
	}

	function toggleRows() {
		var repeat = $( '#_moksafocou_mixmatch_deal_mode' ).val() === 'repeat';
		$( '#_moksafocou_mixmatch_repeat_limit' ).closest( '.form-field' ).toggle( repeat );
	}

	$( function () {
		$( '#discount_type' ).on( 'change', toggleType );
		$( '#_moksafocou_mixmatch_deal_mode' ).on( 'change', toggleRows );
		toggleType();
		toggleRows();
	} );
}( jQuery ) );
