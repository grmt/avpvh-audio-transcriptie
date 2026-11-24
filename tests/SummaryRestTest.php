<?php
/**
 * Summaries through the summary API and the transcription service.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

final class SummaryRestTest extends RestTestCase {

	private const API_URL = 'https://example.org/v1/chat/completions';

	private int $id;

	public function set_up(): void {
		parent::set_up();
		$this->id = $this->job(
			array(
				'service_job_id' => 'svc-s',
				'segments'       => array(
					self::segment(
						'a',
						65,
						'Welkom',
						array(
							'speaker' => 'Voorzitter',
							'party'   => 'VVD',
						)
					),
					self::segment( 'b', 70, 'Dank' ),
				),
			)
		);
	}

	/** @param array<string, mixed> $params */
	private function summarize( array $params = array() ): \WP_REST_Response {
		return $this->rest( 'POST', '/jobs/' . $this->id . '/summary', $params );
	}

	public function test_without_any_summary_route(): void {
		$this->assertRestError( 400, 'avpvh_at_summary_not_configured', $this->summarize() );
	}

	public function test_summary_api_receives_prompts_and_transcript(): void {
		update_option(
			'avpvh_at_settings',
			array(
				'summary_api_url' => self::API_URL,
				'summary_api_key' => 'sk-1',
				'summary_model'   => 'model-x',
			)
		);
		$this->responses[ 'POST ' . self::API_URL ] = array( 'choices' => array( array( 'message' => array( 'content' => "Punt <één>\n\nPunt twee" ) ) ) );

		$result = $this->assertRestOk(
			$this->summarize(
				array(
					'procedure_prompt' => 'Wees kort',
					'content_prompt'   => 'Begroting',
				)
			)
		);

		$this->assertSame( "<p>Punt &lt;één&gt;</p>\n<p>Punt twee</p>\n", $result['summary'] );
		$this->assertSame( $result['summary'], $this->row( $this->id )->summary );
		$request = $this->requests[0];
		$this->assertSame( 'Bearer sk-1', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'model-x', $request['body']['model'] );
		$this->assertSame( 'Wees kort', $request['body']['messages'][0]['content'] );
		$this->assertSame(
			"Inhoudelijke toelichting:\nBegroting\n\nTranscriptie:\n[00:01:05] Voorzitter (VVD): Welkom\n[00:01:10] Dank",
			$request['body']['messages'][1]['content']
		);
	}

	public function test_summary_api_uses_saved_prompts_and_no_key(): void {
		update_option(
			'avpvh_at_settings',
			array(
				'summary_api_url'          => self::API_URL,
				'summary_model'            => 'model-x',
				'summary_procedure_prompt' => '',
				'summary_content_prompt'   => '',
			)
		);
		$this->responses[ 'POST ' . self::API_URL ] = array( 'choices' => array( array( 'message' => array( 'content' => 'Kort' ) ) ) );

		$this->assertRestOk( $this->summarize() );

		$request = $this->requests[0];
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
		$this->assertNotSame( '', $request['body']['messages'][0]['content'] );
		$this->assertStringStartsWith( 'Transcriptie:', $request['body']['messages'][1]['content'] );
	}

	public function test_legacy_prompt_parameter_is_the_procedure_prompt(): void {
		update_option(
			'avpvh_at_settings',
			array(
				'summary_api_url' => self::API_URL,
				'summary_model'   => 'm',
			)
		);
		$this->responses[ 'POST ' . self::API_URL ] = array( 'choices' => array( array( 'message' => array( 'content' => 'Kort' ) ) ) );

		$this->assertRestOk( $this->summarize( array( 'prompt' => 'Oude instructie' ) ) );

		$this->assertSame( 'Oude instructie', $this->requests[0]['body']['messages'][0]['content'] );
	}

	public function test_summary_api_needs_a_model(): void {
		update_option( 'avpvh_at_settings', array( 'summary_api_url' => self::API_URL ) );

		$this->assertRestError( 400, 'avpvh_at_summary_model_missing', $this->summarize() );
	}

	public function test_summary_api_errors(): void {
		update_option(
			'avpvh_at_settings',
			array(
				'summary_api_url' => self::API_URL,
				'summary_model'   => 'm',
			)
		);

		$this->responses[ 'POST ' . self::API_URL ] = self::http_response( 429, array( 'error' => array( 'message' => 'Te veel aanvragen' ) ) );
		$response                                   = $this->summarize();
		$this->assertRestError( 502, 'avpvh_at_summary_service_error', $response );
		$this->assertSame( 'Te veel aanvragen', $response->get_data()['message'] );

		$this->responses[ 'POST ' . self::API_URL ] = self::http_response( 500, 'kapot' );
		$this->assertStringContainsString( '500', $this->summarize()->get_data()['message'] );

		$this->responses[ 'POST ' . self::API_URL ] = array( 'choices' => array() );
		$this->assertRestError( 502, 'avpvh_at_summary_empty', $this->summarize() );

		$this->responses[ 'POST ' . self::API_URL ] = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame( 'http_request_failed', $this->summarize()->get_data()['code'] );

		$this->assertNull( $this->row( $this->id )->summary );
	}

	public function test_service_summary_in_the_background(): void {
		$this->configure();
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs/svc-s/summary' ] = array( 'accepted' => true );

		$result = $this->assertRestOk(
			$this->summarize(
				array(
					'procedure_prompt' => 'P',
					'content_prompt'   => 'C',
				)
			)
		);

		$this->assertTrue( $result['summary_pending'] );
		$this->assertSame( "P\n\nC", $this->requests[0]['body']['prompt'] );
		$this->assertCount( 2, $this->requests[0]['body']['segments'] );

		// Reading the job polls the service for the summary.
		$this->responses[ 'GET ' . self::SERVICE_URL . '/v1/jobs/svc-s' ] = array( 'status' => 'complete' );
		$this->assertTrue( $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $this->id ) )['summary_pending'] );
	}

	public function test_service_summary_returned_at_once(): void {
		$this->configure();
		set_transient( 'avpvh_at_summary_pending_' . $this->id, 1 );
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs/svc-s/summary' ] = array( 'summary' => '<p>Direct</p>' );

		$this->assertSame( '<p>Direct</p>', $this->assertRestOk( $this->summarize() )['summary'] );
		$this->assertFalse( get_transient( 'avpvh_at_summary_pending_' . $this->id ) );
	}

	public function test_service_summary_errors_are_returned(): void {
		$this->configure();
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs/svc-s/summary' ] = self::http_response( 500, '' );

		$this->assertSame( 502, $this->summarize()->get_status() );
		$this->assertFalse( get_transient( 'avpvh_at_summary_pending_' . $this->id ) );
	}
}
