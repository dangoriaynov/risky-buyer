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

/* Settings tab — validate-once key (locks when valid), clear, sync/push icons. */
( function ( $ ) {
	'use strict';
	var $en = $( '#rb-sync-enabled' );
	if ( ! $en.length ) {
		return;
	}

	var $url = $( '#rb-server-url' );
	var $key = $( '#rb-api-key' );
	var $clear = $( '#rb-key-clear' );
	var $keyStatus = $( '#rb-key-status' );
	var $push = $( '#rb-push' );
	var $msg = $( '#rb-sync-msg' );

	function post( action, data ) {
		data = data || {};
		data.action = action;
		data.nonce = RiskyBuyerData.nonce;
		return $.post( RiskyBuyerData.ajax_url, data );
	}

	function gather() {
		return {
			sync_enabled: $en.is( ':checked' ) ? 1 : 0,
			server_url: $url.val() || '',
			api_key: $key.val() || ''
		};
	}

	function save() {
		$( '#rb-save-status' ).css( 'color', '' ).text( RiskyBuyerData.i18n.saving );
		return post( 'riskybuyer_save_settings', gather() )
			.done( function () {
				$( '#rb-save-status' ).css( 'color', '#1a7a3c' ).text( RiskyBuyerData.i18n.saved );
				setTimeout( function () { $( '#rb-save-status' ).text( '' ); }, 1800 );
			} )
			.fail( function () { $( '#rb-save-status' ).css( 'color', '#b32d2e' ).text( RiskyBuyerData.i18n.error ); } );
	}

	function lock() { $key.prop( 'readonly', true ).addClass( 'rb-locked' ); $clear.show(); }
	function unlock() { $key.prop( 'readonly', false ).removeClass( 'rb-locked' ); $push.hide(); }

	function showInvalid() {
		$keyStatus.css( 'color', '#b32d2e' ).text( '✗ ' + RiskyBuyerData.i18n.key_invalid );
		unlock();
	}

	// Validate the key once (no continuous checking). On success: save + lock + reveal write actions.
	function validate( afterValid ) {
		var key = $key.val();
		if ( ! $en.is( ':checked' ) || ! key ) {
			$keyStatus.text( '' );
			$push.hide();
			$clear.toggle( !! key );
			return;
		}
		$clear.show();
		$keyStatus.css( 'color', '' ).text( RiskyBuyerData.i18n.checking );
		post( 'riskybuyer_validate_key', { server_url: $url.val(), api_key: key } )
			.done( function ( r ) {
				if ( r && r.success && r.data && r.data.valid ) {
					$keyStatus.css( 'color', '#1a7a3c' ).text( '✓ ' + ( r.data.domain || '' ) + ' (' + r.data.scope + ')' );
					lock();
					$push.toggle( r.data.scope === 'write' );
					if ( afterValid ) { afterValid(); }
				} else {
					showInvalid();
				}
			} )
			.fail( showInvalid );
	}

	$en.on( 'change', function () {
		$( '#rb-sync-fields' ).toggle( $en.is( ':checked' ) );
		save();
		if ( $en.is( ':checked' ) ) { validate(); } else { $push.hide(); }
	} );

	$url.on( 'change', function () {
		save();
		if ( $key.val() ) { unlock(); validate(); }
	} );

	// Validate only when the user finishes editing the key (blur/change), never per keystroke.
	$key.on( 'change', function () {
		if ( ! $key.val() ) {
			$keyStatus.text( '' );
			$push.hide();
			$clear.hide();
			save();
			return;
		}
		validate( save );
	} );

	// Red ✕ — clear the key and disable key-only actions.
	$clear.on( 'click', function () {
		$key.val( '' ).prop( 'readonly', false ).removeClass( 'rb-locked' ).focus();
		$keyStatus.text( '' );
		$push.hide();
		$clear.hide();
		save();
	} );

	// Sync/Push always persist current settings first, then act (avoids using a stale key).
	function runAfterSave( action, $btn ) {
		$btn.prop( 'disabled', true );
		$msg.css( 'color', '' ).text( RiskyBuyerData.i18n.saving );
		save().always( function () {
			post( action, {} )
				.done( function ( r ) {
					var ok = r && r.success;
					$msg.css( 'color', ok ? '#1a7a3c' : '#b32d2e' ).text( ( r && r.data && r.data.message ) || RiskyBuyerData.i18n.error );
					if ( ok && r.data ) {
						if ( typeof r.data.cached !== 'undefined' ) { $( '#rb-cached-count' ).text( r.data.cached ); }
						if ( typeof r.data.added !== 'undefined' ) { $( '#rb-added-count' ).text( r.data.added ); }
					}
				} )
				.fail( function () { $msg.css( 'color', '#b32d2e' ).text( RiskyBuyerData.i18n.error ); } )
				.always( function () { $btn.prop( 'disabled', false ); } );
		} );
	}
	$( document ).on( 'click', '#rb-sync-now', function () { runAfterSave( 'riskybuyer_sync_now', $( this ) ); } );
	$( document ).on( 'click', '#rb-push', function () { runAfterSave( 'riskybuyer_push', $( this ) ); } );

	// On load: validate a saved key once (locks if valid, unlocks if not).
	if ( $en.is( ':checked' ) && $key.val() ) { validate(); } else { $clear.toggle( !! $key.val() ); }
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
		$reason.val( '' ).trigger( 'change' );
		apply();
	} );

	// Click-to-sort column headers (default: date, newest first).
	var $headers = $table.find( 'thead th.rb-sortable' );
	var $tbody = $table.find( 'tbody' );
	var sortKey = 'date';
	var sortDir = 'desc';

	function cellValue( row, key, idx ) {
		if ( 'date' === key ) { return row.getAttribute( 'data-ts' ) || ''; }
		if ( 'phone' === key ) { return digits( row.getAttribute( 'data-phone' ) || '' ); }
		if ( 'name' === key ) { return row.getAttribute( 'data-name' ) || ''; }
		if ( 'reason' === key ) { return row.getAttribute( 'data-reason' ) || ''; }
		var cell = row.children[ idx ];
		return cell ? cell.textContent.trim().toLowerCase() : '';
	}

	function doSort( key, idx, dir ) {
		var rows = $rows.get();
		rows.sort( function ( a, b ) {
			var va = cellValue( a, key, idx );
			var vb = cellValue( b, key, idx );
			if ( va === vb ) { return 0; }
			var r = va > vb ? 1 : -1;
			return 'asc' === dir ? r : -r;
		} );
		rows.forEach( function ( r ) { $tbody[ 0 ].appendChild( r ); } );
		if ( $nomatch.length ) { $tbody[ 0 ].appendChild( $nomatch[ 0 ] ); }
		$headers.removeClass( 'rb-sort-asc rb-sort-desc' );
		$headers.filter( '[data-sort="' + key + '"]' ).addClass( 'asc' === dir ? 'rb-sort-asc' : 'rb-sort-desc' );
	}

	$headers.on( 'click', function () {
		var key = this.getAttribute( 'data-sort' );
		var idx = $( this ).index();
		sortDir = ( sortKey === key && 'asc' === sortDir ) ? 'desc' : 'asc';
		sortKey = key;
		doSort( key, idx, sortDir );
	} );

	// Reflect the default order (rows arrive newest-first from the server).
	$headers.filter( '[data-sort="date"]' ).addClass( 'rb-sort-desc' );

	apply();
} )( jQuery );

