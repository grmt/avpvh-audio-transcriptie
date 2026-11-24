<?php
/**
 * WordPress test suite configuration: WordPress core from Composer, on a
 * throwaway SQLite database, so the tests need no MySQL server.
 *
 * @package AVPVH_Audio_Transcriptie
 */

$avpvh_at_root = dirname( __DIR__ );
// Set by tests/bootstrap.php, also for the installer process it starts.
$avpvh_at_tmp = (string) getenv( 'AVPVH_AT_TESTS_TMP' );

define( 'ABSPATH', $avpvh_at_root . '/vendor/roots/wordpress-no-content/' );
define( 'WP_CONTENT_DIR', $avpvh_at_tmp . '/wp-content' );
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', $avpvh_at_tmp . '/database' );
define( 'DB_FILE', 'tests.sqlite' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

// Required by the test suite; not used by SQLite.
define( 'DB_NAME', 'wordpress_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'avpvh-at-tests' );
define( 'SECURE_AUTH_KEY', 'avpvh-at-tests' );
define( 'LOGGED_IN_KEY', 'avpvh-at-tests' );
define( 'NONCE_KEY', 'avpvh-at-tests' );
define( 'AUTH_SALT', 'avpvh-at-tests' );
define( 'SECURE_AUTH_SALT', 'avpvh-at-tests' );
define( 'LOGGED_IN_SALT', 'avpvh-at-tests' );
define( 'NONCE_SALT', 'avpvh-at-tests' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
