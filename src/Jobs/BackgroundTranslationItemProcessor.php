<?php
/**
 * Sole per-item domain boundary for background translation jobs.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Jobs;

use AIMultilingual\Glossary\GlossaryService;
use AIMultilingual\Surface\SurfaceCapabilityNames;
use AIMultilingual\Surface\SurfaceRegistry;
use AIMultilingual\Translation\AI\ProviderResult;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\TranslatableSegmentEligibility;
use AIMultilingual\Workspace\TranslationService;
use WP_Error;
use WP_Post;

/**
 * Delegates translation work exclusively through TranslationService (plan §19).
 */
final class BackgroundTranslationItemProcessor {

	/**
	 * Segment store for conflict reads.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Existing auto-translate boundary.
	 *
	 * @var TranslationService
	 */
	private TranslationService $translation;

	/**
	 * Glossary version source.
	 *
	 * @var GlossaryService
	 */
	private GlossaryService $glossary;

	/**
	 * Source hash assembly for pre-checks.
	 *
	 * @var SegmentAssembler
	 */
	private SegmentAssembler $assembler;

	/**
	 * Retry taxonomy for translate errors.
	 *
	 * @var BackgroundTranslationRetryPolicy
	 */
	private BackgroundTranslationRetryPolicy $retry_policy;

	/**
	 * Surface registry for existence / Jobs facts (TSC.0).
	 *
	 * @var SurfaceRegistry|null
	 */
	private ?SurfaceRegistry $surfaces;

	/**
	 * Builds the processor.
	 *
	 * @param Store                                                    $store        Segment store.
	 * @param TranslationService                                       $translation  Translation boundary.
	 * @param GlossaryService                                          $glossary     Glossary service.
	 * @param SegmentAssembler                                         $assembler     Segment assembler.
	 * @param BackgroundTranslationRetryPolicy|null                    $retry_policy  Retry policy.
	 * @param SurfaceRegistry|null                                     $surfaces      Surface registry.
	 * @param \AIMultilingual\Surface\Meta\RegisteredMetaRegistry|null $meta_registry Optional registered-meta catalog.
	 */
	public function __construct(
		Store $store,
		TranslationService $translation,
		GlossaryService $glossary,
		SegmentAssembler $assembler,
		?BackgroundTranslationRetryPolicy $retry_policy = null,
		?SurfaceRegistry $surfaces = null,
		private ?\AIMultilingual\Surface\Meta\RegisteredMetaRegistry $meta_registry = null,
	) {
		$this->store        = $store;
		$this->translation  = $translation;
		$this->glossary     = $glossary;
		$this->assembler    = $assembler;
		$this->retry_policy = $retry_policy ?? new BackgroundTranslationRetryPolicy();
		$this->surfaces     = $surfaces;
	}

