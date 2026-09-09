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
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\Review\ReviewWorkflowException;
use AIMultilingual\Workspace\Review\ReviewWorkflowService;
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
	 * @param ReviewWorkflowService|null  $review      Review workflow, for the trust-review path.
	 * @param PublicationService|null     $publication Publication service, for the trust-review path.
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
		private readonly ReviewToken $tokens,
		private readonly ?ReviewWorkflowService $review = null,
		private readonly ?PublicationService $publication = null
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

	/**
	 * Applies a reviewed package.
	 *
	 * @param string              $raw               Re-uploaded package bytes.
	 * @param string              $token             The dry-run review token.
	 * @param array<string,mixed> $client_plan      The reviewed ImportPlan array (untrusted; hash-checked).
	 * @param string              $mode              One of {@see ApplyMode}.
	 * @param array<int,string>   $row_allowlist     Selective mode: row ids to apply.
	 * @param array<int,string>   $acknowledgements  Selective mode: row ids the operator accepted despite a warning.
	 * @param bool                $trust_review      Carry review/publication state (requires the dedicated capability).
	 * @param callable|null       $pre_write_probe   Test seam: invoked once after re-plan validation, before the write loop.
	 */
	public function apply(
		string $raw,
		string $token,
		array $client_plan,
		string $mode,
		array $row_allowlist = array(),
		array $acknowledgements = array(),
		bool $trust_review = false,
		?callable $pre_write_probe = null
	): ImportResult {
		$mode      = ApplyMode::normalize( $mode );
		$actor_id  = get_current_user_id();
		$validated = $this->validate( $raw );

		if ( ! $validated['ok'] ) {
			return ImportResult::refused( '', $mode, $validated['code'], implode( '; ', $validated['messages'] ) );
		}

		$package    = $validated['package'];
		$package_id = $package->package_id();

		$verified = $this->tokens->verify( $token, $actor_id, $package->package_checksum() );
		if ( ! $verified['ok'] ) {
			return ImportResult::refused( $package_id, $mode, $verified['code'], 'The review token is not valid for this apply.' );
		}
		$token_plan_hash = (string) ( $verified['claims']['canonical_plan_hash'] ?? '' );

		// The client-supplied plan is not trusted: its hash must equal the token's.
		$client_hash = CanonicalJson::sha256( $this->normalize_client_plan( $client_plan ) );
		if ( ! hash_equals( $token_plan_hash, $client_hash ) && ! hash_equals( $token_plan_hash, (string) ( $client_plan['canonical_hash'] ?? '' ) ) ) {
			return ImportResult::refused( $package_id, $mode, 'client_plan_mismatch', 'The reviewed plan does not match the review token.' );
		}

		// Independently re-plan against current state; any material drift refuses
		// the whole apply.
		$fresh = $this->plan( $package );
		$plan  = $fresh['plan'];
		if ( ! hash_equals( $token_plan_hash, $plan->canonical_hash() ) ) {
			return ImportResult::refused(
				$package_id,
				$mode,
				'plan_drifted',
				'This environment changed since the dry-run. Run a fresh dry-run and review it again.'
			);
		}

		if ( null !== $pre_write_probe ) {
			$pre_write_probe();
		}

		$language_map = LanguageMap::from_languages( $this->languages, $package->languages() );
		$by_uuid      = $this->index_objects( $package );

		$applied = array();
		$skipped = array();
		$changed = array();
		$errors  = array();

		$log_id = $this->log->start(
			array(
				'direction'        => 'import',
				'package_id'       => $package_id,
				'package_checksum' => $package->package_checksum(),
				'format_version'   => $package->format_version(),
				'mode'             => $mode,
				'actor_id'         => $actor_id,
				'source_site_uuid' => (string) ( $package->manifest()['source_site']['site_uuid'] ?? '' ),
			)
		);
		$this->audit->emit( PromotionAudit::STAGE_APPLY_START, 'import', $package_id, array( 'mode' => $mode ) );

		foreach ( $plan->rows() as $row ) {
			$decision = $this->admits( $row, $mode, $row_allowlist, $acknowledgements );
			if ( 'apply' !== $decision ) {
				$skipped[ $row->category ] = ( $skipped[ $row->category ] ?? 0 ) + 1;
				continue;
			}

			$outcome = $this->apply_row( $row, $by_uuid, $language_map, $package_id, $trust_review, $actor_id );
			if ( ImportCategory::CHANGED_SINCE_DRY_RUN === $outcome['category'] ) {
				$changed[]                                        = $row->row_id();
				$skipped[ ImportCategory::CHANGED_SINCE_DRY_RUN ] = ( $skipped[ ImportCategory::CHANGED_SINCE_DRY_RUN ] ?? 0 ) + 1;
				continue;
			}
			if ( '' !== $outcome['error'] ) {
				$errors[]                  = $row->row_id() . ': ' . $outcome['error'];
				$skipped[ $row->category ] = ( $skipped[ $row->category ] ?? 0 ) + 1;
				continue;
			}

			$applied[ $row->category ] = ( $applied[ $row->category ] ?? 0 ) + 1;
		}

		$result_status = array() !== $errors ? 'partial' : 'completed';
		$counts        = array(
			'applied'               => $applied,
			'skipped'               => $skipped,
			'changed_since_dry_run' => count( $changed ),
		);
		$this->log->finish( $log_id, $result_status, $counts );
		$this->audit->emit( PromotionAudit::STAGE_APPLY_COMPLETE, 'import', $package_id, $counts );

		return new ImportResult( $package_id, $mode, '', '', $applied, $skipped, $changed, $errors, $result_status );
	}

	/**
	 * Whether a planned row is applied under the mode / allowlist / acks.
	 *
	 * @param PlannedRow        $row             Row.
	 * @param string            $mode            Apply mode.
	 * @param array<int,string> $row_allowlist   Selective allowlist.
	 * @param array<int,string> $acknowledgements Selective acknowledgements.
	 * @return string 'apply' or 'skip'.
	 */
	private function admits( PlannedRow $row, string $mode, array $row_allowlist, array $acknowledgements ): string {
		if ( in_array( $row->category, ImportCategory::never_applicable(), true ) ) {
			return 'skip';
		}

		if ( ApplyMode::SAFE_ONLY === $mode ) {
			return in_array( $row->category, ImportCategory::safe(), true ) ? 'apply' : 'skip';
		}

		if ( ApplyMode::FORCE === $mode ) {
			return in_array( $row->category, ImportCategory::forceable(), true ) ? 'apply' : 'skip';
		}

		// Selective.
		if ( ! in_array( $row->row_id(), $row_allowlist, true ) ) {
			return 'skip';
		}
		if ( in_array( $row->category, ImportCategory::acknowledgeable(), true )
			&& ! in_array( $row->row_id(), $acknowledgements, true ) ) {
			return 'skip';
		}

		return in_array( $row->category, ImportCategory::forceable(), true ) ? 'apply' : 'skip';
	}

	/**
	 * Writes one row through the sanctioned Store path, revalidating first.
	 *
	 * @param PlannedRow                  $row          Planned row.
	 * @param array<string,PackageObject> $by_uuid     Package objects by UUID.
	 * @param LanguageMap                 $language_map Language map.
	 * @param string                      $package_id   Package id.
	 * @param bool                        $trust_review Trust-review flag.
	 * @param int                         $actor_id     Applying user id.
	 * @return array{category:string,error:string}
	 */
	private function apply_row( PlannedRow $row, array $by_uuid, LanguageMap $language_map, string $package_id, bool $trust_review, int $actor_id ): array {
		$uuid           = (string) $row->object_ref['object_uuid'];
		$source_type    = (string) $row->object_ref['source_type'];
		$source_subtype = (string) $row->object_ref['source_subtype'];
		$segment_key    = (string) $row->segment_ref['segment_key'];
		$local_id       = (int) $row->preconditions['expected_local_source_id'];
		$language_id    = (int) $row->preconditions['expected_local_language_id'];

		$entry   = $by_uuid[ $uuid ] ?? null;
		$segment = null !== $entry ? $this->segment_by_key( $entry, $segment_key, (string) $row->segment_ref['language_code'] ) : null;
		if ( null === $segment ) {
			return array(
				'category' => $row->category,
				'error'    => 'segment no longer present in the package',
			);
		}

		// Immediately-before-write precondition revalidation (narrow TOCTOU).
		$current = $this->store->get( $source_type, $local_id, $language_id, $segment_key );
		if ( ! $this->preconditions_hold( $row, $current ) ) {
			return array(
				'category' => ImportCategory::CHANGED_SINCE_DRY_RUN,
				'error'    => '',
			);
		}

		// Adopt the incoming UUID onto the matched local object if bootstrapping.
		if ( ResolvedObject::ACTION_ADOPT_INCOMING === (string) ( $row->resolution['identity_action'] ?? '' )
			&& null === $this->identities->find_by_source( $source_type, $local_id ) ) {
			$adopted = $this->identities->adopt_uuid(
				$source_type,
				$local_id,
				$source_subtype,
				$uuid,
				(string) $row->object_ref['natural_key']
			);
			if ( ! $adopted ) {
				return array(
					'category' => $row->category,
					'error'    => 'could not adopt the package UUID (already taken locally)',
				);
			}
		}

		// PROD's own current source text keeps source_hash / is_stale consistent.
		$prod_source = $this->local_source_text( $source_type, $local_id, $segment );

		$saved = $this->store->save_translation(
			array(
				'source_type'     => $source_type,
				'source_id'       => $local_id,
				'source_subtype'  => $source_subtype,
				'language_id'     => $language_id,
				'field_key'       => $segment->field_key,
				'segment_key'     => $segment_key,
				'segment_kind'    => $segment->segment_kind,
				'segment_order'   => $segment->segment_order,
				'text_format'     => $segment->text_format,
				'status'          => 'reviewed' === $segment->status ? 'manually_edited' : $segment->status,
				'source_text'     => $prod_source,
				'translated_text' => $segment->translated_text,
			)
		);

		if ( $saved instanceof \WP_Error ) {
			return array(
				'category' => $row->category,
				'error'    => $saved->get_error_message(),
			);
		}

		// Update the three-way baseline with what was actually written.
		$this->state->upsert(
			array(
				'object_uuid'                    => $uuid,
				'language_code'                  => $language_map->local_code( $language_id ),
				'segment_hash'                   => (string) $row->segment_ref['segment_hash'],
				'last_promoted_translation_hash' => $segment->translation_hash,
				'last_promoted_source_hash'      => Store::source_hash( $prod_source, $segment->text_format ),
				'last_package_id'                => $package_id,
			)
		);

		if ( $trust_review ) {
			$this->carry_review_state( $segment, $source_type, $local_id, $language_id, $segment_key, $actor_id );
		}

		$this->audit->emit(
			PromotionAudit::STAGE_APPLY_ROW,
			'import',
			$package_id,
			array(
				'category'    => $row->category,
				'object_uuid' => $uuid,
			)
		);

		return array(
			'category' => $row->category,
			'error'    => '',
		);
	}

	/**
	 * Whether the row's captured preconditions still hold against the live row.
	 *
	 * @param PlannedRow  $row     Planned row.
	 * @param object|null $current Current local translation row, or null.
	 */
	private function preconditions_hold( PlannedRow $row, ?object $current ): bool {
		$expected_hash    = $row->preconditions['expected_prod_translation_hash'] ?? null;
		$expected_updated = $row->preconditions['expected_prod_row_updated_at'] ?? null;

		if ( null === $expected_hash ) {
			return null === $current;
		}
		if ( null === $current ) {
			return false;
		}

		return hash_equals( (string) $expected_hash, (string) ( $current->translation_hash ?? '' ) )
			&& ( null === $expected_updated || (string) ( $current->updated_at ?? '' ) === (string) $expected_updated );
	}

	/**
	 * Carries review / publication state through the existing services.
	 *
	 * @param PackageSegment $segment      Package segment.
	 * @param string         $source_type  Source type.
	 * @param int            $local_id     Local id.
	 * @param int            $language_id  Local language id.
	 * @param string         $segment_key  Segment key.
	 * @param int            $actor_id     Applying user id.
	 */
	private function carry_review_state( PackageSegment $segment, string $source_type, int $local_id, int $language_id, string $segment_key, int $actor_id ): void {
		$wants_review    = in_array( $segment->review_status, array( 'approved' ), true );
		$wants_published = 'published' === $segment->publish_status;

		if ( $wants_review && null !== $this->review ) {
			try {
				$this->review->submit( $source_type, $local_id, $language_id, $segment_key, $actor_id );
				$this->review->approve( $source_type, $local_id, $language_id, $segment_key, $actor_id );
			} catch ( ReviewWorkflowException $e ) {
				// An illegal review transition on this segment does not fail the
				// promotion; the operator re-reviews it on PROD.
				unset( $e );
			}
		}

		if ( $wants_published && null !== $this->publication ) {
			// publish() returns a WP_Error on a blocked transition; that is not
			// a promotion failure.
			$this->publication->publish( $source_type, $local_id, $language_id, $segment_key, false, $actor_id, 'promotion' );
		}
	}

	/**
	 * PROD's current canonical text for the field a segment targets.
	 *
	 * Falls back to the package's source text when the field cannot be read
	 * (e.g. block segments): apply then relies on Store::sync_source to flag
	 * staleness on the next canonical change.
	 *
	 * @param string         $source_type Source type.
	 * @param int            $local_id    Local id.
	 * @param PackageSegment $segment     Segment.
	 */
	private function local_source_text( string $source_type, int $local_id, PackageSegment $segment ): string {
		if ( 'post' === $source_type ) {
			return match ( $segment->field_key ) {
				'post_title'   => (string) get_post_field( 'post_title', $local_id ),
				'post_excerpt' => (string) get_post_field( 'post_excerpt', $local_id ),
				'post_content' => (string) get_post_field( 'post_content', $local_id ),
				'post_name'    => (string) get_post_field( 'post_name', $local_id ),
				default        => $segment->source_text,
			};
		}

		if ( 'term' === $source_type ) {
			$term = get_term( $local_id );
			if ( $term instanceof WP_Term ) {
				return match ( $segment->field_key ) {
					'name'        => (string) $term->name,
					'description' => (string) $term->description,
					default       => $segment->source_text,
				};
			}
		}

		return $segment->source_text;
	}

	/**
	 * Package objects keyed by UUID.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<string, PackageObject>
	 */
	private function index_objects( TranslationPackage $package ): array {
		$out = array();
		foreach ( $package->objects() as $entry ) {
			$out[ $entry->object_uuid ] = $entry;
		}

		return $out;
	}

	/**
	 * Finds a segment inside an object by key + language.
	 *
	 * @param PackageObject $entry         Object.
	 * @param string        $segment_key   Segment key.
	 * @param string        $language_code Language code.
	 */
	private function segment_by_key( PackageObject $entry, string $segment_key, string $language_code ): ?PackageSegment {
		foreach ( $entry->segments as $segment ) {
			if ( $segment->segment_key === $segment_key && $segment->language_code === $language_code ) {
				return $segment;
			}
		}

		return null;
	}

	/**
	 * Normalizes a client-supplied plan array to the same shape
	 * {@see ImportPlan::canonical_hash()} hashes.
	 *
	 * @param array<string, mixed> $client_plan Client plan array.
	 * @return array<string, mixed>
	 */
	private function normalize_client_plan( array $client_plan ): array {
		$rows = array();
		foreach ( (array) ( $client_plan['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'row_id'        => (string) ( $row['row_id'] ?? '' ),
				'category'      => (string) ( $row['category'] ?? '' ),
				'preconditions' => isset( $row['preconditions'] ) && is_array( $row['preconditions'] ) ? $row['preconditions'] : array(),
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['row_id'], $b['row_id'] ) );

		return array(
			'package_id'       => (string) ( $client_plan['package_id'] ?? '' ),
			'package_checksum' => (string) ( $client_plan['package_checksum'] ?? '' ),
			'rows'             => $rows,
		);
	}
}
