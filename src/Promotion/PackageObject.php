<?php
/**
 * One translatable source object inside a promotion package (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable value object. Pure — no WordPress functions.
 *
 * `object_uuid` is always non-null in a valid M1 package: export mints and
 * persists it on the source before serialization.
 */
final class PackageObject {

	/**
	 * Builds an immutable object entry.
	 *
	 * @param string                $object_uuid        Cross-environment UUID.
	 * @param string                $source_type        post|term.
	 * @param string                $source_subtype     Post type or taxonomy.
	 * @param string                $natural_key        Deterministic natural key.
	 * @param array<string, mixed>  $natural_key_parts  Structured key parts.
	 * @param array<string, string> $source_fingerprint Advisory tiebreakers.
	 * @param PackageSegment[]      $segments           Translated segments.
	 */
	public function __construct(
		public readonly string $object_uuid,
		public readonly string $source_type,
		public readonly string $source_subtype,
		public readonly string $natural_key,
		public readonly array $natural_key_parts,
		public readonly array $source_fingerprint,
		public readonly array $segments
	) {
	}

	/**
	 * Array form for serialization; segments sorted (language_code, segment_hash).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$segments = array_map(
			static fn( PackageSegment $segment ): array => $segment->to_array(),
			$this->sorted_segments()
		);

		return array(
			'object_uuid'        => $this->object_uuid,
			'source_type'        => $this->source_type,
			'source_subtype'     => $this->source_subtype,
			'natural_key'        => $this->natural_key,
			'natural_key_parts'  => $this->natural_key_parts,
			'source_fingerprint' => $this->source_fingerprint,
			'segments'           => array_values( $segments ),
		);
	}

	/**
	 * Segments in deterministic order.
	 *
	 * @return PackageSegment[]
	 */
	public function sorted_segments(): array {
		$segments = $this->segments;
		usort(
			$segments,
			static function ( PackageSegment $a, PackageSegment $b ): int {
				return array( $a->language_code, $a->segment_hash() ) <=> array( $b->language_code, $b->segment_hash() );
			}
		);

		return array_values( $segments );
	}

	/**
	 * Rebuilds an object from its array form.
	 *
	 * @param array<string, mixed> $row Array from {@see to_array()}.
	 */
	public static function from_array( array $row ): self {
		$segments = array();
		if ( isset( $row['segments'] ) && is_array( $row['segments'] ) ) {
			foreach ( $row['segments'] as $segment_row ) {
				if ( is_array( $segment_row ) ) {
					$segments[] = PackageSegment::from_array( $segment_row );
				}
			}
		}

		$fingerprint = array();
		if ( isset( $row['source_fingerprint'] ) && is_array( $row['source_fingerprint'] ) ) {
			foreach ( $row['source_fingerprint'] as $key => $value ) {
				$fingerprint[ (string) $key ] = (string) $value;
			}
		}

		return new self(
			(string) ( $row['object_uuid'] ?? '' ),
			(string) ( $row['source_type'] ?? '' ),
			(string) ( $row['source_subtype'] ?? '' ),
			(string) ( $row['natural_key'] ?? '' ),
			isset( $row['natural_key_parts'] ) && is_array( $row['natural_key_parts'] ) ? $row['natural_key_parts'] : array(),
			$fingerprint,
			$segments
		);
	}
}
