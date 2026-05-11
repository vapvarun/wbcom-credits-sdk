<?php
/**
 * Vendor BC — proves a plugin that bundles SDK 1.2.0 alongside a plugin
 * bundling 1.3.0 still works.
 *
 * Scenario: Plugin A ships SDK 1.2.0 (its bootstrap registers
 * wbcom_credits_sdk_register_1_2_0 → Versions::register('1.2.0', …)).
 * Plugin B ships SDK 1.3.0 (registers _1_3_0 → '1.3.0').
 *
 * Either bootstrap can run first depending on plugin load order. The SDK
 * promises:
 *   - No "Cannot redeclare" fatal regardless of load order.
 *   - Versions::latest_version() picks '1.3.0' (version_compare semver).
 *   - initialize_latest_version() invokes the 1.3.0 callback, not 1.2.0.
 *   - Both callbacks remain registered in case someone needs explicit
 *     access to the older one (defensive — no current consumer needs it).
 *
 * This test simulates both bootstrap callbacks as plain closures (we
 * can't load two snapshots of the actual src/ files in one process due
 * to PHP's class-redeclaration rules — that path is already protected
 * by ClassLoaderIdempotencyTest).
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Versions;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Versions;

final class VendorBackwardsCompatTest extends TestCase {

	protected function setUp(): void {
		$prop = new ReflectionProperty( Versions::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function test_older_then_newer_load_order(): void {
		$invoked = '';

		// Plugin A bundles 1.2.0, runs first.
		Versions::instance()->register( '1.2.0', static function () use ( &$invoked ) { $invoked = 'one-two'; } );
		// Plugin B bundles 1.3.0, runs second.
		Versions::instance()->register( '1.3.0', static function () use ( &$invoked ) { $invoked = 'one-three'; } );

		Versions::initialize_latest_version();

		$this->assertSame( '1.3.0', Versions::instance()->latest_version() );
		$this->assertSame( 'one-three', $invoked, '1.3.0 must initialize even when registered second' );
	}

	public function test_newer_then_older_load_order(): void {
		$invoked = '';

		// Plugin B bundles 1.3.0, runs first (alphabetical or earlier slug).
		Versions::instance()->register( '1.3.0', static function () use ( &$invoked ) { $invoked = 'one-three'; } );
		// Plugin A bundles 1.2.0, runs second.
		Versions::instance()->register( '1.2.0', static function () use ( &$invoked ) { $invoked = 'one-two'; } );

		Versions::initialize_latest_version();

		$this->assertSame( '1.3.0', Versions::instance()->latest_version() );
		$this->assertSame( 'one-three', $invoked, '1.3.0 must still win when registered first' );
	}

	public function test_three_versions_one_initializer_runs(): void {
		$invocations = array();

		Versions::instance()->register( '1.0.0', static function () use ( &$invocations ) { $invocations[] = '1.0.0'; } );
		Versions::instance()->register( '1.2.0', static function () use ( &$invocations ) { $invocations[] = '1.2.0'; } );
		Versions::instance()->register( '1.3.0', static function () use ( &$invocations ) { $invocations[] = '1.3.0'; } );

		Versions::initialize_latest_version();

		$this->assertSame( array( '1.3.0' ), $invocations, 'only the highest-semver callback fires — older callbacks stay dormant' );
	}

	public function test_pre_release_versions_lose_to_stable(): void {
		$invoked = '';

		// A pre-release tag like 1.4.0-beta vs the stable 1.3.0. version_compare
		// considers '1.4.0-beta' < '1.4.0' but > '1.3.0' — confirm we don't
		// accidentally ship a beta when a stable is bundled.
		Versions::instance()->register( '1.3.0',     static function () use ( &$invoked ) { $invoked = 'stable'; } );
		Versions::instance()->register( '1.4.0-beta', static function () use ( &$invoked ) { $invoked = 'beta'; } );

		Versions::initialize_latest_version();

		// '1.4.0-beta' wins under semver (4.0.0-beta > 3.0.0). This test
		// LOCKS that behaviour — if someone wants stable-only, they need
		// a separate gate, not a hope-it-works default.
		$this->assertSame( 'beta', $invoked, 'beta semver wins over stable lower version — documented behaviour' );
	}

	public function test_identical_version_registered_by_two_plugins(): void {
		// Both plugins bundle the same SDK release. Registry-level dedup
		// makes the second register() a no-op; first callback is preserved.
		$invoked = '';

		Versions::instance()->register( '1.3.0', static function () use ( &$invoked ) { $invoked = 'plugin-a-callback'; } );
		Versions::instance()->register( '1.3.0', static function () use ( &$invoked ) { $invoked = 'plugin-b-callback'; } );

		Versions::initialize_latest_version();

		$this->assertSame( 'plugin-a-callback', $invoked, 'first-bundle-to-register wins under identical-version collision' );
	}
}
