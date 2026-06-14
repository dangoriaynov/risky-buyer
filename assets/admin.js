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

/* Settings tab — auto-save (no reload), key validation, sync/push. */
( function ( $ ) {
	'use strict';
	var $en = $( '#pc-sync-enabled' );
	if ( ! $en.length ) {
		return;
	}

	function post( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce = PC_DATA.nonce;
		return $.post( PC_DATA.ajax_url, data );
	}

	function gather() {
		return {
			sync_enabled: $en.is( ':checked' ) ? 1 : 0,
			server_url: $( '#pc-server-url' ).val() || '',
			api_key: $( '#pc-api-key' ).val() || ''
		};
	}

	function save( cb ) {
		$( '#pc-save-status' ).text( PC_DATA.i18n.saving );
		post( 'probclient_save_settings', gather() ).done( function () {
			$( '#pc-save-status' ).text( PC_DATA.i18n.saved );
			setTimeout( function () { $( '#pc-save-status' ).text( '' ); }, 1500 );
			if ( cb ) { cb(); }
		} ).fail( function () { $( '#pc-save-status' ).text( PC_DATA.i18n.error ); } );
	}

	function validate() {
		$( '#pc-push' ).hide();
		var key = $( '#pc-api-key' ).val();
		if ( ! $en.is( ':checked' ) || ! key ) { $( '#pc-key-status' ).text( '' ); return; }
		$( '#pc-key-status' ).css( 'color', '' ).text( PC_DATA.i18n.checking );
		post( 'probclient_validate_key', { server_url: $( '#pc-server-url' ).val(), api_key: key } )
			.done( function ( r ) {
				if ( r && r.success && r.data && r.data.valid ) {
					$( '#pc-key-status' ).css( 'color', '#1a7a3c' ).text( '✓ ' + ( r.data.domain || '' ) + ' (' + r.data.scope + ')' );
					if ( r.data.scope === 'write' ) { $( '#pc-push' ).show(); }
				} else {
					$( '#pc-key-status' ).css( 'color', '#b32d2e' ).text( '✗ ' + PC_DATA.i18n.key_invalid );
				}
			} )
			.fail( function () { $( '#pc-key-status' ).css( 'color', '#b32d2e' ).text( '✗ ' + PC_DATA.i18n.key_invalid ); } );
	}

	$en.on( 'change', function () { $( '#pc-sync-fields' ).toggle( $en.is( ':checked' ) ); save( validate ); } );
	$( '#pc-server-url, #pc-api-key' ).on( 'change', function () { save( validate ); } );

	$( document ).on( 'click', '#pc-sync-now', function () {
		var $b = $( this ).prop( 'disabled', true );
		post( 'probclient_sync_now', {} ).done( function ( r ) {
			window.alert( ( r && r.data && r.data.message ) || PC_DATA.i18n.error );
			location.reload();
		} ).fail( function () { window.alert( PC_DATA.i18n.error ); $b.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '#pc-push', function () {
		var $b = $( this ).prop( 'disabled', true );
		post( 'probclient_push', {} ).done( function ( r ) {
			window.alert( ( r && r.data && r.data.message ) || PC_DATA.i18n.error );
			$b.prop( 'disabled', false );
		} ).fail( function () { window.alert( PC_DATA.i18n.error ); $b.prop( 'disabled', false ); } );
	} );

	if ( $en.is( ':checked' ) && $( '#pc-api-key' ).val() ) { validate(); }
} )( jQuery );
