<?php
/**
 * TranslationImportService::apply() — token, re-plan, revalidate, write (ADR-0030 §7).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Database\PromotionStateRepository;
use AIMultilingual\Promotion\ApplyMode;
use AIMultilingual\Promotion\ExportFilters;
use AIMultilingual\Promotion\ImportCategory;
use AIMultilingual\Promotion\JsonPackageSerializer;
use AIMultilingual\Promotion\ObjectIdentityResolver;
use AIMultilingual\Promotion\PromotionAudit;
use AIMultilingual\Promotion\ReviewToken;
use AIMultilingual\Promotion\TranslationConflictDetector;
use AIMultilingual\Promotion\TranslationExportService;
use AIMultilingual\Promotion\TranslationImportService;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Store;

/**
 * Writes go through Store; the token binds the reviewed plan; drift is refused.
 */
final class PromotionApplyTest extends AimlTestCase {

	private TranslationExportService $exporter;
	private int $sv_id;
	private int $post_id;
	private string $raw;

	protected function setUp(): void {
		parent::setUp();

		$sv          = $this->add_language( 'sv', 'sv_SE' );
		$this->sv_id = (int) $sv->language_id;

		$identities     = new ObjectIdentityRepository();
		$this->exporter = new TranslationExportService(
			$this->store,
			$this->languages,
			$identities,
			new PromotionLogRepository(),
			new PromotionAudit()
		);

		$this->post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'blue-widget',
				'post_title' => 'Blue Widget',
			)
		);
		$this->write( 'Blue Widget', 'Blå pryl' );

		$package   = $this->exporter->export( new ExportFilters( array( 'sv' ) ) )->package();
		$this->raw = ( new JsonPackageSerializer() )->serialize( $package );
	}

	public function test_safe_apply_of_a_new_translation_writes_through_store(): void {
		// Remove the local translation so the package's segment is "new".
		$this->wipe_translations();

		$importer = $this->importer();
		$prepared = $importer->plan( $this->decode() );
		$result   = $importer->apply(
			$this->raw,
			$prepared['token'],
			$prepared['plan']->to_array(),
			ApplyMode::SAFE_ONLY
		);

		$this->assertFalse( $result->is_refused(), $result->refused_message );
		$this->assertSame( 1, $result->applied_count() );

		$row = ( new Store( $this->cache ) )->get( 'post', $this->post_id, $this->sv_id, 'post_title' );
		$this->assertNotNull( $row );
		$this->assertSame( 'Blå pryl', (string) $row->translated_text );

		// The baseline was recorded.
		$this->assertSame( 1, ( new PromotionStateRepository() )->count() );

		// A promotion-log import row exists.
		global $wpdb;
		$this->assertSame(
			1,
			(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'aiml_promotion_log WHERE direction = "import"' ) // phpcs:ignore WordPress.DB
		);
	}

	public function test_idempotent_reapply_writes_nothing(): void {
		$this->wipe_translations();
		$importer = $this->importer();
		$first    = $importer->plan( $this->decode() );
		$importer->apply( $this->raw, $first['token'], $first['plan']->to_array(), ApplyMode::SAFE_ONLY );

		$importer = $this->importer();
		$second   = $importer->plan( $this->decode() );
		$result   = $importer->apply( $this->raw, $second['token'], $second['plan']->to_array(), ApplyMode::SAFE_ONLY );

		$this->assertSame( 0, $result->applied_count() );
		$this->assertSame( array( ImportCategory::UNCHANGED => 1 ), $result->skipped_by_category );
	}

	public function test_drift_between_dry_run_and_apply_refuses_the_whole_apply(): void {
		$this->wipe_translations();
		$importer = $this->importer();
		$prepared = $importer->plan( $this->decode() );

		// PROD changes after the operator reviewed the plan.
		self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'blue-widget-2',
				'post_title' => 'X',
			)
		);
		$this->write( 'Blue Widget', 'Prod-ändrad' );
		$importer = $this->importer();

		$result = $importer->apply( $this->raw, $prepared['token'], $prepared['plan']->to_array(), ApplyMode::SAFE_ONLY );

		$this->assertTrue( $result->is_refused() );
		$this->assertSame( 'plan_drifted', $result->refused_code );
	}

	public function test_narrow_toctou_makes_one_row_changed_since_dry_run(): void {
		// Local translation exists and equals the last-promoted baseline ⇒ update.
		$this->write( 'Blue Widget', 'Gammal svensk' );
		( new PromotionStateRepository() )->upsert(
			array(
				'object_uuid'                    => $this->decode()->objects()[0]->object_uuid,
				'language_code'                  => 'sv',
				'segment_hash'                   => $this->decode()->objects()[0]->segments[0]->segment_hash(),
				'last_promoted_translation_hash' => sha1( 'Gammal svensk' ),
				'last_promoted_source_hash'      => Store::source_hash( 'Blue Widget', 'plain' ),
				'last_package_id'                => 'prev',
			)
		);

		$importer = $this->importer();
		$prepared = $importer->plan( $this->decode() );

		$result = $importer->apply(
			$this->raw,
			$prepared['token'],
			$prepared['plan']->to_array(),
			ApplyMode::SAFE_ONLY,
			array(),
			array(),
			false,
			// Mutate PROD after re-plan passes, before the row write.
			fn() => $this->write( 'Blue Widget', 'Slipped in' )
		);

		$this->assertFalse( $result->is_refused() );
		$this->assertSame( 0, $result->applied_count() );
		$this->assertCount( 1, $result->changed_since_dry_run );
	}

	public function test_wrong_actor_token_is_refused(): void {
		$this->wipe_translations();
		$importer = $this->importer();
		$prepared = $importer->plan( $this->decode() );

		// A token minted for a different user.
		$token = ( new ReviewToken( 'apply-test-key' ) )->mint( 99999, $this->decode()->package_id(), $this->decode()->package_checksum(), 'x' );

		$result = $importer->apply( $this->raw, $token, $prepared['plan']->to_array(), ApplyMode::SAFE_ONLY );

		$this->assertTrue( $result->is_refused() );
		$this->assertStringContainsString( 'token', $result->refused_code );
	}

	public function test_force_overwrites_a_conflict_and_clears_axes(): void {
		// Local translation edited on PROD, no baseline ⇒ conflict_target_modified.
		$this->write( 'Blue Widget', 'Prod-redigerad' );
		$importer = $this->importer();
		$prepared = $importer->plan( $this->decode() );

		$this->assertArrayHasKey( ImportCategory::CONFLICT_TARGET, $prepared['plan']->counts() );

		$result = $importer->apply( $this->raw, $prepared['token'], $prepared['plan']->to_array(), ApplyMode::FORCE );

		$this->assertFalse( $result->is_refused() );
		$this->assertSame( 1, $result->applied_count() );

		$row = ( new Store( $this->cache ) )->get( 'post', $this->post_id, $this->sv_id, 'post_title' );
		$this->assertSame( 'Blå pryl', (string) $row->translated_text );
	}

	private function importer(): TranslationImportService {
		wp_cache_flush();
		$identities = new ObjectIdentityRepository();

		return new TranslationImportService(
			new Store( $this->cache ),
			$this->languages,
			new ObjectIdentityResolver( $identities ),
			new PromotionStateRepository(),
			$identities,
			new PromotionLogRepository(),
			new PromotionAudit(),
			new Settings(),
			new TranslationConflictDetector(),
			new ReviewToken( 'apply-test-key' )
		);
	}

	private function decode(): \AIMultilingual\Promotion\TranslationPackage {
		return ( new JsonPackageSerializer() )->deserialize( $this->raw, 26214400 );
	}

	private function wipe_translations(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'aiml_translations' ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
	}

	/**
	 * Writes one translated title segment.
	 *
	 * @param string $source Source text.
	 * @param string $target Translated text.
	 */
	private function write( string $source, string $target ): void {
		( new Store( $this->cache ) )->save_translation(
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
}
