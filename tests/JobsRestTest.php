<?php
/**
 * Creating, listing, reading and refreshing transcription jobs.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

final class JobsRestTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->configure();
	}

	/** @return array<string, mixed> */
	private static function upload(): array {
		return array(
			'filename'     => 'raad 12 mei.m4a',
			'size'         => 1024,
			'content_type' => 'audio/mp4',
		);
	}

	public function test_create_job_starts_a_remote_job_and_stores_it(): void {
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs' ] = array(
			'job_id'         => 'svc-42',
			'upload_url'     => 'https://storage.example.org/upload?sig=a%2Fb',
			'upload_headers' => array( 'Content-Type' => "audio/mp4\r\nX: 1" ),
			'playback_url'   => 'https://storage.example.org/play.m4a',
		);

		$data = $this->assertRestOk( $this->rest( 'POST', '/jobs', self::upload() + array( 'title' => 'Raad' ) ) );

		$this->assertSame( 'svc-42', $data['job']['service_job_id'] );
		$this->assertSame( 'Raad', $data['job']['title'] );
		$this->assertSame( 'uploading', $data['job']['status'] );
		$this->assertSame( 'https://storage.example.org/upload?sig=a%2Fb', $data['upload_url'] );
		$this->assertSame( array( 'Content-Type' => 'audio/mp4X: 1' ), $data['upload_headers'] );

		$sent = $this->requests[0]['body'];
		$this->assertSame( 'raad-12-mei.m4a', $sent['filename'] );
		$this->assertSame( 'faster-whisper', $sent['provider'] );
		$this->assertSame( 'nl', $sent['language'] );
		$this->assertSame( 'medium', $sent['model'] );
		$this->assertSame( 600, $sent['chunk_seconds'] );
		$this->assertSame( self::$users['administrator'], (int) $this->row( $data['job']['id'] )->user_id );
	}

	public function test_create_job_uses_the_request_options_and_filename_as_title(): void {
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs' ] = array(
			'job_id'     => 'svc-43',
			'upload_url' => 'https://storage.example.org/upload',
		);

		$data = $this->assertRestOk(
			$this->rest(
				'POST',
				'/jobs',
				self::upload() + array(
					'language'      => 'en',
					'model'         => 'small',
					'provider'      => 'openai',
					'prompt'        => '',
					'chunk_minutes' => 2,
				)
			)
		);

		$this->assertSame( 'raad-12-mei.m4a', $data['job']['title'] );
		$this->assertSame( array(), $data['upload_headers'] );
		$sent = $this->requests[0]['body'];
		$this->assertSame( array( 'openai', 'en', 'small', '', 120 ), array( $sent['provider'], $sent['language'], $sent['model'], $sent['prompt'], $sent['chunk_seconds'] ) );
	}

	public function test_create_job_rejects_missing_audio(): void {
		$this->assertRestError( 400, 'avpvh_at_bad_audio', $this->rest( 'POST', '/jobs', array( 'filename' => 'a.m4a' ) ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_create_job_rejects_an_incomplete_service_response(): void {
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs' ] = array( 'job_id' => 'svc-44' );

		$this->assertRestError( 502, 'avpvh_at_invalid_service_response', $this->rest( 'POST', '/jobs', self::upload() ) );
	}

	public function test_create_job_returns_service_errors(): void {
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs' ] = self::http_response( 402, array( 'message' => 'Tegoed op' ) );

		$this->assertRestError( 502, 'avpvh_at_service_error', $this->rest( 'POST', '/jobs', self::upload() ) );
	}

	public function test_only_uploaders_create_jobs(): void {
		$this->as( 'subscriber' );

		$this->assertSame( 403, $this->rest( 'POST', '/jobs', self::upload() )->get_status() );
	}

	public function test_list_jobs_for_readers_without_segments(): void {
		$first  = $this->job( array( 'segments' => array( self::segment( 'a', 0, 'A' ) ) ) );
		$second = $this->job( array( 'updated_at' => '2099-01-01 00:00:00' ) );
		$this->as( 'subscriber' );

		$jobs = $this->assertRestOk( $this->rest( 'GET', '/jobs' ) );

		$this->assertSame( array( $second, $first ), array_column( $jobs, 'id' ) );
		$this->assertSame( array(), $jobs[1]['segments'] );
	}

	public function test_guests_cannot_read_jobs(): void {
		$id = $this->job();
		$this->as( 'guest' );

		$this->assertSame( 401, $this->rest( 'GET', '/jobs' )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', '/jobs/' . $id )->get_status() );
	}

	public function test_missing_jobs_are_refused(): void {
		$this->assertSame( 403, $this->rest( 'GET', '/jobs/999999' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/jobs/999999/segments' )->get_status() );
	}

	public function test_read_routes_survive_a_site_wide_rest_restriction(): void {
		$id = $this->job();
		$this->as( 'subscriber' );
		// What a members-only plugin does to the whole REST API.
		add_filter( 'rest_pre_dispatch', static fn () => new \WP_Error( 'rest_forbidden', 'Alleen leden', array( 'status' => 401 ) ) );

		$this->assertSame( 200, $this->rest( 'GET', '/jobs/' . $id )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', '/jobs/' . $id . '/title', array( 'title' => 'x' ) )->get_status() );
		$this->assertSame( 401, rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/users' ) )->get_status() );
	}

	public function test_get_finished_job_does_not_call_the_service(): void {
		$id = $this->job( array( 'segments' => array( self::segment( 'a', 0, 'A' ) ) ) );

		$job = $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id ) );

		$this->assertSame( array( 'a' ), array_column( $job['segments'], 'id' ) );
		$this->assertFalse( $job['summary_pending'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_get_running_job_merges_progress_and_keeps_edits(): void {
		$id = $this->job(
			array(
				'service_job_id' => 'svc/1',
				'status'         => 'transcribing',
				'progress'       => 10,
				'segments'       => array( self::segment( 'a', 0, 'Gecorrigeerd', array( 'manual' => true ) ) ),
				'deleted_ids'    => array( 'b' ),
			)
		);
		$this->responses[ 'GET ' . self::SERVICE_URL . '/v1/jobs/svc%2F1' ] = array(
			'status'       => 'complete',
			'progress'     => 140,
			'duration'     => 75.5,
			'playback_url' => 'https://example.org/new.m4a',
			'segments'     => array(
				self::segment( 'a', 0, 'Ruw' ),
				self::segment( 'b', 1, 'Verwijderd' ),
				self::segment( 'c', 2, 'Nieuw' ),
			),
			'summary'      => '<p>Samenvatting</p><script>x</script>',
		);

		$job = $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id ) );

		$this->assertSame( 'complete', $job['status'] );
		$this->assertSame( 100.0, $job['progress'] );
		$this->assertSame( 75.5, $job['duration'] );
		$this->assertSame( 'https://example.org/new.m4a', $job['audio_url'] );
		$this->assertSame( array( 'Gecorrigeerd', 'Nieuw' ), array_column( $job['segments'], 'text' ) );
		$this->assertSame( '<p>Samenvatting</p>x', $job['summary'] );
		$this->assertSame( 0, $job['revision'], 'Service progress is not an editor revision.' );
	}

	public function test_refresh_merges_onto_a_save_made_during_the_service_call(): void {
		global $wpdb;
		$id = $this->job(
			array(
				'service_job_id' => 'svc-race',
				'status'         => 'transcribing',
				'segments'       => array( self::segment( 'a', 0, 'A' ) ),
			)
		);
		$this->responses[ 'GET ' . self::SERVICE_URL . '/v1/jobs/svc-race' ] = function () use ( $wpdb, $id ): array {
			// An editor saves while the service request is in flight.
			$wpdb->update(
				$wpdb->prefix . 'avpvh_at_jobs',
				array(
					'segments' => wp_json_encode( array( self::segment( 'a', 0, 'Tijdens de aanvraag', array( 'manual' => true ) ) ) ),
					'revision' => 1,
				),
				array( 'id' => $id )
			);
			return array(
				'status'   => 'transcribing',
				'segments' => array( self::segment( 'a', 0, 'A' ), self::segment( 'b', 1, 'B' ) ),
			);
		};

		$job = $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id ) );

		$this->assertSame( array( 'Tijdens de aanvraag', 'B' ), array_column( $job['segments'], 'text' ) );
		$this->assertSame( 1, $job['revision'] );
	}

	public function test_finished_job_fetches_a_pending_summary_only(): void {
		$id = $this->job(
			array(
				'service_job_id' => 'svc-sum',
				'segments'       => array( self::segment( 'a', 0, 'Bewerkt', array( 'manual' => true ) ) ),
			)
		);
		set_transient( 'avpvh_at_summary_pending_' . $id, 1 );
		$this->responses[ 'GET ' . self::SERVICE_URL . '/v1/jobs/svc-sum' ] = array(
			'status'   => 'complete',
			'segments' => array( self::segment( 'a', 0, 'Service' ) ),
			'summary'  => 'Klaar',
		);

		$job = $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id ) );

		$this->assertSame( 'Klaar', $job['summary'] );
		$this->assertFalse( $job['summary_pending'] );
		$this->assertSame( array( 'Bewerkt' ), array_column( $job['segments'], 'text' ) );
		$this->assertFalse( get_transient( 'avpvh_at_summary_pending_' . $id ) );
	}

	public function test_pending_summary_stays_pending_until_it_arrives(): void {
		$id = $this->job( array( 'service_job_id' => 'svc-wait' ) );
		set_transient( 'avpvh_at_summary_pending_' . $id, 1 );
		$this->responses[ 'GET ' . self::SERVICE_URL . '/v1/jobs/svc-wait' ] = array( 'status' => 'complete' );

		$this->assertTrue( $this->assertRestOk( $this->rest( 'GET', '/jobs/' . $id ) )['summary_pending'] );
	}

	public function test_get_job_returns_service_errors(): void {
		$id = $this->job(
			array(
				'service_job_id' => 'svc-err',
				'status'         => 'queued',
			)
		);
		$this->responses[ 'GET ' . self::SERVICE_URL . '/v1/jobs/svc-err' ] = self::http_response( 500, '' );

		$this->assertRestError( 502, 'avpvh_at_service_error', $this->rest( 'GET', '/jobs/' . $id ) );
	}

	public function test_update_title(): void {
		$id = $this->job();

		$this->assertSame( 'Nieuwe naam', $this->assertRestOk( $this->rest( 'POST', '/jobs/' . $id . '/title', array( 'title' => ' <b>Nieuwe</b> naam ' ) ) )['title'] );
		$this->assertRestError( 400, 'avpvh_at_title_required', $this->rest( 'POST', '/jobs/' . $id . '/title', array( 'title' => '  ' ) ) );
	}

	public function test_upload_complete_queues_the_job(): void {
		$id = $this->job(
			array(
				'service_job_id' => 'svc-up',
				'status'         => 'uploading',
			)
		);
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs/svc-up/upload-complete' ] = array( 'ok' => true );

		$this->assertSame( 'queued', $this->assertRestOk( $this->rest( 'POST', '/jobs/' . $id . '/upload-complete' ) )['status'] );
	}

	public function test_upload_complete_keeps_the_status_when_the_service_fails(): void {
		$id = $this->job(
			array(
				'service_job_id' => 'svc-up2',
				'status'         => 'uploading',
			)
		);
		$this->responses[ 'POST ' . self::SERVICE_URL . '/v1/jobs/svc-up2/upload-complete' ] = self::http_response( 409, array( 'message' => 'Upload ontbreekt' ) );

		$this->assertSame( 502, $this->rest( 'POST', '/jobs/' . $id . '/upload-complete' )->get_status() );
		$this->assertSame( 'uploading', $this->row( $id )->status );
	}
}
