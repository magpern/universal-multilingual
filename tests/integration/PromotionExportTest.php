<?php
/**
 * TranslationExportService — building a package from the local site (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Promotion\ExportFilters;
use AIMultilingual\Promotion\PromotionAudit;
use AIMultilingual\Promotion\TranslationExportService;
use AIMultilingual\Promotion\TranslationPackageValidator;

/**
 * Every exported object has a UUID; the package validates; filters narrow it.
 */
final class PromotionExportTest extends AimlTestCase {

	private TranslationExportService $exporter;
	private ObjectIdentityRepository $identities;
	private int $sv_id;

	protected function setUp(): void {
		parent::setUp();

		$sv               = $this->add_language( 'sv', 'sv_SE' );
		$this->sv_id      = (int) $sv->language_id;
		$this->identities = new ObjectIdentityRepository();
		$this->exporter   = new TranslationExportService(
			$this->store,
			$this->languages,
			$this->identities,
			new PromotionLogRepository(),
			new PromotionAudit()
		);
	}

	public function test_export_of_a_translated_post_produces_a_valid_package_with_a_uuid(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'blue-widget',
				'post_title' => 'Blue Widget',
			)
		);
		$this->save_segment( $post_id, 'post_title', 'Blue Widget', 'Blå pryl' );

		$result = $this->exporter->export( new ExportFilters( array( 'sv' ) ) );

		$this->assertTrue( $result->is_ok(), $result->message() );
		$package = $result->package();

		$validation = ( new TranslationPackageValidator() )->validate( $package );
		$this->assertTrue( $validation->is_valid(), implode( ' / ', $validation->messages() ) );

		$objects = $package->objects();
		$this->assertCount( 1, $objects );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $objects[0]->object_uuid );
		$this->assertSame( 'post|type=post|slug=blue-widget', $objects[0]->natural_key );
		$this->assertSame( 'Blå pryl', $objects[0]->segments[0]->translated_text );

		// The UUID was persisted on the source before serialization.
		$row = $this->identities->find_by_source( 'post', $post_id );
		$this->assertNotNull( $row );
		$this->assertSame( $objects[0]->object_uuid, (string) $row->uuid );
	}

	public function test_deterministic_across_two_runs(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'stable',
				'post_title' => 'Stable',
			)
		);
		$this->save_segment( $post_id, 'post_title', 'Stable', 'Stabil' );

		$a = $this->exporter->export( new ExportFilters( array( 'sv' ) ) )->package();
		$b = $this->exporter->export( new ExportFilters( array( 'sv' ) ) )->package();

		$this->assertSame( $a->payload_checksum(), $b->payload_checksum() );
		$this->assertNotSame( $a->package_id(), $b->package_id() );
	}

	public function test_empty_when_nothing_is_translated(): void {
		self::factory()->post->create(
			array(
				'post_type' => 'post',
				'post_name' => 'untranslated',
			)
		);

		$result = $this->exporter->export( new ExportFilters( array( 'sv' ) ) );

		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'nothing_to_export', $result->error_code() );
	}

	public function test_unknown_language_is_rejected(): void {
		$result = $this->exporter->export( new ExportFilters( array( 'zz' ) ) );

		$this->assertSame( 'no_target_languages', $result->error_code() );
	}

	public function test_approved_only_filter(): void {
		$approved = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'approved-post',
				'post_title' => 'Approved',
			)
		);
		$plain    = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'plain-post',
				'post_title' => 'Plain',
			)
		);
		$this->save_segment( $approved, 'post_title', 'Approved', 'Godkänd', 'reviewed' );
		$this->save_segment( $plain, 'post_title', 'Plain', 'Vanlig' );

		// Approve the first one's segment.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			$wpdb->prefix . 'aiml_translations',
			array( 'review_status' => 'approved' ),
			array(
				'source_id'   => $approved,
				'language_id' => $this->sv_id,
			)
		);
		$this->store = new \AIMultilingual\Translation\Store( $this->cache );

		$result  = $this->exporter->export( new ExportFilters( array( 'sv' ), array(), array(), array(), true ) );
		$objects = $result->package()->objects();

		$this->assertCount( 1, $objects );
		$this->assertSame( 'post|type=post|slug=approved-post', $objects[0]->natural_key );
	}

	public function test_promotion_log_records_the_export(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'logged',
				'post_title' => 'Logged',
			)
		);
		$this->save_segment( $post_id, 'post_title', 'Logged', 'Loggad' );

		$this->exporter->export( new ExportFilters( array( 'sv' ) ) );

		$rows = ( new PromotionLogRepository() )->recent( 5 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'export', (string) $rows[0]->direction );
		$this->assertSame( 'completed', (string) $rows[0]->result );
	}

	/**
	 * Writes one translated segment through the sanctioned Store path.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $field    Field key.
	 * @param string $source   Source text.
	 * @param string $target   Translated text.
	 * @param string $status   Provenance status.
	 */
	private function save_segment( int $post_id, string $field, string $source, string $target, string $status = 'manually_edited' ): void {
		$result = $this->store->save_translation(
			array(
				'source_type'     => 'post',
				'source_id'       => $post_id,
				'language_id'     => $this->sv_id,
				'field_key'       => $field,
				'segment_key'     => $field,
				'text_format'     => 'plain',
				'status'          => $status,
				'source_text'     => $source,
				'translated_text' => $target,
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}
}
