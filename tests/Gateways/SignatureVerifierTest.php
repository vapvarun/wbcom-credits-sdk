<?php
/**
 * Signature_Verifier tests — Stripe HMAC verification, including the
 * 5-minute replay window and tolerance for multiple v1 entries.
 *
 * PayPal's verifier hits a remote endpoint and is tested via the
 * gateway integration suite (out of scope here).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Wbcom\Credits\Gateways\Signature_Verifier;

final class SignatureVerifierTest extends TestCase {

	private const SECRET = 'whsec_test_unit';

	private static function build_header( string $body, string $secret, int $timestamp ): string {
		$payload   = $timestamp . '.' . $body;
		$signature = hash_hmac( 'sha256', $payload, $secret );
		return 't=' . $timestamp . ',v1=' . $signature;
	}

	public function test_valid_signature_is_accepted(): void {
		$body = '{"id":"evt_1","type":"checkout.session.completed"}';
		$now  = time();
		$hdr  = self::build_header( $body, self::SECRET, $now );

		self::assertTrue(
			Signature_Verifier::verify_stripe( $body, $hdr, self::SECRET, $now )
		);
	}

	public function test_tampered_body_is_rejected(): void {
		$body     = '{"id":"evt_1"}';
		$now      = time();
		$hdr      = self::build_header( $body, self::SECRET, $now );
		$tampered = '{"id":"evt_2"}';

		self::assertFalse(
			Signature_Verifier::verify_stripe( $tampered, $hdr, self::SECRET, $now )
		);
	}

	public function test_wrong_secret_is_rejected(): void {
		$body = '{"id":"evt_1"}';
		$now  = time();
		$hdr  = self::build_header( $body, self::SECRET, $now );

		self::assertFalse(
			Signature_Verifier::verify_stripe( $body, $hdr, 'whsec_wrong', $now )
		);
	}

	public function test_old_timestamp_is_rejected(): void {
		$body = '{"id":"evt_1"}';
		$now  = time();
		$old  = $now - 600; // 10 minutes — outside 5-min tolerance.
		$hdr  = self::build_header( $body, self::SECRET, $old );

		self::assertFalse(
			Signature_Verifier::verify_stripe( $body, $hdr, self::SECRET, $now )
		);
	}

	public function test_future_timestamp_is_rejected(): void {
		$body   = '{"id":"evt_1"}';
		$now    = time();
		$future = $now + 600;
		$hdr    = self::build_header( $body, self::SECRET, $future );

		self::assertFalse(
			Signature_Verifier::verify_stripe( $body, $hdr, self::SECRET, $now )
		);
	}

	public function test_multiple_v1_signatures_one_valid(): void {
		// Stripe rotates secrets; some events arrive with both old and
		// new v1 entries. We must accept if any entry validates.
		$body = '{"id":"evt_1"}';
		$now  = time();
		$good = hash_hmac( 'sha256', $now . '.' . $body, self::SECRET );
		$bad  = hash_hmac( 'sha256', $now . '.' . $body, 'whsec_other' );

		$hdr = sprintf( 't=%d,v1=%s,v1=%s', $now, $bad, $good );
		self::assertTrue(
			Signature_Verifier::verify_stripe( $body, $hdr, self::SECRET, $now )
		);
	}

	public function test_empty_inputs_are_rejected(): void {
		self::assertFalse( Signature_Verifier::verify_stripe( '', 't=1,v1=x', self::SECRET ) );
		self::assertFalse( Signature_Verifier::verify_stripe( 'body', '', self::SECRET ) );
		self::assertFalse( Signature_Verifier::verify_stripe( 'body', 't=1,v1=x', '' ) );
	}

	public function test_malformed_header_is_rejected(): void {
		self::assertFalse(
			Signature_Verifier::verify_stripe( 'body', 'not-a-header', self::SECRET )
		);
	}

	public function test_missing_v1_segment_is_rejected(): void {
		self::assertFalse(
			Signature_Verifier::verify_stripe( 'body', 't=' . time(), self::SECRET )
		);
	}
}
