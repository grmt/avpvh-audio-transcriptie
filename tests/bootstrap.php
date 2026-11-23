<?php
/**
 * Loads the plugin class without WordPress, with stubs for the WordPress
 * functions the tested helpers call.
 *
 * @package AVPVH_Audio_Transcriptie
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );

/** Transients set by a test, keyed by name. */
$GLOBALS['avpvh_at_test_transients'] = array();

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function sanitize_text_field( string $text ): string {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) );
}

function sanitize_textarea_field( string $text ): string {
	return trim( strip_tags( $text ) );
}

function wp_strip_all_tags( string $text ): string {
	return trim( strip_tags( $text ) );
}

function get_transient( string $name ): mixed {
	return $GLOBALS['avpvh_at_test_transients'][ $name ] ?? false;
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {
	}
}

require dirname( __DIR__ ) . '/includes/class-avpvh-at-plugin.php';
require __DIR__ . '/TestCase.php';
