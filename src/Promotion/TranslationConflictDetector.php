<?php
/**
 * Three-way merge classification for one incoming segment (ADR-0030 §3/§4).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Given the incoming segment, the current local translation row (or null), the
 * current local source hash for that field, and the last-promoted baseline
 * (`aiml_promotion_state`, or null), decides the category.
 *
 * Pure — no WordPress functions. All inputs are primitives / plain objects, so
 * the truth table is unit-testable without a bootstrap.
 */
final class TranslationConflictDetector {

	/**
	 * Classifies one incoming segment for a matched, language-resolved object.
	 *
	 * @param PackageSegment $incoming             The package segment.
	 * @param object|null    $local_row            Current local aiml_translations row, or null.
	 * @param string|null    $local_source_hash    Current local source hash for this field, or null when unknown.
	 * @param string|null    $last_promoted_hash   `aiml_promotion_state.last_promoted_translation_hash`, or null.
	 * @return array{category:string,reason:string}
	 */
	public function classify( PackageSegment $incoming, ?object $local_row, ?string $local_source_hash, ?string $last_promoted_hash ): array {
		$t_exists = null !== $local_row;

		// S_match: does the package's source match what this environment now has?
		// When there is no local row and no known local source hash, a brand-new
		// translation cannot be "stale" against anything — treat as matching.
		$s_match = true;
		if ( null !== $local_source_hash && '' !== $local_source_hash ) {
			$s_match = hash_equals( $local_source_hash, $incoming->source_hash );
		} elseif ( $t_exists ) {
			$s_match = hash_equals( (string) ( $local_row->source_hash ?? '' ), $incoming->source_hash );
		}

		$local_translation_hash = $t_exists ? (string) ( $local_row->translation_hash ?? '' ) : '';
		$translation_identical  = $t_exists && hash_equals( $local_translation_hash, $incoming->translation_hash );

		// An identical translation is always a no-op, whatever the baseline says.
		if ( $translation_identical && $s_match ) {
			return $this->row( ImportCategory::UNCHANGED, 'identical translation already present' );
		}

		// Divergence only matters when the two translations actually differ.
		$target_diverged = $t_exists && ! $translation_identical && (
			null === $last_promoted_hash
				|| ! hash_equals( $last_promoted_hash, $local_translation_hash )
		);

		if ( $s_match ) {
			if ( ! $t_exists ) {
				return $this->row( ImportCategory::NEW_TRANSLATION, 'no local translation for this segment' );
			}
			if ( ! $target_diverged ) {
				return $this->row( ImportCategory::UPDATE, 'source unchanged, translation differs' );
			}

			return $this->row( ImportCategory::CONFLICT_TARGET, 'the local translation was edited independently since the last promotion' );
		}

		if ( ! $t_exists || ! $target_diverged ) {
			return $this->row( ImportCategory::STALE_SOURCE, 'the package was translated against a source this environment no longer has' );
		}

		return $this->row( ImportCategory::CONFLICT_BOTH, 'both the source and the local translation changed since the last promotion' );
	}

	/**
	 * Builds a classification verdict.
	 *
	 * @param string $category Category.
	 * @param string $reason   Human reason.
	 * @return array{category:string,reason:string}
	 */
	private function row( string $category, string $reason ): array {
		return array(
			'category' => $category,
			'reason'   => $reason,
		);
	}
}
