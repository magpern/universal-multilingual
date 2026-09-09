<?php
/**
 * Deterministic, environment-independent object natural key (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Builds the natural key used to bootstrap the first cross-environment match.
 *
 * One fixed algorithm per object type, derived **only** from the source
 * object's own canonical characteristics — never from what else exists in the
 * local database. The key is compared through its sha256 and is never mutated
 * per install to disambiguate: an ambiguous resolution is reported, not papered
 * over.
 *
 * Slugs are the raw `post_name` / term `slug` (already lowercased and URL-safe
 * by WordPress), never full permalink paths, so a different rewrite base or
 * permalink structure on the far side does not change the key.
 *
 * Pure: no WordPress functions, safe to unit-test without a bootstrap.
 */
final class ObjectNaturalKey {

	public const KIND_POST = 'post';
	public const KIND_TERM = 'term';

	/**
	 * Natural key for a post-type object (post, page, product, …).
	 *
	 * @param string   $post_type      Post type slug.
	 * @param string   $slug           Raw post_name.
	 * @param string[] $ancestor_slugs Ancestor post_name chain, root-first.
	 * @param bool     $hierarchical   Whether the type is hierarchical.
	 */
	public static function for_post( string $post_type, string $slug, array $ancestor_slugs = array(), bool $hierarchical = false ): string {
		$post_type = self::segment( $post_type );
		$slug      = self::segment( $slug );

		if ( $hierarchical && array() !== $ancestor_slugs ) {
			return 'post|type=' . $post_type . '|ancestors=' . self::chain( $ancestor_slugs ) . '|slug=' . $slug;
		}

		return 'post|type=' . $post_type . '|slug=' . $slug;
	}

	/**
	 * Natural key for a taxonomy term.
	 *
	 * @param string   $taxonomy       Taxonomy slug.
	 * @param string   $slug           Raw term slug.
	 * @param string[] $ancestor_slugs Ancestor term-slug chain, root-first.
	 */
	public static function for_term( string $taxonomy, string $slug, array $ancestor_slugs = array() ): string {
		$taxonomy = self::segment( $taxonomy );
		$slug     = self::segment( $slug );

		if ( array() !== $ancestor_slugs ) {
			return 'term|taxonomy=' . $taxonomy . '|ancestors=' . self::chain( $ancestor_slugs ) . '|slug=' . $slug;
		}

		return 'term|taxonomy=' . $taxonomy . '|slug=' . $slug;
	}

	/**
	 * Structured parts of a key, for audit / display (`natural_key_parts`).
	 *
	 * @param string   $kind           post|term.
	 * @param string   $type_or_tax    Post type or taxonomy.
	 * @param string   $slug           Raw slug.
	 * @param string[] $ancestor_slugs Ancestor chain.
	 * @return array{kind:string,type:string,slug:string,ancestor_slugs:list<string>}
	 */
	public static function parts( string $kind, string $type_or_tax, string $slug, array $ancestor_slugs = array() ): array {
		return array(
			'kind'           => $kind,
			'type'           => self::segment( $type_or_tax ),
			'slug'           => self::segment( $slug ),
			'ancestor_slugs' => array_values( array_map( array( self::class, 'segment' ), $ancestor_slugs ) ),
		);
	}

	/**
	 * Joins an ancestor slug chain with `/`.
	 *
	 * @param string[] $slugs Ancestor slugs, root-first.
	 */
	private static function chain( array $slugs ): string {
		return implode( '/', array_map( array( self::class, 'segment' ), $slugs ) );
	}

	/**
	 * Normalizes one path segment: trim, lowercase, collapse inner whitespace.
	 *
	 * WordPress already produces clean slugs; this only guards against stray
	 * casing / whitespace so the same object yields the same key on both sides.
	 *
	 * @param string $value Raw segment.
	 */
	private static function segment( string $value ): string {
		$value = strtolower( trim( $value ) );

		return (string) preg_replace( '/\s+/', ' ', $value );
	}
}
