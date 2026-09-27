( function () {
	'use strict';

	var cfg = window.wbcomCreditsCfg || {};

	function post( path, body ) {
		return fetch( cfg.restRoot + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data || {} };
			} );
		} );
	}

	/**
	 * Start a checkout.
	 *
	 * opts: slug, gateway, pack_id | credits, returnUrl, billing (object keyed
	 * by billing_* field), coupon. Rejects with { code, message, fields } -
	 * `fields` lists missing billing keys on billing_incomplete.
	 */
	window.wbcomCreditsCheckout = function ( opts ) {
		var body = { return_url: opts.returnUrl || window.location.href };
		if ( opts.pack_id ) { body.pack_id = opts.pack_id; }
		else if ( opts.credits ) { body.credits = parseInt( opts.credits, 10 ); }
		if ( opts.billing ) { body.billing = opts.billing; }
		if ( opts.coupon ) { body.coupon = opts.coupon; }

		return post( opts.slug + '/checkout/' + opts.gateway, body ).then( function ( res ) {
			if ( ! res.ok ) {
				throw {
					code: res.data.code || 'error',
					message: res.data.message || 'Checkout failed.',
					fields: ( res.data.data && res.data.data.fields ) || []
				};
			}
			if ( ! res.data.url ) { throw { code: 'no_url', message: 'No checkout URL returned.' }; }
			window.location = res.data.url;
		} );
	};

	/**
	 * Credit a paid checkout on return from the gateway, without waiting for a
	 * webhook (sites without one never credited). Resolves with the claim
	 * response data, or null when this page is not a checkout return.
	 */
	window.wbcomCreditsClaim = function ( slug ) {
		var params  = new URLSearchParams( window.location.search );
		var session = params.get( 'session_id' ) || params.get( 'token' );
		var gateway = params.get( 'gateway' );
		if ( 'success' !== params.get( 'wbcom_credits' ) || ! session || ! gateway ) {
			return Promise.resolve( null );
		}
		return post( slug + '/claim/' + gateway, { session_id: session } ).then( function ( res ) {
			return res.data;
		} );
	};
}() );
