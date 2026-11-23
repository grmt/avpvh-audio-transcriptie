<?php
/**
 * Base test case with access to the plugin's private static helpers.
 *
 * @package AVPVH_Audio_Transcriptie
 */

declare(strict_types=1);

namespace AVPVH_AT\Tests;

use AVPVH_AT_Plugin;
use ReflectionMethod;

abstract class TestCase extends \WP_UnitTestCase {

	/** Calls a private static method of the plugin class. */
	protected static function call( string $method, mixed ...$args ): mixed {
		return self::call_with( $method, $args );
	}

	/**
	 * Calls a private static method with a prepared argument list, which may
	 * hold references for helpers that update an argument in place.
	 *
	 * @param array<int, mixed> $args Arguments.
	 */
	protected static function call_with( string $method, array $args ): mixed {
		return ( new ReflectionMethod( AVPVH_AT_Plugin::class, $method ) )->invokeArgs( null, $args );
	}
}
