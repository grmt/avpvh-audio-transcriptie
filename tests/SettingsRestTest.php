<?php
/**
 * Settings and license routes.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

final class SettingsRestTest extends RestTestCase {

	public function test_only_administrators_manage_settings(): void {
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->as( $role );
			$this->assertSame( 403, $this->rest( 'GET', '/settings' )->get_status(), $role );
			$this->assertSame( 403, $this->rest( 'POST', '/settings' )->get_status(), $role );
		}
		$this->as( 'guest' );
		$this->assertSame( 401, $this->rest( 'GET', '/settings' )->get_status() );
	}

	public function test_defaults_without_saved_settings(): void {
		$settings = $this->assertRestOk( $this->rest( 'GET', '/settings' ) );

		$this->assertFalse( $settings['license_registered'] );
		$this->assertSame( '', $settings['license_hint'] );
		$this->assertSame( 'faster-whisper', $settings['provider'] );
		$this->assertSame( 'medium', $settings['default_model'] );
		$this->assertSame( 'nl', $settings['default_language'] );
		$this->assertSame( 10, $settings['chunk_minutes'] );
		$this->assertNotSame( '', $settings['summary_procedure_prompt'] );
		$this->assertFalse( $settings['summary_configured'] );
	}

	public function test_secrets_are_masked(): void {
		$this->configure( array( 'summary_api_key' => 'sk-abcdef9876' ) );

		$settings = $this->assertRestOk( $this->rest( 'GET', '/settings' ) );

		$this->assertTrue( $settings['license_registered'] );
		$this->assertSame( '••••1234', $settings['license_hint'] );
		$this->assertTrue( $settings['summary_registered'] );
		$this->assertSame( '••••9876', $settings['summary_key_hint'] );
		$this->assertTrue( $settings['summary_configured'] );
		$this->assertStringNotContainsString( 'secret-key', (string) wp_json_encode( $settings ) );
	}

	public function test_update_stores_and_normalizes_settings(): void {
		$settings = $this->assertRestOk(
			$this->rest(
				'POST',
				'/settings',
				array(
					'service_url'      => self::SERVICE_URL . '/',
					'license_key'      => " key%2Fwith%20encoding\n",
					'provider'         => 'WhisperX',
					'default_model'    => 'large-v3',
					'default_language' => 'EN',
					'default_prompt'   => '<b>Raad</b>',
					'chunk_minutes'    => 500,
					'summary_api_url'  => 'https://example.org/v1/chat/completions',
					'summary_api_key'  => 'sk-1',
					'summary_model'    => 'gpt',
				)
			)
		);

		$saved = get_option( 'avpvh_at_settings' );
		$this->assertSame( self::SERVICE_URL, $saved['service_url'] );
		$this->assertSame( 'key%2Fwith%20encoding', $saved['license_key'] );
		$this->assertSame( 'whisperx', $saved['provider'] );
		$this->assertSame( 'en', $saved['default_language'] );
		$this->assertSame( 'Raad', $saved['default_prompt'] );
		$this->assertSame( 60, $saved['chunk_minutes'] );
		$this->assertSame( 'sk-1', $saved['summary_api_key'] );
		$this->assertSame( 'large-v3', $settings['default_model'] );
		$this->assertTrue( $settings['summary_configured'] );
	}

	public function test_update_keeps_saved_keys_when_left_empty(): void {
		$this->configure( array( 'summary_api_key' => 'sk-old' ) );

		$this->assertRestOk(
			$this->rest(
				'POST',
				'/settings',
				array(
					'service_url'   => self::SERVICE_URL,
					'license_key'   => '',
					'chunk_minutes' => 0,
				)
			)
		);

		$saved = get_option( 'avpvh_at_settings' );
		$this->assertSame( 'secret-key-1234', $saved['license_key'] );
		$this->assertSame( 'sk-old', $saved['summary_api_key'] );
		$this->assertSame( 1, $saved['chunk_minutes'] );
		$this->assertSame( 'faster-whisper', $saved['provider'] );
	}

	/** @return array<string, array{string, string}> */
	public static function invalid_urls(): array {
		return array(
			'service over http' => array( 'service_url', 'http://example.org/service' ),
			'summary over http' => array( 'summary_api_url', 'http://example.org/v1' ),
			'private address'   => array( 'service_url', 'https://127.0.0.1/service' ),
		);
	}

	/**
	 * @dataProvider invalid_urls
	 */
	public function test_update_rejects_unsafe_urls( string $field, string $url ): void {
		$response = $this->rest( 'POST', '/settings', array( $field => $url ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( get_option( 'avpvh_at_settings' ) );
	}

	public function test_validate_license_calls_the_service_with_the_key(): void {
		$this->configure();
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/license/validate' ] = array( 'valid' => true );

		$this->assertSame( array( 'valid' => true ), $this->assertRestOk( $this->rest( 'POST', '/license/validate' ) ) );
		$this->assertSame( 'Bearer secret-key-1234', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_validate_license_without_configuration(): void {
		$this->assertRestError( 400, 'avpvh_at_not_configured', $this->rest( 'POST', '/license/validate' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_service_errors_carry_the_service_message(): void {
		$this->configure();
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/license/validate' ] = self::http_response( 401, array( 'message' => '<b>Onbekende</b> sleutel' ) );

		$response = $this->rest( 'POST', '/license/validate' );

		$this->assertRestError( 502, 'avpvh_at_service_error', $response );
		$this->assertSame( 'Onbekende sleutel', $response->get_data()['message'] );
	}

	public function test_service_errors_without_a_message_report_the_status(): void {
		$this->configure();
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/license/validate' ] = self::http_response( 503, 'Service Unavailable' );

		$this->assertStringContainsString( '503', $this->rest( 'POST', '/license/validate' )->get_data()['message'] );
	}

	public function test_transport_errors_are_returned(): void {
		$this->configure();
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/license/validate' ] = new \WP_Error( 'http_request_failed', 'timeout' );

		$this->assertSame( 'http_request_failed', $this->rest( 'POST', '/license/validate' )->get_data()['code'] );
	}
}
