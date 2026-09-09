<?php
/**
 * Builds a DEV → PROD translation package from the local site (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Language\Languages;
use AIMultilingual\Translation\Store;
use WP_Post;
use WP_Term;

/**
 * Walks the M1-scoped objects (post / page / product and the admitted
 * taxonomies), pulls their translated segments from {@see Store}, mints a
 * plugin-owned UUID for every exported object **before serialization**, and
 * assembles a deterministic {@see TranslationPackage}. Records an
 * `aiml_promotion_log` row and fires `aiml_promotion_audit`.
 *
 * Delegates all persistence: `Store` reads, `ObjectIdentityRepository` for the
 * UUID (a write, but on the source that owns the object), the log repository
 * for history. No raw `$wpdb` here.
 */
final class TranslationExportService {

	/**
	 * Object cap frozen in ADR-0030 (WP1 benchmark).
	 */
	public const MAX_OBJECTS = 3000;

	/**
	 * Builds the service.
	 *
	 * @param Store                    $store      Segment store.
	 * @param Languages                $languages  Language model.
	 * @param ObjectIdentityRepository $identities Identity repository.
	 * @param PromotionLogRepository   $log        Promotion log repository.
	 * @param PromotionAudit           $audit      Audit channel.
	 */
	public function __construct(
		private readonly Store $store,
		private readonly Languages $languages,
		private readonly ObjectIdentityRepository $identities,
		private readonly PromotionLogRepository $log,
		private readonly PromotionAudit $audit
	) {
	}

	/**
	 * Builds a package for the given filters.
	 *
	 * @param ExportFilters $filters Scope filters.
	 */
	public function export( ExportFilters $filters ): ExportResult {
		$language_ids = array();
		foreach ( $filters->language_codes as $code ) {
			$language = $this->languages->find_by_code( $code );
			if ( null !== $language ) {
				$language_ids[ $code ] = (int) $language->language_id;
			}
		}

		if ( array() === $language_ids ) {
			return ExportResult::error( 'no_target_languages', 'None of the requested languages exist on this site.' );
		}

		$objects = array();

		foreach ( $this->walk_posts( $filters ) as $post_id ) {
			$entry = $this->build_post_object( (int) $post_id, $language_ids, $filters );
			if ( null !== $entry ) {
				$objects[] = $entry;
			}
			if ( count( $objects ) > self::MAX_OBJECTS ) {
				return ExportResult::error(
					'too_many_objects',
					sprintf( 'The export exceeds the %d object limit. Split it by language or scope.', self::MAX_OBJECTS )
				);
			}
		}

		foreach ( $this->walk_terms( $filters ) as $term_id ) {
			$entry = $this->build_term_object( (int) $term_id, $language_ids, $filters );
			if ( null !== $entry ) {
				$objects[] = $entry;
			}
			if ( count( $objects ) > self::MAX_OBJECTS ) {
				return ExportResult::error(
					'too_many_objects',
					sprintf( 'The export exceeds the %d object limit. Split it by language or scope.', self::MAX_OBJECTS )
				);
			}
		}

		if ( array() === $objects ) {
			return ExportResult::empty();
		}

		$package_id    = wp_generate_uuid4();
		$language_rows = array();
		foreach ( array_keys( $language_ids ) as $code ) {
			$language = $this->languages->find_by_code( $code );
			if ( null !== $language ) {
				$language_rows[] = array(
					'code'   => (string) $language->code,
					'locale' => (string) $language->locale,
					'name'   => (string) $language->name,
					'status' => (string) $language->status,
				);
			}
		}

		$package = TranslationPackage::create(
			$package_id,
			array(
				'plugin'         => 'universal-multilingual',
				'plugin_version' => defined( 'AIML_VERSION' ) ? AIML_VERSION : '',
				'db_version'     => (int) get_option( 'aiml_db_version', 0 ),
			),
			array(
				'site_uuid'        => $this->site_uuid(),
				'home_url_hash'    => hash( 'sha256', home_url() ),
				'exported_at'      => gmdate( 'c' ),
				'exported_by_hash' => hash( 'sha256', (string) wp_get_current_user()->user_login ),
			),
			Store::NORM_VERSION,
			$language_rows,
			PromotionScope::capabilities(),
			$objects
		);

		$counts = $package->payload()['counts'];
		$log_id = $this->log->start(
			array(
				'direction'        => 'export',
				'package_id'       => $package_id,
				'package_checksum' => $package->package_checksum(),
				'format_version'   => $package->format_version(),
				'language_codes'   => implode( ',', array_keys( $language_ids ) ),
				'actor_id'         => get_current_user_id(),
				'source_site_uuid' => $this->site_uuid(),
			)
		);
		$this->log->finish( $log_id, 'completed', $counts );
		$this->audit->emit( PromotionAudit::STAGE_EXPORT, 'export', $package_id, array( 'counts' => $counts ) );

		return ExportResult::ok( $package );
	}

