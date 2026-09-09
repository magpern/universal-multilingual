<?php
/**
 * Background translation job type constants.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Jobs;

/**
 * MVP job types (plan §5).
 */
final class JobTypes {

	public const TRANSLATE_SELECTED  = 'translate_selected';
	public const TRANSLATE_MISSING   = 'translate_missing';
	public const RETRANSLATE_STALE   = 'retranslate_stale';
	public const RETRANSLATE_MACHINE = 'retranslate_machine';
	public const BULK_TRANSLATE      = 'bulk_translate';

	/**
	 * All job type codes.
	 *
	 * `retranslate_machine` (AIT1 / ADR-0031) replaces every eligible
	 * machine translation for an object regardless of stale state; manual,
	 * reviewed and in-review segments are still never touched.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::TRANSLATE_SELECTED,
			self::TRANSLATE_MISSING,
			self::RETRANSLATE_STALE,
			self::RETRANSLATE_MACHINE,
			self::BULK_TRANSLATE,
		);
	}

	/**
	 * Whether the job type may retranslate existing machine_translated segments.
	 *
	 * @param string $job_type Job type code.
	 */
	public static function allows_retranslate( string $job_type ): bool {
		return in_array(
			$job_type,
			array(
				self::TRANSLATE_SELECTED,
				self::RETRANSLATE_STALE,
				self::RETRANSLATE_MACHINE,
			),
			true
		);
	}

	/**
	 * Whether segment_keys must be supplied explicitly at create time.
	 *
	 * @param string $job_type Job type code.
	 */
	public static function requires_explicit_segments( string $job_type ): bool {
		return in_array( $job_type, self::all(), true );
	}
}
