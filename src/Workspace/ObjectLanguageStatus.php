<?php
/**
 * Server-authoritative "object × language" translation state (MLW1a, ADR-0034 D2).
 *
 * A pure value object + composer over EXISTING read models only — it introduces
 * no new query truth:
 *   - {@see ObjectReviewSummary}          review_status_counts + TranslationStatusCalculator
 *   - SiteTranslateCoverageService        coverage_for_post() -> published/unpublished
 *   - BackgroundTranslationJobRepository  find_active_by_lock_key() (active job probe)
 *   - Languages registry                  language status (preview|published)
 *   - QA metadata scan                    meta['qa'] error count over the object's segments
 *
 * PHP owns the entire state-decision algorithm. TypeScript never re-derives
 * `state` — `utils/object-language-status.ts` is presentation-only.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

use AIMultilingual\Language\Languages;
use AIMultilingual\Workspace\Review\ObjectReviewSummary;

/**
 * Canonical state of one content object in one target language.
 */
final class ObjectLanguageStatus {

	public const STATE_TRANSLATING     = 'translating';
	public const STATE_NOT_TRANSLATED  = 'not_translated';
	public const STATE_MISSING_FIELDS  = 'missing_fields';
	public const STATE_NEEDS_ATTENTION = 'needs_attention';
	public const STATE_PENDING_REVIEW  = 'pending_review';
	public const STATE_REVIEWED        = 'reviewed';
	public const STATE_PREVIEW         = 'preview';
	public const STATE_PUBLISHED       = 'published';
	public const STATE_TRANSLATED      = 'translated';

	/**
	 * States a language may be in and still count toward `ready_count`
	 * (ADR-0034 D3 / correction C5). `ready` additionally requires
	 * `missing == 0 && stale == 0 && has_qa_errors == false`.
	 */
	private const READY_STATES = array(
		self::STATE_TRANSLATED,
		self::STATE_PREVIEW,
		self::STATE_PENDING_REVIEW,
		self::STATE_REVIEWED,
		self::STATE_PUBLISHED,
	);

	/**
	 * Holds the raw per-(object, language) read-model counts the state ladder
	 * and the ready/forgotten predicates are derived from.
	 *
	 * @param int    $language_id        Target language id.
	 * @param string $language_code      Target language code.
	 * @param string $language_name      Human language name.
	 * @param string $language_status    Languages::STATUS_* of the language itself.
	 * @param int    $total              Total translatable segments for object + language.
	 * @param int    $translated         Segments with a translation (any review state).
	 * @param int    $missing            Segments with no usable translation yet.
	 * @param int    $stale              Translated segments whose source changed since.
	 * @param int    $pending            Segments with review_status = pending.
	 * @param int    $approved           Segments with review_status = approved.
	 * @param int    $rejected           Segments with review_status = rejected.
	 * @param int    $published_segments Segments published to public visibility (coverage).
	 * @param bool   $has_active_job     An active background translation job exists.
	 * @param bool   $has_qa_errors      Any error-severity meta['qa'] issue on the object.
	 */
	public function __construct(
		public readonly int $language_id,
		public readonly string $language_code,
		public readonly string $language_name,
		public readonly string $language_status,
		public readonly int $total,
		public readonly int $translated,
		public readonly int $missing,
		public readonly int $stale,
		public readonly int $pending,
		public readonly int $approved,
		public readonly int $rejected,
		public readonly int $published_segments,
		public readonly bool $has_active_job,
		public readonly bool $has_qa_errors
	) {
	}

	/**
	 * Composes the status from existing read models.
	 *
	 * @param object               $language       Languages registry row (language_id/code/name/status).
	 * @param ObjectReviewSummary  $review         Completeness-aware review summary for object + language.
	 * @param array<string, mixed> $coverage       SiteTranslateCoverageService::coverage_for_post() output.
	 * @param bool                 $has_active_job Active background translation job probe result.
	 * @param bool                 $has_qa_errors  meta['qa'] error scan result over the object's segments.
	 */
	public static function compose(
		object $language,
		ObjectReviewSummary $review,
		array $coverage,
		bool $has_active_job,
		bool $has_qa_errors
	): self {
		return new self(
			(int) ( $language->language_id ?? 0 ),
			(string) ( $language->code ?? '' ),
			(string) ( $language->name ?? '' ),
			(string) ( $language->status ?? '' ),
			$review->total,
			$review->translated,
			$review->untranslated,
			$review->stale,
			$review->pending,
			$review->approved,
			$review->rejected,
			(int) ( $coverage['published'] ?? 0 ),
			$has_active_job,
			$has_qa_errors
		);
	}

	/**
	 * Canonical state — the frozen priority ladder (ADR-0034 D2).
	 *
	 * PHP is the single authority: TypeScript renders this verbatim and never
	 * re-implements the ladder.
	 */
	public function state(): string {
		if ( $this->has_active_job ) {
			return self::STATE_TRANSLATING;
		}

		if ( $this->missing > 0 && $this->total === $this->missing ) {
			return self::STATE_NOT_TRANSLATED;
		}

		if ( $this->missing > 0 ) {
			return self::STATE_MISSING_FIELDS;
		}

		if ( $this->has_qa_errors || $this->rejected > 0 || $this->stale > 0 ) {
			return self::STATE_NEEDS_ATTENTION;
		}

		if ( $this->pending > 0 ) {
			return self::STATE_PENDING_REVIEW;
		}

		if ( $this->total > 0 && $this->approved === $this->total ) {
			return $this->published_segments > 0 ? self::STATE_PUBLISHED : self::STATE_REVIEWED;
		}

		if ( Languages::STATUS_PUBLISHED === $this->language_status ) {
			return self::STATE_PUBLISHED;
		}

		if ( Languages::STATUS_PREVIEW === $this->language_status ) {
			return self::STATE_PREVIEW;
		}

		return self::STATE_TRANSLATED;
	}

	/**
	 * Whether this language counts toward `ready_count` — complete and clean
	 * (frozen definition, correction C5).
	 */
	public function is_ready(): bool {
		return 0 === $this->missing
			&& 0 === $this->stale
			&& ! $this->has_qa_errors
			&& in_array( $this->state(), self::READY_STATES, true );
	}

	/**
	 * Whether this target language has been forgotten entirely.
	 */
	public function is_forgotten(): bool {
		return self::STATE_NOT_TRANSLATED === $this->state();
	}

	/**
	 * True when this language has approvable pending review rows — the subset
	 * "Approve all ready languages" acts on.
	 */
	public function is_approvable(): bool {
		return $this->pending > 0
			&& ! $this->has_qa_errors
			&& 0 === $this->rejected
			&& 0 === $this->stale
			&& 0 === $this->missing;
	}

	/**
	 * REST / serialization shape — every count plus the canonical decision.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'language_id'        => $this->language_id,
			'language_code'      => $this->language_code,
			'language_name'      => $this->language_name,
			'language_status'    => $this->language_status,
			'total'              => $this->total,
			'translated'         => $this->translated,
			'missing'            => $this->missing,
			'stale'              => $this->stale,
			'pending'            => $this->pending,
			'approved'           => $this->approved,
			'rejected'           => $this->rejected,
			'published_segments' => $this->published_segments,
			'has_active_job'     => $this->has_active_job,
			'has_qa_errors'      => $this->has_qa_errors,
			'state'              => $this->state(),
			'is_ready'           => $this->is_ready(),
			'is_forgotten'       => $this->is_forgotten(),
			'is_approvable'      => $this->is_approvable(),
		);
	}
}
