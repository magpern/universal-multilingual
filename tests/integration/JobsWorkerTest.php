<?php
/**
 * Background Translation Jobs J3 integration tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\AdapterRegistry;
use AIMultilingual\Block\BlockExtractionLogger;
use AIMultilingual\Block\BlockRegistry;
use AIMultilingual\Database\Schema;
use AIMultilingual\Glossary\GlossaryMatcher;
use AIMultilingual\Glossary\GlossaryNormalizer;
use AIMultilingual\Glossary\GlossaryRepository;
use AIMultilingual\Glossary\GlossaryService;
use AIMultilingual\Jobs\BackgroundTranslationItemProcessor;
use AIMultilingual\Jobs\BackgroundTranslationItemRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobService;
use AIMultilingual\Jobs\BackgroundTranslationWorker;
use AIMultilingual\Jobs\ItemStatuses;
use AIMultilingual\Jobs\JobLeaseService;
use AIMultilingual\Jobs\JobProgressReconciler;
use AIMultilingual\Jobs\JobStatuses;
use AIMultilingual\Jobs\JobTypes;
use AIMultilingual\Settings;
use AIMultilingual\Translation\BlockExtractor;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\TranslationService;
use WP_Error;

/**
 * J3 worker, processor, scheduler integration coverage.
 */
