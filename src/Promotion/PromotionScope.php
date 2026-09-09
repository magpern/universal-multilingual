<?php
/**
 * What Translation Promotion covers in milestone 1 (ADR-0030 §9).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Central list of the source subtypes and field keys milestone 1 promotes.
 * Anything outside it is reported (`unsupported_type` / `unknown_field`), never
 * silently dropped or written.
 *
 * Pure — no WordPress functions.
 */
final class PromotionScope {

	/**
	 * Post types promoted in M1.
	 */
	public const POST_SUBTYPES = array( 'post', 'page', 'product' );

	/**
	 * Taxonomies promoted in M1.
	 */
	public const TERM_SUBTYPES = array( 'category', 'post_tag', 'product_cat', 'product_tag' );

	/**
	 * Native field keys promoted in M1 (block and Rank Math meta segment keys are
	 * admitted by prefix — see {@see segment_key_supported()}).
	 */
	public const NATIVE_FIELD_KEYS = array( 'post_title', 'post_name', 'post_excerpt', 'post_content', 'name', 'description' );

	/**
	 * Segment-key namespace prefixes promoted in M1.
	 */
	public const SEGMENT_KEY_PREFIXES = array( 'b:', 'm:rank-math:' );

	/**
	 * Whether a `(source_type, source_subtype)` pair is in M1 scope.
	 *
	 * @param string $source_type    post|term.
	 * @param string $source_subtype Post type or taxonomy.
	 */
	public static function subtype_supported( string $source_type, string $source_subtype ): bool {
		if ( 'post' === $source_type ) {
			return in_array( $source_subtype, self::POST_SUBTYPES, true );
		}
		if ( 'term' === $source_type ) {
			return in_array( $source_subtype, self::TERM_SUBTYPES, true );
		}

		return false;
	}

	/**
	 * Whether a segment key is one M1 can write.
	 *
	 * @param string $field_key   Logical field.
	 * @param string $segment_key Addressable segment key.
	 */
	public static function segment_key_supported( string $field_key, string $segment_key ): bool {
		if ( in_array( $field_key, self::NATIVE_FIELD_KEYS, true ) ) {
			return true;
		}

		foreach ( self::SEGMENT_KEY_PREFIXES as $prefix ) {
			if ( str_starts_with( $segment_key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The capability descriptor written into a package payload.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function capabilities(): array {
		return array(
			'field_keys'             => self::NATIVE_FIELD_KEYS,
			'segment_key_namespaces' => array_merge( array( '' ), self::SEGMENT_KEY_PREFIXES ),
			'source_types'           => array( 'post', 'term' ),
		);
	}
}
