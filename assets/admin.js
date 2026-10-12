( function () {
	'use strict';
	var i18n = window.USCN || {};
	document.querySelectorAll( 'form.uscn-disconnect' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( i18n.confirmDisconnect || 'Disconnect?' ) ) {
				e.preventDefault();
			}
		} );
	} );
	document.querySelectorAll( '.uscn-select' ).forEach( function ( el ) {
		el.addEventListener( 'click', function () {
			el.select();
		} );
	} );
} )();
