<?php
/**
 * Main plugin class.
 *
 * @package AVPVH_Audio_Transcriptie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates storage, REST endpoints, the remote service and the admin editor.
 *
 * @phpstan-type Settings array{service_url: string, license_key: string, provider: string, default_model: string, default_language: string, default_prompt: string, default_glossary: string, chunk_minutes: int, summary_api_url: string, summary_api_key: string, summary_model: string, summary_procedure_prompt: string, summary_content_prompt: string}
 */
final class AVPVH_AT_Plugin {

	/** REST namespace. */
	private const REST_NAMESPACE = 'avpvh-audio-transcriptie/v1';

	/** Maximum retained revisions per job. */
	private const MAX_REVISIONS = 50;

	/** Database schema version; bump when activate() changes a table. */
	private const DB_VERSION = '2';

	/** Seconds a requested background summary is polled for. */
	private const SUMMARY_PENDING_TTL = 3600;

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
				deleted_ids longtext NULL,
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
		update_option( 'avpvh_at_db_version', self::DB_VERSION, false );
	}

	/**
	 * Applies schema changes after a plugin update without reactivation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( 'avpvh_at_db_version' ) ) {
			self::activate();
		}
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		self::maybe_upgrade();
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'allow_plugin_read_routes' ), PHP_INT_MAX, 3 );
		add_shortcode( 'avpvh_audio_transcriptie', array( self::class, 'render_shortcode' ) );
	}

	/**
	 * Keeps this plugin's authenticated read routes available when a site-wide
	 * content restriction blocks the complete REST API for read-only users.
	 * The plugin's own permission callbacks still run after this filter.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public static function allow_plugin_read_routes( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Required filter signature.
		$route = $request->get_route();
		if (
			is_wp_error( $result )
			&& 'rest_forbidden' === $result->get_error_code()
			&& WP_REST_Server::READABLE === $request->get_method()
			&& str_starts_with( $route, '/' . self::REST_NAMESPACE . '/jobs' )
			&& self::can_read()
		) {
			return null;
		}

		return $result;
	}

	/**
	 * Renders a read-only transcript with audio, search and label filters.
	 *
	 * Adding the shortcode to a page intentionally publishes that transcript to
	 * everyone who can view the page. Page-level access restrictions remain in
	 * control of WordPress and any access-control plugin used by the site.
	 *
	 * @param array<string, mixed>|string $attributes Shortcode attributes.
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
				'Wanneer een bevoegde gebruiker een audiotranscriptie start, worden de gekozen opname, bestandsgegevens, transcriptiefragmenten, sprekerslabels, partijlabels en een eventuele samenvattingsinstructie verzonden naar de transcriptieservice die de sitebeheerder heeft ingesteld. Als er een aparte samenvattings-API of WordPress AI-provider is ingesteld, worden de gecorrigeerde transcriptie en de samenvattingsinstructie naar die dienst verzonden. De transcriptietekst en maximaal 50 correctierevisies worden in de WordPress-database bewaard. Raadpleeg de ingestelde aanbieders voor hun voorwaarden over bewaartermijn, privacy en verwijdering.',
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

		$settings            = self::settings();
		$ai_client_available = self::ai_client_available();
		wp_enqueue_style( 'avpvh-at-admin', AVPVH_AT_URL . 'assets/admin.css', array(), AVPVH_AT_VERSION );
		wp_enqueue_script( 'avpvh-at-admin', AVPVH_AT_URL . 'assets/admin.js', array( 'wp-i18n' ), AVPVH_AT_VERSION, true );
		wp_set_script_translations( 'avpvh-at-admin', 'avpvh-audio-transcriptie', AVPVH_AT_DIR . 'languages' );
		wp_localize_script(
			'avpvh-at-admin',
			'avpvhAudioTranscriptie',
			array(
				'root'              => esc_url_raw( rest_url( self::REST_NAMESPACE . '/' ) ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'canManage'         => current_user_can( 'manage_options' ),
				'canEdit'           => current_user_can( 'upload_files' ),
				'canPublish'        => current_user_can( 'publish_pages' ),
				'summaryConfigured' => self::summary_configured( $settings, $ai_client_available ),
				'aiClientAvailable' => $ai_client_available,
				'defaults'          => current_user_can( 'upload_files' ) ? self::transcription_defaults() : array(),
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
			'/publishing-options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'publishing_options' ),
				'permission_callback' => array( self::class, 'can_publish' ),
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
			'/title'           => array( WP_REST_Server::EDITABLE, 'update_title', 'can_edit_job' ),
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

		register_rest_route(
			self::REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/publish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'publish_page' ),
				'permission_callback' => array( self::class, 'can_publish_job' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
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

	/** Whether the user may publish WordPress pages. */
	public static function can_publish(): bool {
		return current_user_can( 'publish_pages' );
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

	/**
	 * Whether the user may publish an existing transcript as a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function can_publish_job( WP_REST_Request $request ): bool {
		return self::can_publish() && null !== self::get_row( absint( $request['id'] ) );
	}

	/**
	 * Whether any summary route is usable: the WordPress AI Client, a direct
	 * chat-completions API (key optional), or the transcription service.
	 *
	 * @param array     $settings            Normalized settings.
	 * @phpstan-param Settings $settings
	 * @param bool|null $ai_client_available Precomputed AI Client availability.
	 * @return bool
	 */
	private static function summary_configured( array $settings, ?bool $ai_client_available = null ): bool {
		$ai_client_available = $ai_client_available ?? self::ai_client_available();
		return $ai_client_available
			|| ( '' !== $settings['summary_api_url'] && '' !== $settings['summary_model'] )
			|| ( '' !== $settings['service_url'] && '' !== $settings['license_key'] );
	}

	/**
	 * Returns public settings without exposing the secret.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$settings = self::settings();
		return array(
			'summary_configured'       => self::summary_configured( $settings ),
			'service_url'              => $settings['service_url'],
			'license_registered'       => '' !== $settings['license_key'],
			'license_hint'             => '' !== $settings['license_key'] ? '••••' . substr( $settings['license_key'], -4 ) : '',
			'summary_api_url'          => $settings['summary_api_url'],
			'summary_registered'       => '' !== $settings['summary_api_key'],
			'summary_key_hint'         => '' !== $settings['summary_api_key'] ? '••••' . substr( $settings['summary_api_key'], -4 ) : '',
			'summary_model'            => $settings['summary_model'],
			'summary_procedure_prompt' => $settings['summary_procedure_prompt'],
			'summary_content_prompt'   => $settings['summary_content_prompt'],
			'ai_client_available'      => self::ai_client_available(),
			'provider'                 => $settings['provider'],
			'default_model'            => $settings['default_model'],
			'default_language'         => $settings['default_language'],
			'default_prompt'           => $settings['default_prompt'],
			'default_glossary'         => $settings['default_glossary'],
			'chunk_minutes'            => $settings['chunk_minutes'],
		);
	}

	/**
	 * Stores service configuration.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ): array|WP_Error {
		$current                  = self::settings();
		$saved                    = (array) get_option( 'avpvh_at_settings', array() );
		$service_url              = untrailingslashit( esc_url_raw( (string) $request->get_param( 'service_url' ) ) );
		$license_key              = self::sanitize_secret( $request->get_param( 'license_key' ) );
		$license_key              = '' !== $license_key ? $license_key : (string) ( $saved['license_key'] ?? '' );
		$provider                 = sanitize_key( (string) $request->get_param( 'provider' ) );
		$model                    = sanitize_text_field( (string) $request->get_param( 'default_model' ) );
		$language                 = sanitize_key( (string) $request->get_param( 'default_language' ) );
		$prompt                   = sanitize_textarea_field( (string) $request->get_param( 'default_prompt' ) );
		$glossary                 = sanitize_textarea_field( (string) $request->get_param( 'default_glossary' ) );
		$chunk                    = min( 60, max( 1, absint( $request->get_param( 'chunk_minutes' ) ) ) );
		$summary_url              = untrailingslashit( esc_url_raw( (string) $request->get_param( 'summary_api_url' ) ) );
		$summary_key              = self::sanitize_secret( $request->get_param( 'summary_api_key' ) );
		$summary_key              = '' !== $summary_key ? $summary_key : (string) ( $saved['summary_api_key'] ?? '' );
		$summary_model            = sanitize_text_field( (string) $request->get_param( 'summary_model' ) );
		$summary_procedure_prompt = sanitize_textarea_field( (string) $request->get_param( 'summary_procedure_prompt' ) );
		$summary_content_prompt   = sanitize_textarea_field( (string) $request->get_param( 'summary_content_prompt' ) );
		if ( '' !== $service_url && ( ! wp_http_validate_url( $service_url ) || 'https' !== wp_parse_url( $service_url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'avpvh_at_invalid_service_url', __( 'Gebruik een geldige HTTPS-service-URL.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}
		if ( '' !== $summary_url && ( ! wp_http_validate_url( $summary_url ) || 'https' !== wp_parse_url( $summary_url, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'avpvh_at_invalid_summary_url', __( 'Gebruik een geldige HTTPS-URL voor de samenvattings-API.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		// Keys supplied through wp-config.php constants are never copied into the database.
		if ( defined( 'AVPVH_AT_LICENSE_KEY' ) ) {
			$license_key = '';
		}
		if ( defined( 'AVPVH_AT_SUMMARY_API_KEY' ) ) {
			$summary_key = '';
		}

		update_option(
			'avpvh_at_settings',
			array(
				'service_url'              => $service_url,
				'license_key'              => $license_key,
				'provider'                 => $provider ? $provider : $current['provider'],
				'default_model'            => $model ? $model : $current['default_model'],
				'default_language'         => $language ? $language : $current['default_language'],
				'default_prompt'           => $prompt,
				'default_glossary'         => $glossary,
				'chunk_minutes'            => $chunk,
				'summary_api_url'          => $summary_url,
				'summary_api_key'          => $summary_key,
				'summary_model'            => $summary_model,
				'summary_procedure_prompt' => $summary_procedure_prompt,
				'summary_content_prompt'   => $summary_content_prompt,
			),
			false
		);

		return self::get_settings();
	}

	/**
	 * Validates the configured service key.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate_license(): array|WP_Error {
		return self::service_request( 'POST', '/v1/license/validate', array() );
	}

	/**
	 * Lists jobs visible to the current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_jobs(): array {
		global $wpdb;

		$table = self::jobs_table();
		$rows  = (array) $wpdb->get_results( "SELECT id, service_job_id, title, audio_name, audio_url, status, progress, duration, revision, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.

		return array_values(
			array_map(
				static function ( stdClass $row ): array {
					return self::present_job( $row, false );
				},
				array_filter( $rows, static fn( $row ): bool => $row instanceof stdClass )
			)
		);
	}

	/**
	 * Creates a remote and local transcription job.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_job( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$language = $request->get_param( 'language' );
		$model    = $request->get_param( 'model' );
		$title    = $request->get_param( 'title' );
		$settings = self::settings();

		$payload = array(
			'filename'      => sanitize_file_name( (string) $request->get_param( 'filename' ) ),
			'size'          => absint( $request->get_param( 'size' ) ),
			'content_type'  => sanitize_mime_type( (string) $request->get_param( 'content_type' ) ),
			'provider'      => sanitize_key( (string) ( $request->get_param( 'provider' ) ? $request->get_param( 'provider' ) : $settings['provider'] ) ),
			'language'      => sanitize_key( (string) ( $language ? $language : $settings['default_language'] ) ),
			'model'         => sanitize_text_field( (string) ( $model ? $model : $settings['default_model'] ) ),
			'prompt'        => sanitize_textarea_field( (string) ( null !== $request->get_param( 'prompt' ) ? $request->get_param( 'prompt' ) : $settings['default_prompt'] ) ),
			'glossary'      => sanitize_textarea_field( (string) ( null !== $request->get_param( 'glossary' ) ? $request->get_param( 'glossary' ) : $settings['default_glossary'] ) ),
			'chunk_seconds' => 60 * min( 60, max( 1, absint( $request->get_param( 'chunk_minutes' ) ? $request->get_param( 'chunk_minutes' ) : $settings['chunk_minutes'] ) ) ),
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

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
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

		$row = $inserted ? self::get_row( (int) $wpdb->insert_id ) : null;
		if ( null === $row ) {
			return new WP_Error( 'avpvh_at_job_not_stored', __( 'De transcriptie kon niet in de database worden opgeslagen.', 'avpvh-audio-transcriptie' ), array( 'status' => 500 ) );
		}
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
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ): array|WP_Error {
		$row = self::refresh_job( self::get_row( absint( $request['id'] ) ) );
		return is_wp_error( $row ) ? $row : self::present_job( $row );
	}

	/**
	 * Updates the human-readable transcript title.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_title( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'avpvh_at_title_required', __( 'Vul een naam voor de transcriptie in.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}
		$wpdb->update(
			self::jobs_table(),
			array(
				'title'      => $title,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $request['id'] ) )
		);
		$row = self::require_row( absint( $request['id'] ) );
		return is_wp_error( $row ) ? $row : self::present_job( $row );
	}

	/**
	 * Marks a browser-to-service upload as complete.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function upload_complete( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$row = self::require_row( absint( $request['id'] ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
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

		$row = self::require_row( (int) $row->id );
		return is_wp_error( $row ) ? $row : self::present_job( $row );
	}

	/**
	 * Saves corrected segments with optimistic locking.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_segments( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$row = self::require_row( absint( $request['id'] ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$revision = absint( $request->get_param( 'revision' ) );
		$segments = $request->get_param( 'segments' );
		$dirty    = array_map( 'strval', (array) $request->get_param( 'dirty_ids' ) );
		$deleted  = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $request->get_param( 'deleted_ids' ) ) ) ) );

		if ( $revision !== (int) $row->revision ) {
			return self::revision_conflict( $row );
		}

		if ( ! is_array( $segments ) || 100000 < count( $segments ) ) {
			return new WP_Error( 'avpvh_at_bad_segments', __( 'Ongeldige segmenten.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		$existing = array();
		foreach ( self::decode_segments( $row->segments ) as $segment ) {
			$existing[ (string) ( $segment['id'] ?? '' ) ] = $segment;
		}
		$tombstones = array_values( array_unique( array_merge( self::decode_segments( $row->deleted_ids ), $deleted ) ) );
		$removed    = array_flip( $tombstones );

		$clean = array();
		$seen  = array();
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			$id = sanitize_text_field( (string) ( $segment['id'] ?? '' ) );
			if ( isset( $removed[ $id ] ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$clean[]     = array(
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

		// Keep stored segments the browser has not received yet (for example
		// fragments that arrived from the service after its last poll).
		$unseen = false;
		foreach ( $existing as $id => $segment ) {
			if ( ! isset( $seen[ $id ] ) && ! isset( $removed[ $id ] ) ) {
				$clean[] = self::sanitize_remote_segment( $segment );
				$unseen  = true;
			}
		}
		if ( $unseen ) {
			self::sort_segments( $clean );
		}

		$new_revision = $revision + 1;
		$updated      = $wpdb->update(
			self::jobs_table(),
			array(
				'segments'    => wp_json_encode( $clean ),
				'deleted_ids' => wp_json_encode( $tombstones ),
				'revision'    => $new_revision,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id'       => $row->id,
				'revision' => $revision,
			)
		);
		if ( false === $updated ) {
			return new WP_Error( 'avpvh_at_save_failed', __( 'De correcties konden niet worden opgeslagen.', 'avpvh-audio-transcriptie' ), array( 'status' => 500 ) );
		}
		if ( 1 > $updated ) {
			$fresh = self::get_row( (int) $row->id );
			return null === $fresh ? self::missing_job() : self::revision_conflict( $fresh );
		}

		self::store_revision( $row );
		self::trim_revisions( (int) $row->id );

		return array(
			'ok'       => true,
			'revision' => $new_revision,
		);
	}

	/**
	 * Builds the 409 response for a stale revision.
	 *
	 * @param stdClass $row Current job row.
	 * @return WP_Error
	 */
	private static function revision_conflict( stdClass $row ): WP_Error {
		return new WP_Error(
			'avpvh_at_revision_conflict',
			__( 'Er is een nieuwere versie. Herlaad de transcriptie om wijzigingen samen te voegen.', 'avpvh-audio-transcriptie' ),
			array(
				'status'  => 409,
				'current' => self::present_job( $row ),
			)
		);
	}

	/**
	 * Returns revision metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<int, array<string, mixed>>
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
	 * Returns menus and existing page information for the publish dialog.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	public static function publishing_options( WP_REST_Request $request ): array {
		$job_id                     = absint( $request->get_param( 'job_id' ) );
		$page_id                    = self::published_page_id( $job_id );
		$menus                      = array();
		$existing_menu_item         = $page_id ? absint( get_post_meta( $page_id, '_avpvh_at_menu_item_id', true ) ) : 0;
		$existing_navigation_id     = $page_id ? absint( get_post_meta( $page_id, '_avpvh_at_navigation_id', true ) ) : 0;
		$existing_navigation_parent = $page_id ? sanitize_text_field( (string) get_post_meta( $page_id, '_avpvh_at_navigation_parent', true ) ) : '';
		$existing_menu_ids          = $existing_menu_item ? wp_get_object_terms( $existing_menu_item, 'nav_menu', array( 'fields' => 'ids' ) ) : array();
		$block_menus                = array();

		if ( current_user_can( 'edit_theme_options' ) ) {
			foreach ( wp_get_nav_menus() as $menu ) {
				$items      = array();
				$menu_items = wp_get_nav_menu_items( $menu->term_id );
				foreach ( is_array( $menu_items ) ? $menu_items : array() as $item ) {
					$items[] = array(
						'id'     => (int) $item->ID,
						'title'  => wp_strip_all_tags( (string) $item->title ),
						'parent' => (int) $item->menu_item_parent,
					);
				}
				$menus[] = array(
					'id'    => (int) $menu->term_id,
					'value' => 'classic:' . (int) $menu->term_id,
					'name'  => sanitize_text_field( (string) $menu->name ),
					'items' => array_map(
						static function ( array $item ): array {
							$item['value'] = 'classic:' . $item['id'];
							return $item;
						},
						$items
					),
				);
			}

			$navigation_posts = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => array( 'draft', 'publish' ),
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			foreach ( $navigation_posts as $navigation ) {
				$block_menus[] = array(
					'id'    => (int) $navigation->ID,
					'value' => 'block:' . (int) $navigation->ID,
					'name'  => sanitize_text_field( (string) $navigation->post_title ),
					'items' => self::navigation_submenu_options( parse_blocks( $navigation->post_content ) ),
				);
			}
		}

		return array(
			'menus'            => $menus,
			'block_menus'      => $block_menus,
			'can_manage_menus' => current_user_can( 'edit_theme_options' ),
			'existing_page'    => $page_id ? array(
				'id'            => $page_id,
				'title'         => get_the_title( $page_id ),
				'status'        => get_post_status( $page_id ),
				'url'           => get_permalink( $page_id ),
				'edit_url'      => get_edit_post_link( $page_id, 'raw' ),
				'menu_item'     => $existing_menu_item,
				'menu_id'       => ! is_wp_error( $existing_menu_ids ) && $existing_menu_ids ? (int) $existing_menu_ids[0] : 0,
				'parent_item'   => $existing_menu_item ? absint( get_post_meta( $existing_menu_item, '_menu_item_menu_item_parent', true ) ) : 0,
				'menu_ref'      => $existing_navigation_id ? 'block:' . $existing_navigation_id : ( ! is_wp_error( $existing_menu_ids ) && $existing_menu_ids ? 'classic:' . (int) $existing_menu_ids[0] : '0' ),
				'parent_ref'    => $existing_navigation_id ? $existing_navigation_parent : ( $existing_menu_item ? 'classic:' . absint( get_post_meta( $existing_menu_item, '_menu_item_menu_item_parent', true ) ) : '0' ),
				'menu_item_ref' => $existing_menu_item ? 'classic:' . $existing_menu_item : '',
			) : null,
		);
	}

	/**
	 * Creates or updates a WordPress page and optionally places it in a menu.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function publish_page( WP_REST_Request $request ): array|WP_Error {
		$job_id = absint( $request['id'] );
		$job    = self::require_row( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$page_id       = self::published_page_id( $job_id );
		$title         = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$status        = 'publish' === $request->get_param( 'status' ) ? 'publish' : 'draft';
		$menu_ref      = sanitize_text_field( (string) $request->get_param( 'menu_ref' ) );
		$parent_ref    = sanitize_text_field( (string) $request->get_param( 'parent_ref' ) );
		$menu_id       = str_starts_with( $menu_ref, 'classic:' ) ? absint( substr( $menu_ref, 8 ) ) : absint( $request->get_param( 'menu_id' ) );
		$parent        = str_starts_with( $parent_ref, 'classic:' ) ? absint( substr( $parent_ref, 8 ) ) : absint( $request->get_param( 'parent_item_id' ) );
		$navigation_id = str_starts_with( $menu_ref, 'block:' ) ? absint( substr( $menu_ref, 6 ) ) : 0;
		$postdata      = array(
			'post_title'   => '' !== $title ? $title : $job->title,
			'post_content' => sprintf( '[avpvh_audio_transcriptie id="%d"]', $job_id ),
			'post_status'  => $status,
			'post_type'    => 'page',
		);
		if ( $page_id ) {
			$postdata['ID'] = $page_id;
		}
		if ( ( $menu_id || $navigation_id ) && ! current_user_can( 'edit_theme_options' ) ) {
			return new WP_Error( 'avpvh_at_menu_forbidden', __( 'Je mag geen WordPress-menu wijzigen.', 'avpvh-audio-transcriptie' ), array( 'status' => 403 ) );
		}
		if ( ( $menu_id || $navigation_id ) && 'publish' !== $status ) {
			return new WP_Error( 'avpvh_at_menu_requires_publish', __( 'Publiceer de pagina voordat je deze aan een menu toevoegt.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		$page_id = wp_insert_post( wp_slash( $postdata ), true );
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		update_post_meta( $page_id, '_avpvh_at_job_id', $job_id );

		$menu_item_id      = absint( get_post_meta( $page_id, '_avpvh_at_menu_item_id', true ) );
		$old_navigation_id = absint( get_post_meta( $page_id, '_avpvh_at_navigation_id', true ) );
		if ( $menu_id ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			if ( ! $menu ) {
				return new WP_Error( 'avpvh_at_menu_missing', __( 'Het gekozen menu bestaat niet meer.', 'avpvh-audio-transcriptie' ), array( 'status' => 404 ) );
			}
			if ( $parent ) {
				$valid_parent = false;
				$menu_items   = wp_get_nav_menu_items( $menu_id );
				foreach ( is_array( $menu_items ) ? $menu_items : array() as $item ) {
					if ( $parent === (int) $item->ID ) {
						$valid_parent = true;
						break;
					}
				}
				if ( ! $valid_parent ) {
					return new WP_Error( 'avpvh_at_parent_missing', __( 'Het gekozen bovenliggende menu-item hoort niet bij dit menu.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
				}
			}

			$menu_item_id = wp_update_nav_menu_item(
				$menu_id,
				$menu_item_id,
				array(
					'menu-item-object-id' => $page_id,
					'menu-item-object'    => 'page',
					'menu-item-parent-id' => $parent,
					'menu-item-status'    => 'publish',
					'menu-item-title'     => get_the_title( $page_id ),
					'menu-item-type'      => 'post_type',
				)
			);
			if ( is_wp_error( $menu_item_id ) ) {
				return $menu_item_id;
			}
			update_post_meta( $page_id, '_avpvh_at_menu_item_id', $menu_item_id );

			// The page moved from a block navigation to a classic menu.
			if ( $old_navigation_id ) {
				$removed = self::remove_page_from_navigation( $old_navigation_id, (int) $page_id );
				if ( is_wp_error( $removed ) ) {
					return $removed;
				}
				delete_post_meta( $page_id, '_avpvh_at_navigation_id' );
				delete_post_meta( $page_id, '_avpvh_at_navigation_parent' );
			}
		}

		if ( $navigation_id ) {
			$navigation = get_post( $navigation_id );
			if ( ! $navigation || 'wp_navigation' !== $navigation->post_type ) {
				return new WP_Error( 'avpvh_at_navigation_missing', __( 'De gekozen bloknavigatie bestaat niet meer.', 'avpvh-audio-transcriptie' ), array( 'status' => 404 ) );
			}
			$blocks      = parse_blocks( $navigation->post_content );
			$original    = serialize_blocks( $blocks );
			$link_markup = get_comment_delimited_block_content(
				'core/navigation-link',
				array(
					'label'        => get_the_title( $page_id ),
					'kind'         => 'post-type',
					'type'         => 'page',
					'id'           => (int) $page_id,
					'url'          => get_permalink( $page_id ),
					'avpvhAtFresh' => true,
				),
				''
			);
			$link_blocks = parse_blocks( $link_markup );
			$path        = str_starts_with( $parent_ref, 'block:' ) ? substr( $parent_ref, 6 ) : '';
			// Place (or update) the marked link first so the selected path still
			// points at the right submenu, then drop other links to this page.
			if ( ! self::place_navigation_link( $blocks, $path, $link_blocks[0], (int) $page_id ) ) {
				return new WP_Error( 'avpvh_at_navigation_parent_missing', __( 'Het gekozen onderdeel bestaat niet meer in de bloknavigatie.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
			}
			$blocks  = self::prune_navigation_links( $blocks, (int) $page_id );
			$content = serialize_blocks( $blocks );
			if ( $content !== $original ) {
				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'           => $navigation_id,
							'post_content' => $content,
						)
					),
					true
				);
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}

			// The page moved to another navigation or from a classic menu.
			if ( $old_navigation_id && $old_navigation_id !== $navigation_id ) {
				$removed = self::remove_page_from_navigation( $old_navigation_id, (int) $page_id );
				if ( is_wp_error( $removed ) ) {
					return $removed;
				}
			}
			if ( $menu_item_id ) {
				wp_delete_post( $menu_item_id, true );
				delete_post_meta( $page_id, '_avpvh_at_menu_item_id' );
				$menu_item_id = 0;
			}
			update_post_meta( $page_id, '_avpvh_at_navigation_id', $navigation_id );
			update_post_meta( $page_id, '_avpvh_at_navigation_parent', $parent_ref );
		}

		return array(
			'page_id'       => (int) $page_id,
			'status'        => get_post_status( $page_id ),
			'url'           => get_permalink( $page_id ),
			'edit_url'      => get_edit_post_link( $page_id, 'raw' ),
			'menu_item_id'  => (int) $menu_item_id,
			'navigation_id' => (int) $navigation_id,
		);
	}

	/**
	 * Restores a prior revision and records the current one first.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function restore_revision( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$job = self::require_row( absint( $request['id'] ) );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$table = self::revisions_table();
		$old   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND revision = %d ORDER BY id DESC LIMIT 1", $job->id, absint( $request['revision'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
		);

		if ( ! $old instanceof stdClass ) {
			return new WP_Error( 'avpvh_at_revision_missing', __( 'Versie niet gevonden.', 'avpvh-audio-transcriptie' ), array( 'status' => 404 ) );
		}

		// Segments brought back by the restore are no longer deleted.
		$restored_ids = array_flip( array_map( static fn( $segment ) => (string) ( $segment['id'] ?? '' ), self::decode_segments( $old->segments ) ) );
		$tombstones   = array_values( array_filter( self::decode_segments( $job->deleted_ids ), static fn( $id ) => ! isset( $restored_ids[ (string) $id ] ) ) );

		$updated = $wpdb->update(
			self::jobs_table(),
			array(
				'segments'    => $old->segments,
				'deleted_ids' => wp_json_encode( $tombstones ),
				'revision'    => (int) $job->revision + 1,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id'       => $job->id,
				'revision' => $job->revision,
			)
		);
		if ( false === $updated ) {
			return new WP_Error( 'avpvh_at_save_failed', __( 'De versie kon niet worden teruggezet.', 'avpvh-audio-transcriptie' ), array( 'status' => 500 ) );
		}
		if ( 1 > $updated ) {
			$fresh = self::get_row( (int) $job->id );
			return null === $fresh ? self::missing_job() : self::revision_conflict( $fresh );
		}
		self::store_revision( $job );
		self::trim_revisions( (int) $job->id );

		$row = self::require_row( (int) $job->id );
		return is_wp_error( $row ) ? $row : self::present_job( $row );
	}

	/**
	 * Requests an optional summary from the configured service.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_summary( WP_REST_Request $request ): array|WP_Error {
		global $wpdb;
		$row = self::require_row( absint( $request['id'] ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$settings         = self::settings();
		$procedure_prompt = sanitize_textarea_field( (string) $request->get_param( 'procedure_prompt' ) );
		$content_prompt   = sanitize_textarea_field( (string) $request->get_param( 'content_prompt' ) );
		$legacy_prompt    = sanitize_textarea_field( (string) $request->get_param( 'prompt' ) );
		$procedure_prompt = '' !== $procedure_prompt ? $procedure_prompt : ( '' !== $legacy_prompt ? $legacy_prompt : $settings['summary_procedure_prompt'] );
		$content_prompt   = '' !== $content_prompt ? $content_prompt : $settings['summary_content_prompt'];

		if ( self::ai_client_available() ) {
			$remote = self::ai_client_summary_request( $procedure_prompt, $content_prompt, self::decode_segments( $row->segments ) );
		} elseif ( '' !== $settings['summary_api_url'] ) {
			$remote = self::summary_request( $settings, $procedure_prompt, $content_prompt, self::decode_segments( $row->segments ) );
		} elseif ( '' !== $settings['service_url'] && '' !== $settings['license_key'] ) {
			$remote = self::service_request(
				'POST',
				'/v1/jobs/' . rawurlencode( $row->service_job_id ) . '/summary',
				array(
					'segments'         => self::decode_segments( $row->segments ),
					'prompt'           => trim( $procedure_prompt . "\n\n" . $content_prompt ),
					'procedure_prompt' => $procedure_prompt,
					'content_prompt'   => $content_prompt,
				)
			);
		} else {
			return new WP_Error( 'avpvh_at_summary_not_configured', __( 'Stel onder Transcripties → Instellingen een samenvattings-API, model en eventueel API-key in.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( isset( $remote['summary'] ) && '' !== trim( (string) $remote['summary'] ) ) {
			delete_transient( self::summary_pending_key( (int) $row->id ) );
			$remote['summary'] = wp_kses_post( (string) $remote['summary'] );
			$wpdb->update(
				self::jobs_table(),
				array(
					'summary'    => $remote['summary'],
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $row->id )
			);
		} else {
			// The service generates the summary in the background; keep polling it.
			set_transient( self::summary_pending_key( (int) $row->id ), 1, self::SUMMARY_PENDING_TTL );
			$remote['summary_pending'] = true;
		}

		return $remote;
	}

	/**
	 * Transient key marking a background summary that has not arrived yet.
	 *
	 * @param int $job_id Local job ID.
	 * @return string
	 */
	private static function summary_pending_key( int $job_id ): string {
		return 'avpvh_at_summary_pending_' . $job_id;
	}

	/**
	 * Uses WordPress Core's provider-agnostic AI Client for a summary.
	 *
	 * @param string                           $procedure_prompt Procedural instruction.
	 * @param string                           $content_prompt   Content-specific instruction.
	 * @param array<int, array<string, mixed>> $segments         Corrected transcript segments.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function ai_client_summary_request( string $procedure_prompt, string $content_prompt, array $segments ): array|WP_Error {
		$lines = array();
		foreach ( $segments as $segment ) {
			$label   = trim( (string) ( $segment['speaker'] ?? '' ) . ( ! empty( $segment['party'] ) ? ' (' . (string) $segment['party'] . ')' : '' ) );
			$lines[] = '[' . self::format_time( (float) ( $segment['start'] ?? 0 ) ) . '] ' . ( $label ? $label . ': ' : '' ) . (string) ( $segment['text'] ?? '' );
		}

		$user_text = ( $content_prompt ? "Inhoudelijke toelichting:\n" . $content_prompt . "\n\n" : '' ) . "Transcriptie:\n" . implode( "\n", $lines );
		try {
			$builder = wp_ai_client_prompt( $user_text );
			if ( '' !== $procedure_prompt ) {
				$builder = $builder->using_system_instruction( $procedure_prompt );
			}
			$result = $builder->generate_text();
		} catch ( Throwable $error ) {
			return new WP_Error( 'avpvh_at_ai_client_error', sanitize_text_field( $error->getMessage() ), array( 'status' => 502 ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( '' === trim( (string) $result ) ) {
			return new WP_Error( 'avpvh_at_summary_empty', __( 'De WordPress AI-provider gaf geen tekst terug.', 'avpvh-audio-transcriptie' ), array( 'status' => 502 ) );
		}
		return array( 'summary' => wp_kses_post( wpautop( esc_html( (string) $result ) ) ) );
	}

	/**
	 * Calls an OpenAI-compatible chat-completions endpoint for a summary.
	 *
	 * @param array                            $settings Settings.
	 * @phpstan-param Settings $settings
	 * @param string                           $procedure_prompt Procedural instruction.
	 * @param string                           $content_prompt   Content-specific instruction.
	 * @param array<int, array<string, mixed>> $segments         Corrected transcript segments.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function summary_request( array $settings, string $procedure_prompt, string $content_prompt, array $segments ): array|WP_Error {
		if ( '' === $settings['summary_model'] ) {
			return new WP_Error( 'avpvh_at_summary_model_missing', __( 'Kies eerst een model voor de samenvattings-API.', 'avpvh-audio-transcriptie' ), array( 'status' => 400 ) );
		}

		$lines = array();
		foreach ( $segments as $segment ) {
			$label   = trim( (string) ( $segment['speaker'] ?? '' ) . ( ! empty( $segment['party'] ) ? ' (' . (string) $segment['party'] . ')' : '' ) );
			$lines[] = '[' . self::format_time( (float) ( $segment['start'] ?? 0 ) ) . '] ' . ( $label ? $label . ': ' : '' ) . (string) ( $segment['text'] ?? '' );
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $settings['summary_api_key'] ) {
			$headers['Authorization'] = 'Bearer ' . $settings['summary_api_key'];
		}
		$body = wp_json_encode(
			array(
				'model'    => $settings['summary_model'],
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => $procedure_prompt ? $procedure_prompt : __( 'Werk uitsluitend op basis van de transcriptie en verzin geen feiten.', 'avpvh-audio-transcriptie' ),
					),
					array(
						'role'    => 'user',
						'content' => ( $content_prompt ? "Inhoudelijke toelichting:\n" . $content_prompt . "\n\n" : '' ) . "Transcriptie:\n" . implode( "\n", $lines ),
					),
				),
			)
		);
		if ( false === $body ) {
			return new WP_Error( 'avpvh_at_summary_encoding', __( 'De transcriptie kon niet naar JSON worden omgezet.', 'avpvh-audio-transcriptie' ), array( 'status' => 500 ) );
		}
		$response = wp_safe_remote_post(
			$settings['summary_api_url'],
			array(
				'timeout' => 120,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 > $code || 300 <= $code ) {
			/* translators: %d is an HTTP status code returned by the configured summary API. */
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : sprintf( __( 'Samenvattings-API-fout (%d).', 'avpvh-audio-transcriptie' ), $code );
			return new WP_Error( 'avpvh_at_summary_service_error', $message, array( 'status' => 502 ) );
		}

		$content = is_array( $data ) ? (string) ( $data['choices'][0]['message']['content'] ?? '' ) : '';
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'avpvh_at_summary_empty', __( 'De samenvattings-API gaf geen tekst terug.', 'avpvh-audio-transcriptie' ), array( 'status' => 502 ) );
		}

		return array( 'summary' => wp_kses_post( wpautop( esc_html( $content ) ) ) );
	}

	/**
	 * Refreshes a job while preserving manual edits.
	 *
	 * The service request can take up to the HTTP timeout, so the merge runs on
	 * a row re-read after the request and is written only if no save bumped the
	 * revision in the meantime; otherwise it is retried on the newer row.
	 *
	 * @param stdClass|null $row Job row.
	 * @return stdClass|WP_Error
	 */
	private static function refresh_job( ?stdClass $row ): stdClass|WP_Error {
		if ( null === $row ) {
			return self::missing_job();
		}

		$final           = in_array( $row->status, array( 'complete', 'failed' ), true );
		$summary_pending = (bool) get_transient( self::summary_pending_key( (int) $row->id ) );
		if ( $final && ! $summary_pending ) {
			return $row;
		}

		$remote = self::service_request( 'GET', '/v1/jobs/' . rawurlencode( $row->service_job_id ) );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		global $wpdb;
		$has_summary = isset( $remote['summary'] ) && '' !== trim( (string) $remote['summary'] );
		if ( $has_summary ) {
			delete_transient( self::summary_pending_key( (int) $row->id ) );
		}

		// A finished job only needs the background summary.
		if ( $final ) {
			if ( $has_summary ) {
				$wpdb->update(
					self::jobs_table(),
					array(
						'summary'    => wp_kses_post( (string) $remote['summary'] ),
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $row->id )
				);
			}
			return self::require_row( (int) $row->id );
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$fresh = self::get_row( (int) $row->id );
			if ( null === $fresh ) {
				return self::missing_job();
			}

			$update = array(
				'status'     => sanitize_key( (string) ( $remote['status'] ?? $fresh->status ) ),
				'progress'   => min( 100, max( 0, (float) ( $remote['progress'] ?? $fresh->progress ) ) ),
				'updated_at' => current_time( 'mysql', true ),
			);
			if ( isset( $remote['duration'] ) ) {
				$update['duration'] = max( 0, (float) $remote['duration'] );
			}
			if ( isset( $remote['playback_url'] ) ) {
				$update['audio_url'] = esc_url_raw( (string) $remote['playback_url'] );
			}
			if ( isset( $remote['segments'] ) && is_array( $remote['segments'] ) ) {
				$update['segments'] = wp_json_encode(
					self::merge_segments(
						self::decode_segments( $fresh->segments ),
						$remote['segments'],
						self::decode_segments( $fresh->deleted_ids )
					)
				);
			}
			if ( $has_summary ) {
				$update['summary'] = wp_kses_post( (string) $remote['summary'] );
			}

			$updated = $wpdb->update(
				self::jobs_table(),
				$update,
				array(
					'id'       => $fresh->id,
					'revision' => $fresh->revision,
				)
			);
			if ( false === $updated ) {
				return new WP_Error( 'avpvh_at_refresh_failed', __( 'De voortgang kon niet worden opgeslagen.', 'avpvh-audio-transcriptie' ), array( 'status' => 500 ) );
			}
			// Zero rows means either nothing changed or a save won the race.
			$current = self::get_row( (int) $row->id );
			if ( null === $current ) {
				return self::missing_job();
			}
			if ( 0 < $updated || (int) $current->revision === (int) $fresh->revision ) {
				return $current;
			}
		}

		return self::require_row( (int) $row->id );
	}

	/**
	 * Merges remote segments with locally corrected records.
	 *
	 * @param array<int, array<string, mixed>> $local_segments  Locally stored segments.
	 * @param array<mixed>                     $remote_segments Service-provided segments.
	 * @param array<mixed>                     $deleted_ids     IDs of segments an editor removed.
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_segments( array $local_segments, array $remote_segments, array $deleted_ids = array() ): array {
		$local = array();
		foreach ( $local_segments as $segment ) {
			$local[ (string) ( $segment['id'] ?? '' ) ] = $segment;
		}
		$removed = array_flip( array_map( 'strval', $deleted_ids ) );

		$merged = array();
		foreach ( $remote_segments as $remote ) {
			if ( ! is_array( $remote ) ) {
				continue;
			}
			$id = sanitize_text_field( (string) ( $remote['id'] ?? '' ) );
			if ( isset( $removed[ $id ] ) ) {
				unset( $local[ $id ] );
				continue;
			}
			if ( isset( $local[ $id ] ) && ! empty( $local[ $id ]['manual'] ) ) {
				foreach ( array( 'text', 'speaker', 'party', 'manual' ) as $field ) {
					$remote[ $field ] = $local[ $id ][ $field ] ?? '';
				}
			}
			$merged[] = self::sanitize_remote_segment( $remote );
			unset( $local[ $id ] );
		}

		foreach ( $local as $id => $segment ) {
			if ( ! isset( $removed[ $id ] ) && ! empty( $segment['manual'] ) && ! empty( $segment['inserted'] ) ) {
				$merged[] = self::sanitize_remote_segment( $segment );
			}
		}

		self::sort_segments( $merged );
		return $merged;
	}

	/**
	 * Sorts segments by start time, keeping the existing order for ties.
	 *
	 * @param array<int, array<string, mixed>> $segments Segments, sorted in place.
	 * @return void
	 */
	private static function sort_segments( array &$segments ): void {
		$order = array_keys( $segments );
		$keyed = array_combine( $order, $segments );
		uksort(
			$keyed,
			static function ( int $left, int $right ) use ( $keyed ): int {
				return array( (float) $keyed[ $left ]['start'], $left ) <=> array( (float) $keyed[ $right ]['start'], $right );
			}
		);
		$segments = array_values( $keyed );
	}

	/**
	 * Sanitizes one service segment.
	 *
	 * @param array<string, mixed> $segment Untrusted service segment.
	 * @return array<string, mixed>
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
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Endpoint path.
	 * @param array<string, mixed>|null $body   Optional JSON body.
	 * @return array<string, mixed>|WP_Error
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
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return new WP_Error( 'avpvh_at_request_encoding', __( 'De aanvraag kon niet naar JSON worden omgezet.', 'avpvh-audio-transcriptie' ), array( 'status' => 500 ) );
			}
			$args['body'] = $encoded;
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

	/**
	 * Returns normalized settings.
	 *
	 * @return array
	 * @phpstan-return Settings
	 */
	private static function settings(): array {
		$saved = get_option( 'avpvh_at_settings', array() );
		return array(
			'service_url'              => untrailingslashit( (string) ( $saved['service_url'] ?? '' ) ),
			'license_key'              => defined( 'AVPVH_AT_LICENSE_KEY' ) ? (string) AVPVH_AT_LICENSE_KEY : (string) ( $saved['license_key'] ?? '' ),
			'provider'                 => sanitize_key( (string) ( $saved['provider'] ?? 'faster-whisper' ) ),
			'default_model'            => sanitize_text_field( (string) ( $saved['default_model'] ?? 'medium' ) ),
			'default_language'         => sanitize_key( (string) ( $saved['default_language'] ?? 'nl' ) ),
			'default_prompt'           => sanitize_textarea_field( (string) ( $saved['default_prompt'] ?? '' ) ),
			'default_glossary'         => sanitize_textarea_field( (string) ( $saved['default_glossary'] ?? '' ) ),
			'chunk_minutes'            => min( 60, max( 1, absint( $saved['chunk_minutes'] ?? 10 ) ) ),
			'summary_api_url'          => untrailingslashit( (string) ( $saved['summary_api_url'] ?? '' ) ),
			'summary_api_key'          => defined( 'AVPVH_AT_SUMMARY_API_KEY' ) ? (string) AVPVH_AT_SUMMARY_API_KEY : (string) ( $saved['summary_api_key'] ?? '' ),
			'summary_model'            => sanitize_text_field( (string) ( $saved['summary_model'] ?? '' ) ),
			'summary_procedure_prompt' => sanitize_textarea_field( (string) ( $saved['summary_procedure_prompt'] ?? $saved['summary_prompt'] ?? __( 'Werk uitsluitend op basis van de transcriptie. Maak duidelijk onderscheid tussen wat letterlijk is besproken en eventuele gevolgtrekkingen. Verzin geen feiten.', 'avpvh-audio-transcriptie' ) ) ),
			'summary_content_prompt'   => sanitize_textarea_field( (string) ( $saved['summary_content_prompt'] ?? __( 'Behandel de deelnemers en rollen, kernpunten per onderwerp, standpunten per partij, besluiten, actiepunten, openstaande vragen en relevante tijdstippen.', 'avpvh-audio-transcriptie' ) ) ),
		);
	}

	/** Whether WordPress has a configured text-generation provider. */
	private static function ai_client_available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			return true === wp_ai_client_prompt()->is_supported_for_text_generation();
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/**
	 * Returns transcription defaults safe to expose to editors.
	 *
	 * @return array<string, int|string>
	 */
	private static function transcription_defaults(): array {
		$settings = self::settings();
		unset( $settings['service_url'], $settings['license_key'], $settings['summary_api_key'] );
		return $settings;
	}

	/**
	 * Finds the WordPress page previously linked to a transcript.
	 *
	 * @param int $job_id Transcript job ID.
	 * @return int
	 */
	private static function published_page_id( int $job_id ): int {
		if ( 1 > $job_id ) {
			return 0;
		}
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'pending', 'private', 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_avpvh_at_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A single administrative lookup for one managed page.
				'meta_value'     => (string) $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A single administrative lookup for one managed page.
			)
		);
		return $pages ? (int) $pages[0] : 0;
	}

	/**
	 * Lists navigation submenus that can receive the transcript page.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed navigation blocks.
	 * @param string                                  $prefix Parent index path.
	 * @param int                                     $depth  Navigation depth.
	 * @return array<int, array<string, mixed>>
	 */
	private static function navigation_submenu_options( array $blocks, string $prefix = '', int $depth = 0 ): array {
		$options = array();
		foreach ( $blocks as $index => $block ) {
			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			if ( 'core/navigation-submenu' === ( $block['blockName'] ?? '' ) ) {
				$label     = wp_strip_all_tags( (string) ( $block['attrs']['label'] ?? __( 'Naamloos submenu', 'avpvh-audio-transcriptie' ) ) );
				$options[] = array(
					'id'     => $path,
					'value'  => 'block:' . $path,
					'title'  => str_repeat( '— ', $depth ) . $label,
					'parent' => 0,
				);
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$options = array_merge( $options, self::navigation_submenu_options( $block['innerBlocks'], $path, $depth + 1 ) );
			}
		}
		return $options;
	}

	/**
	 * Whether a parsed block is a navigation link to the given page.
	 *
	 * @param mixed $block   Parsed block.
	 * @param int   $page_id Page ID.
	 * @return bool
	 */
	private static function is_page_link( mixed $block, int $page_id ): bool {
		return is_array( $block )
			&& 'core/navigation-link' === ( $block['blockName'] ?? '' )
			&& absint( $block['attrs']['id'] ?? 0 ) === $page_id;
	}

	/**
	 * Puts a page link at the root or inside a selected navigation submenu.
	 * An existing link to the page in that place is updated instead of
	 * duplicated. The placed link carries the avpvhAtFresh marker, which
	 * prune_navigation_links() removes.
	 *
	 * @param array<int|string, mixed> $blocks  Parsed navigation blocks, updated in place.
	 * @param string                   $path    Dot-separated block index path, or empty for root.
	 * @param array<string, mixed>     $link    Parsed navigation-link block with the marker set.
	 * @param int                      $page_id Page ID.
	 * @return bool
	 */
	private static function place_navigation_link( array &$blocks, string $path, array $link, int $page_id ): bool {
		if ( '' === $path ) {
			foreach ( array_keys( $blocks ) as $index ) {
				if ( self::is_page_link( $blocks[ $index ], $page_id ) ) {
					$blocks[ $index ]['attrs'] = array_merge( (array) $blocks[ $index ]['attrs'], $link['attrs'] );
					return true;
				}
			}
			$blocks[] = $link;
			return true;
		}
		if ( ! preg_match( '/^\d+(?:\.\d+)*$/', $path ) ) {
			return false;
		}

		$indexes = array_map( 'absint', explode( '.', $path ) );
		$level   = &$blocks;
		foreach ( $indexes as $position => $index ) {
			if ( ! isset( $level[ $index ] ) || ! is_array( $level[ $index ] ) ) {
				return false;
			}
			if ( count( $indexes ) - 1 === $position ) {
				if ( 'core/navigation-submenu' !== ( $level[ $index ]['blockName'] ?? '' ) ) {
					return false;
				}
				$children = (array) ( $level[ $index ]['innerBlocks'] ?? array() );
				foreach ( array_keys( $children ) as $child ) {
					if ( self::is_page_link( $children[ $child ], $page_id ) ) {
						$level[ $index ]['innerBlocks'][ $child ]['attrs'] = array_merge( (array) $children[ $child ]['attrs'], $link['attrs'] );
						return true;
					}
				}
				// Put the placeholder after the last child so it stays inside any wrapper markup.
				$inner_content = (array) ( $level[ $index ]['innerContent'] ?? array() );
				$last_child    = array_keys( $inner_content, null, true );
				$insert_at     = $last_child ? end( $last_child ) + 1 : count( $inner_content );
				array_splice( $inner_content, $insert_at, 0, array( null ) );
				$level[ $index ]['innerBlocks'][] = $link;
				$level[ $index ]['innerContent']  = $inner_content;
				return true;
			}
			if ( ! isset( $level[ $index ]['innerBlocks'] ) || ! is_array( $level[ $index ]['innerBlocks'] ) ) {
				return false;
			}
			$level = &$level[ $index ]['innerBlocks'];
		}
		return false;
	}

	/**
	 * Removes every link to a page except the one carrying the avpvhAtFresh
	 * marker, and strips that marker. Keeps innerContent placeholders aligned.
	 *
	 * @param array<int|string, mixed> $blocks  Parsed blocks.
	 * @param int                      $page_id Page ID.
	 * @return array<int, mixed>
	 */
	private static function prune_navigation_links( array $blocks, int $page_id ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( self::is_page_link( $block, $page_id ) ) {
				if ( empty( $block['attrs']['avpvhAtFresh'] ) ) {
					continue;
				}
				unset( $block['attrs']['avpvhAtFresh'] );
			}
			if ( is_array( $block ) && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$children      = array();
				$inner_content = array();
				$child_index   = 0;
				foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
					if ( null !== $chunk ) {
						$inner_content[] = $chunk;
						continue;
					}
					$child = $block['innerBlocks'][ $child_index ] ?? null;
					++$child_index;
					if ( null === $child ) {
						continue;
					}
					$pruned = self::prune_navigation_links( array( $child ), $page_id );
					if ( $pruned ) {
						$children[]      = $pruned[0];
						$inner_content[] = null;
					}
				}
				$block['innerBlocks']  = $children;
				$block['innerContent'] = $inner_content;
			}
			$result[] = $block;
		}
		return $result;
	}

	/**
	 * Removes all links to a page from one block navigation.
	 *
	 * @param int $navigation_id wp_navigation post ID.
	 * @param int $page_id       Page ID.
	 * @return bool|WP_Error
	 */
	private static function remove_page_from_navigation( int $navigation_id, int $page_id ): bool|WP_Error {
		$navigation = get_post( $navigation_id );
		if ( ! $navigation || 'wp_navigation' !== $navigation->post_type ) {
			return true;
		}
		$blocks   = parse_blocks( $navigation->post_content );
		$original = serialize_blocks( $blocks );
		$content  = serialize_blocks( self::prune_navigation_links( $blocks, $page_id ) );
		if ( $content === $original ) {
			return true;
		}
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $navigation_id,
					'post_content' => $content,
				)
			),
			true
		);
		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Gets one local job.
	 *
	 * @param int $id Local job ID.
	 * @return stdClass|null
	 */
	private static function get_row( int $id ): ?stdClass {
		global $wpdb;
		$table = self::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is generated internally.
		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * Gets one local job or a 404 error.
	 *
	 * @param int $id Local job ID.
	 * @return stdClass|WP_Error
	 */
	private static function require_row( int $id ): stdClass|WP_Error {
		$row = self::get_row( $id );
		return null === $row ? self::missing_job() : $row;
	}

	/** Returns the error for a job that no longer exists. */
	private static function missing_job(): WP_Error {
		return new WP_Error( 'avpvh_at_job_missing', __( 'Transcriptie niet gevonden.', 'avpvh-audio-transcriptie' ), array( 'status' => 404 ) );
	}

	/**
	 * Cleans a secret without altering it: only surrounding whitespace and
	 * control characters are removed (sanitize_text_field() would strip
	 * percent-encoded sequences).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_secret( mixed $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * Presents a job without secret configuration.
	 *
	 * @param stdClass $row              Local job row.
	 * @param bool     $include_segments Whether transcript segments are needed.
	 * @return array<string, mixed>
	 */
	private static function present_job( stdClass $row, bool $include_segments = true ): array {
		return array(
			'id'              => (int) $row->id,
			'service_job_id'  => $row->service_job_id,
			'title'           => $row->title,
			'audio_name'      => $row->audio_name,
			'audio_url'       => $row->audio_url,
			'status'          => $row->status,
			'progress'        => (float) $row->progress,
			'duration'        => null !== $row->duration ? (float) $row->duration : null,
			'segments'        => $include_segments ? self::decode_segments( $row->segments ?? null ) : array(),
			'summary'         => $row->summary ?? null,
			'summary_pending' => $include_segments && (bool) get_transient( self::summary_pending_key( (int) $row->id ) ),
			'revision'        => (int) $row->revision,
			'updated_at'      => $row->updated_at,
		);
	}

	/**
	 * Stores the current job as a revision.
	 *
	 * @param stdClass $row Current job row.
	 * @return void
	 */
	private static function store_revision( stdClass $row ): void {
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
	 * @return array<mixed>
	 */
	private static function decode_segments( ?string $json ): array {
		$segments = json_decode( $json ? $json : '[]', true );
		return is_array( $segments ) ? $segments : array();
	}

	/**
	 * Sanitizes browser upload headers supplied by the service.
	 *
	 * @param mixed $headers Untrusted header map.
	 * @return array<string, string>
	 */
	private static function sanitize_headers( mixed $headers ): array {
		$clean = array();
		if ( ! is_array( $headers ) ) {
			return $clean;
		}
		foreach ( $headers as $name => $value ) {
			$name = (string) preg_replace( '/[^A-Za-z0-9!#$%&\'*+.^_`|~-]/', '', (string) $name );
			if ( '' === $name || ! is_scalar( $value ) ) {
				continue;
			}
			// Presigned values may contain percent-encoding; only strip control characters.
			$clean[ $name ] = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value );
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
