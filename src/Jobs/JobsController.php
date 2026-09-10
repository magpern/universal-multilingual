<?php
/**
 * Background translation jobs REST API (plan §20).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Jobs;

use AIMultilingual\Language\Languages;
use AIMultilingual\Surface\SurfaceCapabilityNames;
use AIMultilingual\Surface\SurfaceRegistry;
use AIMultilingual\Translation\Assessment\AssessmentAssembler;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Thin REST controller — validates, authorizes, delegates, serializes.
 */
final class JobsController {

	public const NAMESPACE   = 'aiml/v1';
	public const API_HEADER  = 'X-AIML-Jobs-Api-Version';
	public const API_VERSION = '1';

	/**
	 * Job domain service.
	 *
	 * @var BackgroundTranslationJobService
	 */
	private BackgroundTranslationJobService $jobs;

	/**
	 * Bulk job coordinator.
	 *
	 * @var BackgroundTranslationBatchCoordinator
	 */
	private BackgroundTranslationBatchCoordinator $batches;

	/**
	 * Action Scheduler wake service.
	 *
	 * @var BackgroundTranslationScheduler
	 */
	private BackgroundTranslationScheduler $scheduler;

	/**
	 * Worker for synchronous run.
	 *
	 * @var BackgroundTranslationWorker
	 */
	private BackgroundTranslationWorker $worker;

	/**
	 * Language registry.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * ViewModel serializer.
	 *
	 * @var JobsViewModelSerializer
	 */
	private JobsViewModelSerializer $serializer;

	/**
	 * Bounded diagnostics (J7).
	 *
	 * @var BackgroundTranslationDiagnostics
	 */
	private BackgroundTranslationDiagnostics $diagnostics;

	/**
	 * Canonical site concurrency gate.
	 *
	 * @var BackgroundTranslationConcurrencyPolicy
	 */
	private BackgroundTranslationConcurrencyPolicy $concurrency;

	/**
	 * Read-only TI.5 assessment core.
	 *
	 * @var AssessmentAssembler|null
	 */
	private ?AssessmentAssembler $assessment;

	/**
	 * Current segment assembler for item detail.
	 *
	 * @var SegmentAssembler|null
	 */
	private ?SegmentAssembler $assembler;

	/**
	 * Builds the controller.
	 *
	 * @param BackgroundTranslationJobService             $jobs         Job service.
	 * @param BackgroundTranslationBatchCoordinator       $batches      Batch coordinator.
	 * @param BackgroundTranslationScheduler              $scheduler    Scheduler.
	 * @param BackgroundTranslationWorker                 $worker       Worker.
	 * @param Languages                                   $languages    Languages.
	 * @param JobsViewModelSerializer|null                $serializer   Serializer.
	 * @param BackgroundTranslationDiagnostics|null       $diagnostics  Diagnostics.
	 * @param BackgroundTranslationConcurrencyPolicy|null $concurrency Concurrency gate.
	 * @param AssessmentAssembler|null                    $assessment Assessment assembler.
	 * @param SegmentAssembler|null                       $assembler Segment assembler.
	 * @param SurfaceRegistry|null                        $surfaces  Surface registry (TSC.0/TSC.1).
	 */
	public function __construct(
		BackgroundTranslationJobService $jobs,
		BackgroundTranslationBatchCoordinator $batches,
		BackgroundTranslationScheduler $scheduler,
		BackgroundTranslationWorker $worker,
		Languages $languages,
		?JobsViewModelSerializer $serializer = null,
		?BackgroundTranslationDiagnostics $diagnostics = null,
		?BackgroundTranslationConcurrencyPolicy $concurrency = null,
		?AssessmentAssembler $assessment = null,
		?SegmentAssembler $assembler = null,
		private ?SurfaceRegistry $surfaces = null
	) {
		$this->jobs        = $jobs;
		$this->batches     = $batches;
		$this->scheduler   = $scheduler;
		$this->worker      = $worker;
		$this->languages   = $languages;
		$this->serializer  = $serializer ?? new JobsViewModelSerializer();
		$this->diagnostics = $diagnostics ?? new BackgroundTranslationDiagnostics();
		$this->concurrency = $concurrency ?? new BackgroundTranslationConcurrencyPolicy();
		$this->assessment  = $assessment;
		$this->assembler   = $assembler;
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
	 * Route registration callback.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/jobs/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/batch/(?P<batch_id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_batch' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_jobs' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'can_view_job' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/items/(?P<item_id>\d+)/assessment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item_assessment' ),
				'permission_callback' => array( $this, 'can_view_job' ),
			)
		);