	/**
	 * Process one job item through the existing translation pipeline.
	 *
	 * @param object       $job            Job row.
	 * @param object       $item           Item row.
	 * @param WP_Post|null $post           Canonical post when source_type is post.
	 * @param bool         $allow_provider When false, TM/skip/conflict only — no provider call.
	 * @return ItemResult
	 */
	public function process( object $job, object $item, ?WP_Post $post = null, bool $allow_provider = true ): ItemResult {
		$language_id = (int) $job->language_id;
		$segment_key = (string) $item->segment_key;
		$job_type    = (string) $job->job_type;
		$source_type = (string) ( $job->source_type ?? Store::SOURCE_POST );
		$source_id   = (int) ( $job->source_id ?? ( $post instanceof WP_Post ? $post->ID : 0 ) );

		if ( null !== $this->surfaces ) {
			$surface = $this->surfaces->for( $source_type );
			if ( null === $surface || ! $surface->supports( SurfaceCapabilityNames::JOBS ) || ! $surface->exists( $source_id ) ) {
				return ItemResult::skipped_conflict( 'Source object is missing or unregistered for Jobs work.' );
			}
		}

		$row = $this->store->get( $source_type, $source_id, $language_id, $segment_key );
		if ( null !== $row ) {
			$status     = (string) ( $row->status ?? '' );
			$error_code = (string) ( $row->error_code ?? '' );
			if ( Store::STATUS_IGNORED === $status || 'orphaned' === $error_code ) {
				return ItemResult::skipped_conflict( 'Authoritative Store state is ignored/orphaned; not provider-processed.' );
			}
		}

		if ( null !== $this->meta_registry ) {
			$provider_fact = $this->meta_registry->provider_allowed_for_segment( $source_type, $segment_key );
			if ( false === $provider_fact ) {
				return ItemResult::skipped_conflict( 'Registered meta segment is not provider-admitted.' );
			}
		}

		if ( null !== $row && Store::FORMAT_SLUG === (string) ( $row->text_format ?? '' ) ) {
			return ItemResult::skipped_conflict( 'FORMAT_SLUG segments are not provider-admitted.' );
		}
		if ( Extractor::FIELD_SLUG === $segment_key ) {
			return ItemResult::skipped_conflict( 'Slug candidates are not provider-admitted.' );
		}

		if ( Store::SOURCE_TERM === $source_type ) {
			return $this->process_term_item( $job, $item, $row, $allow_provider );
		}

		if ( ! $post instanceof WP_Post ) {
			return ItemResult::from_error(
				ItemStatuses::FAILED,
				'aiml_invalid_source',
				ProviderResult::ERROR_PERMANENT,
				'Job source post is required for post-typed work.'
			);
		}

		$assembled = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $assembled ) {
			return ItemResult::from_error(
				ItemStatuses::FAILED,
				'aiml_invalid_segment',
				ProviderResult::ERROR_PERMANENT,
				'Unknown segment key for this post.'
			);
		}

		$current_source_hash = (string) ( $assembled['source_hash'] ?? '' );
		$captured_source     = (string) ( $item->source_hash_captured ?? '' );

		if ( '' !== $captured_source && $current_source_hash !== $captured_source ) {
			return ItemResult::stale_source();
		}

		$conflict = $this->evaluate_conflict(
			$row,
			$job_type,
			(string) ( $item->translation_hash_captured ?? '' )
		);
		if ( null !== $conflict ) {
			return $conflict;
		}

		$glossary_version = $this->glossary->current_version();
		$result           = $this->translation->translate_segment( $post, $language_id, $segment_key, $allow_provider );
		$usage            = $this->last_attempt_usage();

		if ( $result instanceof WP_Error ) {
			return $this->map_translate_error( $result, $usage );
		}

		// TI.7: PublicationService is invoked inside TranslationService after persist
		// success (same path as sync). Jobs must not duplicate or own policy.

