<?php
/**
 * DEV → PROD translation import — validation, dry-run and apply (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Database\PromotionStateRepository;
use AIMultilingual\Language\Languages;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Store;
use WP_Term;

/**
 * `validate()` and `plan()` are strictly read-only — no DB writes, no option
 * writes, no transients, no audit / action hooks. `apply()` (WP7) is the only
 * method that writes, and only through the sanctioned {@see Store} paths.
 */
final class TranslationImportService {

	/**
	 * Builds the service.
	 *
	 * @param Store                       $store       Segment store.
	 * @param Languages                   $languages   Language model.
	 * @param ObjectIdentityResolver      $resolver    Identity resolver.
	 * @param PromotionStateRepository    $state       Three-way baseline repository.
	 * @param ObjectIdentityRepository    $identities  Identity repository (apply only).
	 * @param PromotionLogRepository      $log         Promotion log (apply only).
	 * @param PromotionAudit              $audit       Audit channel (apply only).
	 * @param Settings                    $settings    Plugin settings.
	 * @param TranslationConflictDetector $detector  Conflict detector.
	 * @param ReviewToken                 $tokens      Review-token helper.
	 */
	public function __construct(
		private readonly Store $store,
		private readonly Languages $languages,
		private readonly ObjectIdentityResolver $resolver,
		private readonly PromotionStateRepository $state,
		private readonly ObjectIdentityRepository $identities,
		private readonly PromotionLogRepository $log,
		private readonly PromotionAudit $audit,
		private readonly Settings $settings,
		private readonly TranslationConflictDetector $detector,
		private readonly ReviewToken $tokens
	) {
	}

	/**
	 * The hard package-size ceiling from settings.
	 */
	public function max_package_bytes(): int {
		return (int) ( $this->settings->get()['promotion_max_package_bytes'] ?? 26214400 );
	}

	/**
	 * Deserializes and structurally validates raw package bytes.
	 *
	 * @param string $raw Uploaded bytes.
	 * @return array{ok:bool,code:string,messages:array<int,string>,package:?TranslationPackage}
	 */
	public function validate( string $raw ): array {
		try {
			$package = ( new JsonPackageSerializer() )->deserialize( $raw, $this->max_package_bytes() );
		} catch ( PackageFormatException $e ) {
			return array(
				'ok'       => false,
				'code'     => $e->error_code(),
				'messages' => array( $e->getMessage() ),
				'package'  => null,
			);
		}

		$validation = ( new TranslationPackageValidator() )->validate( $package );
		if ( ! $validation->is_valid() ) {
			return array(
				'ok'       => false,
				'code'     => $validation->first_code(),
				'messages' => $validation->messages(),
				'package'  => null,
			);
		}

		return array(
			'ok'       => true,
			'code'     => '',
			'messages' => array(),
			'package'  => $package,
		);
	}

	/**
	 * Builds a read-only dry-run plan and a signed review token.
	 *
	 * @param TranslationPackage $package A validated package.
	 * @return array{plan:ImportPlan,token:string}
	 */
	public function plan( TranslationPackage $package ): array {
		$language_map = LanguageMap::from_languages( $this->languages, $package->languages() );
		$rows         = array();

		foreach ( $package->objects() as $entry ) {
			$resolved   = $this->resolver->resolve( $entry );
			$resolution = $resolved->to_array();
			$object_ref = array(
				'object_uuid'    => $entry->object_uuid,
				'natural_key'    => $entry->natural_key,
				'source_type'    => $entry->source_type,
				'source_subtype' => $entry->source_subtype,
			);

			foreach ( $entry->sorted_segments() as $segment ) {
				$rows[] = $this->plan_segment( $entry, $segment, $resolved, $resolution, $object_ref, $language_map );
			}
		}

		$plan  = new ImportPlan( $package->package_id(), $package->package_checksum(), $rows );
		$token = $this->tokens->mint(
			get_current_user_id(),
			$package->package_id(),
			$package->package_checksum(),
			$plan->canonical_hash()
		);

		return array(
			'plan'  => $plan,
			'token' => $token,
		);
	}

