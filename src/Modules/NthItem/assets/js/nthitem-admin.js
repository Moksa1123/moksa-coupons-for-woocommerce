/* global jQuery */
( function ( $ ) {
	'use strict';

	function toggleType() {
		var isNth = $( '#discount_type' ).val() === 'moksafocou_nth_item';
		$( 'li.moksafocou_nth_item_tab' ).toggle( isNth );
		if ( ! isNth ) {
			$( '#moksafocou_nth_item' ).hide();
		}
	}

	function toggleRows() {
		var free = $( '#_moksafocou_nth_reward_mode' ).val() === 'free';
		$( '#_moksafocou_nth_reward_value' ).closest( '.form-field' ).toggle( ! free );

		var repeat = $( '#_moksafocou_nth_deal_mode' ).val() === 'repeat';
		$( '#_moksafocou_nth_repeat_limit' ).closest( '.form-field' ).toggle( repeat );
	}

	$( function () {
		$( '#discount_type' ).on( 'change', toggleType );
		$( '#_moksafocou_nth_reward_mode' ).on( 'change', toggleRows );
		$( '#_moksafocou_nth_deal_mode' ).on( 'change', toggleRows );
		toggleType();
		toggleRows();
	} );
}( jQuery ) );