		return ItemResult::completed( $glossary_version, $usage );
	}

	/**
	 * Term-typed job item path (TSC.1).
	 *
	 * @param object      $job            Job row.
	 * @param object      $item           Item row.
	 * @param object|null $row            Existing Store row.
	 * @param bool        $allow_provider Provider permission.
	 */
	private function process_term_item( object $job, object $item, ?object $row, bool $allow_provider ): ItemResult {
		$language_id = (int) $job->language_id;
		$segment_key = (string) $item->segment_key;
		$job_type    = (string) $job->job_type;
		$term_id     = (int) $job->source_id;

		$surface = null !== $this->surfaces ? $this->surfaces->for( Store::SOURCE_TERM ) : null;
		if ( null === $surface ) {
			return ItemResult::skipped_conflict( 'Term surface is not registered.' );
		}

		$segments = $surface->extract_segments( $term_id );
		$unit     = $segments[ $segment_key ] ?? null;
		if ( ! is_array( $unit ) ) {
			return ItemResult::from_error(
				ItemStatuses::FAILED,
				'aiml_invalid_segment',
				ProviderResult::ERROR_PERMANENT,
				'Unknown segment key for this term.'
			);
		}

		$current_source_hash = Store::source_hash(
			(string) ( $unit['source_text'] ?? '' ),
			(string) ( $unit['text_format'] ?? Store::FORMAT_PLAIN )
		);
		$captured_source     = (string) ( $item->source_hash_captured ?? '' );

		if ( '' !== $captured_source && $current_source_hash !== $captured_source ) {
			return ItemResult::stale_source();
		}

		$conflict = $this->evaluate_conflict(
			$row,
			$job_type,
			(string) ( $item->translation_hash_captured ?? '' )
		);
		if ( null !== $conflict ) {
			return $conflict;
		}

		if ( ! $allow_provider ) {
			return ItemResult::skipped_conflict( 'Provider calls are not allowed in this wake.' );
		}

		$result = $this->translation->translate_term_segment(
			$term_id,
			$surface->source_subtype( $term_id ),
			$language_id,
			$segment_key,
			true
		);
		$usage  = $this->last_attempt_usage();

		if ( $result instanceof WP_Error ) {
			return $this->map_translate_error( $result, $usage );
		}

		return ItemResult::completed( $this->glossary->current_version(), $usage );
	}

	/**
	 * Apply overwrite/conflict policy before calling TranslationService (plan §11).
	 *
	 * @param object|null $row                       Store row when present.
	 * @param string      $job_type                  Job type code.
	 * @param string      $translation_hash_captured Snapshot translation hash.
	 */
	private function evaluate_conflict( ?object $row, string $job_type, string $translation_hash_captured ): ?ItemResult {
		if ( null === $row ) {
			return null;
		}

		$projection = array(
			'status'        => (string) ( $row->status ?? Store::STATUS_MISSING ),
			'review_status' => (string) ( $row->review_status ?? Store::REVIEW_NOT_SUBMITTED ),
		);
		if ( TranslatableSegmentEligibility::is_protected( $projection ) ) {
			return ItemResult::skipped_conflict( TranslatableSegmentEligibility::protected_reason( $projection ) );
		}

		$status = (string) ( $row->status ?? Store::STATUS_MISSING );

		$translated = trim( (string) ( $row->translated_text ?? '' ) );
		if ( Store::STATUS_MISSING === $status || '' === $translated ) {
			return null;
		}

		if ( Store::STATUS_MACHINE_TRANSLATED !== $status ) {
			return ItemResult::skipped_conflict( 'Segment status is not eligible for background overwrite.' );
		}

		if ( ! JobTypes::allows_retranslate( $job_type ) ) {
			return ItemResult::skipped_conflict( 'Job type does not allow retranslation of machine output.' );
		}

		if ( '' !== $translation_hash_captured ) {
			$current_hash = Store::translation_hash( (string) ( $row->translated_text ?? '' ) );
			if ( $current_hash !== $translation_hash_captured ) {
				return ItemResult::skipped_conflict( 'Translation hash differs from the job snapshot.' );
			}
		}

		return null;
	}

	/**
	 * Map TranslationService WP_Error to a bounded ItemResult.
	 *
	 * @param WP_Error             $error Provider or validation error.
	 * @param AttemptUsageEvidence $usage Attempt usage evidence.
	 */
	private function map_translate_error( WP_Error $error, AttemptUsageEvidence $usage ): ItemResult {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$data    = $error->get_error_data();
		$http    = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$retry   = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;

		$disposition = $this->retry_policy->classify( $code, $http > 0 ? $http : null );
		$class       = BackgroundTranslationRetryPolicy::DISPOSITION_RETRYABLE === $disposition
			? ProviderResult::ERROR_RETRYABLE
			: ProviderResult::ERROR_PERMANENT;

		$status = ProviderResult::ERROR_RETRYABLE === $class
			? ItemStatuses::RETRY_WAIT
			: ItemStatuses::FAILED;

		return ItemResult::from_error( $status, $code, $class, $message, $retry, $usage );
	}

	/**
	 * Map the Workspace usage shape into the Jobs orchestration DTO.
	 */
	private function last_attempt_usage(): AttemptUsageEvidence {
		$usage      = $this->translation->last_attempt_usage();
		$tm_outcome = $this->translation->last_tm_outcome();
		$tm_code    = null !== $tm_outcome ? $tm_outcome->code : '';

		if ( null === $usage ) {
			return AttemptUsageEvidence::known_zero( $tm_code );
		}

		return new AttemptUsageEvidence(
			max( 0, (int) ( $usage['provider_requests'] ?? 0 ) ),
			max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
			max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
			(bool) ( $usage['usage_known'] ?? false ),
			(string) ( $usage['tm_outcome_code'] ?? $tm_code )
		);
	}
}
