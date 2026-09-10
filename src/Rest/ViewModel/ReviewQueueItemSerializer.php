<?php
/**
 * Maps Store review rows to REST ViewModels.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Rest\ViewModel;

/**
 * Serializer for review-queue rows.
 */
final class ReviewQueueItemSerializer {

	/**
	 * Maps one hydrated Store row to a ViewModel.
	 *
	 * @param object $row Store row (from Store::query_review_queue()).
	 */
	public function from_row( object $row ): ReviewQueueItemViewModel {
		$review_submitted_by = $row->review_submitted_by ?? null;
		$review_submitted_at = $row->review_submitted_at ?? null;
		$reviewed_by         = $row->reviewed_by ?? null;
		$reviewed_at         = $row->reviewed_at ?? null;
		$rejected_by         = $row->rejected_by ?? null;
		$rejected_at         = $row->rejected_at ?? null;

		return new ReviewQueueItemViewModel(
			(string) ( $row->source_type ?? '' ),
			(int) ( $row->source_id ?? 0 ),
			(int) ( $row->language_id ?? 0 ),
			(string) ( $row->segment_key ?? '' ),
			(string) ( $row->field_key ?? '' ),
			(string) ( $row->source_text ?? '' ),
			(string) ( $row->translated_text ?? '' ),
			(string) ( $row->status ?? '' ),
			(string) ( $row->review_status ?? 'not_submitted' ),
			(string) ( $row->submitted_translation_hash ?? '' ),
			null === $review_submitted_by ? null : (int) $review_submitted_by,
			null === $review_submitted_at ? null : (string) $review_submitted_at,
			null === $reviewed_by ? null : (int) $reviewed_by,
			null === $reviewed_at ? null : (string) $reviewed_at,
			(string) ( $row->rejection_reason ?? '' ),
			null === $rejected_by ? null : (int) $rejected_by,
			null === $rejected_at ? null : (string) $rejected_at,
			(int) ( $row->translation_id ?? 0 ),
			(string) ( $row->field_label ?? '' ),
			(string) ( $row->post_title ?? '' ),
			(string) ( $row->post_type ?? '' )
		);
	}

	/**
	 * Maps many rows to arrays.
	 *
	 * @param array<int, object> $rows Store rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function many_to_arrays( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $this->from_row( $row )->to_array();
		}

		return $out;
	}
}