	/**
	 * Suggested download filename for a package.
	 *
	 * @param TranslationPackage $package Package.
	 */
	public function filename( TranslationPackage $package ): string {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug  = sanitize_file_name( (string) $host );
		$langs = array();
		foreach ( $package->languages() as $language ) {
			$langs[] = $language['code'];
		}

		return sprintf(
			'universal-multilingual-promotion-%s-%s-%s.json',
			'' !== $slug ? $slug : 'site',
			'' !== implode( '-', $langs ) ? implode( '-', $langs ) : 'all',
			gmdate( 'Ymd-His' )
		);
	}

	/**
	 * Post ids to consider.
	 *
	 * @param ExportFilters $filters Filters.
	 * @return array<int, int>
	 */
	private function walk_posts( ExportFilters $filters ): array {
		$types = $filters->effective_post_types();
		if ( array() === $types ) {
			return array();
		}

		$args = array(
			'post_type'              => $types,
			'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'numberposts'            => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'suppress_filters'       => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( $filters->restricts_ids() ) {
			$args['post__in'] = $filters->object_ids;
		}

		$ids = get_posts( $args );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Term ids to consider.
	 *
	 * @param ExportFilters $filters Filters.
	 * @return array<int, int>
	 */
	private function walk_terms( ExportFilters $filters ): array {
		$taxonomies = array_values( array_filter( $filters->effective_taxonomies(), 'taxonomy_exists' ) );
		if ( array() === $taxonomies ) {
			return array();
		}

		$args = array(
			'taxonomy'   => $taxonomies,
			'hide_empty' => false,
			'fields'     => 'ids',
		);

		if ( $filters->restricts_ids() ) {
			$args['include'] = $filters->object_ids;
		}

		$ids = get_terms( $args );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Builds a package object for one post, or null when it has nothing to promote.
	 *
	 * @param int                $post_id      Post id.
	 * @param array<string, int> $language_ids code => language id.
	 * @param ExportFilters      $filters      Filters.
	 */
	private function build_post_object( int $post_id, array $language_ids, ExportFilters $filters ): ?PackageObject {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $filters->allows_id( $post_id ) ) {
			return null;
		}
		if ( ! PromotionScope::subtype_supported( 'post', $post->post_type ) ) {
			return null;
		}

		$hierarchical = is_post_type_hierarchical( $post->post_type );
		$ancestors    = array();
		if ( $hierarchical ) {
			foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $ancestor_id ) {
				$ancestors[] = (string) get_post_field( 'post_name', (int) $ancestor_id );
			}
		}

		$segments = $this->collect_segments( 'post', $post_id, $language_ids, $filters );
		if ( array() === $segments ) {
			return null;
		}

		return $this->assemble(
			'post',
			$post->post_type,
			ObjectNaturalKey::for_post( $post->post_type, (string) $post->post_name, $ancestors, $hierarchical ),
			ObjectNaturalKey::parts( 'post', $post->post_type, (string) $post->post_name, $ancestors ),
			$post_id,
			array(
				'title_hash'   => sha1( (string) $post->post_title ),
				'slug_hash'    => sha1( (string) $post->post_name ),
				'wp_guid_hash' => hash( 'sha256', (string) $post->guid ),
				'sku_hint'     => $this->product_sku( $post_id, $post->post_type ),
			),
			$segments
		);
	}