/* Color the closed reason <select> box to match the chosen reason's color. */
( function ( $ ) {
	'use strict';
	function colorize() {
		var $s = $( this );
		var color = $s.find( 'option:selected' ).attr( 'data-color' ) || '';
		$s[ 0 ].style.backgroundColor = color;
		$s.toggleClass( 'rb-has-color', !! color );
	}
	$( 'select.rb-reason-color' ).each( colorize ).on( 'change', colorize );
} )( jQuery );

/* Add tab — autocomplete customers from existing orders. */
( function ( $ ) {
	'use strict';
	var $q = $( '#rb-cust-q' );
	if ( ! $q.length ) {
		return;
	}
	var $results = $( '#rb-cust-results' );
	var emptyText = $results.attr( 'data-empty' ) || '';
	var timer;

	function render( items ) {
		$results.empty();
		if ( ! items || ! items.length ) {
			$results.append( $( '<div class="rb-cust-empty"></div>' ).text( emptyText ) ).show();
			return;
		}
		$.each( items, function ( i, it ) {
			$( '<button type="button" class="rb-cust-item"></button>' )
				.text( it.label )
				.data( 'name', it.name )
				.data( 'phone', it.phone )
				.appendTo( $results );
		} );
		$results.show();
	}

	function search() {
		var q = $.trim( $q.val() || '' );
		if ( q.length < 2 ) { $results.hide().empty(); return; }
		$.post( RiskyBuyerData.ajax_url, { action: 'riskybuyer_search_customers', nonce: RiskyBuyerData.nonce, q: q } )
			.done( function ( r ) { if ( r && r.success && r.data ) { render( r.data.results ); } } );
	}

	$q.on( 'input', function () { clearTimeout( timer ); timer = setTimeout( search, 300 ); } );
	$q.on( 'focus', function () { if ( $results.children().length ) { $results.show(); } } );

	$results.on( 'click', '.rb-cust-item', function () {
		$( '#rb-name' ).val( $( this ).data( 'name' ) || '' );
		$( '#rb-phone' ).val( $( this ).data( 'phone' ) || '' );
		$results.hide().empty();
		$q.val( '' );
		$( '#rb-reason' ).focus();
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.rb-cust-search' ).length ) { $results.hide(); }
	} );
} )( jQuery );
