<?php
/**
 * The read-only outcome of a dry-run (ADR-0030 §5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable aggregate of {@see PlannedRow}s plus counts and a per-object rollup.
 * The canonical serialization of a plan is what the review token binds to.
 *
 * Pure — no WordPress functions beyond the `wp_json_encode` shim (via
 * {@see CanonicalJson}).
 */
final class ImportPlan {

	/**
	 * Builds a plan.
	 *
	 * @param string       $package_id       Package id.
	 * @param string       $package_checksum Whole-artifact checksum.
	 * @param PlannedRow[] $rows             Classified rows.
	 */
	public function __construct(
		private readonly string $package_id,
		private readonly string $package_checksum,
		private readonly array $rows
	) {
	}

	/**
	 * The package id.
	 */
	public function package_id(): string {
		return $this->package_id;
	}

	/**
	 * The whole-artifact checksum.
	 */
	public function package_checksum(): string {
		return $this->package_checksum;
	}

	/**
	 * The classified rows.
	 *
	 * @return PlannedRow[]
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * Row counts keyed by category.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array_fill_keys( ImportCategory::all(), 0 );
		foreach ( $this->rows as $row ) {
			$counts[ $row->category ] = ( $counts[ $row->category ] ?? 0 ) + 1;
		}

		return array_filter( $counts, static fn( int $n ): bool => $n > 0 );
	}

	/**
	 * A deterministic hash of the plan — categories + per-row preconditions.
	 *
	 * This is what the review token signs and what apply re-verifies. Row order
	 * is normalized so a re-plan produces the same hash for the same state.
	 */
	public function canonical_hash(): string {
		$rows = array_map(
			static fn( PlannedRow $row ): array => array(
				'row_id'        => $row->row_id(),
				'category'      => $row->category,
				'preconditions' => $row->preconditions,
			),
			$this->rows
		);
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['row_id'], $b['row_id'] ) );

		return CanonicalJson::sha256(
			array(
				'package_id'       => $this->package_id,
				'package_checksum' => $this->package_checksum,
				'rows'             => $rows,
			)
		);
	}

	/**
	 * Array form for REST / the UI.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'package_id'       => $this->package_id,
			'package_checksum' => $this->package_checksum,
			'counts'           => $this->counts(),
			'canonical_hash'   => $this->canonical_hash(),
			'rows'             => array_map( static fn( PlannedRow $row ): array => $row->to_array(), $this->rows ),
		);
	}
}
