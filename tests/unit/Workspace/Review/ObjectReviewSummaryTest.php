<?php
/**
 * ObjectReviewSummary unit tests (RVQ1 / WP1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Workspace\Review;

use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\Review\ObjectReviewSummary;
use PHPUnit\Framework\TestCase;

/**
 * Completeness state composed from the two existing read models.
 */
final class ObjectReviewSummaryTest extends TestCase {

	/**
	 * @param array<string, int> $review Review-status counts overrides.
	 * @param array<string, int> $status Status-calculator overrides.
	 */
	private function summary( array $review, array $status ): ObjectReviewSummary {
		return ObjectReviewSummary::compose(
			array_merge(
				array(
					Store::REVIEW_NOT_SUBMITTED => 0,
					Store::REVIEW_PENDING       => 0,
					Store::REVIEW_APPROVED      => 0,
					Store::REVIEW_REJECTED      => 0,
				),
				$review
			),
			array_merge(
				array(
					'total_segments'   => 0,
					'missing_count'    => 0,
					'stale_count'      => 0,
					'translated_count' => 0,
				),
				$status
			)
		);
	}

	public function test_incomplete_when_a_field_is_untranslated(): void {
		$summary = $this->summary(
			array( Store::REVIEW_PENDING => 5 ),
			array(
				'total_segments'   => 10,
				'missing_count'    => 3,
				'translated_count' => 7,
			)
		);
		$this->assertSame( ObjectReviewSummary::STATE_INCOMPLETE, $summary->state() );
		$this->assertFalse( $summary->is_fully_reviewed() );
		$this->assertSame( 3, $summary->to_array()['untranslated'] );
	}

	public function test_ready_when_pending_and_nothing_missing(): void {
		$summary = $this->summary(
			array(
				Store::REVIEW_PENDING  => 4,
				Store::REVIEW_APPROVED => 6,
			),
			array(
				'total_segments'   => 10,
				'translated_count' => 10,
			)
		);
		$this->assertSame( ObjectReviewSummary::STATE_READY, $summary->state() );
	}

	public function test_needs_attention_when_a_field_is_rejected(): void {
		$summary = $this->summary(
			array(
				Store::REVIEW_REJECTED => 1,
				Store::REVIEW_APPROVED => 9,
			),
			array(
				'total_segments'   => 10,
				'translated_count' => 10,
			)
		);
		$this->assertSame( ObjectReviewSummary::STATE_NEEDS_ATTENTION, $summary->state() );
	}

	public function test_complete_only_when_nothing_pending_missing_or_rejected(): void {
		$summary = $this->summary(
			array( Store::REVIEW_APPROVED => 10 ),
			array(
				'total_segments'   => 10,
				'translated_count' => 10,
			)
		);
		$this->assertSame( ObjectReviewSummary::STATE_COMPLETE, $summary->state() );
		$this->assertTrue( $summary->is_fully_reviewed() );
	}

	public function test_empty_object_is_not_fully_reviewed(): void {
		$summary = $this->summary( array(), array() );
		$this->assertSame( ObjectReviewSummary::STATE_COMPLETE, $summary->state() );
		$this->assertFalse( $summary->is_fully_reviewed() );
	}
}
