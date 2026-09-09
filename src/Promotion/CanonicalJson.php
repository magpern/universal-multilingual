<?php
/**
 * Deterministic JSON serialization for promotion package checksums (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Canonical JSON: associative-array keys are sorted recursively; list order is
 * preserved (the package builder is responsible for ordering every list
 * deterministically). Two structures that are equal as data serialize to
 * byte-identical strings, which is what the payload and package checksums and
 * the review-token plan hash depend on.
 *
 * Pure: no WordPress functions.
 */
final class CanonicalJson {

	/**
	 * Produces the canonical JSON string for a value.
	 *
	 * @param mixed $value Scalar, list or associative array.
	 */
	public static function encode( $value ): string {
		return (string) wp_json_encode( self::normalize( $value ), JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Produces the sha256 hex digest of the canonical form.
	 *
	 * @param mixed $value Value to hash.
	 */
	public static function sha256( $value ): string {
		return hash( 'sha256', self::encode( $value ) );
	}

	/**
	 * Recursively sorts associative-array keys; leaves lists untouched.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_list( $value ) ) {
			return array_map( array( self::class, 'normalize' ), $value );
		}

		ksort( $value );
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::normalize( $item );
		}

		return $out;
	}

	/**
	 * Whether an array is a sequential list (PHP < 8.1 compatible check kept
	 * explicit for clarity even though 8.1 is the minimum).
	 *
	 * @param array<mixed> $value Array.
	 */
	private static function is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}

		$i = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}

		return true;
	}
}
