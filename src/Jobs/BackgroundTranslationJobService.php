<?php
/**
 * Background translation job domain lifecycle (create, pause, cancel, finalize).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Jobs;

use AIMultilingual\Surface\SurfaceCapabilityNames;
use AIMultilingual\Surface\SurfaceRegistry;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\TranslatableSegmentEligibility;
use WP_Error;
use WP_Post;

/**
 * Job orchestration domain service — no Action Scheduler wake in J2 (plan §19).
 */
final class BackgroundTranslationJobService {

	/**
	 * Job repository.
	 *
	 * @var BackgroundTranslationJobRepository
	 */
	private BackgroundTranslationJobRepository $jobs;

	/**
	 * Item repository.
	 *
	 * @var BackgroundTranslationItemRepository
	 */
	private BackgroundTranslationItemRepository $items;

	/**
	 * Lease service.
	 *
	 * @var JobLeaseService
	 */
	private JobLeaseService $leases;

	/**
	 * Progress reconciler.
	 *
	 * @var JobProgressReconciler
	 */
	private JobProgressReconciler $reconciler;

	/**
	 * Segment store for create-time resolution (J3).
	 *
	 * @var Store|null
	 */
	private ?Store $store;

	/**
	 * Segment assembler for create-time resolution (J3).
	 *
	 * @var SegmentAssembler|null
	 */
	private ?SegmentAssembler $assembler;

	/**
	 * Action Scheduler health gate (J4).
	 *
	 * @var BackgroundTranslationScheduler|null
	 */
	private ?BackgroundTranslationScheduler $scheduler;

	/**
	 * Create-time budget preflight (J4).
	 *
	 * @var BackgroundTranslationBudgetPolicy|null
	 */
	private ?BackgroundTranslationBudgetPolicy $budget;

	/**
	 * Provider availability at create (J4).
	 *
	 * @var BackgroundTranslationJobProviderValidator|null
	 */
	private ?BackgroundTranslationJobProviderValidator $provider_validator;

	/**
	 * Audit logger (J7).
	 *
	 * @var BackgroundTranslationJobAuditLogger|null
	 */
	private ?BackgroundTranslationJobAuditLogger $audit;

	/**
	 * Bounded diagnostics (J7).
	 *
	 * @var BackgroundTranslationDiagnostics|null
	 */
	private ?BackgroundTranslationDiagnostics $diagnostics;

	/**
	 * Surface registry for source-type admission facts (TSC.0).
	 *
	 * @var SurfaceRegistry|null
	 */
	private ?SurfaceRegistry $surfaces;

	/**
	 * Builds the job service.
	 *
	 * @param BackgroundTranslationJobRepository|null        $jobs                Job repository.
	 * @param BackgroundTranslationItemRepository|null       $items               Item repository.
	 * @param JobLeaseService|null                           $leases              Lease service.
	 * @param JobProgressReconciler|null                     $reconciler          Progress reconciler.
	 * @param Store|null                                     $store               Segment store.
	 * @param SegmentAssembler|null                          $assembler           Segment assembler.
	 * @param BackgroundTranslationScheduler|null            $scheduler           AS scheduler.
	 * @param BackgroundTranslationBudgetPolicy|null         $budget              Budget policy.
	 * @param BackgroundTranslationJobProviderValidator|null $provider_validator  Provider validator.
	 * @param BackgroundTranslationJobAuditLogger|null       $audit               Audit logger.
	 * @param BackgroundTranslationDiagnostics|null          $diagnostics         Diagnostics.
	 * @param SurfaceRegistry|null                           $surfaces            Surface registry.
	 */
	public function __construct(
		?BackgroundTranslationJobRepository $jobs = null,
		?BackgroundTranslationItemRepository $items = null,
		?JobLeaseService $leases = null,
		?JobProgressReconciler $reconciler = null,
		?Store $store = null,
		?SegmentAssembler $assembler = null,
		?BackgroundTranslationScheduler $scheduler = null,
		?BackgroundTranslationBudgetPolicy $budget = null,
		?BackgroundTranslationJobProviderValidator $provider_validator = null,
		?BackgroundTranslationJobAuditLogger $audit = null,
		?BackgroundTranslationDiagnostics $diagnostics = null,
		?SurfaceRegistry $surfaces = null
	) {
		$this->jobs               = $jobs ?? new BackgroundTranslationJobRepository();
		$this->items              = $items ?? new BackgroundTranslationItemRepository();
		$this->leases             = $leases ?? new JobLeaseService( $this->jobs, $this->items );
		$this->reconciler         = $reconciler ?? new JobProgressReconciler( $this->jobs, $this->items );
		$this->store              = $store;
		$this->assembler          = $assembler;
		$this->scheduler          = $scheduler;
		$this->budget             = $budget ?? new BackgroundTranslationBudgetPolicy( $this->jobs );
		$this->provider_validator = $provider_validator;
		$this->audit              = $audit;
		$this->diagnostics        = $diagnostics;
		$this->surfaces           = $surfaces;
	}

