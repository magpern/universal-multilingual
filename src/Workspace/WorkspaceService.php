<?php
/**
 * Translator workspace application facade.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

use AIMultilingual\Integration\IntegrationAdmission;
use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use AIMultilingual\Routing\RoutePublicationService;
use AIMultilingual\Routing\SlugCandidateService;
use AIMultilingual\Surface\AdmittedPostTypes;
use AIMultilingual\Translation\AI\FieldSemanticMapper;
use AIMultilingual\Translation\Assessment\AssessmentAssembler;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Memory\TranslationMemoryService;
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Surface\SurfaceRegistry;
use AIMultilingual\Translation\Store;
use AIMultilingual\Translation\TermAdoptionService;
use AIMultilingual\Jobs\BackgroundTranslationJobService;
use AIMultilingual\Workspace\Operator\AllowedActionsResolver;
use AIMultilingual\Workspace\Operator\OperationsBulkCoordinator;
use AIMultilingual\Workspace\Operator\OperatorTranslationAssembler;
use AIMultilingual\Workspace\QA\QAEngine;
use AIMultilingual\Workspace\QA\QAIssue;
use AIMultilingual\Workspace\QA\QAResult;
use AIMultilingual\Workspace\Review\ReviewBatchCoordinator;
use AIMultilingual\Workspace\Review\ReviewDiagnosticsCounters;
use AIMultilingual\Workspace\Review\ReviewWorkflowException;
use AIMultilingual\Workspace\Review\ReviewWorkflowService;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Orchestration entry point for workspace queries and commands.
 */
final class WorkspaceService {

	public const SUPPORTED_POST_TYPES = AdmittedPostTypes::WORKSPACE_TYPES;

	/**
	 * Injected dependency.
	 *
	 * @var SegmentAssembler
	 */
	private SegmentAssembler $assembler;

	/**
	 * Injected dependency.
	 *
	 * @var TranslationStatusCalculator
	 */
	private TranslationStatusCalculator $status_calculator;

	/**
	 * Injected dependency.
	 *
	 * @var BatchOperationCoordinator
	 */
	private BatchOperationCoordinator $batch;

	/**
	 * Injected dependency.
	 *
	 * @var TranslationService
	 */
	private TranslationService $translation;

	/**
	 * Injected dependency.
	 *
	 * @var PreviewService
	 */
	private PreviewService $preview;

	/**
	 * Injected dependency.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Injected dependency.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Injected dependency.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	/**
	 * Injected dependency.
	 *
	 * @var TranslationSuggestionService
	 */
	private TranslationSuggestionService $suggestions;

	/**
	 * Injected dependency.
	 *
	 * @var QAEngine
	 */
	private QAEngine $qa;

	/**
	 * Injected dependency.
	 *
	 * @var TranslationMemoryService
	 */
	private TranslationMemoryService $tm;

	/**
	 * Injected dependency.
	 *
	 * @var ReviewWorkflowService
	 */
	private ReviewWorkflowService $review;

	/**
	 * Owns bulk iteration for review actions.
	 *
	 * @var ReviewBatchCoordinator
	 */
	private ReviewBatchCoordinator $review_batch;

	/**
	 * Owns bounded Operations bulk orchestration (OTL.5).
	 *
	 * @var OperationsBulkCoordinator
	 */
	private OperationsBulkCoordinator $operations_bulk;

	/**
	 * Bounded, low-cardinality review diagnostic counters (ADR-0015 §13).
	 *
	 * @var ReviewDiagnosticsCounters
	 */
	private ReviewDiagnosticsCounters $review_diagnostics;

	/**
	 * TI.5 assessment core (read-only, recomputed).
	 *
	 * @var AssessmentAssembler
	 */
	private AssessmentAssembler $assessment;

	/**
	 * FieldSemantic mapper for narrow assessment exceptions.
	 *
	 * @var FieldSemanticMapper
	 */
	private FieldSemanticMapper $field_semantic_mapper;

	/**
	 * Optional TI.7 publication service.
	 *
	 * @var PublicationService|null
	 */
	private ?PublicationService $publication;

	/**
	 * Optional MSEO.1 slug candidate service.
	 *
	 * @var SlugCandidateService|null
	 */
	private ?SlugCandidateService $slug_candidates;

	/**
	 * Optional MSEO.1 route publication service.
	 *
	 * @var RoutePublicationService|null
	 */
	private ?RoutePublicationService $route_publication;

	/**
	 * Lazy OTL.0 operator assembler.
	 *
	 * @var OperatorTranslationAssembler|null
	 */
	private ?OperatorTranslationAssembler $operator = null;

	/**
	 * TSC.1 hosted-to-native term adoption (content writes only).
	 *
	 * @var TermAdoptionService|null
	 */
	private ?TermAdoptionService $term_adoption;

	/**
	 * Builds the collaborator.
	 *
	 * @param SegmentAssembler               $assembler           Segment assembly.
	 * @param TranslationStatusCalculator    $status_calculator   Status aggregation.
	 * @param TranslationService             $translation         Auto-translate boundary.
	 * @param PreviewService                 $preview             Preview URLs.
	 * @param Languages                      $languages           Language registry.
	 * @param Store                          $store               Segment store.
	 * @param Extractor                      $extractor           Source extractor.
	 * @param TranslationSuggestionService   $suggestions         Suggestion orchestration.
	 * @param QAEngine                       $qa                  Quality assurance engine.
	 * @param TranslationMemoryService       $tm                  Translation memory write-back.
	 * @param ReviewWorkflowService          $review              Review Workflow transition policy.
	 * @param ReviewDiagnosticsCounters|null $review_diagnostics Bounded review diagnostic counters.
	 * @param AssessmentAssembler|null       $assessment          Optional TI.5 assessment core.
	 * @param FieldSemanticMapper|null       $field_semantic_mapper Optional FieldSemantic mapper.
	 * @param PublicationService|null        $publication         Optional TI.7 publication service.
	 * @param SurfaceRegistry|null           $surfaces            Optional surface registry (TSC.0).
	 * @param TermAdoptionService|null       $term_adoption       Optional TSC.1 term adoption service.
	 * @param SlugCandidateService|null      $slug_candidates     Optional MSEO.1 slug candidate service.
	 * @param RoutePublicationService|null   $route_publication   Optional MSEO.1 route publication service.
	 */
	public function __construct(
		SegmentAssembler $assembler,
		TranslationStatusCalculator $status_calculator,
		TranslationService $translation,
		PreviewService $preview,
		Languages $languages,
		Store $store,
		Extractor $extractor,
		TranslationSuggestionService $suggestions,
		QAEngine $qa,
		TranslationMemoryService $tm,
		ReviewWorkflowService $review,
		?ReviewDiagnosticsCounters $review_diagnostics = null,
		?AssessmentAssembler $assessment = null,
		?FieldSemanticMapper $field_semantic_mapper = null,
		?PublicationService $publication = null,
		?SurfaceRegistry $surfaces = null,
		?TermAdoptionService $term_adoption = null,
		?SlugCandidateService $slug_candidates = null,
		?RoutePublicationService $route_publication = null
	) {
		$this->assembler             = $assembler;
		$this->status_calculator     = $status_calculator;
		$this->translation           = $translation;
		$this->preview               = $preview;
		$this->languages             = $languages;
		$this->store                 = $store;
		$this->extractor             = $extractor;
		$this->suggestions           = $suggestions;
		$this->qa                    = $qa;
		$this->tm                    = $tm;
		$this->review                = $review;
		$this->review_diagnostics    = $review_diagnostics ?? new ReviewDiagnosticsCounters();
		$this->assessment            = $assessment ?? new AssessmentAssembler();
		$this->field_semantic_mapper = $field_semantic_mapper ?? new FieldSemanticMapper();
		$this->publication           = $publication;
		$this->term_adoption         = $term_adoption;
		$this->slug_candidates       = $slug_candidates;
		$this->route_publication     = $route_publication;
		$this->batch                 = new BatchOperationCoordinator( $this, $translation, $assembler );
		$this->review_batch          = new ReviewBatchCoordinator( $this );
		$this->operations_bulk       = new OperationsBulkCoordinator( $this, $store, $publication, null, $surfaces );
	}

