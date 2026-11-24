<?php
/**
 * Schema, hooks, admin page and the public shortcode.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

use AVPVH_AT_Plugin;

final class WordPressIntegrationTest extends RestTestCase {

	public function test_tables_and_schema_version_exist(): void {
		global $wpdb;
		$this->assertSame( '2', get_option( 'avpvh_at_db_version' ) );
		$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}avpvh_at_jobs LIMIT 0" );
		$this->assertContains( 'deleted_ids', (array) $wpdb->get_col_info( 'name' ) );
	}

	public function test_upgrade_runs_only_for_an_older_schema(): void {
		update_option( 'avpvh_at_db_version', '1' );
		AVPVH_AT_Plugin::maybe_upgrade();
		$this->assertSame( '2', get_option( 'avpvh_at_db_version' ) );
	}

	public function test_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( AVPVH_AT_Plugin::class, 'register_menu' ) ) );
		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', array( AVPVH_AT_Plugin::class, 'allow_plugin_read_routes' ) ) );
		$this->assertTrue( shortcode_exists( 'avpvh_audio_transcriptie' ) );
	}

	public function test_admin_page_and_menu(): void {
		AVPVH_AT_Plugin::register_menu();
		$this->assertNotEmpty( menu_page_url( 'avpvh-audio-transcriptie', false ) );

		ob_start();
		AVPVH_AT_Plugin::render_page();
		$this->assertStringContainsString( 'id="avpvh-at-app"', (string) ob_get_clean() );
	}

	public function test_admin_assets_load_only_on_the_plugin_page(): void {
		AVPVH_AT_Plugin::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'avpvh-at-admin', 'enqueued' ) );

		$this->configure( array( 'summary_api_key' => 'sk-hidden' ) );
		$this->as( 'editor' );
		AVPVH_AT_Plugin::enqueue_assets( 'toplevel_page_avpvh-audio-transcriptie' );

		$this->assertTrue( wp_script_is( 'avpvh-at-admin', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'avpvh-at-admin', 'data' );
		$this->assertStringContainsString( '"canPublish":"1"', $data );
		$this->assertStringContainsString( '"canManage":""', $data );
		$this->assertStringContainsString( '"default_model":"medium"', $data );
		$this->assertStringNotContainsString( 'secret-key', $data );
		$this->assertStringNotContainsString( 'sk-hidden', $data );

		$script = wp_scripts()->registered['avpvh-at-admin'];
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'avpvh-audio-transcriptie', $script->textdomain );
		$this->assertSame( AVPVH_AT_DIR . 'languages', $script->translations_path );
	}

	public function test_privacy_policy_text_is_suggested(): void {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );

		// As if called from admin_init, where the plugin hooks it.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		AVPVH_AT_Plugin::add_privacy_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );

		$this->assertStringContainsString( 'transcriptieservice', (string) wp_json_encode( \WP_Privacy_Policy_Content::get_suggested_policy_text() ) );
	}

	public function test_shortcode_for_an_unknown_transcript(): void {
		$this->assertStringContainsString( 'Transcriptie niet gevonden.', do_shortcode( '[avpvh_audio_transcriptie id="999999"]' ) );
		$this->assertStringContainsString( 'Transcriptie niet gevonden.', AVPVH_AT_Plugin::render_shortcode( '' ) );
	}

	public function test_shortcode_renders_an_escaped_filterable_transcript(): void {
		$id = $this->job(
			array(
				'title'    => 'Raad <script>',
				'duration' => 3725,
				'segments' => array(
					self::segment(
						'a',
						65,
						"Welkom <b>allen</b>\nTweede regel",
						array(
							'speaker' => 'Voorzitter',
							'party'   => 'VVD',
						)
					),
					self::segment(
						'b',
						70,
						'Dank',
						array(
							'speaker' => 'anna',
							'party'   => '',
						)
					),
					self::segment( 'c', 75, 'Stilte' ),
				),
			)
		);

		$html = do_shortcode( '[avpvh_audio_transcriptie id="' . $id . '"]' );

		$this->assertStringContainsString( 'Raad &lt;script&gt;', $html );
		$this->assertStringContainsString( '3 fragmenten · 01:02:05', $html );
		$this->assertStringContainsString( 'src="https://example.org/audio.m4a"', $html );
		$this->assertMatchesRegularExpression( '/value="anna".*value="Voorzitter"/s', $html, 'Speakers sorted naturally, case-insensitively.' );
		$this->assertStringContainsString( '<option value="VVD">', $html );
		$this->assertStringContainsString( 'Welkom allen<br />', $html );
		$this->assertStringContainsString( 'data-start="65" data-speaker="Voorzitter" data-party="VVD"', $html );
		$this->assertStringContainsString( '<button type="button" class="avpvh-at-public-time">00:01:05</button>', $html );
		$this->assertSame( 2, substr_count( $html, 'avpvh-at-public-labels' ), 'No label row for unlabelled segments.' );
		$this->assertTrue( wp_script_is( 'avpvh-at-frontend', 'enqueued' ) );
	}

	public function test_shortcode_without_audio_has_no_player(): void {
		$id = $this->job( array( 'audio_url' => '' ) );

		$this->assertStringNotContainsString( '<audio', do_shortcode( '[avpvh_audio_transcriptie id="' . $id . '"]' ) );
	}
}
