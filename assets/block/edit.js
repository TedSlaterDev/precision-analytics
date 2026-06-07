/**
 * Editor UI for the Precision Analytics "Popular Posts" block.
 *
 * Plain JS against the global wp.* runtime — no build step. The block is
 * server-rendered, so the editor shows a settings-aware placeholder rather than
 * fetching live data.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var c = wp.components;

	wp.blocks.registerBlockType( 'precision-analytics/popular-posts', {
		edit: function ( props ) {
			var a = props.attributes;

			var controls = el(
				InspectorControls,
				{},
				el(
					c.PanelBody,
					{ title: __( 'Popular Posts', 'precision-analytics' ), initialOpen: true },
					el( c.SelectControl, {
						label: __( 'Time window', 'precision-analytics' ),
						value: a.window,
						options: [
							{ label: __( 'Last 12 hours', 'precision-analytics' ), value: '12h' },
							{ label: __( 'Last 24 hours', 'precision-analytics' ), value: '24h' },
							{ label: __( 'Last 7 days', 'precision-analytics' ), value: '7d' },
							{ label: __( 'Last 30 days', 'precision-analytics' ), value: '30d' }
						],
						onChange: function ( v ) { props.setAttributes( { window: v } ); }
					} ),
					el( c.RangeControl, {
						label: __( 'Number of posts', 'precision-analytics' ),
						value: a.count, min: 1, max: 20,
						onChange: function ( v ) { props.setAttributes( { count: v } ); }
					} ),
					el( c.TextControl, {
						label: __( 'Post type (blank = posts)', 'precision-analytics' ),
						value: a.postType,
						onChange: function ( v ) { props.setAttributes( { postType: v } ); }
					} ),
					el( c.ToggleControl, {
						label: __( 'Show view counts', 'precision-analytics' ),
						checked: !! a.showViews,
						onChange: function ( v ) { props.setAttributes( { showViews: v } ); }
					} )
				)
			);

			var placeholder = el(
				'div',
				{ style: { border: '1px dashed #c3c4c7', padding: '1em', borderRadius: '4px' } },
				el( 'strong', {}, __( 'Precision Analytics — Popular Posts', 'precision-analytics' ) ),
				el( 'p', { style: { margin: '.4em 0 0' } }, __( 'Your most-read posts render here on the front end.', 'precision-analytics' ) ),
				el( 'p', { style: { margin: '.2em 0 0', opacity: 0.7 } }, __( 'Window', 'precision-analytics' ) + ': ' + a.window + ' · ' + __( 'Count', 'precision-analytics' ) + ': ' + a.count )
			);

			return el( 'div', useBlockProps(), controls, placeholder );
		},
		save: function () { return null; }
	} );
} )( window.wp );