	/**
	 * Returns the bulk operation coordinator.
	 *
	 * @return BatchOperationCoordinator
	 */
	public function batch_coordinator(): BatchOperationCoordinator {
		return $this->batch;
	}

	/**
	 * Returns the review batch coordinator.
	 *
	 * @return ReviewBatchCoordinator
	 */
	public function review_batch_coordinator(): ReviewBatchCoordinator {
		return $this->review_batch;
	}

	/**
	 * Returns the Operations bulk coordinator (OTL.5).
	 */
	public function operations_bulk_coordinator(): OperationsBulkCoordinator {
		return $this->operations_bulk;
	}

	/**
	 * Injects TI.6 Jobs after Plugin constructs the Jobs stack.
	 *
	 * @param BackgroundTranslationJobService $jobs Jobs service.
	 */
	public function set_jobs_service( BackgroundTranslationJobService $jobs ): void {
		$this->operations_bulk->set_jobs( $jobs );
	}

	/**
	 * Runs a bounded Operations bulk action (OTL.5).
	 *
	 * @param string                           $action  Bulk action.
	 * @param array<int, array<string, mixed>> $items   Per-item payloads.
	 * @param int                              $user_id Acting user.
	 * @return array<string, mixed>|WP_Error
	 */
	public function operations_bulk( string $action, array $items, int $user_id ) {
		return $this->operations_bulk->run( $action, $items, $user_id );
	}

	/**
	 * OTL.0 language-scoped operations list (cheap — no QA/assessment/explain).
	 *
	 * @param array<string, mixed> $args Query args (language_id required).
	 * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public function list_operations( array $args ): array {
		$result = $this->store->query_operations( $args );
		$items  = array();
		foreach ( $result['items'] as $row ) {
			$items[] = $this->operator()->assemble_list_item( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $result['total'],
			'page'     => $result['page'],
			'per_page' => $result['per_page'],
		);
	}

	/**
	 * OTL.1 language-scoped operational attention counts (auth ≡ list).
	 *
	 * @param int $language_id Language id.
	 * @return array{
	 *     total: int,
	 *     stale: int,
	 *     review_pending: int,
	 *     review_rejected: int,
	 *     unpublished: int,
	 *     translation_failed: int
	 * }
	 */
	public function operations_attention_counts( int $language_id ): array {
		return $this->store->count_operations_attention( $language_id );
	}

	/**
	 * OTL.0 translation detail by primary key.
	 *
	 * @param int $translation_id Translation PK.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_operation( int $translation_id ) {
		$row = $this->store->get_by_translation_id( $translation_id );
		if ( null === $row ) {
			return new WP_Error( 'aiml_translation_missing', __( 'Translation not found.', 'universal-multilingual' ), array( 'status' => 404 ) );
		}

		return $this->operator()->assemble_detail( $row );
	}

	/**
	 * Resets OTL invocation counters (tests).
	 */
	public function reset_otl_invocation_counts(): void {
		$this->operator()->reset_invocation_counts();
	}

	/**
	 * Returns OTL assembler invocation counters for tests.
	 *
	 * @return array{assessment: int, publication_explain: int, qa: int, jobs: int}
	 */
	public function otl_invocation_counts(): array {
		return $this->operator()->invocation_counts();
	}

	/**
	 * Lazy OperatorTranslationAssembler.
	 */
	private function operator(): OperatorTranslationAssembler {
		if ( null === $this->operator ) {
			$this->operator = new OperatorTranslationAssembler(
				$this->store,
				$this->languages,
				new AllowedActionsResolver(),
				$this->preview,
				$this->assessment,
				$this->qa,
				$this->field_semantic_mapper,
				$this->publication,
				new \AIMultilingual\Jobs\JobsLifecycleLinker()
			);
		}

		return $this->operator;
	}

