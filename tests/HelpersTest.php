<?php
/**
 * Secrets, headers, settings checks and job presentation.
 *
 * @package AVPVH_Audio_Transcriptie
 */

declare(strict_types=1);

namespace AVPVH_AT\Tests;

use stdClass;

final class HelpersTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['avpvh_at_test_transients'] = array();
	}

	public function test_sanitize_secret_keeps_percent_encoding(): void {
		$this->assertSame( 'ab%20cd%2F', self::call( 'sanitize_secret', " ab%20cd%2F\r\n" ) );
		$this->assertSame( 'abc', self::call( 'sanitize_secret', "a\x00b\x7Fc" ) );
		$this->assertSame( '42', self::call( 'sanitize_secret', 42 ) );
		$this->assertSame( '', self::call( 'sanitize_secret', array( 'x' ) ) );
		$this->assertSame( '', self::call( 'sanitize_secret', null ) );
	}

	public function test_sanitize_headers_strips_injection_but_keeps_encoding(): void {
		$headers = array(
			'Content-Type'    => 'audio/mp4',
			'x-amz-meta-name' => "a%2Fb\r\nInjected: 1",
			"Bad Name:\n"     => 'kept under a cleaned name',
			'(){}'            => 'no valid characters',
			'X-Array'         => array( 'not', 'scalar' ),
			'X-Number'        => 5,
		);

		$this->assertSame(
			array(
				'Content-Type'    => 'audio/mp4',
				'x-amz-meta-name' => 'a%2FbInjected: 1',
				'BadName'         => 'kept under a cleaned name',
				'X-Number'        => '5',
			),
			self::call( 'sanitize_headers', $headers )
		);
	}

	public function test_sanitize_headers_ignores_non_arrays(): void {
		$this->assertSame( array(), self::call( 'sanitize_headers', 'Content-Type: text/plain' ) );
	}

	/** @return array<string, array{array<string, string>, bool, bool}> */
	public static function summary_routes(): array {
		$none = array(
			'service_url'     => '',
			'license_key'     => '',
			'summary_api_url' => '',
			'summary_model'   => '',
		);
		return array(
			'nothing configured'   => array( $none, false, false ),
			'AI Client available'  => array( $none, true, true ),
			'direct API and model' => array( array( 'summary_api_url' => 'https://api.example/v1', 'summary_model' => 'm' ) + $none, false, true ),
			'direct API, no model' => array( array( 'summary_api_url' => 'https://api.example/v1' ) + $none, false, false ),
			'service with license' => array( array( 'service_url' => 'https://svc.example', 'license_key' => 'k' ) + $none, false, true ),
			'service, no license'  => array( array( 'service_url' => 'https://svc.example' ) + $none, false, false ),
		);
	}

	/** @param array<string, string> $settings */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'summary_routes' )]
	public function test_summary_configured( array $settings, bool $ai_client, bool $expected ): void {
		$this->assertSame( $expected, self::call( 'summary_configured', $settings, $ai_client ) );
	}

	public function test_format_time(): void {
		$this->assertSame( '00:00:00', self::call( 'format_time', -5.0 ) );
		$this->assertSame( '00:01:05', self::call( 'format_time', 65.9 ) );
		$this->assertSame( '27:46:40', self::call( 'format_time', 100000.0 ) );
	}

	public function test_summary_pending_key_is_per_job(): void {
		$this->assertSame( 'avpvh_at_summary_pending_12', self::call( 'summary_pending_key', 12 ) );
	}

	private static function row(): stdClass {
		return (object) array(
			'id'             => '12',
			'service_job_id' => 'svc-1',
			'title'          => 'Vergadering',
			'audio_name'     => 'vergadering.m4a',
			'audio_url'      => 'https://example.test/a.m4a',
			'status'         => 'complete',
			'progress'       => '100',
			'duration'       => '61.5',
			'segments'       => '[{"id":"a"}]',
			'summary'        => null,
			'revision'       => '3',
			'updated_at'     => '2026-11-23 10:00:00',
		);
	}

	public function test_present_job_casts_types_and_reports_pending_summary(): void {
		$GLOBALS['avpvh_at_test_transients']['avpvh_at_summary_pending_12'] = 1;

		$job = self::call( 'present_job', self::row() );

		$this->assertSame( 12, $job['id'] );
		$this->assertSame( 100.0, $job['progress'] );
		$this->assertSame( 61.5, $job['duration'] );
		$this->assertSame( 3, $job['revision'] );
		$this->assertSame( array( array( 'id' => 'a' ) ), $job['segments'] );
		$this->assertTrue( $job['summary_pending'] );
		$this->assertArrayNotHasKey( 'license_key', $job );
	}

	public function test_present_job_for_the_list_skips_segments(): void {
		$GLOBALS['avpvh_at_test_transients']['avpvh_at_summary_pending_12'] = 1;
		$row           = self::row();
		$row->duration = null;
		unset( $row->segments, $row->summary );

		$job = self::call( 'present_job', $row, false );

		$this->assertSame( array(), $job['segments'] );
		$this->assertNull( $job['duration'] );
		$this->assertNull( $job['summary'] );
		$this->assertFalse( $job['summary_pending'] );
	}
}
