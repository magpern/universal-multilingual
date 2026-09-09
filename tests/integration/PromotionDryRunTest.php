<?php
/**
 * TranslationImportService::plan() — strictly read-only dry-run (ADR-0030 §5/§6).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Database\PromotionStateRepository;
use AIMultilingual\Promotion\ExportFilters;
use AIMultilingual\Promotion\ImportCategory;
use AIMultilingual\Promotion\ObjectIdentityResolver;
use AIMultilingual\Promotion\PromotionAudit;
use AIMultilingual\Promotion\ReviewToken;
use AIMultilingual\Promotion\TranslationConflictDetector;
use AIMultilingual\Promotion\TranslationExportService;
use AIMultilingual\Promotion\TranslationImportService;
use AIMultilingual\Promotion\TranslationPackage;
use AIMultilingual\Settings;

/**
 * The dry-run classifies every incoming segment and writes nothing.
 */
final class PromotionDryRunTest extends AimlTestCase {

	private TranslationExportService $exporter;
	private TranslationImportService $importer;
	private int $sv_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		$sv          = $this->add_language( 'sv', 'sv_SE' );
		$this->sv_id = (int) $sv->language_id;

		$state          = new PromotionStateRepository();
		$identities     = new ObjectIdentityRepository();
		$this->exporter = new TranslationExportService(
			$this->store,
			$this->languages,
			$identities,
			new PromotionLogRepository(),
			new PromotionAudit()
		);
		$this->importer = new TranslationImportService(
			$this->store,
			$this->languages,
			new ObjectIdentityResolver( $identities ),
			$state,
			$identities,
			new PromotionLogRepository(),
			new PromotionAudit(),
			new Settings(),
			new TranslationConflictDetector(),
			new ReviewToken( 'dry-run-test-key' )
		);

		$this->post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'blue-widget',
				'post_title' => 'Blue Widget',
			)
		);
		$this->save_segment( 'Blue Widget', 'Blå pryl' );
	}

	public function test_dry_run_is_strictly_read_only(): void {
		global $wpdb;

		$package = $this->exported_package();
		$before  = $this->snapshot();

		$out = $this->importer->plan( $package );

		$this->assertNotEmpty( $out['token'] );
		$this->assertSame( $before, $this->snapshot(), 'plan() must not change any aiml_* table or promotion option' );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'aiml_promotion_log WHERE direction = "import"' ) ); // phpcs:ignore WordPress.DB
	}

	public function test_reimport_of_an_unchanged_site_is_all_unchanged(): void {
		$package = $this->exported_package();

		$plan = $this->importer->plan( $package )['plan'];

		$this->assertSame( array( ImportCategory::UNCHANGED => 1 ), $plan->counts() );
	}

	public function test_missing_local_translation_is_new(): void {
		$package = $this->exported_package();

		// Remove the local translation; the package still carries it.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'aiml_translations WHERE source_id = ' . $this->post_id ); // phpcs:ignore WordPress.DB
		$this->store = $this->fresh_store();
		$this->rebuild_importer();

		$plan = $this->importer->plan( $package )['plan'];

		$this->assertSame( array( ImportCategory::NEW_TRANSLATION => 1 ), $plan->counts() );
	}

	public function test_update_when_baseline_matches_local_and_package_differs(): void {
		$package = $this->exported_package();

		// PROD edited the translation, but that edit *is* what we last promoted.
		$this->save_segment( 'Blue Widget', 'Annan text' );
		$state = new PromotionStateRepository();
		$state->upsert(
			array(
				'object_uuid'                    => $package->objects()[0]->object_uuid,
				'language_code'                  => 'sv',
				'segment_hash'                   => $package->objects()[0]->segments[0]->segment_hash(),
				'last_promoted_translation_hash' => sha1( 'Annan text' ),
				'last_promoted_source_hash'      => sha1( 'Blue Widget' ),
				'last_package_id'                => 'prev-package',
			)
		);
		$this->store = $this->fresh_store();
		$this->rebuild_importer();

		$plan = $this->importer->plan( $package )['plan'];

		$this->assertSame( array( ImportCategory::UPDATE => 1 ), $plan->counts() );
	}

	public function test_conflict_when_local_edited_and_no_baseline(): void {
		$package = $this->exported_package();

		$this->save_segment( 'Blue Widget', 'Redigerad på prod' );
		$this->store = $this->fresh_store();
		$this->rebuild_importer();

		$plan = $this->importer->plan( $package )['plan'];

		$this->assertSame( array( ImportCategory::CONFLICT_TARGET => 1 ), $plan->counts() );
	}

	public function test_missing_source_when_natural_key_matches_nothing(): void {
		$package = $this->exported_package();
		wp_delete_post( $this->post_id, true );
		// Also drop the identity row so the UUID pass cannot short-circuit.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'aiml_object_identity' ); // phpcs:ignore WordPress.DB
		$this->rebuild_importer();

		$plan = $this->importer->plan( $package )['plan'];

		$this->assertSame( array( ImportCategory::MISSING_SOURCE => 1 ), $plan->counts() );
	}

	public function test_unsupported_type_is_reported(): void {
		$package = TranslationPackage::from_array(
			array(
				'manifest' => $this->exported_package()->manifest(),
				'payload'  => array_merge(
					$this->exported_package()->payload(),
					array(
						'objects' => array(
							array_merge(
								$this->exported_package()->objects()[0]->to_array(),
								array( 'source_subtype' => 'nav_menu_item' )
							),
						),
					)
				),
			)
		);

		$plan = $this->importer->plan( $package )['plan'];

		$this->assertSame( array( ImportCategory::UNSUPPORTED_TYPE => 1 ), $plan->counts() );
	}

	private function exported_package(): TranslationPackage {
		return $this->exporter->export( new ExportFilters( array( 'sv' ) ) )->package();
	}

	private function fresh_store(): \AIMultilingual\Translation\Store {
		wp_cache_flush();

		return new \AIMultilingual\Translation\Store( $this->cache );
	}

	private function rebuild_importer(): void {
		$identities     = new ObjectIdentityRepository();
		$this->importer = new TranslationImportService(
			$this->store,
			$this->languages,
			new ObjectIdentityResolver( $identities ),
			new PromotionStateRepository(),
			$identities,
			new PromotionLogRepository(),
			new PromotionAudit(),
			new Settings(),
			new TranslationConflictDetector(),
			new ReviewToken( 'dry-run-test-key' )
		);
	}

	/**
	 * Writes one translated title segment.
	 *
	 * @param string $source Source text.
	 * @param string $target Translated text.
	 */
	private function save_segment( string $source, string $target ): void {
		$this->store->save_translation(
			array(
				'source_type'     => 'post',
				'source_id'       => $this->post_id,
				'language_id'     => $this->sv_id,
				'field_key'       => 'post_title',
				'segment_key'     => 'post_title',
				'text_format'     => 'plain',
				'status'          => 'manually_edited',
				'source_text'     => $source,
				'translated_text' => $target,
			)
		);
	}

	/**
	 * A stable snapshot of every promotion-relevant table + option.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		global $wpdb;

		$out = array();
		foreach ( array( 'aiml_translations', 'aiml_object_identity', 'aiml_promotion_state', 'aiml_promotion_log' ) as $table ) {
			$out[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . $table . ' ORDER BY 1', ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		$out['aiml_caps_version'] = get_option( 'aiml_caps_version' );

		return $out;
	}
}