	/**
	 * Lists posts/pages for the workspace picker.
	 *
	 * @param array<string, mixed> $args Query args: search, page, per_page, language.
	 * @return array<string, mixed>
	 */
	public function list_pages( array $args ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$language = $this->resolve_language( (string) ( $args['language'] ?? '' ) );

		$query = new WP_Query(
			array(
				'post_type'              => $this->workspace_post_types(),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				's'                      => $search,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$summaries = $this->status_calculator->summaries_for_post( (int) $post->ID );
			$lang_id   = null !== $language ? (int) $language->language_id : 0;
			$summary   = $lang_id > 0 ? ( $summaries[ $lang_id ] ?? array(
				'total' => 0,
				'stale' => 0,
			) ) : array(
				'total' => 0,
				'stale' => 0,
			);

			$items[] = array(
				'post_id'        => (int) $post->ID,
				'post_title'     => $this->workspace_list_title( $post ),
				'post_type'      => (string) $post->post_type,
				'post_status'    => (string) $post->post_status,
				'modified_gmt'   => (string) $post->post_modified_gmt,
				'language_id'    => $lang_id,
				'total_segments' => (int) ( $summary['total'] ?? 0 ),
				'stale_count'    => (int) ( $summary['stale'] ?? 0 ),
			);
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Loads segment DTOs for one post.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 * @return array<int, array<string, mixed>>
	 */
	public function load_segments( WP_Post $post, int $language_id ): array {
		$this->assert_supported_post( $post );

		$segments = $this->assembler->assemble_for_post( $post, $language_id );

		return $this->attach_meta( $segments, $language_id );
	}

	/**
	 * Returns page-level status DTO.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 * @return array<string, mixed>
	 */
	public function page_status( WP_Post $post, int $language_id ): array {
		$this->assert_supported_post( $post );

		return $this->page_status_for_segments(
			$post,
			$language_id,
			$this->assembler->assemble_for_post( $post, $language_id )
		);
	}

	/**
	 * Page status derived from already-assembled segment DTOs.
	 *
	 * @param WP_Post                          $post        Canonical post.
	 * @param int                              $language_id Target language id.
	 * @param array<int, array<string, mixed>> $segments    Assembled segment DTOs.
	 * @return array<string, mixed>
	 */
	public function page_status_for_segments( WP_Post $post, int $language_id, array $segments ): array {
		$this->assert_supported_post( $post );

		return $this->status_calculator->for_segments( $post, $language_id, $segments );
	}

	/**
	 * Operation handler.
	 *
	 * @param WP_Post $post          Canonical post.
	 * @param string  $language_code Target language code.
	 * @return string|WP_Error
	 */
	public function preview_url( WP_Post $post, string $language_code ) {
		$this->assert_supported_post( $post );

		return $this->preview->preview_url( $post, $language_code );
	}

	/**
	 * Saves one segment with optimistic locking.
	 *
	 * @param WP_Post     $post            Canonical post.
	 * @param int         $language_id     Target language id.
	 * @param string      $segment_key     Segment key.
	 * @param string      $translated_text Target text.
	 * @param string      $source_hash     Client source hash.
	 * @param string      $status                     Optional workflow status.
	 * @param string      $save_origin                Optional TM save origin (human|ai_accepted|tm_accepted|machine|import).
	 * @param int         $tm_id                      Optional TM id when accepting an existing memory hit.
	 * @param string|null $expected_translation_hash  Optimistic target token; null = missing (fail closed).
	 * @return array<string, mixed>
	 * @throws WorkspaceConflictException When source_hash mismatches.
	 * @throws WorkspaceTranslationConflictException When expected_translation_hash mismatches.
	 * @throws WorkspaceQAException When QA errors block the save.
	 * @throws \InvalidArgumentException When the segment cannot be saved.
	 * @throws \RuntimeException When the saved segment cannot be reloaded.
	 */
	public function save_segment(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		string $translated_text,
		string $source_hash,
		string $status = '',
		string $save_origin = '',
		int $tm_id = 0,
		?string $expected_translation_hash = null
	): array {
		$this->assert_supported_post( $post );

		$current = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $current ) {
			throw new \InvalidArgumentException( 'Unknown segment key for this post.' );
		}

		if ( ! (bool) ( $current['can_edit'] ?? false ) ) {
			throw new \InvalidArgumentException( 'This segment is not editable.' );
		}

		$this->assert_writable_segment_key( $segment_key, (int) $post->ID, $language_id );

		if ( null === $expected_translation_hash ) {
			throw new \InvalidArgumentException( 'expected_translation_hash is required.' );
		}

		if ( '' !== $source_hash && (string) ( $current['source_hash'] ?? '' ) !== $source_hash ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- conflict payload is structured data, not exception text.
			throw new WorkspaceConflictException( array( $current ) );
		}

		$current_translation_hash = (string) ( $current['translation_hash'] ?? '' );
		if ( $expected_translation_hash !== $current_translation_hash ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- conflict payload is structured data, not exception text.
			throw new WorkspaceTranslationConflictException( array( $current ) );
		}

		$format  = (string) ( $current['text_format'] ?? Store::FORMAT_PLAIN );
		$default = $this->languages->default();
		$qa      = $this->qa->evaluate(
			(string) ( $current['source_text'] ?? '' ),
			$translated_text,
			$format,
			array(
				'source_language_id' => $default ? (int) $default->language_id : 0,
				'target_language_id' => $language_id,
			)
		);

		// Clearing a translation (blank target) is an allowed workspace action;
		// empty_translation must not block that path.
		if ( '' === trim( $translated_text ) ) {
			$qa = new \AIMultilingual\Workspace\QA\QAResult(
				array_values(
					array_filter(
						$qa->issues,
						static fn( \AIMultilingual\Workspace\QA\QAIssue $issue ): bool => 'empty_translation' !== $issue->code
					)
				)
			);
		}

		if ( $this->qa->should_block_save( $qa ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- QA payload is structured data, not exception text.
			throw new WorkspaceQAException( $qa );
		}

		$save_status = '' !== $status ? $status : Store::STATUS_MANUALLY_EDITED;
		if ( ! in_array( $save_status, Store::statuses(), true ) ) {
			$save_status = Store::STATUS_MANUALLY_EDITED;
		}

		$result = $this->store->save_translation(
			array_merge(
				array(
					'source_type'     => Store::SOURCE_POST,
					'source_id'       => (int) $post->ID,
					'source_subtype'  => (string) $post->post_type,
					'language_id'     => $language_id,
					'field_key'       => (string) ( $current['field_key'] ?? '' ),
					'segment_key'     => $segment_key,
					'segment_kind'    => (string) ( $current['segment_kind'] ?? Store::KIND_BLOCK ),
					'segment_order'   => (int) ( $current['segment_order'] ?? 0 ),
					'text_format'     => (string) ( $current['text_format'] ?? Store::FORMAT_PLAIN ),
					'source_text'     => (string) ( $current['source_text'] ?? '' ),
					'translated_text' => $translated_text,
					'status'          => $save_status,
				),
				$this->term_write_identity( Store::SOURCE_POST, (int) $post->ID, $language_id, $segment_key )
			)
		);

		if ( $result instanceof WP_Error ) {
			throw new \InvalidArgumentException( esc_html( $result->get_error_message() ) );
		}

		$this->record_tm_usage_after_save(
			$language_id,
			$current,
			$translated_text,
			$save_origin,
			$tm_id
		);

		$refreshed = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $refreshed ) {
			throw new \RuntimeException( 'Saved segment could not be reloaded.' );
		}

		$with_meta = $this->attach_meta( array( $refreshed ), $language_id );

		return $with_meta[0];
	}

	/**
	 * Identity a content write must target when the segment holds a term field.
	 *
	 * Editing a hosted compatibility row is a content write, so the field is
	 * adopted first and the save lands on the term identity — otherwise the
	 * edit would live on a row the resolver has already stopped treating as
	 * authoritative. Returns nothing for ordinary post segments.
	 *
	 * @param string $source_type Address source type.
	 * @param int    $source_id   Address source id.
	 * @param int    $language_id Language id.
	 * @param string $segment_key Address segment key.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When adoption fails.
	 */
	private function term_write_identity( string $source_type, int $source_id, int $language_id, string $segment_key ): array {
		if ( null === $this->term_adoption ) {
			return array();
		}

		$identity = $this->term_adoption->native_write_identity( $source_type, $source_id, $language_id, $segment_key );

		if ( $identity instanceof WP_Error ) {
			throw new \InvalidArgumentException( esc_html( $identity->get_error_message() ) );
		}

		return $identity;
	}

	/**
	 * Requests ranked suggestions for one segment (TM + optional AI profile).
	 *
	 * @param WP_Post $post           Canonical post.
	 * @param int     $language_id    Target language id.
	 * @param string  $segment_key    Segment key.
	 * @param string  $prompt_profile Prompt profile id.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the segment is unknown.
	 */
	public function request_suggestions(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		string $prompt_profile
	): array {
		$this->assert_supported_post( $post );

		$segment = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $segment ) {
			throw new \InvalidArgumentException( 'Unknown segment key for this post.' );
		}

		$default = $this->languages->default();
		$context = array(
			'source_language_id' => $default ? (int) $default->language_id : 0,
			'target_language_id' => $language_id,
			'prompt_profile'     => $prompt_profile,
			'post'               => $post,
		);

		$suggestions         = $this->suggestions->request_suggestions( $segment, $context );
		$meta                = is_array( $segment['meta'] ?? null ) ? $segment['meta'] : array();
		$meta['suggestions'] = $suggestions;
		$suggest_target      = (string) ( $segment['translated_text'] ?? '' );
		$meta['qa']          = '' === trim( $suggest_target )
			? ( new QAResult( array() ) )->to_array()
			: $this->qa->evaluate(
				(string) ( $segment['source_text'] ?? '' ),
				$suggest_target,
				(string) ( $segment['text_format'] ?? Store::FORMAT_PLAIN ),
				array(
					'source_language_id' => $default ? (int) $default->language_id : 0,
					'target_language_id' => $language_id,
				)
			)->to_array();
		$segment['meta']     = $meta;

		return $segment;
	}

	/**
	 * Operation handler.
	 *
	 * @param WP_Post                          $post        Canonical post.
	 * @param int                              $language_id Target language id.
	 * @param array<int, array<string, mixed>> $items       Batch items.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_batch( WP_Post $post, int $language_id, array $items ) {
		$this->assert_supported_post( $post );

		return $this->batch->save_batch( $post, $language_id, $items );
	}

	/**
	 * Operation handler.
	 *
	 * @param WP_Post               $post                       Canonical post.
	 * @param int                   $language_id                Target language id.
	 * @param array<int, string>    $segment_keys               Segment keys.
	 * @param string                $mode                       sync|async placeholder.
	 * @param array<string, string> $expected_translation_hashes Map of segment_key => translation_hash.
	 * @return array<string, mixed>|WP_Error
	 */
	public function translate(
		WP_Post $post,
		int $language_id,
		array $segment_keys,
		string $mode = 'sync',
		array $expected_translation_hashes = array()
	) {
		$this->assert_supported_post( $post );
		unset( $mode );

		return $this->batch->translate_batch( $post, $language_id, $segment_keys, $expected_translation_hashes );
	}

	/**
	 * Submits (or resubmits) a translation for review.
	 *
	 * @param WP_Post     $post                   Canonical post.
	 * @param int         $language_id            Target language id.
	 * @param string      $segment_key            Segment key.
	 * @param int         $user_id                Submitting user id.
	 * @param string|null $expected_review_status Optional optimistic review_status.
	 * @return array<string, mixed>
	 *
	 * @throws \AIMultilingual\Workspace\Review\ReviewWorkflowException When the transition is illegal or conflicts.
	 */
	public function submit_review(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		int $user_id,
		?string $expected_review_status = null
	): array {
		$this->assert_supported_post( $post );

		try {
			$this->review->submit( Store::SOURCE_POST, (int) $post->ID, $language_id, $segment_key, $user_id, $expected_review_status );
		} catch ( ReviewWorkflowException $exception ) {
			$this->record_review_conflict( $exception );
			throw $exception;
		}

		return $this->segment_view_after_review( $post, $language_id, $segment_key );
	}

	/**
	 * Approves a pending review after a QA freshness re-check.
	 *
	 * Approval never rewrites translated text, source hashes, or the
	 * translation-axis `status` (ADR-0015 §4.4). QA errors block approval only
	 * when `qa_block_on_error` is enabled; warnings (including
	 * `glossary_term_missing`) never block.
	 *
	 * TM write-back moved from save-time to approval-time (ADR-0015 §7 / F11
	 * amendment, R5): eligible content writes back to
	 * {@see TranslationMemoryService} exactly once, only on a real
	 * `pending` → `approved` transition — never on an idempotent duplicate
	 * approve (`$previous_review_status` is captured before
	 * {@see ReviewWorkflowService::approve()} runs, so a no-op on an
	 * already-`approved` row is correctly skipped).
	 *
	 * Also propagates {@see WorkspaceQAException} (QA errors block approval)
	 * and {@see \AIMultilingual\Workspace\Review\ReviewWorkflowException}
	 * (illegal transition or stale optimistic fields) from its collaborators.
	 *
	 * @param WP_Post     $post                   Canonical post.
	 * @param int         $language_id            Target language id.
	 * @param string      $segment_key            Segment key.
	 * @param int         $user_id                Reviewer user id.
	 * @param string|null $expected_review_status Optional optimistic review_status.
	 * @param string|null $client_submitted_hash  Optional client submitted hash.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException When the segment is unknown.
	 * @throws WorkspaceQAException When QA errors block approval under policy.
	 * @throws \AIMultilingual\Workspace\Review\ReviewWorkflowException When the transition is illegal or conflicts.
	 */
	public function approve_review(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		int $user_id,
		?string $expected_review_status = null,
		?string $client_submitted_hash = null
	): array {
		$this->assert_supported_post( $post );

		$current = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $current ) {
			throw new \InvalidArgumentException( 'Unknown segment key for this post.' );
		}

		try {
			$this->assert_qa_passes_for_approval( $current, $language_id );
		} catch ( WorkspaceQAException $qa_exception ) {
			$this->review_diagnostics->increment( ReviewDiagnosticsCounters::QA_BLOCKED_APPROVALS );
			throw $qa_exception;
		}

		$previous_review_status = (string) ( $current['review_status'] ?? Store::REVIEW_NOT_SUBMITTED );

		try {
			$this->review->approve(
				Store::SOURCE_POST,
				(int) $post->ID,
				$language_id,
				$segment_key,
				$user_id,
				$expected_review_status,
				$client_submitted_hash
			);
		} catch ( ReviewWorkflowException $exception ) {
			$this->review_diagnostics->increment( ReviewDiagnosticsCounters::APPROVAL_FAILURES );
			$this->record_review_conflict( $exception );
			throw $exception;
		}

		if ( Store::REVIEW_PENDING === $previous_review_status ) {
			$this->write_back_tm_on_approval( $language_id, $current );
		}

		return $this->segment_view_after_review( $post, $language_id, $segment_key );
	}