final class JobsWorkerTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationJobService $job_service;

	private BackgroundTranslationWorker $worker;

	private RecordingJobsScheduler $scheduler;

	private BackgroundTranslationItemRepository $items;

	private BackgroundTranslationJobRepository $jobs;

	private JobLeaseService $leases;

	private Store $job_store;

	protected function setUp(): void {
		parent::setUp();
		$this->enable_strategy_f_flags();

		$settings         = new Settings();
		$adapter_registry = new AdapterRegistry();
		$block_registry   = new BlockRegistry( $adapter_registry );
		$extractor        = new Extractor(
			$settings,
			new BlockExtractor(
				$adapter_registry,
				$block_registry,
				new BlockExtractionLogger()
			)
		);
		$this->job_store  = $this->store;
		$assembler        = new SegmentAssembler( $extractor, $this->job_store, $block_registry );
		$glossary         = new GlossaryService(
			new GlossaryRepository(),
			new GlossaryNormalizer(),
			new GlossaryMatcher( new GlossaryNormalizer() )
		);
		$translation      = new TranslationService(
			$this->job_store,
			$assembler,
			$this->languages,
			new EchoAIProvider(),
			null,
			null,
			$glossary
		);
		$processor        = new BackgroundTranslationItemProcessor(
			$this->job_store,
			$translation,
			$glossary,
			$assembler
		);

		$this->jobs        = new BackgroundTranslationJobRepository();
		$this->items       = new BackgroundTranslationItemRepository();
		$this->leases      = new JobLeaseService( $this->jobs, $this->items );
		$reconciler        = new JobProgressReconciler( $this->jobs, $this->items );
		$this->job_service = new BackgroundTranslationJobService(
			$this->jobs,
			$this->items,
			$this->leases,
			$reconciler,
			$this->job_store,
			$assembler
		);
		require_once __DIR__ . '/RecordingJobsScheduler.php';
		$this->scheduler = new RecordingJobsScheduler();
		$this->worker    = new BackgroundTranslationWorker(
			$processor,
			$this->job_service,
			$this->jobs,
			$this->items,
			$this->leases,
			$reconciler,
			null,
			null,
			$this->scheduler
		);
	}

	public function test_processor_completes_missing_segment(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$key      = $this->default_segment_key();

		$job = $this->job_service->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_SELECTED,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => (int) $language->language_id,
				'segment_keys'   => array( $key ),
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$result = $this->worker->run( (int) $job->job_id, 'worker-a' );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( JobStatuses::COMPLETED, $result->status );

		$row = $this->job_store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, $key );
		$this->assertNotNull( $row );
		$this->assertSame( Store::STATUS_MACHINE_TRANSLATED, $row->status );
		$this->assertSame( Store::REVIEW_NOT_SUBMITTED, $row->review_status );
		$this->assertStringStartsWith( 'SV:', (string) $row->translated_text );
	}

	public function test_worker_reschedules_next_wake_when_claimable_items_remain(): void {
		$language = $this->add_language();

		$blocks = '';
		for ( $i = 1; $i <= 14; $i++ ) {
			$uuid    = sprintf( '550e8400-e29b-41d4-a716-4466554400%02d', $i );
			$blocks .= sprintf(
				'<!-- wp:paragraph {"%1$s":"%2$s"} --><p>Paragraph number %3$d.</p><!-- /wp:paragraph -->',
				\AIMultilingual\Block\Contract::ATTR_NAME,
				$uuid,
				$i
			);
		}
		$post = $this->create_page( 'Many segments page', $blocks );

		$job = $this->job_service->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_MISSING,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => (int) $language->language_id,
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );
		$job_id = (int) $job->job_id;
		$this->assertGreaterThan( 10, (int) $job->total_items, 'fixture must exceed one bounded wake' );

		// First bounded wake: stops at MAX_ITEMS_PER_WAKE and must arm the next.
		$after_first = $this->worker->run( $job_id, 'wake-1' );
		$this->assertNotInstanceOf( WP_Error::class, $after_first );
		$this->assertSame( JobStatuses::RUNNING, $after_first->status );
		$this->assertSame( 10, (int) $after_first->completed_items );
		$this->assertContains(
			$job_id,
			$this->scheduler->enqueued,
			'worker must re-enqueue its own wake while claimable items remain'
		);

		// Second wake drains the rest and finalises.
		$after_second = $this->worker->run( $job_id, 'wake-2' );
		$this->assertNotInstanceOf( WP_Error::class, $after_second );
		$this->assertSame( JobStatuses::COMPLETED, $after_second->status );
		$this->assertSame( (int) $job->total_items, (int) $after_second->completed_items );
	}

	public function test_pending_review_segment_skips_conflict(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$key      = $this->default_segment_key();

		$this->job_store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => 'page',
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'content',
				'segment_key'     => $key,
				'segment_kind'    => Store::KIND_BLOCK,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_HTML,
				'source_text'     => 'Hello workspace',
				'translated_text' => 'Human pending',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
			)
		);
		$this->job_store->update_review_metadata(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			$key,
			array(
				'review_status' => Store::REVIEW_PENDING,
			)
		);

		$job = $this->job_service->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_SELECTED,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => (int) $language->language_id,
				'segment_keys'   => array( $key ),
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$result = $this->worker->run( (int) $job->job_id, 'worker-b' );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( JobStatuses::FAILED, $result->status );

		$item = $this->items->list_by_job( (int) $job->job_id )[0];
		$this->assertSame( ItemStatuses::SKIPPED_CONFLICT, $item->status );
	}

	public function test_stale_source_hash_marks_item_stale(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$key      = $this->default_segment_key();

		$job = $this->job_service->create_job(
			array(
				'job_type'          => JobTypes::TRANSLATE_SELECTED,
				'source_type'       => Store::SOURCE_POST,
				'source_id'         => (int) $post->ID,
				'language_id'       => (int) $language->language_id,
				'segment_keys'      => array( $key ),
				'segment_snapshots' => array(
					$key => array(
						'source_hash_captured' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
					),
				),
				'provider_id'       => 'echo',
				'prompt_profile'    => 'default',
				'prompt_version'    => '1',
				'created_by'        => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$result = $this->worker->run( (int) $job->job_id, 'worker-c' );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		$item = $this->items->list_by_job( (int) $job->job_id )[0];
		$this->assertSame( ItemStatuses::STALE_SOURCE, $item->status );
	}

	public function test_worker_does_not_write_translation_memory(): void {
		global $wpdb;

		$language = $this->add_language();
		$post     = $this->create_block_page();
		$key      = $this->default_segment_key();

		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB

		$job = $this->job_service->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_SELECTED,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => (int) $language->language_id,
				'segment_keys'   => array( $key ),
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$this->worker->run( (int) $job->job_id, 'worker-d' );

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB
		$this->assertSame( $before, $after );
		unset( $wpdb );
	}

	public function test_duplicate_callback_while_lease_held(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$key      = $this->default_segment_key();

		$job = $this->job_service->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_SELECTED,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => (int) $language->language_id,
				'segment_keys'   => array( $key ),
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$claim = $this->leases->claim( (int) $job->job_id, 'holder-token', 300 );
		$this->assertNotInstanceOf( WP_Error::class, $claim );

		$duplicate = $this->worker->run( (int) $job->job_id, 'other-token' );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'lease_held', $duplicate->get_error_code() );
	}
}
