<?php
/**
 * Credit paid checkouts nobody claimed.
 *
 * @package Wbcom\Credits
 * @since   1.9.0
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Gateways;

use Wbcom\Credits\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly, every pending checkout older than a few minutes is looked up at its
 * gateway; the paid ones are credited through the same claim path as a
 * buyer's return (session-scoped idempotency, amount and currency checked
 * against the pending entry). Covers the buyer who closes the tab before
 * returning on a site with no webhook, and delayed payment methods whose
 * webhook never arrived.
 *
 * @since 1.9.0
 */
final class Reconciler {

	public const CRON_HOOK = 'wbcom_credits_reconcile_checkouts';

	/**
	 * Sessions looked up per slug per pass (each is one HTTP request).
	 *
	 * @var int
	 */
	private const BATCH = 25;

	/**
	 * Reconcile one slug.
	 *
	 * @since 1.9.0
	 * @param string $slug Plugin slug.
	 * @return int Checkouts credited.
	 */
	public static function run( string $slug ): int {
		$credited = 0;
		foreach ( Pending_Checkouts::oldest( $slug, self::BATCH ) as $pending ) {
			$gateway = Gateway_Registry::for_slug( $slug )->get( $pending['gateway'] );
			if ( ! $gateway instanceof Abstract_Gateway || ! $gateway->is_available() ) {
				continue;
			}
			$response = $gateway->claim_checkout( $slug, $pending['session_id'] );
			if ( 200 === $response->get_status() && empty( $response->get_data()['duplicate'] ) ) {
				++$credited;
			}
		}
		return $credited;
	}

	/**
	 * Cron: every registered slug.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function run_all(): void {
		foreach ( Registry::instance()->get_slugs() as $slug ) {
			self::run( $slug );
		}
	}
}