	/**
	 * Create a job, materialize items, and acquire active_lock_key.
	 *
	 * J2 note: translate_missing and retranslate_stale accept a pre-resolved
	 * segment_keys list (and optional source_hash_captured / translation_hash_captured
	 * per item via segment_snapshots). When segment_keys are omitted, J3 resolves
	 * eligible keys from Store via SegmentAssembler.
	 *
	 * @param array<string, mixed> $args Create arguments.
	 * @return object|WP_Error
	 */
	public function create_job( array $args ) {
		$validation = $this->validate_create_args( $args );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( null !== $this->scheduler ) {
			$health = $this->scheduler->health();
			if ( empty( $health['available'] ) ) {
				return new WP_Error(
					'action_scheduler_unavailable',
					(string) ( $health['message'] ?? 'Action Scheduler is not available.' )
				);
			}
		}

		$job_type = (string) $args['job_type'];
		$segments = $this->resolve_segment_keys( $args, $job_type );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}

		if ( array() === $segments ) {
			return new WP_Error( 'empty_workload', 'No segments to materialize for this job.' );
		}

		if ( count( $segments ) > JobBounds::MAX_ITEMS_PER_JOB ) {
			return new WP_Error(
				'workload_limit_exceeded',
				'Job exceeds max items per job.',
				array( 'status' => 422 )
			);
		}

