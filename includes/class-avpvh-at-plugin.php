<?php
/**
 * Main plugin class.
 *
 * @package AVPVH_Audio_Transcriptie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates storage, REST endpoints, the remote service and the admin editor.
 */
final class AVPVH_AT_Plugin {

	/** REST namespace. */
	private const REST_NAMESPACE = 'avpvh-audio-transcriptie/v1';

	/** Maximum retained revisions per job. */
	private const MAX_REVISIONS = 50;

	/**
	 * Creates plugin tables.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$jobs_table      = self::jobs_table();
		$revisions_table = self::revisions_table();

		dbDelta(
			"CREATE TABLE {$jobs_table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				service_job_id varchar(191) NOT NULL,
				user_id bigint unsigned NOT NULL,
				title varchar(255) NOT NULL,
				audio_name varchar(255) NOT NULL,
				audio_url text NULL,
				status varchar(32) NOT NULL DEFAULT 'created',
				progress decimal(5,2) NOT NULL DEFAULT 0,
				duration decimal(12,3) NULL,
				segments longtext NULL,
				summary longtext NULL,
				revision bigint unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY service_job_id (service_job_id),
				KEY user_id (user_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$revisions_table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint unsigned NOT NULL,
				revision bigint unsigned NOT NULL,
				user_id bigint unsigned NOT NULL,
				segments longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY job_revision (job_id, revision)
			) {$charset_collate};"
		);
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_shortcode( 'avpvh_audio_transcriptie', array( self::class, 'render_shortcode' ) );
	}

	/**
	 * Renders a read-only transcript with audio, search and label filters.
	 *
	 * Adding the shortcode to a page intentionally publishes that transcript to
	 * everyone who can view the page. Page-level access restrictions remain in
	 * control of WordPress and any access-control plugin used by the site.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( array|string $attributes ): string {
		$attributes = shortcode_atts(
			array( 'id' => 0 ),
			is_array( $attributes ) ? $attributes : array(),
			'avpvh_audio_transcriptie'
		);
		$job_id     = absint( $attributes['id'] );
		$row        = self::get_row( $job_id );
		if ( null === $row ) {
			return '<p>' . esc_html__( 'Transcriptie niet gevonden.', 'avpvh-audio-transcriptie' ) . '</p>';
		}

		$segments = self::decode_segments( $row->segments );
		$speakers = array();
		$parties  = array();
		foreach ( $segments as $segment ) {
			$speaker = sanitize_text_field( (string) ( $segment['speaker'] ?? '' ) );
			$party   = sanitize_text_field( (string) ( $segment['party'] ?? '' ) );
			if ( '' !== $speaker ) {
				$speakers[ $speaker ] = true;
			}
			if ( '' !== $party ) {
				$parties[ $party ] = true;
			}
		}
		ksort( $speakers, SORT_NATURAL | SORT_FLAG_CASE );
		ksort( $parties, SORT_NATURAL | SORT_FLAG_CASE );

		wp_enqueue_style( 'avpvh-at-frontend', AVPVH_AT_URL . 'assets/frontend.css', array(), AVPVH_AT_VERSION );
		wp_enqueue_script( 'avpvh-at-frontend', AVPVH_AT_URL . 'assets/frontend.js', array(), AVPVH_AT_VERSION, true );

		$instance = wp_unique_id( 'avpvh-at-public-' );
		ob_start();
		?>
		<section id="<?php echo esc_attr( $instance ); ?>" class="avpvh-at-public" data-job-id="<?php echo esc_attr( (string) $job_id ); ?>">
			<header class="avpvh-at-public-header">
				<h2><?php echo esc_html( $row->title ); ?></h2>
				<p class="avpvh-at-public-summary">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %1$d is a segment count, %2$s is a duration. */
							__( '%1$d fragmenten · %2$s', 'avpvh-audio-transcriptie' ),
							count( $segments ),
							self::format_time( (float) $row->duration )
						)
					);
					?>
				</p>
				<?php if ( '' !== (string) $row->audio_url ) : ?>
					<audio class="avpvh-at-public-player" controls preload="metadata" src="<?php echo esc_url( $row->audio_url ); ?>"></audio>
				<?php endif; ?>
				<div class="avpvh-at-public-controls">
					<label>
						<span><?php echo esc_html__( 'Zoeken', 'avpvh-audio-transcriptie' ); ?></span>
						<input class="avpvh-at-public-search" type="search" placeholder="<?php echo esc_attr__( 'Zoeken in uitspraken…', 'avpvh-audio-transcriptie' ); ?>">
					</label>
					<label>
						<span><?php echo esc_html__( 'Spreker', 'avpvh-audio-transcriptie' ); ?></span>
						<select class="avpvh-at-public-speaker">
							<option value=""><?php echo esc_html__( 'Alle sprekers', 'avpvh-audio-transcriptie' ); ?></option>
							<?php foreach ( array_keys( $speakers ) as $speaker ) : ?>
								<option value="<?php echo esc_attr( $speaker ); ?>"><?php echo esc_html( $speaker ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__( 'Partij', 'avpvh-audio-transcriptie' ); ?></span>
						<select class="avpvh-at-public-party">
							<option value=""><?php echo esc_html__( 'Alle partijen', 'avpvh-audio-transcriptie' ); ?></option>
							<?php foreach ( array_keys( $parties ) as $party ) : ?>
								<option value="<?php echo esc_attr( $party ); ?>"><?php echo esc_html( $party ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</header>
			<div class="avpvh-at-public-segments">
				<?php foreach ( $segments as $segment ) : ?>
					<?php
					$speaker    = sanitize_text_field( (string) ( $segment['speaker'] ?? '' ) );
					$party      = sanitize_text_field( (string) ( $segment['party'] ?? '' ) );
					$text       = sanitize_textarea_field( (string) ( $segment['text'] ?? '' ) );
					$searchable = $speaker . ' ' . $party . ' ' . $text;
					?>
					<article class="avpvh-at-public-segment" data-start="<?php echo esc_attr( (string) (float) ( $segment['start'] ?? 0 ) ); ?>" data-speaker="<?php echo esc_attr( $speaker ); ?>" data-party="<?php echo esc_attr( $party ); ?>" data-search="<?php echo esc_attr( $searchable ); ?>">
						<button type="button" class="avpvh-at-public-time"><?php echo esc_html( self::format_time( (float) ( $segment['start'] ?? 0 ) ) ); ?></button>
						<div>
							<?php if ( '' !== $speaker || '' !== $party ) : ?>
								<p class="avpvh-at-public-labels">
									<?php if ( '' !== $speaker ) : ?>
										<span><?php echo esc_html( $speaker ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $party ) : ?>
										<span class="is-party"><?php echo esc_html( $party ); ?></span>
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<p class="avpvh-at-public-text"><?php echo nl2br( esc_html( $text ) ); ?></p>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Adds suggested privacy-policy text for site administrators.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = wpautop(
			esc_html__(
				'When an authorized user starts an audio transcription, the selected recording, file metadata, transcript segments, speaker labels, group labels, and optional summary instruction are sent to the transcription service configured by this site administrator. Transcript text and up to 50 correction revisions are stored in the WordPress database. Consult the configured service provider for its retention, privacy, and deletion terms.',
				'avpvh-audio-transcriptie'
			),
			false
		);
		wp_add_privacy_policy_content( 'AVPVH Audio Transcriptie', $content );
	}

	/**
	 * Registers the admin page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			esc_html__( 'Audio Transcripties', 'avpvh-audio-transcriptie' ),
			esc_html__( 'Transcripties', 'avpvh-audio-transcriptie' ),
			'read',
			'avpvh-audio-transcriptie',
			array( self::class, 'render_page' ),
			'dashicons-controls-volumeon',
			31
		);
	}

	/**
	 * Renders the editor mount point.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Audio Transcripties', 'avpvh-audio-transcriptie' ); ?></h1>
			<div id="avpvh-at-app">
				<p><?php echo esc_html__( 'Editor laden…', 'avpvh-audio-transcriptie' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Loads admin assets only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_avpvh-audio-transcriptie' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'avpvh-at-admin', AVPVH_AT_URL . 'assets/admin.css', array(), AVPVH_AT_VERSION );
		wp_enqueue_script( 'avpvh-at-admin', AVPVH_AT_URL . 'assets/admin.js', array(), AVPVH_AT_VERSION, true );
		wp_localize_script(
			'avpvh-at-admin',
			'avpvhAudioTranscriptie',
			array(
				'root'      => esc_url_raw( rest_url( self::REST_NAMESPACE . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'canManage' => current_user_can( 'manage_options' ),
				'canEdit'   => current_user_can( 'upload_files' ),
				'i18n'      => array(
					'requestFailed' => esc_html__( 'De aanvraag is mislukt.', 'avpvh-audio-transcriptie' ),
				),
			)
		);
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_settings' ),
					'permission_callback' => array( self::class, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/license/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'validate_license' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_jobs' ),
					'permission_callback' => array( self::class, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_job' ),
					'permission_callback' => array( self::class, 'can_upload' ),
				),
			)
		);

		$job_routes = array(
			''                 => array( WP_REST_Server::READABLE, 'get_job', 'can_view_job' ),
			'/upload-complete' => array( WP_REST_Server::CREATABLE, 'upload_complete', 'can_edit_job' ),
			'/segments'        => array( WP_REST_Server::EDITABLE, 'save_segments', 'can_edit_job' ),
			'/history'         => array( WP_REST_Server::READABLE, 'history', 'can_view_job' ),
			'/summary'         => array( WP_REST_Server::CREATABLE, 'create_summary', 'can_edit_job' ),
		);

		foreach ( $job_routes as $suffix => $route ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/jobs/(?P<id>\d+)' . $suffix,
				array(
					'methods'             => $route[0],
					'callback'            => array( self::class, $route[1] ),
					'permission_callback' => array( self::class, $route[2] ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
							'validate_callback' => static function ( $value ): bool {
								return 0 < absint( $value );
							},
						),
					),
				)
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/restore/(?P<revision>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'restore_revision' ),
				'permission_callback' => array( self::class, 'can_edit_job' ),
				'args'                => array(
					'id'       => array( 'sanitize_callback' => 'absint' ),
					'revision' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/** Whether the user may manage settings. */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/** Whether the user may create jobs. */
	public static function can_upload(): bool {
		return current_user_can( 'upload_files' );
	}

	/** Whether the signed-in user may view transcripts. */
	public static function can_read(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	/**
	 * Checks whether a signed-in user may view an existing job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_view_job( WP_REST_Request $request ): bool {
		return self::can_read() && null !== self::get_row( absint( $request['id'] ) );
	}

	/**
	 * Checks whether the user may modify an existing job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_edit_job( WP_REST_Request $request ): bool {
		return self::can_upload() && null !== self::get_row( absint( $request['id'] ) );
	}

	/** Returns public settings without exposing the secret. */
	public static function get_settings(): array {
		$settings = self::settings();
		return array(
			'service_url'        => $settings['service_url'],
			'license_registered' => '' !== $settings['license_key'],
			'license_hint'       => '' !== $settings['license_key'] ? '••••' . substr( $settings['license_key'], -4 ) : '',
		);
	}

	/**
	 * Stores service configuration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ): array|WP_Error {
		if ( defined( 'AVPVH_AT_LICENSE_KEY' ) ) {
			return new WP_Error( 'avpvh_at_constant_key', __( 'De servicesleutel is via wp-config.php ingesteld.', 'avpvh-audio-transcriptie' ), array( 'status' => 409 ) );
		}

		$current     = self::settings();
		$service_url = untrailingslashit( esc_url_raw( (string) $request->get_param( 'service_url' ) ) );
		$license_key = trim( sanitize_text_field( (string) $request->get_param( 'license_key' ) ) );
		$license_key = '' !== $license_key ? $license_key : $current['license_key'];
		if ( '' !== $service_url && ( ! wp_http_validate_url( $service_url ) || 'https' !== wp_parse_url( $service_url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'avpvh_at_invalid_service_url', __( 'Gebruik een geldige HTTPS-service-URL.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		update_option(
			'avpvh_at_settings',
			array(
				'service_url' => $service_url,
				'license_key' => $license_key,
			),
			false
		);

		return self::get_settings();
	}

	/** Validates the configured service key. */
	public static function validate_license(): array|WP_Error {
		return self::service_request( 'POST', '/v1/license/validate', array() );
	}

	/** Lists jobs visible to the current user. */
	public static function list_jobs(): array {
		global $wpdb;

		$table = self::jobs_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.

		return array_map(
			static function ( object $row ): array {
				return self::present_job( $row, false );
			},
			$rows
		);
	}

	/**
	 * Creates a remote and local transcription job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$language = $request->get_param( 'language' );
		$model    = $request->get_param( 'model' );
		$title    = $request->get_param( 'title' );

		$payload = array(
			'filename'     => sanitize_file_name( (string) $request->get_param( 'filename' ) ),
			'size'         => absint( $request->get_param( 'size' ) ),
			'content_type' => sanitize_mime_type( (string) $request->get_param( 'content_type' ) ),
			'language'     => sanitize_key( (string) ( $language ? $language : 'nl' ) ),
			'model'        => sanitize_key( (string) ( $model ? $model : 'medium' ) ),
		);

		if ( '' === $payload['filename'] || 1 > $payload['size'] ) {
			return new WP_Error( 'avpvh_at_bad_audio', __( 'Ongeldig audiobestand.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		$remote = self::service_request( 'POST', '/v1/jobs', $payload );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( empty( $remote['job_id'] ) || empty( $remote['upload_url'] ) ) {
			return new WP_Error( 'avpvh_at_invalid_service_response', __( 'De transcriptieservice gaf een onvolledig antwoord.', 'avpvh-audio-transcriptie' ), array( 'status' => 502 ) );
		}

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::jobs_table(),
			array(
				'service_job_id' => sanitize_text_field( (string) $remote['job_id'] ),
				'user_id'        => get_current_user_id(),
				'title'          => sanitize_text_field( (string) ( $title ? $title : $payload['filename'] ) ),
				'audio_name'     => $payload['filename'],
				'audio_url'      => esc_url_raw( (string) ( $remote['playback_url'] ?? '' ) ),
				'status'         => 'uploading',
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$row = self::get_row( (int) $wpdb->insert_id );
		return array(
			'job'            => self::present_job( $row ),
			'upload_url'     => esc_url_raw( (string) $remote['upload_url'] ),
			'upload_headers' => self::sanitize_headers( $remote['upload_headers'] ?? array() ),
		);
	}

	/**
	 * Returns one job and refreshes its background state.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ): array|WP_Error {
		$row = self::refresh_job( self::get_row( absint( $request['id'] ) ) );
		return is_wp_error( $row ) ? $row : self::present_job( $row );
	}

	/**
	 * Marks a browser-to-service upload as complete.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function upload_complete( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$row    = self::get_row( absint( $request['id'] ) );
		$remote = self::service_request( 'POST', '/v1/jobs/' . rawurlencode( $row->service_job_id ) . '/upload-complete', array() );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$wpdb->update(
			self::jobs_table(),
			array(
				'status'     => 'queued',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $row->id )
		);

		return self::present_job( self::get_row( (int) $row->id ) );
	}

	/**
	 * Saves corrected segments with optimistic locking.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function save_segments( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$row      = self::get_row( absint( $request['id'] ) );
		$revision = absint( $request->get_param( 'revision' ) );
		$segments = $request->get_param( 'segments' );
		$dirty    = array_map( 'strval', (array) $request->get_param( 'dirty_ids' ) );

		if ( $revision !== (int) $row->revision ) {
			return new WP_Error(
				'avpvh_at_revision_conflict',
				__( 'Er is een nieuwere versie. Herlaad de transcriptie om wijzigingen samen te voegen.', 'avpvh-audio-transcriptie' ),
				array(
					'status'  => 409,
					'current' => self::present_job( $row ),
				)
			);
		}

		if ( ! is_array( $segments ) || 100000 < count( $segments ) ) {
			return new WP_Error( 'avpvh_at_bad_segments', __( 'Ongeldige segmenten.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		$existing = array();
		foreach ( self::decode_segments( $row->segments ) as $segment ) {
			$existing[ (string) ( $segment['id'] ?? '' ) ] = $segment;
		}

		$clean = array();
		foreach ( $segments as $segment ) {
			$id      = sanitize_text_field( (string) ( $segment['id'] ?? '' ) );
			$clean[] = array(
				'id'       => $id,
				'start'    => max( 0, (float) ( $segment['start'] ?? 0 ) ),
				'end'      => max( 0, (float) ( $segment['end'] ?? 0 ) ),
				'text'     => sanitize_textarea_field( (string) ( $segment['text'] ?? '' ) ),
				'speaker'  => sanitize_text_field( (string) ( $segment['speaker'] ?? '' ) ),
				'party'    => sanitize_text_field( (string) ( $segment['party'] ?? '' ) ),
				'manual'   => in_array( $id, $dirty, true ) || ! empty( $existing[ $id ]['manual'] ),
				'inserted' => ! empty( $segment['inserted'] ),
			);
		}

		self::store_revision( $row );
		$new_revision = $revision + 1;
		$wpdb->update(
			self::jobs_table(),
			array(
				'segments'   => wp_json_encode( $clean ),
				'revision'   => $new_revision,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $row->id )
		);
		self::trim_revisions( (int) $row->id );

		return array(
			'ok'       => true,
			'revision' => $new_revision,
		);
	}

	/**
	 * Returns revision metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	public static function history( WP_REST_Request $request ): array {
		global $wpdb;
		$table = self::revisions_table();

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT id, revision, user_id, created_at FROM {$table} WHERE job_id = %d ORDER BY id DESC LIMIT %d", absint( $request['id'] ), self::MAX_REVISIONS ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
			ARRAY_A
		);
	}

	/**
	 * Restores a prior revision and records the current one first.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function restore_revision( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$job   = self::get_row( absint( $request['id'] ) );
		$table = self::revisions_table();
		$old   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND revision = %d", $job->id, absint( $request['revision'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
		);

		if ( null === $old ) {
			return new WP_Error( 'avpvh_at_revision_missing', __( 'Versie niet gevonden.', 'avpvh-audio-transcriptie' ), array( 'status' => 404 ) );
		}

		self::store_revision( $job );
		$wpdb->update(
			self::jobs_table(),
			array(
				'segments'   => $old->segments,
				'revision'   => (int) $job->revision + 1,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $job->id )
		);

		return self::present_job( self::get_row( (int) $job->id ) );
	}

	/**
	 * Requests an optional summary from the configured service.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 */
	public static function create_summary( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$row    = self::get_row( absint( $request['id'] ) );
		$remote = self::service_request(
			'POST',
			'/v1/jobs/' . rawurlencode( $row->service_job_id ) . '/summary',
			array(
				'segments' => self::decode_segments( $row->segments ),
				'prompt'   => sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ),
			)
		);

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( isset( $remote['summary'] ) ) {
			$remote['summary'] = wp_kses_post( $remote['summary'] );
			$wpdb->update(
				self::jobs_table(),
				array(
					'summary'    => $remote['summary'],
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $row->id )
			);
		}

		return $remote;
	}

	/**
	 * Refreshes a job while preserving manual edits.
	 *
	 * @param object|null $row Job row.
	 * @return object Job row or WP_Error instance.
	 */
	private static function refresh_job( ?object $row ): object {
		if ( null === $row ) {
			return new WP_Error( 'avpvh_at_job_missing', __( 'Transcriptie niet gevonden.', 'avpvh-audio-transcriptie' ), array( 'status' => 404 ) );
		}

		if ( in_array( $row->status, array( 'complete', 'failed' ), true ) ) {
			return $row;
		}

		$remote = self::service_request( 'GET', '/v1/jobs/' . rawurlencode( $row->service_job_id ) );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$update = array(
			'status'     => sanitize_key( (string) ( $remote['status'] ?? $row->status ) ),
			'progress'   => min( 100, max( 0, (float) ( $remote['progress'] ?? $row->progress ) ) ),
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( isset( $remote['duration'] ) ) {
			$update['duration'] = max( 0, (float) $remote['duration'] );
		}
		if ( isset( $remote['playback_url'] ) ) {
			$update['audio_url'] = esc_url_raw( (string) $remote['playback_url'] );
		}
		if ( isset( $remote['segments'] ) && is_array( $remote['segments'] ) ) {
			$update['segments'] = wp_json_encode( self::merge_segments( self::decode_segments( $row->segments ), $remote['segments'] ) );
		}
		if ( isset( $remote['summary'] ) ) {
			$update['summary'] = wp_kses_post( $remote['summary'] );
		}

		global $wpdb;
		$wpdb->update( self::jobs_table(), $update, array( 'id' => $row->id ) );
		return self::get_row( (int) $row->id );
	}

	/**
	 * Merges remote segments with locally corrected records.
	 *
	 * @param array $local_segments  Locally stored segments.
	 * @param array $remote_segments Service-provided segments.
	 * @return array
	 */
	private static function merge_segments( array $local_segments, array $remote_segments ): array {
		$local = array();
		foreach ( $local_segments as $segment ) {
			$local[ (string) ( $segment['id'] ?? '' ) ] = $segment;
		}

		$merged = array();
		foreach ( $remote_segments as $remote ) {
			$id = sanitize_text_field( (string) ( $remote['id'] ?? '' ) );
			if ( isset( $local[ $id ] ) && ! empty( $local[ $id ]['manual'] ) ) {
				foreach ( array( 'text', 'speaker', 'party', 'manual' ) as $field ) {
					$remote[ $field ] = $local[ $id ][ $field ] ?? '';
				}
			}
			$merged[] = self::sanitize_remote_segment( $remote );
			unset( $local[ $id ] );
		}

		foreach ( $local as $segment ) {
			if ( ! empty( $segment['manual'] ) && ! empty( $segment['inserted'] ) ) {
				$merged[] = self::sanitize_remote_segment( $segment );
			}
		}

		usort(
			$merged,
			static function ( array $left, array $right ): int {
				return $left['start'] <=> $right['start'];
			}
		);
		return $merged;
	}

	/**
	 * Sanitizes one service segment.
	 *
	 * @param array $segment Untrusted service segment.
	 * @return array
	 */
	private static function sanitize_remote_segment( array $segment ): array {
		return array(
			'id'       => sanitize_text_field( (string) ( $segment['id'] ?? '' ) ),
			'start'    => max( 0, (float) ( $segment['start'] ?? 0 ) ),
			'end'      => max( 0, (float) ( $segment['end'] ?? 0 ) ),
			'text'     => sanitize_textarea_field( (string) ( $segment['text'] ?? '' ) ),
			'speaker'  => sanitize_text_field( (string) ( $segment['speaker'] ?? '' ) ),
			'party'    => sanitize_text_field( (string) ( $segment['party'] ?? '' ) ),
			'manual'   => ! empty( $segment['manual'] ),
			'inserted' => ! empty( $segment['inserted'] ),
		);
	}

	/**
	 * Sends one authenticated request to the configured opt-in service.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Endpoint path.
	 * @param array|null $body   Optional JSON body.
	 * @return array|WP_Error
	 */
	private static function service_request( string $method, string $path, ?array $body = null ): array|WP_Error {
		$settings = self::settings();
		if ( '' === $settings['service_url'] || '' === $settings['license_key'] ) {
			return new WP_Error( 'avpvh_at_not_configured', __( 'De transcriptieservice of servicesleutel ontbreekt.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 2,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $settings['license_key'],
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $settings['service_url'] . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 > $code || 300 <= $code ) {
			/* translators: %d is an HTTP status code returned by the configured service. */
			$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : sprintf( __( 'Transcriptieservicefout (%d).', 'avpvh-audio-transcriptie' ), $code );
			return new WP_Error( 'avpvh_at_service_error', $message, array( 'status' => 502 ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/** Returns normalized settings. */
	private static function settings(): array {
		$saved = get_option( 'avpvh_at_settings', array() );
		return array(
			'service_url' => untrailingslashit( (string) ( $saved['service_url'] ?? '' ) ),
			'license_key' => defined( 'AVPVH_AT_LICENSE_KEY' ) ? (string) AVPVH_AT_LICENSE_KEY : (string) ( $saved['license_key'] ?? '' ),
		);
	}

	/**
	 * Gets one local job.
	 *
	 * @param int $id Local job ID.
	 * @return object|null
	 */
	private static function get_row( int $id ): ?object {
		global $wpdb;
		$table = self::jobs_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
	}

	/**
	 * Presents a job without secret configuration.
	 *
	 * @param object $row              Local job row.
	 * @param bool   $include_segments Whether transcript segments are needed.
	 * @return array
	 */
	private static function present_job( object $row, bool $include_segments = true ): array {
		return array(
			'id'             => (int) $row->id,
			'service_job_id' => $row->service_job_id,
			'title'          => $row->title,
			'audio_name'     => $row->audio_name,
			'audio_url'      => $row->audio_url,
			'status'         => $row->status,
			'progress'       => (float) $row->progress,
			'duration'       => null !== $row->duration ? (float) $row->duration : null,
			'segments'       => $include_segments ? self::decode_segments( $row->segments ) : array(),
			'summary'        => $row->summary,
			'revision'       => (int) $row->revision,
			'updated_at'     => $row->updated_at,
		);
	}

	/**
	 * Stores the current job as a revision.
	 *
	 * @param object $row Current job row.
	 * @return void
	 */
	private static function store_revision( object $row ): void {
		global $wpdb;
		$wpdb->insert(
			self::revisions_table(),
			array(
				'job_id'     => $row->id,
				'revision'   => $row->revision,
				'user_id'    => get_current_user_id(),
				'segments'   => $row->segments ? $row->segments : '[]',
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Removes revisions beyond the retention limit.
	 *
	 * @param int $job_id Local job ID.
	 * @return void
	 */
	private static function trim_revisions( int $job_id ): void {
		global $wpdb;
		$table = self::revisions_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE job_id = %d ORDER BY id DESC LIMIT 100000 OFFSET %d", $job_id, self::MAX_REVISIONS ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
		);
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query        = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated for integer IDs.
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/**
	 * Safely decodes stored segment JSON.
	 *
	 * @param string|null $json Stored JSON.
	 * @return array
	 */
	private static function decode_segments( ?string $json ): array {
		$segments = json_decode( $json ? $json : '[]', true );
		return is_array( $segments ) ? $segments : array();
	}

	/**
	 * Sanitizes browser upload headers supplied by the service.
	 *
	 * @param mixed $headers Untrusted header map.
	 * @return array
	 */
	private static function sanitize_headers( mixed $headers ): array {
		$clean = array();
		if ( ! is_array( $headers ) ) {
			return $clean;
		}
		foreach ( $headers as $name => $value ) {
			$clean[ sanitize_key( (string) $name ) ] = sanitize_text_field( (string) $value );
		}
		return $clean;
	}

	/**
	 * Formats seconds as a continuous timestamp.
	 *
	 * @param float $seconds Number of seconds.
	 * @return string
	 */
	private static function format_time( float $seconds ): string {
		$seconds = max( 0, (int) floor( $seconds ) );
		return sprintf(
			'%02d:%02d:%02d',
			(int) floor( $seconds / 3600 ),
			(int) floor( ( $seconds % 3600 ) / 60 ),
			$seconds % 60
		);
	}

	/** Returns the jobs table name. */
	private static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'avpvh_at_jobs';
	}

	/** Returns the revisions table name. */
	private static function revisions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'avpvh_at_revisions';
	}
}
