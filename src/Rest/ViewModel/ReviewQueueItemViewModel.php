<?php
/**
 * REST presentation contract for one review-queue row (ADR-0015).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Rest\ViewModel;

/**
 * Review queue item ViewModel v1. Never the raw Store row.
 */
final class ReviewQueueItemViewModel {

	/**
	 * Builds the ViewModel.
	 *
	 * @param string      $source_type                Source type.
	 * @param int         $post_id                     Source object id.
	 * @param int         $language_id                 Target language id.
	 * @param string      $segment_key                 Segment key.
	 * @param string      $field_key                   Field key.
	 * @param string      $source_text                 Canonical source text.
	 * @param string      $translated_text             Target text.
	 * @param string      $status                      Translation-axis status.
	 * @param string      $review_status               Review-axis status.
	 * @param string      $submitted_translation_hash  Hash captured at last submit.
	 * @param int|null    $review_submitted_by         Submitting user id.
	 * @param string|null $review_submitted_at         Submission timestamp.
	 * @param int|null    $reviewed_by                 Approving reviewer id.
	 * @param string|null $reviewed_at                 Approval timestamp.
	 * @param string      $rejection_reason            Active rejection reason.
	 * @param int|null    $rejected_by                 Rejecting reviewer id.
	 * @param string|null $rejected_at                 Rejection timestamp.
	 * @param int         $translation_id              Store translation PK (OTL.6 additive).
	 * @param string      $field_label                 Reviewer-friendly field label (RVQ1).
	 * @param string      $post_title                  Canonical object title (RVQ1).
	 * @param string      $post_type                   Canonical object post type (RVQ1).
	 */
	public function __construct(
		public readonly string $source_type,
		public readonly int $post_id,
		public readonly int $language_id,
		public readonly string $segment_key,
		public readonly string $field_key,
		public readonly string $source_text,
		public readonly string $translated_text,
		public readonly string $status,
		public readonly string $review_status,
		public readonly string $submitted_translation_hash,
		public readonly ?int $review_submitted_by,
		public readonly ?string $review_submitted_at,
		public readonly ?int $reviewed_by,
		public readonly ?string $reviewed_at,
		public readonly string $rejection_reason,
		public readonly ?int $rejected_by,
		public readonly ?string $rejected_at,
		public readonly int $translation_id = 0,
		public readonly string $field_label = '',
		public readonly string $post_title = '',
		public readonly string $post_type = ''
	) {
	}

	/**
	 * Serializes the ViewModel to REST v1 JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'source_type'                => $this->source_type,
			'post_id'                    => $this->post_id,
			'language_id'                => $this->language_id,
			'segment_key'                => $this->segment_key,
			'field_key'                  => $this->field_key,
			'source_text'                => $this->source_text,
			'translated_text'            => $this->translated_text,
			'status'                     => $this->status,
			'review_status'              => $this->review_status,
			'submitted_translation_hash' => $this->submitted_translation_hash,
			'review_submitted_by'        => $this->review_submitted_by,
			'review_submitted_at'        => $this->review_submitted_at,
			'reviewed_by'                => $this->reviewed_by,
			'reviewed_at'                => $this->reviewed_at,
			'rejection_reason'           => $this->rejection_reason,
			'rejected_by'                => $this->rejected_by,
			'rejected_at'                => $this->rejected_at,
			'translation_id'             => $this->translation_id,
			'field_label'                => $this->field_label,
			'post_title'                 => $this->post_title,
			'post_type'                  => $this->post_type,
		);
	}
}
