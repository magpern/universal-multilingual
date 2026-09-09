<?php
/**
 * Shared AI-write eligibility policy (AIT1 / ADR-0031).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

use AIMultilingual\Translation\Store;

/**
 * One place that decides whether an AI translation action may write a segment.
 *
 * Both the synchronous workspace path (BatchOperationCoordinator) and the
 * background Jobs path (materialisation in BackgroundTranslationJobService and
 * the per-item overwrite gate in BackgroundTranslationItemProcessor) consult
 * this policy so their protection rules can never drift apart.
 *
 * ABSOLUTE RULE (ADR-0031): a page-level or bulk AI action never overwrites a
 * manually edited, reviewed, or in-review translation, in any mode. There is no
 * override.
 */
final class TranslatableSegmentEligibility {

	/**
	 * Only untranslated / empty segments.
	 */
	public const MODE_MISSING = 'missing';

	/**
	 * Only stale machine translations.
	 */
	public const MODE_STALE = 'stale';

	/**
	 * All machine translations regardless of stale state.
	 */
	public const MODE_MACHINE = 'machine';

	/**
	 * Review states that mean "a human owns this segment right now".
	 */
	private const ACTIVE_REVIEW_STATES = array(
		Store::REVIEW_PENDING,
		Store::REVIEW_APPROVED,
		Store::REVIEW_REJECTED,
	);

	/**
	 * Editorial statuses an AI action must never overwrite.
	 */
	private const PROTECTED_STATUSES = array(
		Store::STATUS_MANUALLY_EDITED,
		Store::STATUS_REVIEWED,
	);

	/**
	 * All supported modes.
	 *
	 * @return list<string>
	 */
	public static function modes(): array {
		return array( self::MODE_MISSING, self::MODE_STALE, self::MODE_MACHINE );
	}

	/**
	 * Whether an AI translation action in the given mode may write this segment.
	 *
	 * Accepts either an assembled segment DTO (SegmentAssembler) or a plain array
	 * projection of a Store row. Missing keys are treated as the safe default
	 * (untranslated, not stale, not in review).
	 *
	 * @param array<string, mixed> $segment Segment DTO / row projection.
	 * @param string               $mode    One of the MODE_* constants.
	 */
	public static function is_ai_writable( array $segment, string $mode ): bool {
		if ( self::is_protected( $segment ) ) {
			return false;
		}

		$status  = (string) ( $segment['status'] ?? Store::STATUS_MISSING );
		$text    = trim( (string) ( $segment['translated_text'] ?? '' ) );
		$machine = Store::STATUS_MACHINE_TRANSLATED === $status;

		switch ( $mode ) {
			case self::MODE_MISSING:
				return Store::STATUS_MISSING === $status || '' === $text;

			case self::MODE_STALE:
				return $machine && ! empty( $segment['is_stale'] );

			case self::MODE_MACHINE:
				// Every existing machine translation, stale or not — but not
				// untranslated segments (those are "Translate missing").
				return $machine && '' !== $text;

			default:
				return false;
		}
	}

	/**
	 * Whether a human owns this segment (manual edit, review outcome, or an open
	 * review submission). Such segments are never AI-writable, in any mode.
	 *
	 * @param array<string, mixed> $segment Segment DTO / row projection.
	 */
	public static function is_protected( array $segment ): bool {
		$review_status = (string) ( $segment['review_status'] ?? Store::REVIEW_NOT_SUBMITTED );
		if ( in_array( $review_status, self::ACTIVE_REVIEW_STATES, true ) ) {
			return true;
		}

		$status = (string) ( $segment['status'] ?? Store::STATUS_MISSING );

		return in_array( $status, self::PROTECTED_STATUSES, true );
	}

	/**
	 * Human-readable skip reason for a protected segment (bounded, for item rows
	 * and batch result summaries).
	 *
	 * @param array<string, mixed> $segment Segment DTO / row projection.
	 */
	public static function protected_reason( array $segment ): string {
		$review_status = (string) ( $segment['review_status'] ?? Store::REVIEW_NOT_SUBMITTED );
		if ( in_array( $review_status, self::ACTIVE_REVIEW_STATES, true ) ) {
			return 'Segment is in an active review state.';
		}

		return 'Segment was manually edited or reviewed.';
	}
}
