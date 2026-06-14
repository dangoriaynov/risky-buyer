/* global RiskyBuyerData, jQuery */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce = RiskyBuyerData.nonce;
		return $.post( RiskyBuyerData.ajax_url, data );
	}

	$( document ).on( 'click', '.rb-mark-btn', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.rb-metabox' );
		var orderId = $box.data( 'order-id' );
		var reason = $box.find( '.rb-reason' ).val() || 'other';
		var note = $box.find( '.rb-note-input' ).val() || '';
		var $btn = $( this ).prop( 'disabled', true );

		post( 'riskybuyer_mark', { order_id: orderId, reason: reason, note: note } )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					window.alert( ( res && res.data && res.data.message ) || RiskyBuyerData.i18n.error );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				window.alert( RiskyBuyerData.i18n.error );
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.rb-unmark-btn', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( RiskyBuyerData.i18n.confirm_remove ) ) {
			return;
		}
		var uuid = $( this ).data( 'uuid' );
		var $btn = $( this ).prop( 'disabled', true );

		post( 'riskybuyer_unmark', { uuid: uuid } )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					window.alert( ( res && res.data && res.data.message ) || RiskyBuyerData.i18n.error );
					$btn.prop( 'disabled', false );
				}
			} )
			.fail( function () {
				window.alert( RiskyBuyerData.i18n.error );
				$btn.prop( 'disabled', false );
			} );
	} );
} )( jQuery );

/* Settings tab — auto-save (no reload), key validation, sync/push. */
( function ( $ ) {
	'use strict';
	var $en = $( '#rb-sync-enabled' );
	if ( ! $en.length ) {
		return;
	}

	function post( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce = RiskyBuyerData.nonce;
		return $.post( RiskyBuyerData.ajax_url, data );
	}

	function gather() {
		return {
			sync_enabled: $en.is( ':checked' ) ? 1 : 0,
			server_url: $( '#rb-server-url' ).val() || '',
			api_key: $( '#rb-api-key' ).val() || ''
		};
	}

	function save( cb ) {
		$( '#rb-save-status' ).text( RiskyBuyerData.i18n.saving );
		post( 'riskybuyer_save_settings', gather() ).done( function () {
			$( '#rb-save-status' ).text( RiskyBuyerData.i18n.saved );
			setTimeout( function () { $( '#rb-save-status' ).text( '' ); }, 1500 );
			if ( cb ) { cb(); }
		} ).fail( function () { $( '#rb-save-status' ).text( RiskyBuyerData.i18n.error ); } );
	}

	function validate() {
		$( '#rb-push' ).hide();
		var key = $( '#rb-api-key' ).val();
		if ( ! $en.is( ':checked' ) || ! key ) { $( '#rb-key-status' ).text( '' ); return; }
		$( '#rb-key-status' ).css( 'color', '' ).text( RiskyBuyerData.i18n.checking );
		post( 'riskybuyer_validate_key', { server_url: $( '#rb-server-url' ).val(), api_key: key } )
			.done( function ( r ) {
				if ( r && r.success && r.data && r.data.valid ) {
					$( '#rb-key-status' ).css( 'color', '#1a7a3c' ).text( '✓ ' + ( r.data.domain || '' ) + ' (' + r.data.scope + ')' );
					if ( r.data.scope === 'write' ) { $( '#rb-push' ).show(); }
				} else {
					$( '#rb-key-status' ).css( 'color', '#b32d2e' ).text( '✗ ' + RiskyBuyerData.i18n.key_invalid );
				}
			} )
			.fail( function () { $( '#rb-key-status' ).css( 'color', '#b32d2e' ).text( '✗ ' + RiskyBuyerData.i18n.key_invalid ); } );
	}

	$en.on( 'change', function () { $( '#rb-sync-fields' ).toggle( $en.is( ':checked' ) ); save( validate ); } );
	$( '#rb-server-url, #rb-api-key' ).on( 'change', function () { save( validate ); } );

	$( document ).on( 'click', '#rb-sync-now', function () {
		var $b = $( this ).prop( 'disabled', true );
		post( 'riskybuyer_sync_now', {} ).done( function ( r ) {
			window.alert( ( r && r.data && r.data.message ) || RiskyBuyerData.i18n.error );
			location.reload();
		} ).fail( function () { window.alert( RiskyBuyerData.i18n.error ); $b.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '#rb-push', function () {
		var $b = $( this ).prop( 'disabled', true );
		post( 'riskybuyer_push', {} ).done( function ( r ) {
			window.alert( ( r && r.data && r.data.message ) || RiskyBuyerData.i18n.error );
			$b.prop( 'disabled', false );
		} ).fail( function () { window.alert( RiskyBuyerData.i18n.error ); $b.prop( 'disabled', false ); } );
	} );

	if ( $en.is( ':checked' ) && $( '#rb-api-key' ).val() ) { validate(); }
} )( jQuery );

/* List tab — instant in-browser filtering (no reload, no AJAX). */
( function ( $ ) {
	'use strict';
	var $bar = $( '#rb-filterbar' );
	var $table = $( '#rb-list' );
	if ( ! $bar.length || ! $table.length ) {
		return;
	}

	var $rows = $table.find( 'tbody tr.rb-row' );
	var $nomatch = $table.find( 'tr.rb-nomatch' );
	var $count = $( '#rb-count' );
	var $phone = $( '#rb-fphone' );
	var $name = $( '#rb-fname' );
	var $op = $( '#rb-op' );
	var $reason = $( '#rb-freason' );

	function digits( s ) {
		return ( s || '' ).replace( /\D+/g, '' );
	}

	function apply() {
		var ph = digits( $phone.val() );
		var nm = ( $name.val() || '' ).trim().toLowerCase();
		var or = $op.val() === 'OR';
		var rs = $reason.val();
		var visible = 0;

		$rows.each( function () {
			var row = this;
			var okPhone = ! ph || ( row.getAttribute( 'data-phone' ) || '' ).indexOf( ph ) > -1;
			var okName = ! nm || ( row.getAttribute( 'data-name' ) || '' ).indexOf( nm ) > -1;
			var textOk;
			if ( ph && nm ) {
				textOk = or ? ( okPhone || okName ) : ( okPhone && okName );
			} else {
				textOk = okPhone && okName;
			}
			var okReason = ! rs || row.getAttribute( 'data-reason' ) === rs;
			var show = textOk && okReason;
			row.style.display = show ? '' : 'none';
			if ( show ) {
				visible++;
			}
		} );

		$nomatch.css( 'display', visible === 0 ? '' : 'none' );
		$count.text( visible + ' / ' + $rows.length );
	}

	$phone.add( $name ).on( 'input', apply );
	$op.add( $reason ).on( 'change', apply );
	$( '#rb-clear' ).on( 'click', function () {
		$phone.val( '' );
		$name.val( '' );
		$op.val( 'AND' );
		$reason.val( '' );
		apply();
	} );

	apply();
} )( jQuery );
