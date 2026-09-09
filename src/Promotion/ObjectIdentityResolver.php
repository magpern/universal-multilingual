<?php
/**
 * Resolves a package object to a local WordPress object (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

use AIMultilingual\Database\ObjectIdentityRepository;
use WP_Term;

/**
 * Strictly read-only. Never inserts or mutates `aiml_object_identity` — it only
 * proposes an `identity_action` for the apply engine to carry out later.
 *
 * Resolution order (ADR-0030 §1):
 *
 * 1. **UUID pass — authoritative once the relationship exists.** The incoming
 *    `object_uuid` is always present. If a local identity row owns it, that
 *    mapping wins, even when the incoming natural key now resolves elsewhere
 *    (recorded as an advisory note). `identity_conflict` only when the mapped
 *    local source no longer exists or its type is incompatible.
 * 2. **Natural-key pass — bootstrap only.** Resolve the natural key against
 *    live WordPress. Exactly one hit whose local identity row (if any) does not
 *    already carry a different UUID ⇒ `adopt_incoming_uuid_on_apply`. A hit
 *    already joined to a different UUID ⇒ `identity_conflict`. More than one ⇒
 *    `ambiguous_source`.
 * 3. **Fingerprint fallback — advisory.** One low-confidence suggestion
 *    (product SKU). Usable only by explicit operator acceptance in Selective
 *    mode.
 * 4. No match ⇒ `missing_source`.
 *
 * WordPress reads go through core functions; the identity table is read through
 * {@see ObjectIdentityRepository}.
 */
final class ObjectIdentityResolver {

	/**
	 * Builds the resolver.
	 *
	 * @param ObjectIdentityRepository $identities Identity table repository.
	 */
	public function __construct(
		private readonly ObjectIdentityRepository $identities
	) {
	}

	/**
	 * Resolves one package object.
	 *
	 * @param PackageObject $package_object Package object.
	 */
	public function resolve( PackageObject $package_object ): ResolvedObject {
		if ( ! PromotionScope::subtype_supported( $package_object->source_type, $package_object->source_subtype ) ) {
			return ResolvedObject::blocked(
				'unsupported_type',
				array( sprintf( '%s "%s" is not promoted in this milestone.', $package_object->source_type, $package_object->source_subtype ) )
			);
		}

		$owned = $this->identities->find_by_uuid( $package_object->object_uuid );
		if ( null !== $owned ) {
			return $this->resolve_by_uuid( $package_object, $owned );
		}

		$candidates = $this->locate( $package_object );
		if ( count( $candidates ) > 1 ) {
			return ResolvedObject::blocked(
				'ambiguous_source',
				array( 'The natural key resolves to more than one local object; a manual mapping is needed.' )
			);
		}

		if ( 1 === count( $candidates ) ) {
			return $this->bootstrap( $package_object, $candidates[0] );
		}

		$suggestion = $this->locate_by_fingerprint( $package_object );
		if ( null !== $suggestion ) {
			return new ResolvedObject(
				$suggestion,
				ResolvedObject::MATCH_FINGERPRINT,
				ResolvedObject::ACTION_ADOPT_INCOMING,
				ResolvedObject::CONFIDENCE_LOW,
				null,
				array( 'Low-confidence fingerprint match (SKU); accept explicitly in Selective mode.' )
			);
		}

		return ResolvedObject::blocked(
			'missing_source',
			array( 'No local object matches the natural key or fingerprint.' )
		);
	}

	/**
	 * UUID pass — the mapping is authoritative unless it is broken.
	 *
	 * @param PackageObject $package_object Package object.
	 * @param object        $owned  Local identity row owning the UUID.
	 */
	private function resolve_by_uuid( PackageObject $package_object, object $owned ): ResolvedObject {
		$local_id = (int) $owned->source_id;

		if ( ! $this->object_exists( $package_object->source_type, $local_id )
			|| ! $this->subtype_compatible( $package_object, $local_id ) ) {
			return ResolvedObject::blocked(
				'identity_conflict',
				array( 'The object this UUID maps to no longer exists or has an incompatible type.' )
			);
		}

		$notes  = array();
		$by_key = $this->locate( $package_object );
		if ( array() !== $by_key && ! in_array( $local_id, $by_key, true ) ) {
			$notes[] = 'Natural-key divergence: the incoming natural key now resolves to a different local object. The established UUID mapping is kept.';
		}

		return ResolvedObject::matched( $local_id, ResolvedObject::MATCH_UUID, ResolvedObject::ACTION_NONE, $notes );
	}