		if ( null !== $this->budget ) {
			$preflight = $this->budget->preflight( $args, count( $segments ) );
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}
		}

		if ( null !== $this->provider_validator && ! empty( $args['provider_id'] ) ) {
			$probe = (object) array(
				'provider_id' => (string) $args['provider_id'],
			);
			if ( ! $this->provider_validator->is_provider_available( $probe ) ) {
				return new WP_Error( 'provider_unavailable', 'Configured provider is not available.' );
			}
		}

		$idempotency = $this->resolve_idempotency_key( $args );
		if ( is_wp_error( $idempotency ) ) {
			return $idempotency;
		}

		$idempotency_key = $idempotency['key'];
		$force_new       = $idempotency['force_new'];
		if ( ! $force_new ) {
			$existing = $this->jobs->find_by_idempotency_key( $idempotency_key );
			if ( null !== $existing ) {
				$reuse = $this->handle_idempotency_hit( $existing, $args );
				if ( null !== $reuse ) {
					return $reuse;
				}
			}
		}

		$lock_key = JobLockKey::build(
			(string) $args['source_type'],
			(int) $args['source_id'],
			(int) ( $args['language_id'] ?? 0 )
		);

		$lock_holder = $this->jobs->find_active_by_lock_key( $lock_key );
		if ( null !== $lock_holder && empty( $args['force_new'] ) ) {
			if ( ! $force_new ) {
				$existing = $this->jobs->find_by_idempotency_key( $idempotency_key );
				if ( null !== $existing && (int) $existing->job_id === (int) $lock_holder->job_id ) {
					return $existing;
				}
			}

			return new WP_Error(
				'lock_key_conflict',
				'Another active job holds the object+language lock.',
				array( 'existing_job_id' => (int) $lock_holder->job_id )
			);
		}

		$job_row = array(
			'job_type'                  => $job_type,
			'status'                    => JobStatuses::QUEUED,
			'requested_action'          => RequestedActions::NONE,
			'batch_id'                  => isset( $args['batch_id'] ) ? (string) $args['batch_id'] : null,
			'idempotency_key'           => $idempotency_key,
			'source_type'               => (string) $args['source_type'],
			'source_id'                 => (int) $args['source_id'],
			'language_id'               => (int) $args['language_id'],
			'lock_key'                  => $lock_key,
			'active_lock_key'           => $this->leases->active_lock_for_create( $lock_key ),
			'provider_id'               => (string) ( $args['provider_id'] ?? '' ),
			'prompt_profile'            => (string) ( $args['prompt_profile'] ?? '' ),
			'prompt_version'            => (string) ( $args['prompt_version'] ?? '' ),
			'provider_config_fp'        => (string) ( $args['provider_config_fp'] ?? '' ),
			'glossary_version_intended' => (int) ( $args['glossary_version_intended'] ?? 0 ),
			'budget_max_requests'       => (int) ( $args['budget_max_requests'] ?? 0 ),
			'budget_max_tokens'         => (int) ( $args['budget_max_tokens'] ?? 0 ),
			'budget_warning_pct'        => (int) ( $args['budget_warning_pct'] ?? 80 ),
			'created_by'                => (int) ( $args['created_by'] ?? 0 ),
		);

		$inserted = $this->jobs->insert( $job_row );
		if ( is_wp_error( $inserted ) ) {
			return $this->map_create_insert_error( $inserted, $lock_key, $args );
		}

		$snapshots = (array) ( $args['segment_snapshots'] ?? array() );
		foreach ( $segments as $segment_key ) {
			$snapshot = (array) ( $snapshots[ $segment_key ] ?? array() );
			$item     = $this->items->insert(
				array(
					'job_id'                    => (int) $inserted->job_id,
					'segment_key'               => $segment_key,
					'status'                    => ItemStatuses::QUEUED,
					'source_hash_captured'      => (string) ( $snapshot['source_hash_captured'] ?? '' ),
					'translation_hash_captured' => (string) ( $snapshot['translation_hash_captured'] ?? '' ),
				)
			);

			if ( is_wp_error( $item ) ) {
				return $item;
			}
		}

		$reconciled = $this->reconciler->reconcile( (int) $inserted->job_id );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}

		if ( 0 === (int) $reconciled->total_items ) {
			$failed = $this->transition_job(
				(int) $inserted->job_id,
				JobStatuses::FAILED,
				array(
					'last_error_code'    => 'empty_workload',
					'last_error_message' => 'Job materialized zero items.',
					'finished_at'        => current_time( 'mysql', true ),
				)
			);
			if ( is_wp_error( $failed ) ) {
				return $failed;
			}
			$this->leases->clear_active_lock_on_terminal( (int) $inserted->job_id );
			$final = $this->jobs->find( (int) $inserted->job_id );
			$this->audit_job( BackgroundTranslationJobAuditEvents::FAILED, $final, array( 'source_surface' => 'create' ) );

			return $final;
		}

		$created = $this->jobs->find( (int) $inserted->job_id );
		$this->audit_job( BackgroundTranslationJobAuditEvents::CREATED, $created, array( 'source_surface' => 'create' ) );

		return $created;
	}

	/**
	 * List jobs with optional filters.
	 *
	 * @param array<string, mixed> $filters Query filters.
	 * @return array{items: list<object>, total: int, page: int, per_page: int}
	 */
	public function list_jobs( array $filters ): array {
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $filters['per_page'] ?? 20 ) ) );

		$query = $this->jobs->query(
			array(
				'status'      => $filters['status'] ?? null,
				'batch_id'    => $filters['batch_id'] ?? null,
				'language_id' => $filters['language_id'] ?? null,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);

		return array(
			'items'    => $query['items'],
			'total'    => $query['total'],
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Load one job row.
	 *
	 * @param int $job_id Job id.
	 * @return object|null
	 */
	public function find_job( int $job_id ): ?object {
		return $this->jobs->find( $job_id );
	}

	/**
	 * Load item rows for one job.
	 *
	 * @param int         $job_id Job id.
	 * @param string|null $status Optional item status filter.
	 * @return list<object>
	 */
	public function list_job_items( int $job_id, ?string $status = null ): array {
		return $this->items->list_by_job( $job_id, $status );
	}

	/**
	 * Load one job item row.
	 *
	 * @param int $item_id Item id.
	 */
	public function find_job_item( int $item_id ): ?object {
		return $this->items->find( $item_id );
	}

	/**
	 * Set requested_action to pause (observed at safe item boundary).
	 *
	 * @param int $job_id Job id.
	 * @return object|WP_Error
	 */
	public function request_pause( int $job_id ) {
		return $this->set_requested_action( $job_id, RequestedActions::PAUSE );
	}

	/**
	 * Set requested_action to cancel (observed at safe item boundary).
	 *
	 * @param int $job_id Job id.
	 * @return object|WP_Error
	 */
	public function request_cancel( int $job_id ) {
		return $this->set_requested_action( $job_id, RequestedActions::CANCEL );
	}

	/**
	 * Resume a paused job.
	 *
	 * @param int $job_id Job id.
	 * @return object|WP_Error
	 */
	public function resume( int $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		$valid = JobTransitionPolicy::validate_resume( $job );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$updated = $this->transition_job(
			$job_id,
			JobTransitionPolicy::resume_target(),
			array(
				'requested_action' => RequestedActions::NONE,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->audit_job( BackgroundTranslationJobAuditEvents::RESUMED, $updated, array( 'source_surface' => 'operator' ) );

		return $updated;
	}

	/**
	 * Pause a non-terminal job with orchestration error metadata (budget/provider).
	 *
	 * @param int    $job_id      Job id.
	 * @param string $error_code  Stable error code.
	 * @param string $message     Bounded operator message.
	 * @param string $error_class Error class (retryable|permanent).
	 * @return object|WP_Error
	 */
	public function pause_for_orchestration_error(
		int $job_id,
		string $error_code,
		string $message,
		string $error_class = 'permanent'
	) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		if ( JobStatuses::is_terminal( (string) $job->status ) ) {
			return $job;
		}

		if ( JobStatuses::PAUSED === (string) $job->status ) {
			$updated = $this->jobs->update(
				$job_id,
				array(
					'last_error_code'    => $error_code,
					'last_error_class'   => $error_class,
					'last_error_message' => $message,
				)
			);
			if ( 'budget_exceeded' === $error_code ) {
				$this->audit_budget_exceeded( is_object( $updated ) ? $updated : $job );
			}

			return $updated;
		}

		$updated = $this->transition_job(
			$job_id,
			JobTransitionPolicy::pause_target(),
			array(
				'requested_action'   => RequestedActions::NONE,
				'last_error_code'    => $error_code,
				'last_error_class'   => $error_class,
				'last_error_message' => $message,
			)
		);

		if ( ! is_wp_error( $updated ) ) {
			$this->audit_job(
				BackgroundTranslationJobAuditEvents::PAUSED,
				$updated,
				array(
					'source_surface' => 'worker',
					'error_code'     => $error_code,
					'error_class'    => $error_class,
				)
			);
			if ( 'budget_exceeded' === $error_code ) {
				$this->audit_budget_exceeded( $updated );
			}
		}

		return $updated;
	}

	/**
	 * Apply pause/cancel at a safe item boundary.
	 *
	 * @param int $job_id Job id.
	 * @return object|WP_Error|null Null when no pending requested_action.
	 */
	public function observe_requested_action_at_boundary( int $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		$action = (string) $job->requested_action;
		if ( ! RequestedActions::is_pending( $action ) ) {
			return null;
		}

		if ( RequestedActions::PAUSE === $action ) {
			$updated = $this->transition_job(
				$job_id,
				JobTransitionPolicy::pause_target(),
				array(
					'requested_action' => RequestedActions::NONE,
				)
			);

			if ( ! is_wp_error( $updated ) ) {
				$this->audit_job( BackgroundTranslationJobAuditEvents::PAUSED, $updated, array( 'source_surface' => 'operator' ) );
			}

			return is_wp_error( $updated ) ? $updated : $updated;
		}

		if ( RequestedActions::CANCEL === $action ) {
			$this->cancel_remaining_items( $job_id );

			$updated = $this->transition_job(
				$job_id,
				JobTransitionPolicy::cancel_target(),
				array(
					'requested_action' => RequestedActions::NONE,
					'finished_at'      => current_time( 'mysql', true ),
				)
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$this->leases->clear_active_lock_on_terminal( $job_id );
			$this->jobs->archive_idempotency_key( $job_id );
			$this->reconciler->reconcile( $job_id );

			$final = $this->jobs->find( $job_id );
			$this->audit_job( BackgroundTranslationJobAuditEvents::CANCELLED, $final, array( 'source_surface' => 'operator' ) );

			return $final;
		}

		return null;
	}

	/**
	 * Reset failed items to queued for operator retry-failed.
	 *
	 * Preserves last_error_* evidence until a new attempt starts (J2: not cleared on reset).
	 *
	 * @param int $job_id Job id.
	 * @return object|WP_Error
	 */
	public function retry_failed_items( int $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		// UI + execution share TI.6 admission eligibility (caps enforced at REST).
		$admission = new JobsOperationAdmission( $this->items );
		$decision  = $admission->admit(
			JobsOperationAdmission::OP_RETRY_FAILED,
			$job,
			array( 'can_run' => true )
		);
		if ( ! $decision['allowed'] ) {
			if ( JobsOperationAdmission::REASON_NO_FAILED_ITEMS === $decision['reason_code'] ) {
				return $job;
			}

			return new WP_Error( 'illegal_transition', 'This terminal job is not eligible for retry-failed.' );
		}

		$failed = $this->items->list_by_job( $job_id, ItemStatuses::FAILED );
		if ( array() === $failed ) {
			return $job;
		}

		$status = (string) $job->status;
		if ( in_array( $status, array( JobStatuses::FAILED, JobStatuses::COMPLETED_WITH_ERRORS ), true ) ) {
			$transitioned = $this->transition_job(
				$job_id,
				JobStatuses::QUEUED,
				array(
					'finished_at'     => null,
					'active_lock_key' => (string) $job->lock_key,
				)
			);
			if ( is_wp_error( $transitioned ) ) {
				return $transitioned;
			}
		}

		foreach ( $failed as $item ) {
			$check = ItemTransitionPolicy::validate_transition( ItemStatuses::FAILED, ItemStatuses::QUEUED );
			if ( is_wp_error( $check ) ) {
				continue;
			}

			$this->items->update(
				(int) $item->item_id,
				array(
					'status'      => ItemStatuses::QUEUED,
					'result_code' => '',
				)
			);
		}

		$reconciled = $this->reconciler->reconcile( $job_id );
		return is_wp_error( $reconciled ) ? $reconciled : $reconciled;
	}

	/**
	 * Derive terminal job status from item rows (plan §16).
	 *
	 * @param int $job_id Job id.
	 * @return object|WP_Error
	 */
	public function finalize_job_from_items( int $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		if ( JobStatuses::CANCELLED === (string) $job->status ) {
			return $job;
		}

		$counts = $this->items->count_by_status( $job_id );

		$completed            = (int) ( $counts[ ItemStatuses::COMPLETED ] ?? 0 );
		$terminal_non_success = 0;

		foreach ( $counts as $status => $count ) {
			if ( ItemStatuses::is_non_success_terminal( (string) $status ) ) {
				$terminal_non_success += (int) $count;
			}
		}

		$active_items = 0;
		foreach ( array( ItemStatuses::QUEUED, ItemStatuses::RUNNING, ItemStatuses::RETRY_WAIT ) as $active_status ) {
			$active_items += (int) ( $counts[ $active_status ] ?? 0 );
		}

		if ( $active_items > 0 ) {
			return $job;
		}

		$target = JobStatuses::FAILED;
		if ( $completed > 0 && 0 === $terminal_non_success ) {
			$target = JobStatuses::COMPLETED;
		} elseif ( $completed > 0 && $terminal_non_success > 0 ) {
			$target = JobStatuses::COMPLETED_WITH_ERRORS;
		} elseif ( 0 === $completed && $terminal_non_success > 0 ) {
			$target = JobStatuses::FAILED;
		} elseif ( 0 === $completed && 0 === $terminal_non_success ) {
			$target = JobStatuses::FAILED;
		}

		$updated = $this->transition_job(
			$job_id,
			$target,
			array(
				'finished_at' => current_time( 'mysql', true ),
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->leases->clear_active_lock_on_terminal( $job_id );

		if ( in_array( $target, array( JobStatuses::COMPLETED, JobStatuses::COMPLETED_WITH_ERRORS, JobStatuses::FAILED ), true ) ) {
			$this->jobs->archive_idempotency_key( $job_id );
		}

		$this->reconciler->reconcile( $job_id );

		$final = $this->jobs->find( $job_id );
		if ( null !== $final ) {
			if ( in_array( $target, array( JobStatuses::COMPLETED, JobStatuses::COMPLETED_WITH_ERRORS ), true ) ) {
				$this->audit_job( BackgroundTranslationJobAuditEvents::COMPLETED, $final, array( 'source_surface' => 'worker' ) );
			} elseif ( JobStatuses::FAILED === $target ) {
				$this->audit_job( BackgroundTranslationJobAuditEvents::FAILED, $final, array( 'source_surface' => 'worker' ) );
			}
		}

		return $final;
	}

	/**
	 * Validate create args and workload bounds.
	 *
	 * @param array<string, mixed> $args Create arguments.
	 * @return true|WP_Error
	 */
	private function validate_create_args( array $args ) {
		$job_type = (string) ( $args['job_type'] ?? '' );
		if ( ! in_array( $job_type, JobTypes::all(), true ) ) {
			return new WP_Error( 'invalid_job_type', 'Unknown job type.' );
		}

		if ( empty( $args['source_type'] ) || empty( $args['source_id'] ) || empty( $args['language_id'] ) ) {
			return new WP_Error( 'invalid_job_scope', 'Job requires source_type, source_id, and language_id.' );
		}

		$source_type = (string) $args['source_type'];
		$source_id   = (int) $args['source_id'];

		if ( null !== $this->surfaces ) {
			$surface = $this->surfaces->for( $source_type );
			if ( null === $surface || ! $surface->supports( SurfaceCapabilityNames::JOBS ) ) {
				return new WP_Error( 'unregistered_source_type', 'Job source_type is not registered for Jobs work.' );
			}
			if ( ! $surface->exists( $source_id ) ) {
				return new WP_Error( 'source_not_found', 'Job source object does not exist.' );
			}
		}

		if ( JobTypes::TRANSLATE_SELECTED === $job_type ) {
			$keys = (array) ( $args['segment_keys'] ?? array() );
			if ( count( $keys ) > JobBounds::MAX_SELECTED_SEGMENTS ) {
				return new WP_Error( 'workload_limit_exceeded', 'translate_selected exceeds max segment count.' );
			}
		}

		$segments = (array) ( $args['segment_keys'] ?? array() );
		if ( count( $segments ) > JobBounds::MAX_ITEMS_PER_JOB ) {
			return new WP_Error( 'workload_limit_exceeded', 'Job exceeds max items per job.' );
		}

		return true;
	}

	/**
	 * Resolve segment keys for materialization.
	 *
	 * @param array<string, mixed> $args     Create arguments.
	 * @param string               $job_type Job type.
	 * @return list<string>|WP_Error
	 */
	private function resolve_segment_keys( array $args, string $job_type ) {
		$keys = array_values(
			array_unique(
				array_map(
					static fn( $key ): string => trim( (string) $key ),
					(array) ( $args['segment_keys'] ?? array() )
				)
			)
		);
		$keys = array_values( array_filter( $keys, static fn( string $key ): bool => '' !== $key ) );

		if ( JobTypes::TRANSLATE_SELECTED === $job_type ) {
			if ( array() === $keys ) {
				return new WP_Error( 'empty_workload', 'translate_selected requires segment_keys.' );
			}

			return $keys;
		}

		if ( array() !== $keys ) {
			return $keys;
		}

		// translate_missing + bulk_translate (no keys): resolve missing segments.
		// retranslate_stale (no keys): resolve stale segments.
		// bulk_translate matches Workspace multi-post create (posts[] without segment_keys)
		// and shares missing-only overwrite policy with translate_missing (allows_retranslate false).
		if ( in_array(
			$job_type,
			array(
				JobTypes::TRANSLATE_MISSING,
				JobTypes::RETRANSLATE_STALE,
				JobTypes::RETRANSLATE_MACHINE,
				JobTypes::BULK_TRANSLATE,
			),
			true
		) ) {
			return $this->resolve_segments_from_store( $args, $job_type );
		}

		if ( array() === $keys ) {
			return new WP_Error( 'empty_workload', 'Job requires segment_keys.' );
		}

		return $keys;
	}

	/**
	 * Resolve missing or stale segment keys at create time via Store assembly.
	 *
	 * @param array<string, mixed> $args     Create arguments.
	 * @param string               $job_type Job type.
	 * @return list<string>|WP_Error
	 */
	private function resolve_segments_from_store( array $args, string $job_type ) {
		if ( null === $this->store || null === $this->assembler ) {
			return new WP_Error( 'segment_resolution_unavailable', 'Segment resolution requires Store and SegmentAssembler.' );
		}

		$source_type = (string) ( $args['source_type'] ?? Store::SOURCE_POST );
		$source_id   = (int) ( $args['source_id'] ?? 0 );
		$language_id = (int) ( $args['language_id'] ?? 0 );

		if ( Store::SOURCE_TERM === $source_type ) {
			$surface = null !== $this->surfaces ? $this->surfaces->for( Store::SOURCE_TERM ) : null;
			if ( null === $surface || ! $surface->exists( $source_id ) ) {
				return new WP_Error( 'source_not_found', 'Job source term not found.' );
			}

			$keys = array();
			foreach ( $surface->extract_segments( $source_id ) as $segment_key => $unit ) {
				if ( ! is_array( $unit ) || ! is_string( $segment_key ) || '' === $segment_key ) {
					continue;
				}
				$row        = $this->store->get( Store::SOURCE_TERM, $source_id, $language_id, $segment_key );
				$projection = array(
					'status'          => null === $row ? Store::STATUS_MISSING : (string) ( $row->status ?? Store::STATUS_MISSING ),
					'translated_text' => null === $row ? '' : (string) ( $row->translated_text ?? '' ),
					'is_stale'        => null !== $row && ! empty( $row->is_stale ),
					'review_status'   => null === $row ? Store::REVIEW_NOT_SUBMITTED : (string) ( $row->review_status ?? Store::REVIEW_NOT_SUBMITTED ),
				);

				if ( TranslatableSegmentEligibility::is_ai_writable( $projection, $this->job_type_mode( $job_type ) ) ) {
					$keys[] = $segment_key;
				}
			}

			return $keys;
		}

		$post = get_post( $source_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'source_not_found', 'Job source post not found.' );
		}

		$segments = $this->assembler->assemble_for_post( $post, $language_id );
		$keys     = array();

		foreach ( $segments as $segment ) {
			if ( empty( $segment['can_edit'] ) ) {
				continue;
			}

			if ( Store::FORMAT_SLUG === (string) ( $segment['text_format'] ?? '' )
			|| Extractor::FIELD_SLUG === (string) ( $segment['segment_key'] ?? '' )
			|| Extractor::FIELD_SLUG === (string) ( $segment['field_key'] ?? '' ) ) {
				continue;
			}

			$segment_key = (string) ( $segment['segment_key'] ?? '' );
			if ( '' === $segment_key ) {
				continue;
			}

			if ( TranslatableSegmentEligibility::is_ai_writable( $segment, $this->job_type_mode( $job_type ) ) ) {
				$keys[] = $segment_key;
			}
		}

		return $keys;
	}

	/**
	 * Map a job type to its shared AI-write eligibility mode for create-time
	 * segment resolution (AIT1 / ADR-0031).
	 *
	 * `translate_missing` and `bulk_translate` share missing-only semantics
	 * (P2 A1). `retranslate_stale` resolves stale machine translations.
	 * `retranslate_machine` resolves every machine translation regardless of
	 * stale state. All three exclude manual / reviewed / in-review segments via
	 * TranslatableSegmentEligibility.
	 *
	 * @param string $job_type Job type code.
	 */
	private function job_type_mode( string $job_type ): string {
		switch ( $job_type ) {
			case JobTypes::RETRANSLATE_STALE:
				return TranslatableSegmentEligibility::MODE_STALE;

			case JobTypes::RETRANSLATE_MACHINE:
				return TranslatableSegmentEligibility::MODE_MACHINE;

			case JobTypes::TRANSLATE_MISSING:
			case JobTypes::BULK_TRANSLATE:
			default:
				return TranslatableSegmentEligibility::MODE_MISSING;
		}
	}

	/**
	 * Resolve idempotency key, honoring force_new.
	 *
	 * @param array<string, mixed> $args Create arguments.
	 * @return array{key: string, force_new: bool}|WP_Error
	 */
	private function resolve_idempotency_key( array $args ) {
		$force_new = ! empty( $args['force_new'] );
		$base      = JobIdempotencyKey::build( $args );

		if ( $force_new ) {
			$suffix = function_exists( 'wp_generate_password' )
				? wp_generate_password( 12, false, false )
				: bin2hex( random_bytes( 6 ) );

			return array(
				'key'       => hash( 'sha256', $base . '|fn|' . $suffix ),
				'force_new' => true,
			);
		}

		return array(
			'key'       => $base,
			'force_new' => false,
		);
	}

	/**
	 * Handle an existing idempotency key hit.
	 *
	 * @param object               $existing Existing job row.
	 * @param array<string, mixed> $args     Create arguments.
	 * @return object|WP_Error|null Existing job, error, or null to proceed with insert.
	 */
	private function handle_idempotency_hit( object $existing, array $args ) {
		$status = (string) $existing->status;

		if ( JobStatuses::is_active( $status ) ) {
			if ( JobIdempotencyKey::args_match( $args, $this->args_from_job( $existing, $args ) ) ) {
				return $existing;
			}

			if ( ! empty( $args['client_token'] ) ) {
				return new WP_Error( 'idempotency_conflict', 'Active job idempotency key with different parameters.' );
			}

			return new WP_Error( 'idempotency_conflict', 'Active job idempotency key with different parameters.' );
		}

		if ( in_array( $status, array( JobStatuses::COMPLETED, JobStatuses::COMPLETED_WITH_ERRORS ), true ) ) {
			return new WP_Error( 'idempotency_conflict', 'Completed job retains idempotency key.' );
		}

		if ( in_array( $status, array( JobStatuses::CANCELLED, JobStatuses::FAILED ), true ) ) {
			$this->jobs->archive_idempotency_key( (int) $existing->job_id );

			return null;
		}

		return new WP_Error( 'idempotency_conflict', 'Conflicting idempotency key state.' );
	}

	/**
	 * Reconstruct comparable create args from a stored job row.
	 *
	 * @param object               $job  Job row.
	 * @param array<string, mixed> $args Incoming args (for segment keys).
	 * @return array<string, mixed>
	 */
	private function args_from_job( object $job, array $args ): array {
		// Compare on the caller's inputs, not the materialised output: when the
		// request did not pin explicit segment_keys (translate_missing /
		// retranslate_stale / retranslate_machine / bulk_translate), two
		// submissions with the same object+language+token are the same request
		// even though resolution produced a concrete key list (AIT1 / ADR-0031
		// automatic idempotency).
		$incoming_keys = array_values(
			array_filter(
				array_map( 'strval', (array) ( $args['segment_keys'] ?? array() ) ),
				static fn( string $key ): bool => '' !== $key
			)
		);

		if ( array() === $incoming_keys ) {
			$keys = array();
		} else {
			$keys = array_map(
				static fn( object $item ): string => (string) $item->segment_key,
				$this->items->list_by_job( (int) $job->job_id )
			);
		}

		return array(
			'job_type'       => (string) $job->job_type,
			'source_type'    => (string) $job->source_type,
			'source_id'      => (int) $job->source_id,
			'language_id'    => (int) $job->language_id,
			'segment_keys'   => $keys,
			'provider_id'    => (string) $job->provider_id,
			'prompt_profile' => (string) $job->prompt_profile,
			'prompt_version' => (string) $job->prompt_version,
			'created_by'     => (int) $job->created_by,
			'client_token'   => (string) ( $args['client_token'] ?? '' ),
		);
	}

	/**
	 * Map insert failures to stable domain errors.
	 *
	 * @param WP_Error             $error    Repository error.
	 * @param string               $lock_key Lock key.
	 * @param array<string, mixed> $args     Create args.
	 * @return WP_Error
	 */
	private function map_create_insert_error( WP_Error $error, string $lock_key, array $args ): WP_Error {
		$code = $error->get_error_code();

		if ( 'job_idempotency_conflict' === $code ) {
			$existing = $this->jobs->find_by_idempotency_key( JobIdempotencyKey::build( $args ) );
			if ( null !== $existing ) {
				$reuse = $this->handle_idempotency_hit( $existing, $args );
				if ( $reuse instanceof WP_Error ) {
					return new WP_Error( 'idempotency_conflict', $reuse->get_error_message() );
				}
				if ( null !== $reuse ) {
					return $reuse;
				}
			}

			return new WP_Error( 'idempotency_conflict', 'Duplicate job idempotency key.' );
		}

		if ( 'job_lock_key_conflict' === $code ) {
			return new WP_Error( 'lock_key_conflict', 'Another active job holds the object+language lock.' );
		}

		return $error;
	}

	/**
	 * Cancel queued/retry_wait items when job cancel is observed.
	 *
	 * @param int $job_id Job id.
	 */
	private function cancel_remaining_items( int $job_id ): void {
		foreach ( array( ItemStatuses::QUEUED, ItemStatuses::RETRY_WAIT ) as $status ) {
			$items = $this->items->list_by_job( $job_id, $status );
			foreach ( $items as $item ) {
				$this->items->update(
					(int) $item->item_id,
					array(
						'status'      => ItemStatuses::CANCELLED,
						'result_code' => ItemStatuses::CANCELLED,
						'finished_at' => current_time( 'mysql', true ),
					)
				);
			}
		}
	}

	/**
	 * Set requested_action when job is non-terminal.
	 *
	 * @param int    $job_id Job id.
	 * @param string $action Requested action.
	 * @return object|WP_Error
	 */
	private function set_requested_action( int $job_id, string $action ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		if ( JobStatuses::is_terminal( (string) $job->status ) ) {
			return new WP_Error( 'illegal_transition', 'Cannot request action on a terminal job.' );
		}

		if ( ! in_array( $action, RequestedActions::all(), true ) ) {
			return new WP_Error( 'invalid_requested_action', 'Unknown requested_action.' );
		}

		$updated = $this->jobs->update(
			$job_id,
			array(
				'requested_action' => $action,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return $this->jobs->find( $job_id );
	}

	/**
	 * Transition job status with validation.
	 *
	 * @param int                  $job_id Job id.
	 * @param string               $to     Target status.
	 * @param array<string, mixed> $extra  Extra fields.
	 * @return object|WP_Error|null
	 */
	private function transition_job( int $job_id, string $to, array $extra = array() ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return new WP_Error( 'job_not_found', 'Job not found.' );
		}

		$from  = (string) $job->status;
		$valid = JobTransitionPolicy::validate_transition( $from, $to );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$fields           = $extra;
		$fields['status'] = $to;

		$updated = $this->jobs->update( $job_id, $fields );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return $updated ?? $this->jobs->find( $job_id );
	}

	/**
	 * Emits a safe job audit event when audit is configured.
	 *
	 * @param string               $event  Event name.
	 * @param object|null          $job    Job row.
	 * @param array<string, mixed> $extras Optional safe extras.
	 */
	private function audit_job( string $event, ?object $job, array $extras = array() ): void {
		if ( null === $this->audit || null === $job ) {
			return;
		}

		$this->audit->log( $event, $this->audit->payload_from_job( $job, $extras ) );
	}

	/**
	 * Records budget-exceeded audit and diagnostics counter.
	 *
	 * @param object $job Job row.
	 */
	private function audit_budget_exceeded( object $job ): void {
		if ( null !== $this->diagnostics ) {
			$this->diagnostics->increment( BackgroundTranslationDiagnostics::BUDGET_STOPS );
		}

		$this->audit_job(
			BackgroundTranslationJobAuditEvents::BUDGET_EXCEEDED,
			$job,
			array(
				'source_surface' => 'worker',
				'error_code'     => 'budget_exceeded',
				'error_class'    => 'permanent',
			)
		);
	}
}
