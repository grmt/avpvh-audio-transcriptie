<?php
/**
 * Base for tests that call the plugin's REST API, with a fake transcription
 * service and summary API behind WordPress' HTTP API.
 *
 * @package AVPVH_Audio_Transcriptie
 */

namespace AVPVH_AT\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

abstract class RestTestCase extends TestCase {

	/** The site's own host, so URL validation needs no DNS. */
	protected const SERVICE_URL = 'https://example.org/service';

	/** @var array<string, int> User IDs by role. */
	protected static array $users = array();

	/**
	 * Fake HTTP responses by "METHOD url": an array body (sent as JSON, status
	 * 200), a callable receiving the request arguments, or a full response.
	 *
	 * @var array<string, mixed>
	 */
	protected array $responses = array();

	/** @var array<int, array{method: string, url: string, args: array<string, mixed>, body: mixed}> */
	protected array $requests = array();

	public static function wpSetUpBeforeClass( $factory ): void {
		foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
			self::$users[ $role ] = $factory->user->create( array( 'role' => $role ) );
		}
	}

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		$this->as( 'administrator' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	protected function as( string $role ): void {
		wp_set_current_user( 'guest' === $role ? 0 : self::$users[ $role ] );
	}

	/** Configures the transcription service (and other settings). */
	protected function configure( array $settings = array() ): void {
		update_option(
			'avpvh_at_settings',
			$settings + array(
				'service_url' => self::SERVICE_URL,
				'license_key' => 'secret-key-1234',
			)
		);
	}

	/**
	 * Answers WordPress HTTP requests from $responses.
	 *
	 * @param false|array<string, mixed> $pre  Short-circuit value.
	 * @param array<string, mixed>       $args Request arguments.
	 * @param string                     $url  Request URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function fake_http( $pre, array $args, string $url ) {
		$method           = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
			'body'   => isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);
		$key              = $method . ' ' . $url;
		if ( ! array_key_exists( $key, $this->responses ) ) {
			$this->fail( 'Unexpected HTTP request: ' . $key );
		}
		$response = $this->responses[ $key ];
		if ( is_callable( $response ) ) {
			$response = $response( $args );
		}
		if ( $response instanceof \WP_Error || isset( $response['response'] ) ) {
			return $response;
		}
		return self::http_response( 200, $response );
	}

	/** @return array<string, mixed> */
	protected static function http_response( int $code, mixed $body ): array {
		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/** Sends a REST request to the plugin's namespace. */
	protected function rest( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/avpvh-audio-transcriptie/v1' . $route );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return rest_do_request( $request );
	}

	/** Asserts an error response with the given status and code. */
	protected function assertRestError( int $status, string $code, WP_REST_Response $response ): void {
		$this->assertSame( $status, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/** Asserts a successful response and returns its data. */
	protected function assertRestOk( WP_REST_Response $response ): mixed {
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * Stores a job row directly.
	 *
	 * @param array<string, mixed> $fields Column overrides; arrays are JSON-encoded.
	 */
	protected function job( array $fields = array() ): int {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$fields = array_map(
			static fn ( $value ) => is_array( $value ) ? wp_json_encode( $value ) : $value,
			$fields + array(
				'service_job_id' => 'svc-' . wp_generate_password( 8, false ),
				'user_id'        => self::$users['administrator'],
				'title'          => 'Vergadering',
				'audio_name'     => 'vergadering.m4a',
				'audio_url'      => 'https://example.org/audio.m4a',
				'status'         => 'complete',
				'progress'       => 100,
				'duration'       => 120,
				'segments'       => array(),
				'deleted_ids'    => array(),
				'revision'       => 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$wpdb->insert( $wpdb->prefix . 'avpvh_at_jobs', $fields );
		return (int) $wpdb->insert_id;
	}

	/** Reads a job row directly. */
	protected function row( int $id ): \stdClass {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}avpvh_at_jobs WHERE id = %d", $id ) );
	}

	/** @return array<int, array<string, mixed>> */
	protected function segments( int $id ): array {
		return json_decode( (string) $this->row( $id )->segments, true );
	}

	/** @return array<string, mixed> */
	protected static function segment( string $id, float $start, string $text, array $extra = array() ): array {
		return $extra + array(
			'id'    => $id,
			'start' => $start,
			'end'   => $start + 1,
			'text'  => $text,
		);
	}
}