		$actions = array(
			'pause'        => array( $this, 'pause_job' ),
			'resume'       => array( $this, 'resume_job' ),
			'cancel'       => array( $this, 'cancel_job' ),
			'retry-failed' => array( $this, 'retry_failed_job' ),
			'run'          => array( $this, 'run_job' ),
		);

		foreach ( $actions as $action => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/jobs/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => $callback,
					'permission_callback' => array( $this, 'can_mutate_job' ),
				)
			);
		}
	}

	/**
	 * Whether the current user may view jobs.
	 *
	 * @return true|WP_Error
	 */
	public function can_view() {
		if ( current_user_can( JobsCapabilities::VIEW_JOBS ) ) {
			return true;
		}

		return new WP_Error(
			'aiml_forbidden',
			__( 'You do not have permission to view translation jobs.', 'universal-multilingual' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether the current user may create jobs.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( current_user_can( JobsCapabilities::MANAGE_JOBS ) ) {
			return true;
		}

		return new WP_Error(
			'aiml_forbidden',
			__( 'You do not have permission to manage translation jobs.', 'universal-multilingual' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether the current user may inspect one job (view + post scope).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_view_job( WP_REST_Request $request ) {
		$view = $this->can_view();
		if ( is_wp_error( $view ) ) {
			return $view;
		}

		return $this->assert_post_scope_for_job( (int) $request['id'] );
	}

	/**
	 * Whether the current user may mutate one job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_mutate_job( WP_REST_Request $request ) {
		$route = (string) $request->get_route();
		$cap   = JobsCapabilities::CANCEL_JOBS;

		if ( str_ends_with( $route, '/run' ) || str_ends_with( $route, '/retry-failed' ) ) {
			$cap = JobsCapabilities::RUN_JOBS;
		}

		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to perform this job action.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return $this->assert_post_scope_for_job( (int) $request['id'] );
	}

	/**
	 * Lists jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_jobs( WP_REST_Request $request ): WP_REST_Response {
		$per_page_param = $request->get_param( 'per_page' );
		$page           = max( 1, (int) $request->get_param( 'page' ) );
		$per_page       = max( 1, min( 100, (int) ( null !== $per_page_param && '' !== $per_page_param ? $per_page_param : 20 ) ) );

		$filters = array(
			'page'     => $page,
			'per_page' => $per_page,
		);

		$status = $request->get_param( 'status' );
		if ( null !== $status && '' !== $status ) {
			$filters['status'] = sanitize_key( (string) $status );
		}

		$batch_id = $request->get_param( 'batch_id' );
		if ( null !== $batch_id && '' !== $batch_id ) {
			$filters['batch_id'] = sanitize_text_field( (string) $batch_id );
		}

		$language = $request->get_param( 'language_id' );
		if ( null !== $language && '' !== $language ) {
			$filters['language_id'] = (int) $language;
		}

		$result = $this->jobs->list_jobs( $filters );

		return $this->respond(
			array(
				'items'    => $this->serializer->many_jobs_to_arrays( $result['items'] ),
				'total'    => $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
			)
		);
	}

	/**
	 * Creates one job or a bulk batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ) {
		$body = $this->body_params( $request );

		if ( ! empty( $body['posts'] ) && is_array( $body['posts'] ) ) {
			return $this->create_bulk_jobs( $body );
		}

		$args = $this->build_create_args( $body );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$scope = $this->assert_create_scope( $args );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$result = $this->jobs->create_job( $args );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}

		$this->maybe_autostart_job( $body, $result );

		return $this->respond( $this->serializer->job_array_with_operations( $result ), 201 );
	}

	/**
	 * Enqueue a worker wake for a freshly created job when the caller asked for
	 * it (user-facing "Translate with AI" actions only — ADR-0031 §4). Best
	 * effort: a scheduler outage leaves the job queued and the existing health
	 * banner explains why; the create response is never failed for this.
	 *
	 * @param array<string, mixed> $body   Request body.
	 * @param object               $result Created job row.
	 */
	private function maybe_autostart_job( array $body, object $result ): void {
		if ( empty( $body['autostart'] ) ) {
			return;
		}

		if ( JobStatuses::QUEUED !== (string) ( $result->status ?? '' ) ) {
			return;
		}

		if ( empty( $this->scheduler->health()['available'] ) ) {
			return;
		}

		$this->scheduler->enqueue_job( (int) $result->job_id );
	}

	/**
	 * Inspects one job with bounded item summaries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		$job    = $this->jobs->find_job( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}

		$items = $this->jobs->list_job_items( $job_id );

		return $this->respond( $this->serializer->job_detail_array_with_operations( $job, $items ) );
	}

	/**
	 * Compute TI.5 assessment for one job item without lifecycle mutation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item_assessment( WP_REST_Request $request ) {
		if ( null === $this->assessment || null === $this->assembler ) {
			return new WP_Error( 'assessment_unavailable', 'Assessment service is unavailable.', array( 'status' => 503 ) );
		}

		$job_id = (int) $request['id'];
		$item   = $this->jobs->find_job_item( (int) $request['item_id'] );
		$job    = $this->jobs->find_job( $job_id );
		if ( null === $job || null === $item || (int) $item->job_id !== $job_id ) {
			return new WP_Error( 'job_item_not_found', 'Job item not found.', array( 'status' => 404 ) );
		}

		$post = get_post( (int) $job->source_id );
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error( 'source_not_found', 'Job source post not found.', array( 'status' => 404 ) );
		}

		$segment = $this->assembler->assemble_one( $post, (int) $job->language_id, (string) $item->segment_key );
		if ( null === $segment ) {
			return new WP_Error( 'aiml_invalid_segment', 'Job segment is no longer available.', array( 'status' => 404 ) );
		}

		return $this->respond(
			array(
				'job_id'     => $job_id,
				'item_id'    => (int) $item->item_id,
				'assessment' => $this->assessment->assess_segment( $segment )->to_array(),
			)
		);
	}

	/**
	 * Returns derived batch progress.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_batch( WP_REST_Request $request ): WP_REST_Response {
		$batch_id = sanitize_text_field( (string) $request['batch_id'] );

		return $this->respond( $this->batches->batch_progress( $batch_id ) );
	}

	/**
	 * Returns Action Scheduler health.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function health( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->respond( $this->scheduler->health() );
	}

	/**
	 * Returns bounded job diagnostics aggregates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function diagnostics( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$snapshot                     = $this->diagnostics->snapshot();
		$snapshot['action_scheduler'] = $this->scheduler->health();

		return $this->respond( $snapshot );
	}

	/**
	 * Requests job pause and observes it at a safe boundary.
	 *
	 * Queued/retry_wait jobs have no in-flight item; observe immediately so
	 * operators can resume. Matches cancel REST behaviour. Running jobs that
	 * already hold a lease still observe pause inside the worker item loop;
	 * this call is still safe (idempotent when requested_action is none).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause_job( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];

		$result = $this->jobs->request_pause( $job_id );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}

		$observed = $this->jobs->observe_requested_action_at_boundary( $job_id );
		if ( is_wp_error( $observed ) ) {
			return $this->map_domain_error( $observed );
		}

		$job = is_object( $observed ) ? $observed : $result;

		return $this->respond( $this->serializer->job_array_with_operations( $job ) );
	}

	/**
	 * Resumes a paused job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_job( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		$job    = $this->jobs->find_job( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}
		// Admit before mutation so a saturated gate leaves the job paused.
		$admit = $this->admit_job( $job );
		if ( is_wp_error( $admit ) ) {
			return $this->map_domain_error( $admit );
		}
		$result = $this->jobs->resume( $job_id );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}
		$wake = $this->scheduler->enqueue_job( $job_id );
		if ( is_wp_error( $wake ) ) {
			return $this->map_domain_error( $wake );
		}

		return $this->respond( $this->serializer->job_array_with_operations( $result ) );
	}

	/**
	 * Requests job cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];

		$result = $this->jobs->request_cancel( $job_id );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}

		$observed = $this->jobs->observe_requested_action_at_boundary( $job_id );
		if ( is_wp_error( $observed ) ) {
			return $this->map_domain_error( $observed );
		}

		$job = is_object( $observed ) ? $observed : $result;

		return $this->respond( $this->serializer->job_array_with_operations( $job ) );
	}

	/**
	 * Resets failed items to queued.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry_failed_job( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		$job    = $this->jobs->find_job( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}
		// Admit before mutation so a saturated gate leaves terminal state intact.
		$admit = $this->admit_job( $job );
		if ( is_wp_error( $admit ) ) {
			return $this->map_domain_error( $admit );
		}

		$result = $this->jobs->retry_failed_items( $job_id );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}

		$wake = $this->scheduler->enqueue_job( $job_id );
		if ( is_wp_error( $wake ) ) {
			return $this->map_domain_error( $wake );
		}

		return $this->respond( $this->serializer->job_array_with_operations( $result ) );
	}

	/**
	 * Enqueues or synchronously runs a job wake.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		$job    = $this->jobs->find_job( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}
		$admit = $this->admit_job( $job );
		if ( is_wp_error( $admit ) ) {
			return $this->map_domain_error( $admit );
		}

		$sync = filter_var( $request->get_param( 'sync' ), FILTER_VALIDATE_BOOLEAN );

		if ( $sync ) {
			$result = $this->worker->run( $job_id );
			if ( is_wp_error( $result ) ) {
				return $this->map_domain_error( $result );
			}

			return $this->respond( $this->serializer->job_array_with_operations( $result ) );
		}

		$wake = $this->scheduler->enqueue_job( $job_id );
		if ( is_wp_error( $wake ) ) {
			return $this->map_domain_error( $wake );
		}

		return $this->respond(
			array(
				'job_id'  => $job_id,
				'queued'  => true,
				'message' => 'Worker wake enqueued.',
			),
			202
		);
	}

	/**
	 * Creates a bulk batch via the batch coordinator.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return WP_REST_Response|WP_Error
	 */
	private function create_bulk_jobs( array $body ) {
		$language_id = (int) ( $body['language_id'] ?? 0 );
		$language    = $this->validate_language_id( $language_id );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$posts = array_values( array_filter( (array) $body['posts'], 'is_array' ) );
		if ( array() === $posts ) {
			return new WP_Error( 'empty_workload', 'Bulk request requires at least one post.', array( 'status' => 422 ) );
		}

		$scope_posts = array();
		foreach ( $posts as $post_args ) {
			$source_id = (int) ( $post_args['source_id'] ?? 0 );
			if ( $source_id <= 0 ) {
				return new WP_Error( 'invalid_job_scope', 'Each bulk post requires source_id.', array( 'status' => 422 ) );
			}

			$post_scope = array(
				'source_type'  => Store::SOURCE_POST,
				'source_id'    => $source_id,
				'segment_keys' => array_values( array_map( 'strval', (array) ( $post_args['segment_keys'] ?? array() ) ) ),
			);

			// MLW1a (ADR-0034 D7): honour a per-post language_id so the generic
			// bulk API can also express an N objects × M languages request. The
			// batch coordinator already reads it; the shared language_id stays
			// the fallback.
			if ( isset( $post_args['language_id'] ) && (int) $post_args['language_id'] > 0 ) {
				$post_scope['language_id'] = (int) $post_args['language_id'];
			}

			$scope_posts[] = $post_scope;
		}

		$scope = $this->assert_edit_post_for_sources( $scope_posts );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$shared = $this->build_shared_create_args( $body );
		if ( is_wp_error( $shared ) ) {
			return $shared;
		}

		$result = $this->batches->create_bulk( $scope_posts, $language_id, $shared );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}

		if ( ! empty( $body['autostart'] ) && ! empty( $this->scheduler->health()['available'] ) ) {
			$this->batches->run_batch( (string) $result['batch_id'] );
		}

		return $this->respond(
			array(
				'batch_id' => $result['batch_id'],
				'jobs'     => $this->serializer->many_jobs_to_arrays( $result['jobs'] ),
			),
			201
		);
	}

	/**
	 * Builds create args for a single job.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_create_args( array $body ) {
		$job_type = sanitize_key( (string) ( $body['job_type'] ?? '' ) );
		if ( '' === $job_type ) {
			return new WP_Error( 'invalid_job_type', 'Unknown job type.', array( 'status' => 422 ) );
		}

		$language_id = (int) ( $body['language_id'] ?? 0 );
		$language    = $this->validate_language_id( $language_id );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$source_type = sanitize_key( (string) ( $body['source_type'] ?? Store::SOURCE_POST ) );
		$source_id   = (int) ( $body['source_id'] ?? 0 );
		if ( $source_id <= 0 ) {
			return new WP_Error( 'invalid_job_scope', 'Job requires source_type, source_id, and language_id.', array( 'status' => 422 ) );
		}

		$shared = $this->build_shared_create_args( $body );

		$args = array_merge(
			$shared,
			array(
				'job_type'    => $job_type,
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'language_id' => $language_id,
			)
		);

		if ( isset( $body['segment_keys'] ) ) {
			$args['segment_keys'] = array_values(
				array_map(
					static fn( $key ): string => (string) $key,
					(array) $body['segment_keys']
				)
			);
		}

		if ( isset( $body['segment_snapshots'] ) && is_array( $body['segment_snapshots'] ) ) {
			$args['segment_snapshots'] = $body['segment_snapshots'];
		}

		return $args;
	}

	/**
	 * Shared create fields across single and bulk jobs.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_shared_create_args( array $body ) {
		$args = array(
			'provider_id'    => sanitize_key( (string) ( $body['provider_id'] ?? '' ) ),
			'prompt_profile' => sanitize_key( (string) ( $body['prompt_profile'] ?? '' ) ),
			'prompt_version' => sanitize_text_field( (string) ( $body['prompt_version'] ?? '' ) ),
			'created_by'     => (int) get_current_user_id(),
		);

		foreach ( array( 'batch_id', 'client_token', 'provider_config_fp' ) as $optional_string ) {
			if ( isset( $body[ $optional_string ] ) && '' !== (string) $body[ $optional_string ] ) {
				$args[ $optional_string ] = sanitize_text_field( (string) $body[ $optional_string ] );
			}
		}

		if ( isset( $body['job_type'] ) && '' !== (string) $body['job_type'] ) {
			$args['job_type'] = sanitize_key( (string) $body['job_type'] );
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
	 * Validates create scope for a single job.
	 *
	 * @param array<string, mixed> $args Create args.
	 * @return true|WP_Error
	 */
	private function assert_create_scope( array $args ) {
		return $this->assert_edit_post_for_sources(
			array(
				array(
					'source_type' => (string) $args['source_type'],
					'source_id'   => (int) $args['source_id'],
				),
			)
		);
	}

	/**
	 * Ensures the current user may edit every requested source.
	 *
	 * @param list<array<string, mixed>> $sources Source descriptors.
	 * @return true|WP_Error
	 */
	private function assert_edit_post_for_sources( array $sources ) {
		foreach ( $sources as $source ) {
			$source_type = (string) ( $source['source_type'] ?? Store::SOURCE_POST );
			$source_id   = (int) ( $source['source_id'] ?? 0 );

			if ( $source_id <= 0 ) {
				return new WP_Error(
					'aiml_forbidden',
					__( 'You do not have permission to create jobs for one or more requested sources.', 'universal-multilingual' ),
					array( 'status' => 403 )
				);
			}

			if ( Store::SOURCE_POST === $source_type ) {
				if ( ! current_user_can( 'edit_post', $source_id ) ) {
					return new WP_Error(
						'aiml_forbidden',
						__( 'You do not have permission to create jobs for one or more requested posts.', 'universal-multilingual' ),
						array( 'status' => 403 )
					);
				}
				continue;
			}

			$surface = null !== $this->surfaces ? $this->surfaces->for( $source_type ) : null;
			if (
				null === $surface
				|| ! $surface->supports( SurfaceCapabilityNames::JOBS )
				|| ! $surface->user_can_edit_source( 0, $source_id )
			) {
				return new WP_Error(
					'aiml_forbidden',
					__( 'You do not have permission to create jobs for one or more requested sources.', 'universal-multilingual' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Ensures the current user may access the job's source scope.
	 *
	 * @param int $job_id Job id.
	 * @return true|WP_Error
	 */
	private function assert_post_scope_for_job( int $job_id ) {
		$job = $this->jobs->find_job( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.', array( 'status' => 404 ) );
		}

		$source_type = (string) $job->source_type;
		$source_id   = (int) $job->source_id;

		if ( Store::SOURCE_POST === $source_type ) {
			if ( ! current_user_can( 'edit_post', $source_id ) ) {
				return new WP_Error(
					'aiml_forbidden',
					__( 'You do not have permission to access this translation job.', 'universal-multilingual' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		$surface = null !== $this->surfaces ? $this->surfaces->for( $source_type ) : null;
		if (
			null === $surface
			|| ! $surface->supports( SurfaceCapabilityNames::JOBS )
			|| ! $surface->user_can_edit_source( 0, $source_id )
		) {
			return new WP_Error(
				'aiml_forbidden',
				__( 'You do not have permission to access this translation job.', 'universal-multilingual' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validates a target language id.
	 *
	 * @param int $language_id Language id.
	 * @return object|WP_Error
	 */
	private function validate_language_id( int $language_id ) {
		if ( $language_id <= 0 ) {
			return new WP_Error(
				'aiml_invalid_language',
				__( 'Unknown language.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		$language = $this->languages->find( $language_id );
		if ( null === $language ) {
			return new WP_Error(
				'aiml_invalid_language',
				__( 'Unknown language.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		$default = $this->languages->default();
		if ( null !== $default && (int) $default->language_id === $language_id ) {
			return new WP_Error(
				'aiml_invalid_language',
				__( 'The default language is the source; it is not translated.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		return $language;
	}

	/**
	 * Applies a domain mutation and serializes the result.
	 *
	 * @param int      $job_id   Job id.
	 * @param callable $callback Service callback.
	 * @return WP_REST_Response|WP_Error
	 */
	private function mutate_job( int $job_id, callable $callback ) {
		$result = $callback( $job_id );
		if ( is_wp_error( $result ) ) {
			return $this->map_domain_error( $result );
		}

		return $this->respond( $this->serializer->job_array_with_operations( $result ) );
	}

	/**
	 * Maps domain errors to HTTP statuses (plan §6.8).
	 *
	 * @param WP_Error $error Domain error.
	 * @return WP_Error
	 */
	private function map_domain_error( WP_Error $error ): WP_Error {
		$code   = $error->get_error_code();
		$status = 422;

		if ( in_array( $code, array( 'idempotency_conflict', 'lock_key_conflict', 'illegal_transition', 'job_not_resumable', 'concurrency_limit_exceeded' ), true ) ) {
			$status = 409;
		} elseif ( in_array( $code, array( 'aiml_forbidden', 'rest_forbidden' ), true ) ) {
			$status = 403;
		} elseif ( 'job_not_found' === $code ) {
			$status = 404;
		} elseif ( 'action_scheduler_unavailable' === $code ) {
			$status = 503;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['status'] = $status;
		$error->add_data( $data );

		return $error;
	}

	/**
	 * Apply the canonical concurrency gate and count rejects.
	 *
	 * @param object $job Job row.
	 * @return true|WP_Error
	 */
	private function admit_job( object $job ) {
		$result = $this->concurrency->admit_running(
			JobStatuses::RUNNING === (string) $job->status ? (int) $job->job_id : null
		);
		if ( is_wp_error( $result ) ) {
			$this->diagnostics->increment( BackgroundTranslationDiagnostics::CONCURRENCY_REJECTS );
		}
		return $result;
	}

	/**
	 * Extracts JSON/body params.
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
	 * Versioned jobs response.
	 *
	 * @param array<string, mixed> $payload Body.
	 * @param int                  $status  HTTP status.
	 */
	private function respond( array $payload, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $payload, $status );
		$response->header( self::API_HEADER, self::API_VERSION );

		return $response;
	}
}
