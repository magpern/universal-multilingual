<?php
/**
 * Completeness-aware review summary for one content object + language (RVQ1).
 *
 * Composed from existing read models — never a new coverage calculation:
 *   - Store::review_status_counts()            → approved / pending / rejected
 *   - TranslationStatusCalculator::for_post()  → total / missing / stale / translated
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace\Review;

use AIMultilingual\Translation\Store;

/**
 * Value object describing how complete a translation review is.
 */
final class ObjectReviewSummary {

	public const STATE_COMPLETE        = 'complete';
	public const STATE_READY           = 'ready';
	public const STATE_NEEDS_ATTENTION = 'needs_attention';
	public const STATE_INCOMPLETE      = 'incomplete';

	/**
	 * Builds the summary.
	 *
	 * @param int $total        Total translatable segments for the object + language.
	 * @param int $approved     Segments with review_status = approved.
	 * @param int $pending      Segments with review_status = pending.
	 * @param int $rejected     Segments with review_status = rejected.
	 * @param int $untranslated Segments with no usable translation yet.
	 * @param int $stale        Translated segments whose source changed since.
	 * @param int $translated   Segments with a translation (any review state).
	 */
	public function __construct(
		public readonly int $total,
		public readonly int $approved,
		public readonly int $pending,
		public readonly int $rejected,
		public readonly int $untranslated,
		public readonly int $stale,
		public readonly int $translated
	) {
	}

	/**
	 * Composes the summary from the two existing read models.
	 *
	 * @param array<string, int>   $review_counts Output of Store::review_status_counts().
	 * @param array<string, mixed> $status        Output of TranslationStatusCalculator::for_post().
	 */
	public static function compose( array $review_counts, array $status ): self {
		return new self(
			(int) ( $status['total_segments'] ?? 0 ),
			(int) ( $review_counts[ Store::REVIEW_APPROVED ] ?? 0 ),
			(int) ( $review_counts[ Store::REVIEW_PENDING ] ?? 0 ),
			(int) ( $review_counts[ Store::REVIEW_REJECTED ] ?? 0 ),
			(int) ( $status['missing_count'] ?? 0 ),
			(int) ( $status['stale_count'] ?? 0 ),
			(int) ( $status['translated_count'] ?? 0 )
		);
	}

	/**
	 * Plain-language completeness state.
	 *
	 * Order matters: an untranslated field always wins, because that is the
	 * "page looks done but is not" failure this feature exists to prevent.
	 */
	public function state(): string {
		if ( $this->untranslated > 0 ) {
			return self::STATE_INCOMPLETE;
		}

		if ( $this->rejected > 0 ) {
			return self::STATE_NEEDS_ATTENTION;
		}

		if ( $this->pending > 0 ) {
			return self::STATE_READY;
		}

		return self::STATE_COMPLETE;
	}

	/**
	 * True only when nothing is left to translate or review.
	 */
	public function is_fully_reviewed(): bool {
		return self::STATE_COMPLETE === $this->state() && $this->total > 0;
	}

	/**
	 * REST/serialization shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'total'             => $this->total,
			'approved'          => $this->approved,
			'pending'           => $this->pending,
			'rejected'          => $this->rejected,
			'untranslated'      => $this->untranslated,
			'stale'             => $this->stale,
			'translated'        => $this->translated,
			'state'             => $this->state(),
			'is_fully_reviewed' => $this->is_fully_reviewed(),
		);
	}
}
