/* global ProbclientData, jQuery */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce = ProbclientData.nonce;
		return $.post( ProbclientData.ajax_url, data );
	}

	$( document ).on( 'click', '.pc-mark-btn', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.pc-metabox' );
		var orderId = $box.data( 'order-id' );
		var reason = $box.find( '.pc-reason' ).val() || 'other';
		var note = $box.find( '.pc-note-input' ).val() || '';
		var $btn = $( this ).prop( 'disabled', true );

		post( 'probclient_mark', { order_id: orderId, reason: reason, note: note } )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					window.alert( ( res && res.data && res.data.message ) || ProbclientData.i18n.error );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				window.alert( ProbclientData.i18n.error );
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.pc-unmark-btn', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( ProbclientData.i18n.confirm_remove ) ) {
			return;
		}
		var uuid = $( this ).data( 'uuid' );
		var $btn = $( this ).prop( 'disabled', true );

		post( 'probclient_unmark', { uuid: uuid } )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					window.alert( ( res && res.data && res.data.message ) || ProbclientData.i18n.error );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				window.alert( ProbclientData.i18n.error );
				$btn.prop( 'disabled', false );
			} );
	} );
} )( jQuery );
