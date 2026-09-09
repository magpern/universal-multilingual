<?php
/**
 * Import apply modes (ADR-0030 §5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * There is deliberately no `skip_conflicts` mode. Pure — no WordPress functions.
 */
final class ApplyMode {

	public const SAFE_ONLY = 'safe_only';
	public const SELECTIVE = 'selective';
	public const FORCE     = 'force';

	/**
	 * Every valid mode.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::SAFE_ONLY, self::SELECTIVE, self::FORCE );
	}

	/**
	 * Normalizes a loose mode string, defaulting to the safest.
	 *
	 * @param string $mode Raw mode.
	 */
	public static function normalize( string $mode ): string {
		$mode = strtolower( trim( $mode ) );

		return in_array( $mode, self::all(), true ) ? $mode : self::SAFE_ONLY;
	}
}