	/**
	 * Classifies one incoming segment.
	 *
	 * @param PackageObject        $entry        Package object.
	 * @param PackageSegment       $segment      Package segment.
	 * @param ResolvedObject       $resolved     Object resolution.
	 * @param array<string, mixed> $resolution   Resolution array.
	 * @param array<string, mixed> $object_ref   Object ref array.
	 * @param LanguageMap          $language_map Language map.
	 */
	private function plan_segment( PackageObject $entry, PackageSegment $segment, ResolvedObject $resolved, array $resolution, array $object_ref, LanguageMap $language_map ): PlannedRow {
		$segment_ref = array(
			'field_key'     => $segment->field_key,
			'segment_key'   => $segment->segment_key,
			'segment_hash'  => $segment->segment_hash(),
			'language_code' => $segment->language_code,
		);
		$incoming    = array(
			'translation_hash' => $segment->translation_hash,
			'source_hash'      => $segment->source_hash,
			'norm_version'     => $segment->norm_version,
		);

		$make = fn( string $category, string $reason, array $pre = array() ): PlannedRow => new PlannedRow(
			$object_ref,
			$segment_ref,
			$category,
			$resolution,
			$incoming,
			$pre,
			$reason
		);

		// Object-level blockers short-circuit every segment.
		if ( null !== $resolved->category ) {
			return $make( $resolved->category, (string) ( $resolved->notes[0] ?? '' ) );
		}

		if ( ! PromotionScope::segment_key_supported( $segment->field_key, $segment->segment_key ) ) {
			return $make( ImportCategory::UNKNOWN_FIELD, 'this site does not translate this field' );
		}

		$language_id = $language_map->resolve( $segment->language_code );
		if ( null === $language_id ) {
			return $make( ImportCategory::MISSING_LANGUAGE, 'no local language for this locale' );
		}

		$local_id  = (int) $resolved->local_source_id;
		$local_row = $this->store->get( $entry->source_type, $local_id, $language_id, $segment->segment_key );

		$local_code        = $language_map->local_code( $language_id );
		$baseline          = $this->state->find( $entry->object_uuid, $local_code, $segment->segment_hash() );
		$last_promoted     = null === $baseline ? null : (string) $baseline->last_promoted_translation_hash;
		$local_source_hash = null === $local_row ? null : (string) ( $local_row->source_hash ?? '' );

		$verdict  = $this->detector->classify( $segment, $local_row, $local_source_hash, $last_promoted );
		$category = $verdict['category'];

		// A slug segment whose value would collide with another object's route.
		if ( ImportCategory::UNCHANGED !== $category
			&& $this->is_slug_segment( $segment )
			&& $this->slug_would_collide( $entry->source_type, $local_id, $entry->source_subtype, $segment->translated_text ) ) {
			$category          = ImportCategory::ROUTE_CONFLICT;
			$verdict['reason'] = 'the translated slug collides with another object';
		}

		$preconditions = array(
			'expected_object_uuid'           => $entry->object_uuid,
			'expected_local_source_id'       => $local_id,
			'expected_local_language_id'     => $language_id,
			'expected_prod_translation_hash' => null === $local_row ? null : (string) ( $local_row->translation_hash ?? '' ),
			'expected_prod_source_hash'      => $local_source_hash,
			'expected_prod_row_updated_at'   => null === $local_row ? null : (string) ( $local_row->updated_at ?? '' ),
			'expected_last_promoted_hash'    => $last_promoted,
		);

		return $make( $category, $verdict['reason'], $preconditions );
	}

	/**
	 * Whether a package segment carries a translated slug candidate.
	 *
	 * @param PackageSegment $segment Segment.
	 */
	private function is_slug_segment( PackageSegment $segment ): bool {
		return 'slug' === $segment->text_format || 'post_name' === $segment->field_key || 'slug' === $segment->field_key;
	}

	/**
	 * Heuristic: whether the translated slug matches the canonical slug of a
	 * *different* local object of the same subtype, which would make the
	 * localized route ambiguous.
	 *
	 * @param string $source_type    post|term.
	 * @param int    $local_id       The matched local object.
	 * @param string $source_subtype Post type / taxonomy.
	 * @param string $slug           Translated slug value.
	 */
	private function slug_would_collide( string $source_type, int $local_id, string $source_subtype, string $slug ): bool {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return false;
		}

		if ( 'post' === $source_type ) {
			$others = get_posts(
				array(
					'name'                   => $slug,
					'post_type'              => $source_subtype,
					'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'exclude'                => array( $local_id ),
					'numberposts'            => 1,
					'fields'                 => 'ids',
					'suppress_filters'       => true,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			return is_array( $others ) && array() !== $others;
		}

		if ( 'term' === $source_type && taxonomy_exists( $source_subtype ) ) {
			$term = get_term_by( 'slug', $slug, $source_subtype );

			return $term instanceof WP_Term && (int) $term->term_id !== $local_id;
		}

		return false;
	}
}
