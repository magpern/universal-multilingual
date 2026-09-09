<?php
/**
 * AIT1 WP2 — page-level "Translate with AI" job orchestration.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\AdapterRegistry;
use AIMultilingual\Block\BlockExtractionLogger;
use AIMultilingual\Block\BlockRegistry;
use AIMultilingual\Settings;
use AIMultilingual\Translation\BlockExtractor;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Jobs\BackgroundTranslationBatchCoordinator;
use AIMultilingual\Jobs\BackgroundTranslationItemProcessor;
use AIMultilingual\Jobs\BackgroundTranslationItemRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobService;
use AIMultilingual\Jobs\BackgroundTranslationWorker;
use AIMultilingual\Jobs\JobLeaseService;
use AIMultilingual\Jobs\JobProgressReconciler;
use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Jobs\JobsController;
use AIMultilingual\Jobs\JobStatuses;
use AIMultilingual\Jobs\JobTypes;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\TranslationService;
use WP_Error;
use WP_REST_Request;

/**
 * The product path: create a job for a page, autostart it, stay idempotent.
 */
final class PageAiTranslateJobTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationJobRepository $job_repo;

	private RecordingJobsScheduler $scheduler;

	private JobsController $controller;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/RecordingJobsScheduler.php';
		$this->enable_strategy_f_flags();
		JobsCapabilities::grant_default_roles();

		$this->job_repo  = new BackgroundTranslationJobRepository();
		$items           = new BackgroundTranslationItemRepository();
		$leases          = new JobLeaseService( $this->job_repo, $items );
		$reconciler      = new JobProgressReconciler( $this->job_repo, $items );
		$this->scheduler = new RecordingJobsScheduler();

		$adapters    = new AdapterRegistry();
		$blocks      = new BlockRegistry( $adapters );
		$extractor   = new Extractor(
			new Settings(),
			new BlockExtractor( $adapters, $blocks, new BlockExtractionLogger() )
		);
		$assembler   = new SegmentAssembler( $extractor, $this->store, $blocks );
		$translation = new TranslationService(
			$this->store,
			$assembler,
			$this->languages,
			new EchoAIProvider()
		);
		$jobs        = new BackgroundTranslationJobService(
			$this->job_repo,
			$items,
			$leases,
			$reconciler,
			$this->store,
			$assembler,
			$this->scheduler
		);
		$processor   = new BackgroundTranslationItemProcessor(
			$this->store,
			$translation,
			$this->make_glossary(),
			$assembler
		);
		$worker      = new BackgroundTranslationWorker( $processor, $jobs, $this->job_repo, $items, $leases, $reconciler );
		$batches     = new BackgroundTranslationBatchCoordinator( $jobs, $this->job_repo, $this->scheduler );

		$this->controller = new JobsController( $jobs, $batches, $this->scheduler, $worker, $this->languages );
	}

	private function make_glossary(): \AIMultilingual\Glossary\GlossaryService {
		return new \AIMultilingual\Glossary\GlossaryService(
			new \AIMultilingual\Glossary\GlossaryRepository(),
			new \AIMultilingual\Glossary\GlossaryNormalizer(),
			new \AIMultilingual\Glossary\GlossaryMatcher( new \AIMultilingual\Glossary\GlossaryNormalizer() )
		);
	}

	/**
	 * @param array<string, mixed> $overrides Body overrides.
	 */
	private function create( array $overrides ): array {
		$request = new WP_REST_Request( 'POST', '/aiml/v1/jobs' );
		$request->set_body_params(
			array_merge(
				array(
					'source_type'    => Store::SOURCE_POST,
					'prompt_profile' => 'default',
					'prompt_version' => '1',
				),
				$overrides
			)
		);

		$result = $this->controller->create_job( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result->get_data();
	}

	public function test_translate_missing_page_job_autostarts(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		wp_set_current_user( $this->create_translator() );

		$job = $this->create(
			array(
				'job_type'     => JobTypes::TRANSLATE_MISSING,
				'source_id'    => (int) $post->ID,
				'language_id'  => (int) $language->language_id,
				'client_token' => 'tok-1',
				'autostart'    => true,
			)
		);

		$this->assertSame( JobStatuses::QUEUED, $job['status'] );
		$this->assertContains( (int) $job['job_id'], $this->scheduler->enqueued, 'autostart must enqueue the job' );
	}

	public function test_no_autostart_flag_leaves_job_queued_and_not_enqueued(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
		wp_set_current_user( $this->create_translator() );

		$job = $this->create(
			array(
				'job_type'    => JobTypes::TRANSLATE_MISSING,
				'source_id'   => (int) $post->ID,
				'language_id' => (int) $language->language_id,
			)
		);

		$this->assertSame( JobStatuses::QUEUED, $job['status'] );
		$this->assertNotContains( (int) $job['job_id'], $this->scheduler->enqueued );
	}

	public function test_double_submit_same_token_is_one_job(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' );
		wp_set_current_user( $this->create_translator() );

		$body = array(
			'job_type'     => JobTypes::TRANSLATE_MISSING,
			'source_id'    => (int) $post->ID,
			'language_id'  => (int) $language->language_id,
			'client_token' => 'tok-double',
			'autostart'    => true,
		);

		$first  = $this->create( $body );
		$second = $this->create( $body );

		$this->assertSame( (int) $first['job_id'], (int) $second['job_id'], 'same client_token collapses to one job' );
	}

	public function test_fresh_token_after_completion_creates_new_job(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page( 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' );
		wp_set_current_user( $this->create_translator() );

		$first = $this->create(
			array(
				'job_type'     => JobTypes::TRANSLATE_MISSING,
				'source_id'    => (int) $post->ID,
				'language_id'  => (int) $language->language_id,
				'client_token' => 'tok-a',
				'autostart'    => true,
			)
		);

		// Drive the job to a terminal state, as a real worker would.
		$this->controller_run( (int) $first['job_id'] );

		// A deliberate re-run with a fresh token is new work, not the old job.
		$second = $this->create(
			array(
				'job_type'     => JobTypes::RETRANSLATE_MACHINE,
				'source_id'    => (int) $post->ID,
				'language_id'  => (int) $language->language_id,
				'client_token' => 'tok-b',
				'autostart'    => true,
			)
		);

		$this->assertNotSame( (int) $first['job_id'], (int) $second['job_id'] );
	}

	private function controller_run( int $job_id ): void {
		$request = new WP_REST_Request( 'POST', '/aiml/v1/jobs/' . $job_id . '/run' );
		$request->set_param( 'id', $job_id );
		$request->set_param( 'sync', true );
		$result = $this->controller->run_job( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$data = $result->get_data();
		$this->assertContains(
			(string) ( $data['status'] ?? '' ),
			array( JobStatuses::COMPLETED, JobStatuses::COMPLETED_WITH_ERRORS ),
			'seed job must finish: ' . wp_json_encode( $data )
		);
	}

	public function test_retranslate_machine_page_job_never_overwrites_a_manual_segment(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page( 'dddddddd-dddd-4ddd-8ddd-dddddddddddd' );
		wp_set_current_user( $this->create_translator() );

		// Establish a real machine translation for every segment, then a human
		// takes ownership of the paragraph.
		$seed = $this->create(
			array(
				'job_type'     => JobTypes::TRANSLATE_MISSING,
				'source_id'    => (int) $post->ID,
				'language_id'  => (int) $language->language_id,
				'client_token' => 'seed',
				'autostart'    => true,
			)
		);
		$this->controller_run( (int) $seed['job_id'] );

		$content_key = null;
		foreach ( ( new BackgroundTranslationItemRepository() )->list_by_job( (int) $seed['job_id'] ) as $item ) {
			if ( str_starts_with( (string) $item->segment_key, 'b:' ) ) {
				$content_key = (string) $item->segment_key;
			}
		}
		$this->assertNotNull( $content_key, 'seed job should have translated a block segment' );
		$key = $content_key;

		$existing = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, $key );
		$this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => 'page',
				'language_id'     => (int) $language->language_id,
				'field_key'       => (string) $existing->field_key,
				'segment_key'     => $key,
				'segment_kind'    => (string) $existing->segment_kind,
				'segment_order'   => (int) $existing->segment_order,
				'text_format'     => (string) $existing->text_format,
				'source_text'     => (string) $existing->source_text,
				'translated_text' => 'Manuellt skriven',
				'status'          => Store::STATUS_MANUALLY_EDITED,
			)
		);

		$job = $this->create(
			array(
				'job_type'     => JobTypes::RETRANSLATE_MACHINE,
				'source_id'    => (int) $post->ID,
				'language_id'  => (int) $language->language_id,
				'client_token' => 'retro',
				'autostart'    => true,
			)
		);

		$items        = ( new BackgroundTranslationItemRepository() )->list_by_job( (int) $job['job_id'] );
		$materialised = array_map( static fn( $i ): string => (string) $i->segment_key, $items );
		$this->assertNotContains( $key, $materialised, 'a manually edited segment is never materialised' );

		$this->controller_run( (int) $job['job_id'] );

		$row = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, $key );
		$this->assertSame( 'Manuellt skriven', (string) $row->translated_text );
		$this->assertSame( Store::STATUS_MANUALLY_EDITED, (string) $row->status );
	}
}
