<?php
/**
 * Starts the WordPress test suite with the plugin loaded, on a fresh SQLite
 * database (see wp-tests-config.php).
 *
 * @package AVPVH_Audio_Transcriptie
 */

$avpvh_at_root = dirname( __DIR__ );
require $avpvh_at_root . '/vendor/autoload.php';

// The same directory for this process and the installer it starts.
$avpvh_at_tmp = getenv( 'AVPVH_AT_TESTS_TMP' );
if ( ! $avpvh_at_tmp ) {
	$avpvh_at_tmp = sys_get_temp_dir() . '/avpvh-at-tests-' . getmypid();
	putenv( 'AVPVH_AT_TESTS_TMP=' . $avpvh_at_tmp );
}
@mkdir( $avpvh_at_tmp . '/wp-content', 0777, true );
@unlink( $avpvh_at_tmp . '/database/tests.sqlite' );
// The SQLite drop-in, loaded by WordPress from WP_CONTENT_DIR.
file_put_contents(
	$avpvh_at_tmp . '/wp-content/db.php',
	"<?php\nrequire_once " . var_export( $avpvh_at_root . '/vendor/wordpress/sqlite-database-integration/wp-includes/sqlite/db.php', true ) . ";\n"
);

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $avpvh_at_root . '/vendor/yoast/phpunit-polyfills' );

$avpvh_at_tests_dir = $avpvh_at_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $avpvh_at_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $avpvh_at_root ): void {
		require $avpvh_at_root . '/avpvh-audio-transcriptie.php';
	}
);

require $avpvh_at_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/RestTestCase.php';