	/**
	 * Rejects a pending review with a required reason.
	 *
	 * Reject never requires a QA pass (ADR-0015 §8) and preserves the
	 * submitted translated text for correction.
	 *
	 * @param WP_Post     $post                   Canonical post.
	 * @param int         $language_id            Target language id.
	 * @param string      $segment_key            Segment key.
	 * @param int         $user_id                Reviewer user id.
	 * @param string      $reason                 Rejection reason.
	 * @param string|null $expected_review_status Optional optimistic review_status.
	 * @param string|null $client_submitted_hash  Optional client submitted hash.
	 * @return array<string, mixed>
	 *
	 * @throws \AIMultilingual\Workspace\Review\ReviewWorkflowException When the transition is illegal, conflicts, or the reason is invalid.
	 */
	public function reject_review(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		int $user_id,
		string $reason,
		?string $expected_review_status = null,
		?string $client_submitted_hash = null
	): array {
		$this->assert_supported_post( $post );

		try {
			$this->review->reject(
				Store::SOURCE_POST,
				(int) $post->ID,
				$language_id,
				$segment_key,
				$user_id,
				$reason,
				$expected_review_status,
				$client_submitted_hash
			);
		} catch ( ReviewWorkflowException $exception ) {
			$this->record_review_conflict( $exception );
			throw $exception;
		}

		return $this->segment_view_after_review( $post, $language_id, $segment_key );
	}

