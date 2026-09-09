<?php
/**
 * One classified incoming segment in a dry-run plan (ADR-0030 §5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable. `preconditions` are captured during the read-only dry-run and
 * revalidated immediately before each write at apply time; a mismatch there
 * makes the row `changed_since_dry_run`.
 *
 * Pure — no WordPress functions.
 */
final class PlannedRow {

	/**
	 * Builds a planned row.
	 *
	 * @param array<string, mixed> $object_ref   {object_uuid, natural_key, source_type, source_subtype}.
	 * @param array<string, mixed> $segment_ref  {field_key, segment_key, segment_hash, language_code}.
	 * @param string               $category     One of {@see ImportCategory}.
	 * @param array<string, mixed> $resolution   {@see ResolvedObject::to_array()}.
	 * @param array<string, mixed> $incoming     {translation_hash, source_hash, norm_version}.
	 * @param array<string, mixed> $preconditions Apply-time revalidation tokens.
	 * @param string               $reason_code  Short machine reason.
	 */
	public function __construct(
		public readonly array $object_ref,
		public readonly array $segment_ref,
		public readonly string $category,
		public readonly array $resolution,
		public readonly array $incoming,
		public readonly array $preconditions,
		public readonly string $reason_code = ''
	) {
	}

	/**
	 * The stable row id used by Selective allowlists / acknowledgements.
	 */
	public function row_id(): string {
		return implode(
			'|',
			array(
				(string) ( $this->object_ref['object_uuid'] ?? '' ),
				(string) ( $this->segment_ref['segment_hash'] ?? '' ),
				(string) ( $this->segment_ref['language_code'] ?? '' ),
			)
		);
	}

	/**
	 * Array form for the plan / REST / review-token hash.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'row_id'        => $this->row_id(),
			'object_ref'    => $this->object_ref,
			'segment_ref'   => $this->segment_ref,
			'category'      => $this->category,
			'resolution'    => $this->resolution,
			'incoming'      => $this->incoming,
			'preconditions' => $this->preconditions,
			'reason_code'   => $this->reason_code,
		);
	}
}
