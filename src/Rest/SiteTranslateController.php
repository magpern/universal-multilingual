<?php
/**
 * Site Translate REST API.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Rest;

use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Jobs\JobsViewModelSerializer;
use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use AIMultilingual\SiteTranslate\SiteTranslateAdmissionService;
use AIMultilingual\SiteTranslate\SiteTranslateBatchService;
use AIMultilingual\SiteTranslate\SiteTranslateCoverageService;
use AIMultilingual\SiteTranslate\SiteTranslateLocalizedUrlBatchService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Thin REST controller for Site Translate operator workflows.
 */
final class SiteTranslateController {

	public const REST_NAMESPACE = 'aiml/v1';

	public const REST_BASE = 'site-translate';

	/**
	 * Coverage read model.
	 *
	 * @var SiteTranslateCoverageService
	 */
	private SiteTranslateCoverageService $coverage;

	/**
	 * Strategy F admission.
	 *
	 * @var SiteTranslateAdmissionService
	 */
	private SiteTranslateAdmissionService $admission;

	/**
	 * Job batch orchestration.
	 *
	 * @var SiteTranslateBatchService
	 */
	private SiteTranslateBatchService $batches;

	/**
	 * Localized URL batch orchestration.
	 *
	 * @var SiteTranslateLocalizedUrlBatchService
	 */
	private SiteTranslateLocalizedUrlBatchService $localized_urls;

	/**
	 * Language registry.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Job serializer.
	 *
	 * @var JobsViewModelSerializer
	 */
	private JobsViewModelSerializer $job_serializer;

	/**
	 * Builds the controller.
	 *
	 * @param SiteTranslateCoverageService          $coverage       Coverage service.
	 * @param SiteTranslateAdmissionService         $admission      Admission service.
	 * @param SiteTranslateBatchService             $batches        Batch service.
	 * @param SiteTranslateLocalizedUrlBatchService $localized_urls LU batch service.
	 * @param Languages                             $languages      Languages.
	 * @param JobsViewModelSerializer               $job_serializer Job serializer.
	 */
	public function __construct(
		SiteTranslateCoverageService $coverage,
		SiteTranslateAdmissionService $admission,
		SiteTranslateBatchService $batches,
		SiteTranslateLocalizedUrlBatchService $localized_urls,
		Languages $languages,
		JobsViewModelSerializer $job_serializer
	) {
		$this->coverage       = $coverage;
		$this->admission      = $admission;
		$this->batches        = $batches;
		$this->localized_urls = $localized_urls;
		$this->languages      = $languages;
		$this->job_serializer = $job_serializer;
	}