	/**
	 * Publishes one segment when PublicationPolicy allows it (manual path).
	 *
	 * @param WP_Post     $post                   Canonical post.
	 * @param int         $language_id            Target language id.
	 * @param string      $segment_key            Segment key.
	 * @param int         $user_id                Acting user id.
	 * @param string|null $expected_publish_status Optional optimistic publish_status.
	 * @return array<string, mixed>|WP_Error
	 */
	public function publish_segment(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		int $user_id,
		?string $expected_publish_status = null
	) {
		$this->assert_supported_post( $post );

		if ( null === $this->publication ) {
			return new WP_Error(
				'aiml_publication_unavailable',
				__( 'Publication service is not available.', 'universal-multilingual' ),
				array( 'status' => 503 )
			);
		}

		$result = $this->publication->publish(
			Store::SOURCE_POST,
			(int) $post->ID,
			$language_id,
			$segment_key,
			false,
			$user_id,
			'workspace',
			$expected_publish_status
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$segment                       = $this->segment_view_after_review( $post, $language_id, $segment_key );
		$segment['publication_result'] = $result;

		return $segment;
	}

	/**
	 * Generates a localized slug candidate (MSEO.1).
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function generate_slug_candidate( WP_Post $post, int $language_id ) {
		$this->assert_supported_post( $post );
		if ( null === $this->slug_candidates || null === $this->route_publication ) {
			return new WP_Error( 'aiml_slug_unavailable', __( 'Slug services are not available.', 'universal-multilingual' ), array( 'status' => 503 ) );
		}
		$row = $this->slug_candidates->generate( $post, $language_id );
		if ( $row instanceof WP_Error ) {
			return $row;
		}

		return $this->route_publication->sync_view( $post, $language_id );
	}

	/**
	 * Saves a manual slug candidate (MSEO.1).
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @param string  $candidate   Candidate leaf.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_slug_candidate( WP_Post $post, int $language_id, string $candidate ) {
		$this->assert_supported_post( $post );
		if ( null === $this->slug_candidates || null === $this->route_publication ) {
			return new WP_Error( 'aiml_slug_unavailable', __( 'Slug services are not available.', 'universal-multilingual' ), array( 'status' => 503 ) );
		}
		$row = $this->slug_candidates->save_manual( $post, $language_id, $candidate );
		if ( $row instanceof WP_Error ) {
			return $row;
		}

		return $this->route_publication->sync_view( $post, $language_id );
	}

	/**
	 * Clears the slug candidate (MSEO.1).
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function clear_slug_candidate( WP_Post $post, int $language_id ) {
		$this->assert_supported_post( $post );
		if ( null === $this->slug_candidates || null === $this->route_publication ) {
			return new WP_Error( 'aiml_slug_unavailable', __( 'Slug services are not available.', 'universal-multilingual' ), array( 'status' => 503 ) );
		}
		$row = $this->slug_candidates->clear( $post, $language_id );
		if ( $row instanceof WP_Error ) {
			return $row;
		}

		return $this->route_publication->sync_view( $post, $language_id );
	}

	/**
	 * Publishes the prepared route for a slug candidate (MSEO.1).
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @param int     $user_id     User id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function publish_prepared_route( WP_Post $post, int $language_id, int $user_id = 0 ) {
		$this->assert_supported_post( $post );
		if ( null === $this->route_publication ) {
			return new WP_Error( 'aiml_slug_unavailable', __( 'Slug services are not available.', 'universal-multilingual' ), array( 'status' => 503 ) );
		}

		return $this->route_publication->publish_route( $post, $language_id, $user_id );
	}

	/**
	 * Slug/route sync view model (MSEO.1).
	 *
	 * @param WP_Post $post        Post.
	 * @param int     $language_id Language id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function slug_route_view( WP_Post $post, int $language_id ) {
		$this->assert_supported_post( $post );
		if ( null === $this->route_publication ) {
			return new WP_Error( 'aiml_slug_unavailable', __( 'Slug services are not available.', 'universal-multilingual' ), array( 'status' => 503 ) );
		}

		return $this->route_publication->sync_view( $post, $language_id );
	}

	/**
	 * Unpublishes one segment (manual path).
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 * @param string  $segment_key Segment key.
	 * @param int     $user_id     Acting user id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function unpublish_segment(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		int $user_id
	) {
		$this->assert_supported_post( $post );

		if ( null === $this->publication ) {
			return new WP_Error(
				'aiml_publication_unavailable',
				__( 'Publication service is not available.', 'universal-multilingual' ),
				array( 'status' => 503 )
			);
		}

		$result = $this->publication->unpublish(
			Store::SOURCE_POST,
			(int) $post->ID,
			$language_id,
			$segment_key,
			$user_id
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$segment                       = $this->segment_view_after_review( $post, $language_id, $segment_key );
		$segment['publication_result'] = $result;

		return $segment;
	}

	/**
	 * Explains publication eligibility without mutation.
	 *
	 * @param WP_Post $post          Canonical post.
	 * @param int     $language_id   Target language id.
	 * @param string  $segment_key   Segment key.
	 * @param bool    $for_automatic Treat as automatic path.
	 * @return array<string, mixed>|WP_Error
	 */
	public function explain_publication(
		WP_Post $post,
		int $language_id,
		string $segment_key,
		bool $for_automatic = false
	) {
		$this->assert_supported_post( $post );

		if ( null === $this->publication ) {
			return new WP_Error(
				'aiml_publication_unavailable',
				__( 'Publication service is not available.', 'universal-multilingual' ),
				array( 'status' => 503 )
			);
		}

		$decision = $this->publication->explain(
			Store::SOURCE_POST,
			(int) $post->ID,
			$language_id,
			$segment_key,
			$for_automatic
		);

		if ( $decision instanceof WP_Error ) {
			return $decision;
		}

		return $decision->to_array();
	}

	/**
	 * Applies one review action to multiple segments (bounded, partial success).
	 *
	 * @param WP_Post                          $post          Canonical post.
	 * @param int                              $language_id   Target language id.
	 * @param string                           $action        One of ReviewBatchCoordinator::actions().
	 * @param array<int, array<string, mixed>> $items         Per-item payloads.
	 * @param int                              $user_id       Acting user id.
	 * @param string                           $shared_reason Fallback reject reason when an item omits one.
	 * @return array<string, mixed>|WP_Error
	 */
	public function batch_review(
		WP_Post $post,
		int $language_id,
		string $action,
		array $items,
		int $user_id,
		string $shared_reason = ''
	) {
		$this->assert_supported_post( $post );

		return $this->review_batch->run_batch( $post, $language_id, $action, $items, $user_id, $shared_reason );
	}

	/**
	 * Returns a filtered, paginated review queue (Store view; ADR-0015 §5, §11).
	 *
	 * @param array<string, mixed> $args Query args: post_id, language, review_status, page, per_page.
	 * @return array<string, mixed>|WP_Error
	 */
	public function review_queue( array $args ) {
		$language_id = 0;
		$code        = (string) ( $args['language'] ?? '' );
		if ( '' !== $code ) {
			$language = $this->resolve_language( $code );
			if ( null === $language ) {
				return new WP_Error(
					'aiml_invalid_language',
					__( 'Unknown language code.', 'universal-multilingual' ),
					array( 'status' => 404 )
				);
			}
			$language_id = (int) $language->language_id;
		}

		return $this->store->query_review_queue(
			array(
				'source_type'   => Store::SOURCE_POST,
				'source_id'     => (int) ( $args['post_id'] ?? 0 ),
				'language_id'   => $language_id,
				'review_status' => (string) ( $args['review_status'] ?? Store::REVIEW_PENDING ),
				'page'          => (int) ( $args['page'] ?? 1 ),
				'per_page'      => (int) ( $args['per_page'] ?? 20 ),
			)
		);
	}

	/**
	 * Resolves a language code for OTL operations (required).
	 *
	 * @param string $language_code Language code.
	 * @return object|WP_Error
	 */
	public function resolve_language_for_operations( string $language_code ) {
		$language = $this->resolve_language( $language_code );
		if ( null === $language ) {
			return new WP_Error(
				'aiml_invalid_language',
				__( 'Unknown language code.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		return $language;
	}

	/**
	 * Resolves a language code to a language row.
	 *
	 * @param string $language_code Language code from the request.
	 * @return object|null
	 */
	public function resolve_language( string $language_code ): ?object {
		if ( '' === $language_code ) {
			return null;
		}

		return $this->languages->find_by_code( $language_code );
	}

	/**
	 * Operation handler.
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|WP_Error
	 */
	public function get_post( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'aiml_post_not_found',
				__( 'Post not found.', 'universal-multilingual' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $post->post_type, $this->workspace_post_types(), true ) ) {
			return new WP_Error(
				'aiml_post_type_unsupported',
				__( 'This post type is not supported in the workspace.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		return $post;
	}

	/**
	 * Accepts exact TM suggestions for selected segments via save_batch.
	 *
	 * @param WP_Post            $post         Canonical post.
	 * @param int                $language_id  Target language id.
	 * @param array<int, string> $segment_keys Segment keys (empty = all with exact TM).
	 * @return array<string, mixed>|WP_Error
	 */
	public function accept_tm_exact_batch( WP_Post $post, int $language_id, array $segment_keys = array() ) {
		$this->assert_supported_post( $post );

		$segments = $this->load_segments( $post, $language_id );
		$wanted   = array();
		foreach ( $segment_keys as $key ) {
			$wanted[ (string) $key ] = true;
		}

		$items = array();
		foreach ( $segments as $segment ) {
			$key = (string) ( $segment['segment_key'] ?? '' );
			if ( array() !== $wanted && ! isset( $wanted[ $key ] ) ) {
				continue;
			}

			if ( ! (bool) ( $segment['can_edit'] ?? false ) ) {
				continue;
			}

			$exact = $this->first_exact_tm_suggestion( $segment );
			if ( null === $exact ) {
				continue;
			}

			$items[] = array(
				'segment_key'               => $key,
				'translated_text'           => $exact['text'],
				'source_hash'               => (string) ( $segment['source_hash'] ?? '' ),
				'expected_translation_hash' => (string) ( $segment['translation_hash'] ?? '' ),
				'status'                    => Store::STATUS_MANUALLY_EDITED,
				'save_origin'               => 'tm_accepted',
				'tm_id'                     => $exact['tm_id'],
			);
		}

		if ( array() === $items ) {
			return array(
				'status'   => 'completed',
				'segments' => array(),
				'errors'   => array(),
			);
		}

		return $this->batch->save_batch( $post, $language_id, $items );
	}

	/**
	 * Runs read-only QA for selected or all segments.
	 *
	 * @param WP_Post            $post         Canonical post.
	 * @param int                $language_id  Target language id.
	 * @param array<int, string> $segment_keys Segment keys (empty = all).
	 * @return array<string, mixed>
	 */
	public function qa_batch( WP_Post $post, int $language_id, array $segment_keys = array() ): array {
		$this->assert_supported_post( $post );

		$segments = $this->load_segments( $post, $language_id );
		$wanted   = array();
		foreach ( $segment_keys as $key ) {
			$wanted[ (string) $key ] = true;
		}

		$out      = array();
		$errors   = 0;
		$warnings = 0;
		$info     = 0;

		foreach ( $segments as $segment ) {
			$key = (string) ( $segment['segment_key'] ?? '' );
			if ( array() !== $wanted && ! isset( $wanted[ $key ] ) ) {
				continue;
			}

			$qa        = is_array( $segment['meta']['qa'] ?? null ) ? $segment['meta']['qa'] : array();
			$summary   = is_array( $qa['summary'] ?? null ) ? $qa['summary'] : array();
			$errors   += (int) ( $summary['errors'] ?? 0 );
			$warnings += (int) ( $summary['warnings'] ?? 0 );
			$info     += (int) ( $summary['info'] ?? 0 );
			$out[]     = $segment;
		}

		return array(
			'segments' => $out,
			'summary'  => array(
				'errors'   => $errors,
				'warnings' => $warnings,
				'info'     => $info,
			),
		);
	}

	/**
	 * Returns the top exact TM suggestion text and tm_id, if any.
	 *
	 * @param array<string, mixed> $segment Segment DTO with meta.suggestions.
	 * @return array{text: string, tm_id: int}|null
	 */
	private function first_exact_tm_suggestion( array $segment ): ?array {
		$suggestions = is_array( $segment['meta']['suggestions'] ?? null )
			? $segment['meta']['suggestions']
			: array();

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			if ( 'tm' !== (string) ( $suggestion['provider_id'] ?? '' ) ) {
				continue;
			}
			$tier  = (int) ( $suggestion['rank_tier'] ?? 0 );
			$meta  = is_array( $suggestion['metadata'] ?? null ) ? $suggestion['metadata'] : array();
			$match = (string) ( $meta['match_type'] ?? '' );
			if ( 1 === $tier || 'exact' === $match ) {
				$text = (string) ( $suggestion['target_text'] ?? '' );
				if ( '' === $text ) {
					return null;
				}

				return array(
					'text'  => $text,
					'tm_id' => (int) ( $meta['tm_id'] ?? 0 ),
				);
			}
		}

		return null;
	}

	/**
	 * Records Translation Memory usage for an already-existing exact match
	 * accepted at save time.
	 *
	 * New-content write-back moved from save-time to approval-time
	 * (ADR-0015 §7 / F11 amendment, R5) — see
	 * {@see write_back_tm_on_approval()}. This method only ever calls
	 * {@see TranslationMemoryService::record_usage()}: accepting an
	 * existing TM hit does not add new content to the catalogue, so it is
	 * not covered by the approval gate and stays wired to the moment of
	 * acceptance. Machine persist never reaches this method
	 * (TranslationService writes Store directly).
	 *
	 * @param int                  $language_id     Target language id.
	 * @param array<string, mixed> $current         Pre-save assembled segment DTO.
	 * @param string               $translated_text Saved target text.
	 * @param string               $save_origin     Optional explicit origin token.
	 * @param int                  $tm_id           Optional TM id for tm_accepted.
	 */
	private function record_tm_usage_after_save(
		int $language_id,
		array $current,
		string $translated_text,
		string $save_origin = '',
		int $tm_id = 0
	): void {
		if ( '' === trim( $translated_text ) ) {
			return;
		}

		if ( 'machine' === $save_origin ) {
			return;
		}

		$resolved_origin = $save_origin;
		$resolved_tm_id  = $tm_id;

		if ( '' === $resolved_origin ) {
			$match = $this->matching_tm_suggestion( $current, $language_id, $translated_text );
			if ( null !== $match ) {
				$resolved_origin = 'tm_accepted';
				$resolved_tm_id  = $match;
			}
		}

		if ( 'tm_accepted' !== $resolved_origin ) {
			return;
		}

		if ( $resolved_tm_id <= 0 ) {
			$resolved_tm_id = $this->matching_tm_suggestion( $current, $language_id, $translated_text ) ?? 0;
		}

		if ( $resolved_tm_id > 0 ) {
			$this->tm->record_usage( $resolved_tm_id );
		}
	}

	/**
	 * Writes new eligible content back to Translation Memory on approval.
	 *
	 * ADR-0015 §7 / F11 amendment (R5): pending and rejected translations
	 * never reach this method — it is only called from
	 * {@see approve_review()}, and only on a real `pending` → `approved`
	 * transition (never on an idempotent duplicate approve). Machine-origin
	 * content is excluded unless a human has since edited it — i.e. `status`
	 * is no longer {@see Store::STATUS_MACHINE_TRANSLATED} — matching the
	 * existing "never machine unless AI-accepted" write-back policy.
	 *
	 * Always resolves to `human` provenance: this milestone has no live
	 * `ai_accepted` / `import` signal by the time a segment reaches formal
	 * review approval (unchanged from pre-R5 behaviour, which also had no
	 * caller passing those tokens). Reuses
	 * {@see TranslationMemoryService::write_back()} — its identity upsert
	 * never touches `use_count`, so approving content that already exists in
	 * TM (e.g. an earlier `tm_accepted` save) safely updates the existing row
	 * instead of duplicating it or inflating usage.
	 *
	 * @param int                  $language_id Target language id.
	 * @param array<string, mixed> $segment     Assembled segment DTO captured
	 *                                          immediately before the approve
	 *                                          transition; `translated_text`
	 *                                          is guaranteed to match the
	 *                                          approved content via
	 *                                          `submitted_translation_hash`.
	 */
	private function write_back_tm_on_approval( int $language_id, array $segment ): void {
		$translated_text = (string) ( $segment['translated_text'] ?? '' );
		if ( '' === trim( $translated_text ) ) {
			return;
		}

		if ( Store::STATUS_MACHINE_TRANSLATED === (string) ( $segment['status'] ?? '' ) ) {
			return;
		}

		$default = $this->languages->default();
		if ( null === $default ) {
			return;
		}

		$result = $this->tm->write_back(
			array(
				'source_lang_id' => (int) $default->language_id,
				'target_lang_id' => $language_id,
				'source_text'    => (string) ( $segment['source_text'] ?? '' ),
				'source_hash'    => (string) ( $segment['source_hash'] ?? '' ),
				'target_text'    => $translated_text,
				'text_format'    => (string) ( $segment['text_format'] ?? Store::FORMAT_PLAIN ),
				'context'        => TranslationMemoryService::derive_context(
					(string) ( $segment['block_name'] ?? '' ),
					(string) ( $segment['field_key'] ?? '' )
				),
			),
			'human'
		);

		// null means write-back was skipped as ineligible (format/origin) —
		// not a failure, so it is not counted either way (ADR-0015 §13).
		if ( $result instanceof WP_Error ) {
			$this->review_diagnostics->increment( ReviewDiagnosticsCounters::TM_WRITE_BACK_FAILURE );
		} elseif ( null !== $result ) {
			$this->review_diagnostics->increment( ReviewDiagnosticsCounters::TM_WRITE_BACK_SUCCESS );
		}
	}

	/**
	 * Finds a TM suggestion id whose target text matches the saved translation.
	 *
	 * @param array<string, mixed> $current         Segment DTO (meta optional).
	 * @param int                  $language_id     Target language id.
	 * @param string               $translated_text Saved target text.
	 */
	private function matching_tm_suggestion( array $current, int $language_id, string $translated_text ): ?int {
		$suggestions = is_array( $current['meta']['suggestions'] ?? null )
			? $current['meta']['suggestions']
			: array();

		if ( array() === $suggestions ) {
			$with_meta   = $this->attach_meta( array( $current ), $language_id );
			$suggestions = is_array( $with_meta[0]['meta']['suggestions'] ?? null )
				? $with_meta[0]['meta']['suggestions']
				: array();
		}

		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			if ( 'tm' !== (string) ( $suggestion['provider_id'] ?? '' ) ) {
				continue;
			}
			if ( (string) ( $suggestion['target_text'] ?? '' ) !== $translated_text ) {
				continue;
			}
			$meta  = is_array( $suggestion['metadata'] ?? null ) ? $suggestion['metadata'] : array();
			$tm_id = (int) ( $meta['tm_id'] ?? 0 );
			if ( $tm_id > 0 ) {
				return $tm_id;
			}
		}

		return null;
	}

	/**
	 * Attaches ranked suggestions and QA via dedicated services.
	 *
	 * @param list<array<string, mixed>> $segments    Assembled segment DTOs.
	 * @param int                        $language_id Target language id.
	 * @return list<array<string, mixed>>
	 */
	private function attach_meta( array $segments, int $language_id ): array {
		$default = $this->languages->default();
		$context = array(
			'source_language_id' => $default ? (int) $default->language_id : 0,
			'target_language_id' => $language_id,
		);

		$by_key = $this->suggestions->suggestions_for_batch( $segments, $context );

		foreach ( $segments as $index => $segment ) {
			$key                 = (string) ( $segment['segment_key'] ?? '' );
			$meta                = is_array( $segment['meta'] ?? null ) ? $segment['meta'] : array();
			$meta['suggestions'] = $by_key[ $key ] ?? array();
			$target_text         = (string) ( $segment['translated_text'] ?? '' );

			// A segment with no translation yet has no translation quality to
			// report — the Status column already shows "Missing". Running the
			// detectors here only turns every untranslated segment (a fresh
			// page, or fields the AI flow does not touch such as the URL slug)
			// into a wall of empty_translation / number / tag warnings. Quality
			// checks still run in full on the save and review paths.
			$meta['qa'] = '' === trim( $target_text )
				? ( new QAResult( array() ) )->to_array()
				: $this->qa->evaluate(
					(string) ( $segment['source_text'] ?? '' ),
					$target_text,
					(string) ( $segment['text_format'] ?? Store::FORMAT_PLAIN ),
					array(
						'source_language_id' => $default ? (int) $default->language_id : 0,
						'target_language_id' => $language_id,
					)
				)->to_array();
			// TI.5: same assessment core; Workspace save-path has no request markers.
			$post_type                  = (string) ( $segment['source_subtype'] ?? '' );
			$meta['assessment']         = $this->assessment->assess_segment(
				$segment,
				$this->field_semantic_mapper->map( $segment, $post_type ),
				array(),
				false
			)->to_array();
			$meta['publish_status']     = (string) ( $segment['publish_status'] ?? Store::PUBLISH_UNPUBLISHED );
			$segments[ $index ]['meta'] = $meta;
		}

		return $segments;
	}

	/**
	 * Reloads the merged segment DTO after a review transition.
	 *
	 * ReviewWorkflowService returns the raw Store row; re-assembling through
	 * the same path as `save_segment()` keeps block_name/uuid/can_edit/meta
	 * consistent and picks up the freshly written review-axis fields.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 * @param string  $segment_key Segment key.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the segment cannot be reloaded.
	 */
	private function segment_view_after_review( WP_Post $post, int $language_id, string $segment_key ): array {
		$refreshed = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $refreshed ) {
			throw new \RuntimeException( 'Segment could not be reloaded after review update.' );
		}

		$with_meta = $this->attach_meta( array( $refreshed ), $language_id );

		return $with_meta[0];
	}

	/**
	 * Re-evaluates QA against current content before approval (freshness check).
	 *
	 * Mirrors the save-time gate (`save_segment()`) so approving stale QA
	 * evidence cannot silently pass: it recomputes issues from the current
	 * source/translated text rather than trusting any previously cached
	 * result. Warnings (including `glossary_term_missing`) never block;
	 * errors block only when `qa_block_on_error` is enabled.
	 *
	 * Deliberately does not catch broad exceptions from `QAEngine::evaluate()`
	 * (guarded by `PluginGuardTest::test_no_broad_exception_is_swallowed()`);
	 * a checker bug should fail loudly, exactly as it would on save.
	 * `ReviewQAUnavailableException` is reserved for a narrower, R6 hook
	 * (e.g. an explicit "QA temporarily disabled" signal) once one exists.
	 *
	 * @param array<string, mixed> $segment     Assembled segment DTO.
	 * @param int                  $language_id Target language id.
	 * @throws WorkspaceQAException When QA errors block approval under policy.
	 */
	private function assert_qa_passes_for_approval( array $segment, int $language_id ): void {
		$default = $this->languages->default();
		$qa      = $this->qa->evaluate(
			(string) ( $segment['source_text'] ?? '' ),
			(string) ( $segment['translated_text'] ?? '' ),
			(string) ( $segment['text_format'] ?? Store::FORMAT_PLAIN ),
			array(
				'source_language_id' => $default ? (int) $default->language_id : 0,
				'target_language_id' => $language_id,
			)
		);

		if ( $this->qa->should_block_save( $qa ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- QA payload is structured data, not exception text.
			throw new WorkspaceQAException( $qa );
		}
	}

	/**
	 * Increments the bounded stale-conflict counter for 409 review conflicts.
	 *
	 * Shared by submit/approve/reject so every optimistic-locking conflict
	 * across the review lifecycle is counted the same way (ADR-0015 §13).
	 *
	 * @param ReviewWorkflowException $exception Domain exception from ReviewWorkflowService.
	 */
	private function record_review_conflict( ReviewWorkflowException $exception ): void {
		if ( ReviewWorkflowService::CODE_CONFLICT === $exception->get_error_code() ) {
			$this->review_diagnostics->increment( ReviewDiagnosticsCounters::CONFLICTS );
		}
	}

	/**
	 * Returns bounded, low-cardinality Review Workflow diagnostics
	 * (ADR-0015 §13): query-time counts/pending-age from the Store plus
	 * cross-request counters for conflicts, approval failures, QA-blocked
	 * approvals, and TM write-back outcomes.
	 *
	 * @param array<string, mixed> $args Optional scope: post_id, language (code).
	 * @return array<string, mixed>
	 */
	public function review_diagnostics( array $args = array() ): array {
		$source_id   = (int) ( $args['post_id'] ?? 0 );
		$language_id = 0;

		$code = (string) ( $args['language'] ?? '' );
		if ( '' !== $code ) {
			$language = $this->resolve_language( $code );
			if ( null !== $language ) {
				$language_id = (int) $language->language_id;
			}
		}

		return array(
			'review_status_counts' => $this->store->review_status_counts( Store::SOURCE_POST, $source_id, $language_id ),
			'pending_age'          => $this->store->review_pending_age_stats( Store::SOURCE_POST, $source_id, $language_id ),
			'counters'             => $this->review_diagnostics->counters(),
		);
	}

	/**
	 * List/picker title for a workspace post.
	 *
	 * Nav menu items get a stable human prefix so N1 rows are recognizable
	 * beside pages/products without a second workflow product.
	 *
	 * @param WP_Post $post Canonical post.
	 */
	private function workspace_list_title( WP_Post $post ): string {
		$title = (string) $post->post_title;

		if ( 'nav_menu_item' !== $post->post_type ) {
			return $title;
		}

		if ( '' === trim( $title ) ) {
			$title = __( '(untitled menu item)', 'universal-multilingual' );
		}

		return sprintf(
			/* translators: %s: navigation menu item title */
			__( 'Menu item: %s', 'universal-multilingual' ),
			$title
		);
	}

	/**
	 * Ensures the post type is supported by the workspace.
	 *
	 * @param WP_Post $post Canonical post.
	 * @throws \InvalidArgumentException When the post type is unsupported.
	 */
	private function assert_supported_post( WP_Post $post ): void {
		if ( ! in_array( $post->post_type, $this->workspace_post_types(), true ) ) {
			throw new \InvalidArgumentException( 'This post type is not supported in the workspace.' );
		}
	}

	/**
	 * Core workspace types plus activated M5-A chrome CPT slugs.
	 *
	 * @return list<string>
	 */
	private function workspace_post_types(): array {
		$types     = self::SUPPORTED_POST_TYPES;
		$admission = IntegrationAdmission::registry();
		if ( null === $admission ) {
			return $types;
		}
		foreach ( $admission->activated_post_types() as $post_type ) {
			if ( ! in_array( $post_type, $types, true ) ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}

	/**
	 * Execution-time write denial for TSC.3 compatibility / authority rows.
	 *
	 * @param string $segment_key Segment key.
	 * @param int    $source_id   Host source id.
	 * @param int    $language_id Language id.
	 * @throws \InvalidArgumentException When the row is non-writable authority.
	 */
	private function assert_writable_segment_key( string $segment_key, int $source_id, int $language_id ): void {
		$row = (object) array(
			'source_type' => Store::SOURCE_POST,
			'source_id'   => $source_id,
			'language_id' => $language_id,
			'segment_key' => $segment_key,
		);
		if ( \AIMultilingual\Workspace\Operator\AllowedActionsResolver::denies_row_write( $row ) ) {
			throw new \InvalidArgumentException( 'This translation segment is read-only compatibility or requires Woo attribute authority.' );
		}
	}

	/**
	 * Whether the current user may access workspace operations for a post.
	 *
	 * @param WP_Post $post Canonical post.
	 */
	public function current_user_can_access( WP_Post $post ): bool {
		return current_user_can( Plugin::CAPABILITY ) && current_user_can( 'edit_post', (int) $post->ID );
	}
}