	/**
	 * Natural-key bootstrap for a single candidate.
	 *
	 * @param PackageObject $package_object    Package object.
	 * @param int           $local_id  The one local candidate.
	 */
	private function bootstrap( PackageObject $package_object, int $local_id ): ResolvedObject {
		$existing = $this->identities->find_by_source( $package_object->source_type, $local_id );

		if ( null !== $existing && (string) $existing->uuid !== $package_object->object_uuid ) {
			return ResolvedObject::blocked(
				'identity_conflict',
				array( 'The matched local object is already joined to a different package lineage.' )
			);
		}

		return ResolvedObject::matched(
			$local_id,
			ResolvedObject::MATCH_NATURAL_KEY,
			ResolvedObject::ACTION_ADOPT_INCOMING
		);
	}

	/**
	 * Local object ids matching the deterministic natural key.
	 *
	 * @param PackageObject $package_object Package object.
	 * @return array<int, int>
	 */
	private function locate( PackageObject $package_object ): array {
		$parts     = $package_object->natural_key_parts;
		$type      = (string) ( $parts['type'] ?? '' );
		$slug      = (string) ( $parts['slug'] ?? '' );
		$ancestors = array_values( array_map( 'strval', (array) ( $parts['ancestor_slugs'] ?? array() ) ) );

		if ( '' === $type || '' === $slug ) {
			return array();
		}

		return 'post' === $package_object->source_type
			? $this->locate_post( $type, $slug, $ancestors )
			: $this->locate_term( $type, $slug, $ancestors );
	}

	/**
	 * Posts matching a type + slug (+ ancestor path for hierarchical types).
	 *
	 * @param string            $post_type  Post type.
	 * @param string            $slug       post_name.
	 * @param array<int,string> $ancestors Ancestor slug chain, root-first.
	 * @return array<int, int>
	 */
	private function locate_post( string $post_type, string $slug, array $ancestors ): array {
		if ( array() !== $ancestors ) {
			$path = implode( '/', $ancestors ) . '/' . $slug;
			$page = get_page_by_path( $path, OBJECT, $post_type );

			return $page instanceof \WP_Post ? array( (int) $page->ID ) : array();
		}

		$ids = get_posts(
			array(
				'name'                   => $slug,
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'numberposts'            => 5,
				'fields'                 => 'ids',
				'suppress_filters'       => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Terms matching a taxonomy + slug (+ ancestor slug chain).
	 *
	 * @param string            $taxonomy  Taxonomy.
	 * @param string            $slug      Term slug.
	 * @param array<int,string> $ancestors Ancestor slug chain, root-first.
	 * @return array<int, int>
	 */
	private function locate_term( string $taxonomy, string $slug, array $ancestors ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slug,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$matched = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			if ( $this->term_ancestor_slugs_match( $term, $ancestors ) ) {
				$matched[] = (int) $term->term_id;
			}
		}

		return $matched;
	}

	/**
	 * Whether a term's ancestor slug chain equals the expected one.
	 *
	 * @param WP_Term           $term      Term.
	 * @param array<int,string> $expected  Expected ancestor slugs, root-first.
	 */
	private function term_ancestor_slugs_match( WP_Term $term, array $expected ): bool {
		$chain     = array();
		$parent_id = (int) $term->parent;
		$guard     = 0;
		while ( $parent_id > 0 && $guard < 50 ) {
			$parent = get_term( $parent_id, $term->taxonomy );
			if ( ! $parent instanceof WP_Term ) {
				break;
			}
			array_unshift( $chain, $parent->slug );
			$parent_id = (int) $parent->parent;
			++$guard;
		}

		return array_values( $expected ) === $chain;
	}

	/**
	 * Advisory fingerprint suggestion — product SKU only.
	 *
	 * @param PackageObject $package_object Package object.
	 */
	private function locate_by_fingerprint( PackageObject $package_object ): ?int {
		if ( 'post' !== $package_object->source_type || 'product' !== $package_object->source_subtype ) {
			return null;
		}

		$sku = (string) ( $package_object->source_fingerprint['sku_hint'] ?? '' );
		if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return null;
		}

		$id = (int) wc_get_product_id_by_sku( $sku );

		return $id > 0 ? $id : null;
	}

	/**
	 * Whether a local object still exists.
	 *
	 * @param string $source_type post|term.
	 * @param int    $local_id    Local id.
	 */
	private function object_exists( string $source_type, int $local_id ): bool {
		if ( 'post' === $source_type ) {
			return false !== get_post_status( $local_id );
		}

		$term = get_term( $local_id );

		return $term instanceof WP_Term;
	}

	/**
	 * Whether a local object's subtype matches the package object's.
	 *
	 * @param PackageObject $package_object   Package object.
	 * @param int           $local_id Local id.
	 */
	private function subtype_compatible( PackageObject $package_object, int $local_id ): bool {
		$expected = (string) ( $package_object->natural_key_parts['type'] ?? $package_object->source_subtype );

		if ( 'post' === $package_object->source_type ) {
			return get_post_type( $local_id ) === $expected;
		}

		$term = get_term( $local_id );

		return $term instanceof WP_Term && $term->taxonomy === $expected;
	}
}
