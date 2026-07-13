( function () {
	'use strict';
	window.wbcomCreditsCheckout = function ( opts ) {
		var cfg = window.wbcomCreditsCfg || {};
		var slug = opts.slug;
		var gateway = opts.gateway;
		var body = { return_url: opts.returnUrl || window.location.href };
		if ( opts.pack_id ) { body.pack_id = opts.pack_id; }
		else if ( opts.credits ) { body.credits = parseInt( opts.credits, 10 ); }
		return fetch( cfg.restRoot + slug + '/checkout/' + gateway, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				if ( ! r.ok ) { throw { code: data.code || 'error', message: data.message || 'Checkout failed.' }; }
				if ( ! data.url ) { throw { code: 'no_url', message: 'No checkout URL returned.' }; }
				window.location = data.url;
			} );
		} );
	};
}() );
