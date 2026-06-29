/* RiskyBuyer — flags blacklisted customers' rows in the WooCommerce orders list. */
( function () {
	var data = window.RiskybuyerOrdersList || {};
	var MAP = data.map || {};
	var RB_LABEL = data.label || '';

	function rgba( hex, a ) {
		var h = hex.replace( '#', '' );
		return 'rgba(' + parseInt( h.substr( 0, 2 ), 16 ) + ',' + parseInt( h.substr( 2, 2 ), 16 ) + ',' + parseInt( h.substr( 4, 2 ), 16 ) + ',' + a + ')';
	}

	function run() {
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			if ( tr.getAttribute( 'data-rb' ) ) { return; }
			var cb = tr.querySelector( '.check-column input[type=checkbox]' );
			if ( ! cb || ! cb.value ) { return; }
			var info = MAP[ String( cb.value ) ];
			if ( ! info ) { return; }

			tr.setAttribute( 'data-rb', '1' );
			tr.classList.add( 'rb-flag' );
			tr.style.setProperty( '--rb-bd', info.color );
			tr.style.setProperty( '--rb-bg', rgba( info.color, 0.08 ) );

			var cell = tr.querySelector( 'td.column-order_number, td.order_number' ) || tr.querySelectorAll( 'td' )[0];
			if ( cell ) {
				var b = document.createElement( 'span' );
				b.className = 'rb-badge';
				b.style.background = info.color;
				b.textContent = '⚠ ' + RB_LABEL + ' · ' + info.label;
				if ( info.note ) { b.title = info.note; }
				cell.appendChild( document.createElement( 'br' ) );
				cell.appendChild( b );
			}
		} );
	}

	run();
	if ( window.MutationObserver ) {
		var tbody = document.querySelector( '.wp-list-table tbody' );
		if ( tbody ) {
			new MutationObserver( function () { run(); } ).observe( tbody, { childList: true } );
		}
	}
} )();