	/**
	 * Registers REST routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( did_action( 'rest_api_init' ) ) {
			$this->register_routes();
		}
	}

	/**
	 * Registers Site Translate routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/objects',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_objects' ),
				'permission_callback' => array( $this, 'can_translate' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/coverage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_coverage' ),
				'permission_callback' => array( $this, 'can_translate' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/admission',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'check_admission' ),
				'permission_callback' => array( $this, 'can_manage_jobs' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_jobs' ),
				'permission_callback' => array( $this, 'can_manage_jobs' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/jobs/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_batch' ),
				'permission_callback' => array( $this, 'can_run_jobs' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/routes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_routes' ),
				'permission_callback' => array( $this, 'can_translate' ),
			)
		);
	}

	/**
	 * Lists Site Translate objects with coverage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_objects( WP_REST_Request $request ) {
		$language = $this->resolve_language_id( $request );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$result = $this->coverage->list_objects(
			array(
				'page'        => (int) $request->get_param( 'page' ),
				'per_page'    => (int) $request->get_param( 'per_page' ),
				'search'      => (string) $request->get_param( 'search' ),
				'post_type'   => (string) $request->get_param( 'post_type' ),
				'language_id' => (int) $language,
			)
		);

		return $this->respond(
			array_merge(
				$result,
				array(
					'strategy_f_valid' => $this->admission->is_strategy_f_fully_valid(),
				)
			)
		);
	}

	/**
	 * Returns coverage for explicit post ids.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_coverage( WP_REST_Request $request ) {
		$language = $this->resolve_language_id( $request );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$post_ids = array_map( 'intval', (array) $request->get_param( 'post_ids' ) );
		if ( array() === $post_ids ) {
			return new WP_Error( 'invalid_selection', __( 'post_ids is required.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		return $this->respond(
			array(
				'language_id'      => (int) $language,
				'items'            => $this->coverage->coverage_for_ids( $post_ids, (int) $language ),
				'strategy_f_valid' => $this->admission->is_strategy_f_fully_valid(),
			)
		);
	}

	/**
	 * Validates Strategy F admission for a selection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_admission( WP_REST_Request $request ) {
		$body     = $this->body_params( $request );
		$post_ids = array_map( 'intval', (array) ( $body['post_ids'] ?? array() ) );

		$scope = $this->assert_edit_posts( $post_ids );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$result = $this->admission->validate_selection( $post_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->respond(
			array(
				'allowed'          => true,
				'strategy_f_valid' => $this->admission->is_strategy_f_fully_valid(),
			)
		);
	}

	/**
	 * Creates chunked Site Translate Jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_jobs( WP_REST_Request $request ) {
		$body        = $this->body_params( $request );
		$post_ids    = array_map( 'intval', (array) ( $body['post_ids'] ?? array() ) );
		$language_id = (int) ( $body['language_id'] ?? 0 );

		$language = $this->validate_language_id( $language_id );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$scope = $this->assert_edit_posts( $post_ids );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$shared = $this->build_shared_create_args( $body );
		$result = $this->batches->create_jobs(
			$post_ids,
			$language_id,
			$shared,
			isset( $body['batch_id'] ) ? sanitize_text_field( (string) $body['batch_id'] ) : null
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$autostarted = false;
		if ( ! empty( $body['autostart'] ) && ! empty( (string) $result['batch_id'] ) ) {
			$run         = $this->batches->run_batch_now( (string) $result['batch_id'] );
			$autostarted = ! is_wp_error( $run );
		}

		$status = ! empty( $result['complete'] ) ? 201 : 207;

		return $this->respond(
			array(
				'batch_id'        => $result['batch_id'],
				'autostarted'     => $autostarted,
				'complete'        => (bool) $result['complete'],
				'created_count'   => (int) $result['created_count'],
				'attempted_count' => (int) $result['attempted_count'],
				'skipped_count'   => (int) $result['skipped_count'],
				'chunk_count'     => (int) $result['chunk_count'],
				'failed'          => $result['failed'],
				'jobs'            => $this->job_serializer->many_jobs_to_arrays( $result['jobs'] ),
			),
			$status
		);
	}

	/**
	 * Enqueues all waiting jobs in a Site Translate batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_batch( WP_REST_Request $request ) {
		$body     = $this->body_params( $request );
		$batch_id = sanitize_text_field( (string) ( $body['batch_id'] ?? '' ) );

		$result = $this->batches->run_batch_now( $batch_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->respond( $result, 202 );
	}

	/**
	 * Generates slug candidates and publishes routes for a selection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish_routes( WP_REST_Request $request ) {
		$body        = $this->body_params( $request );
		$post_ids    = array_map( 'intval', (array) ( $body['post_ids'] ?? array() ) );
		$language_id = (int) ( $body['language_id'] ?? 0 );

		$language = $this->validate_language_id( $language_id );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$scope = $this->assert_edit_posts( $post_ids );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		return $this->respond(
			$this->localized_urls->generate_and_publish(
				$post_ids,
				$language_id,
				(int) get_current_user_id()
			)
		);
	}

	/**
	 * Workspace translate permission.
	 *
	 * @return bool|WP_Error
	 */
	public function can_translate() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to access the translator workspace.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Job manage permission.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage_jobs() {
		$translate = $this->can_translate();
		if ( is_wp_error( $translate ) ) {
			return $translate;
		}

		if ( ! current_user_can( JobsCapabilities::MANAGE_JOBS ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to manage translation jobs.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Job run permission.
	 *
	 * @return bool|WP_Error
	 */
	public function can_run_jobs() {
		$manage = $this->can_manage_jobs();
		if ( is_wp_error( $manage ) ) {
			return $manage;
		}

		if ( ! current_user_can( JobsCapabilities::RUN_JOBS ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to run translation jobs.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Resolves language id from code or id param.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|WP_Error
	 */
	private function resolve_language_id( WP_REST_Request $request ) {
		$language_id = (int) $request->get_param( 'language_id' );
		if ( $language_id > 0 ) {
			return $this->validate_language_id( $language_id );
		}

		$code = sanitize_key( (string) $request->get_param( 'language' ) );
		if ( '' === $code ) {
			return new WP_Error( 'invalid_language', __( 'language or language_id is required.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		$lang = $this->languages->find_by_code( $code );
		if ( null === $lang ) {
			return new WP_Error( 'invalid_language', __( 'Unknown language.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		return (int) $lang->language_id;
	}

	/**
	 * Validates a language id.
	 *
	 * @param int $language_id Language id.
	 * @return true|WP_Error
	 */
	private function validate_language_id( int $language_id ) {
		if ( $language_id <= 0 ) {
			return new WP_Error( 'invalid_language', __( 'language_id is required.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		if ( null === $this->languages->find( $language_id ) ) {
			return new WP_Error( 'invalid_language', __( 'Unknown language.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		return $language_id;
	}

	/**
	 * Ensures the current user may edit all selected posts.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return true|WP_Error
	 */
	private function assert_edit_posts( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'aiml_forbidden',
					__( 'You do not have permission to edit one or more selected objects.', 'universal-multilingual' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Shared job create args from request body.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>
	 */
	private function build_shared_create_args( array $body ): array {
		$args = array(
			'provider_id'    => sanitize_key( (string) ( $body['provider_id'] ?? '' ) ),
			'prompt_profile' => sanitize_key( (string) ( $body['prompt_profile'] ?? '' ) ),
			'prompt_version' => sanitize_text_field( (string) ( $body['prompt_version'] ?? '' ) ),
			'created_by'     => (int) get_current_user_id(),
		);

		foreach ( array( 'client_token', 'provider_config_fp' ) as $optional_string ) {
			if ( isset( $body[ $optional_string ] ) && '' !== (string) $body[ $optional_string ] ) {
				$args[ $optional_string ] = sanitize_text_field( (string) $body[ $optional_string ] );
			}
		}

		foreach ( array( 'budget_max_requests', 'budget_max_tokens', 'budget_warning_pct', 'glossary_version_intended' ) as $optional_int ) {
			if ( isset( $body[ $optional_int ] ) ) {
				$args[ $optional_int ] = (int) $body[ $optional_int ];
			}
		}

		if ( ! empty( $body['force_new'] ) ) {
			$args['force_new'] = true;
		}

		return $args;
	}

	/**
	 * JSON body params helper.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function body_params( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		if ( array() === $params ) {
			$params = (array) $request->get_body_params();
		}

		return $params;
	}

	/**
	 * Wraps a REST response.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @param int                  $status  HTTP status.
	 */
	private function respond( array $payload, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $payload, $status );
	}
}
