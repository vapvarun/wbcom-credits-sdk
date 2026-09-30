<?php
/**
 * Test gateway with a settings schema, for Gateway_Settings tests.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways\Fixtures;

use Wbcom\Credits\Gateways\Abstract_Gateway;
use Wbcom\Credits\Gateways\Gateway_Event;

final class Settings_Gateway extends Abstract_Gateway {

	public function get_id(): string {
		return 'fakepay';
	}

	public function get_label(): string {
		return 'FakePay';
	}

	public function is_available(): bool {
		return true;
	}

	public function get_settings_fields(): array {
		return array(
			array( 'key' => 'enabled', 'type' => 'bool', 'label' => 'Enable FakePay' ),
			array(
				'key'     => 'mode',
				'type'    => 'select',
				'label'   => 'Mode',
				'options' => array( 'test' => 'Test', 'live' => 'Live' ),
			),
			array( 'key' => 'public_key', 'type' => 'text', 'label' => 'Public key', 'required' => true ),
			array( 'key' => 'secret', 'type' => 'password', 'label' => 'Secret', 'required' => true ),
			array( 'key' => 'return', 'type' => 'url', 'label' => 'Return URL' ),
		);
	}

	public function create_checkout( string $slug, int $user_id, int $credits, int $price_cents, string $currency = 'USD', ?string $return_url = null ): string {
		return '';
	}

	public function verify_signature( string $raw_body, array $headers ): bool {
		return false;
	}

	public function normalize_event( array $payload ): ?Gateway_Event {
		return null;
	}

	public function refund( string $slug, string $session_id, ?int $amount_cents = null ): bool {
		return false;
	}
}
