<?php
/**
 * The categories a dry-run assigns to each incoming segment (ADR-0030 §3/§5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Pure — no WordPress functions.
 */
final class ImportCategory {

	public const NEW_TRANSLATION       = 'new';
	public const UPDATE                = 'update';
	public const UNCHANGED             = 'unchanged';
	public const CONFLICT_TARGET       = 'conflict_target_modified';
	public const CONFLICT_BOTH         = 'conflict_both_changed';
	public const STALE_SOURCE          = 'stale_source';
	public const MISSING_SOURCE        = 'missing_source';
	public const MISSING_LANGUAGE      = 'missing_language';
	public const UNSUPPORTED_TYPE      = 'unsupported_type';
	public const UNKNOWN_FIELD         = 'unknown_field';
	public const ROUTE_CONFLICT        = 'route_conflict';
	public const IDENTITY_CONFLICT     = 'identity_conflict';
	public const AMBIGUOUS_SOURCE      = 'ambiguous_source';
	public const VALIDATION_FAILURE    = 'validation_failure';
	public const CHANGED_SINCE_DRY_RUN = 'changed_since_dry_run';

	/**
	 * Every category, in report order.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::NEW_TRANSLATION,
			self::UPDATE,
			self::UNCHANGED,
			self::CONFLICT_TARGET,
			self::CONFLICT_BOTH,
			self::STALE_SOURCE,
			self::MISSING_SOURCE,
			self::MISSING_LANGUAGE,
			self::UNSUPPORTED_TYPE,
			self::UNKNOWN_FIELD,
			self::ROUTE_CONFLICT,
			self::IDENTITY_CONFLICT,
			self::AMBIGUOUS_SOURCE,
			self::VALIDATION_FAILURE,
			self::CHANGED_SINCE_DRY_RUN,
		);
	}

	/**
	 * Categories that a safe-only apply may write.
	 *
	 * @return array<int, string>
	 */
	public static function safe(): array {
		return array( self::NEW_TRANSLATION, self::UPDATE );
	}

	/**
	 * Categories that need an explicit per-row acknowledgement in Selective mode.
	 *
	 * @return array<int, string>
	 */
	public static function acknowledgeable(): array {
		return array( self::STALE_SOURCE, self::CONFLICT_TARGET, self::ROUTE_CONFLICT );
	}

	/**
	 * Categories a force apply may write (still never identity/ambiguous).
	 *
	 * @return array<int, string>
	 */
	public static function forceable(): array {
		return array( self::NEW_TRANSLATION, self::UPDATE, self::STALE_SOURCE, self::CONFLICT_TARGET, self::CONFLICT_BOTH, self::ROUTE_CONFLICT );
	}

	/**
	 * Categories that can never be applied under any mode.
	 *
	 * @return array<int, string>
	 */
	public static function never_applicable(): array {
		return array(
			self::UNCHANGED,
			self::MISSING_SOURCE,
			self::MISSING_LANGUAGE,
			self::UNSUPPORTED_TYPE,
			self::UNKNOWN_FIELD,
			self::IDENTITY_CONFLICT,
			self::AMBIGUOUS_SOURCE,
			self::VALIDATION_FAILURE,
			self::CHANGED_SINCE_DRY_RUN,
		);
	}
}
