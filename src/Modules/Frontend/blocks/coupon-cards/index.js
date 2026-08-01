/**
 * Editor script for the moksafocou/coupon-cards dynamic block. Plain wp.* (no build step):
 * a ServerSideRender preview plus an inspector control for the card count.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var SSR = wp.serverSideRender;
	var InspectorControls = wp.blockEditor && wp.blockEditor.InspectorControls;
	var PanelBody = wp.components && wp.components.PanelBody;
	var RangeControl = wp.components && wp.components.RangeControl;
	var __ = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };

	wp.blocks.registerBlockType( 'moksafocou/coupon-cards', {
		edit: function ( props ) {
			var inspector = null;
			if ( InspectorControls && PanelBody && RangeControl ) {
				inspector = el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Settings', 'moksa-coupons-for-woocommerce' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Maximum number to display', 'moksa-coupons-for-woocommerce' ),
							value: props.attributes.limit,
							min: 1,
							max: 50,
							onChange: function ( v ) {
								props.setAttributes( { limit: v } );
							}
						} )
					)
				);
			}
			var preview = SSR
				? el( SSR, {
					key: 'ssr',
					block: 'moksafocou/coupon-cards',
					attributes: props.attributes
				} )
				: el( 'p', { key: 'ssr' }, __( 'Coupon cards (front-end display)', 'moksa-coupons-for-woocommerce' ) );

			return el( Fragment, {}, inspector, preview );
		},
		save: function () {
			return null; // dynamic — rendered by PHP render_callback.
		}
	} );
} )( window.wp );