	/**
	 * Builds a package object for one term, or null when it has nothing to promote.
	 *
	 * @param int                $term_id      Term id.
	 * @param array<string, int> $language_ids code => language id.
	 * @param ExportFilters      $filters      Filters.
	 */
	private function build_term_object( int $term_id, array $language_ids, ExportFilters $filters ): ?PackageObject {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term || ! $filters->allows_id( $term_id ) ) {
			return null;
		}
		if ( ! PromotionScope::subtype_supported( 'term', $term->taxonomy ) ) {
			return null;
		}

		$ancestors = array();
		foreach ( array_reverse( get_ancestors( $term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$ancestors[] = (string) $ancestor->slug;
			}
		}

		$segments = $this->collect_segments( 'term', $term_id, $language_ids, $filters );
		if ( array() === $segments ) {
			return null;
		}

		return $this->assemble(
			'term',
			$term->taxonomy,
			ObjectNaturalKey::for_term( $term->taxonomy, (string) $term->slug, $ancestors ),
			ObjectNaturalKey::parts( 'term', $term->taxonomy, (string) $term->slug, $ancestors ),
			$term_id,
			array(
				'title_hash'   => sha1( (string) $term->name ),
				'slug_hash'    => sha1( (string) $term->slug ),
				'wp_guid_hash' => '',
				'sku_hint'     => '',
			),
			$segments
		);
	}

	/**
	 * Collects qualifying package segments for an object across the target languages.
	 *
	 * @param string             $source_type  post|term.
	 * @param int                $source_id    Local id.
	 * @param array<string, int> $language_ids code => language id.
	 * @param ExportFilters      $filters      Filters.
	 * @return array<int, PackageSegment>
	 */
	private function collect_segments( string $source_type, int $source_id, array $language_ids, ExportFilters $filters ): array {
		$out    = array();
		$latest = '';

		foreach ( $language_ids as $code => $language_id ) {
			foreach ( $this->store->load_object( $source_type, $source_id, $language_id ) as $row ) {
				if ( '' === (string) ( $row->translated_text ?? '' ) ) {
					continue;
				}
				if ( ! PromotionScope::segment_key_supported( (string) $row->field_key, (string) $row->segment_key ) ) {
					continue;
				}
				if ( $filters->approved_only && 'approved' !== (string) ( $row->review_status ?? '' ) ) {
					continue;
				}
				if ( $filters->published_only && 'published' !== (string) ( $row->publish_status ?? '' ) ) {
					continue;
				}

				$out[]  = SegmentMapper::from_store_row( $row, (string) $code );
				$latest = max( $latest, (string) ( $row->updated_at ?? '' ) );
			}
		}

		if ( null !== $filters->changed_since && '' !== $filters->changed_since ) {
			$since = gmdate( 'Y-m-d H:i:s', (int) strtotime( $filters->changed_since ) );
			if ( '' === $latest || $latest < $since ) {
				return array();
			}
		}

		return $out;
	}

	/**
	 * Ensures the object's UUID and assembles the package object.
	 *
	 * @param string                     $source_type    post|term.
	 * @param string                     $source_subtype Post type or taxonomy.
	 * @param string                     $natural_key    Deterministic key.
	 * @param array<string, mixed>       $natural_parts  Structured key parts.
	 * @param int                        $source_id      Local id.
	 * @param array<string, string>      $fingerprint    Advisory fingerprint.
	 * @param array<int, PackageSegment> $segments      Segments.
	 */
	private function assemble( string $source_type, string $source_subtype, string $natural_key, array $natural_parts, int $source_id, array $fingerprint, array $segments ): PackageObject {
		$identity = $this->identities->ensure(
			$source_type,
			$source_id,
			$source_subtype,
			$natural_key,
			(string) wp_json_encode( $natural_parts )
		);

		return new PackageObject(
			(string) $identity->uuid,
			$source_type,
			$source_subtype,
			$natural_key,
			$natural_parts,
			$fingerprint,
			$segments
		);
	}

	/**
	 * A product's SKU, when WooCommerce is present and the type is product.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type.
	 */
	private function product_sku( int $post_id, string $post_type ): string {
		if ( 'product' !== $post_type || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $post_id );

		return $product ? (string) $product->get_sku() : '';
	}

	/**
	 * A stable per-site UUID for audit / self-import detection.
	 */
	private function site_uuid(): string {
		$uuid = (string) get_option( 'aiml_site_uuid', '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( 'aiml_site_uuid', $uuid, true );
		}

		return $uuid;
	}
}
