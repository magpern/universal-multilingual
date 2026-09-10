<?php
/**
 * AIT1 WP4 — bulk translation modes map to the right job type per post.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\AdapterRegistry;
use AIMultilingual\Block\BlockExtractionLogger;
use AIMultilingual\Block\BlockRegistry;
use AIMultilingual\Jobs\BackgroundTranslationBatchCoordinator;
use AIMultilingual\Jobs\BackgroundTranslationItemRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobService;
use AIMultilingual\Jobs\JobLeaseService;
use AIMultilingual\Jobs\JobProgressReconciler;
use AIMultilingual\Jobs\JobTypes;
use AIMultilingual\Settings;
use AIMultilingual\Translation\BlockExtractor;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;

/**
 * Site Translate / bulk coordinator honours the three-mode selector.
 */
final class BatchAiTranslateModeTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationBatchCoordinator $coordinator;

	private BackgroundTranslationJobRepository $jobs;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/RecordingJobsScheduler.php';
		$this->enable_strategy_f_flags();

		$adapters  = new AdapterRegistry();
		$blocks    = new BlockRegistry( $adapters );
		$extractor = new Extractor(
			new Settings(),
			new BlockExtractor( $adapters, $blocks, new BlockExtractionLogger() )
		);
		$assembler = new SegmentAssembler( $extractor, $this->store, $blocks );

		$this->jobs = new BackgroundTranslationJobRepository();
		$items      = new BackgroundTranslationItemRepository();
		$scheduler  = new RecordingJobsScheduler();
		$service    = new BackgroundTranslationJobService(
			$this->jobs,
			$items,
			new JobLeaseService( $this->jobs, $items ),
			new JobProgressReconciler( $this->jobs, $items ),
			$this->store,
			$assembler,
			$scheduler
		);

		$this->coordinator = new BackgroundTranslationBatchCoordinator(
			$service,
			$this->jobs,
			$scheduler
		);
	}

	/**
	 * @param string $uuid        Block uuid.
	 * @param int    $language_id Target language id.
	 */
	private function machine_page( string $uuid, int $language_id ): \WP_Post {
		$post = $this->create_block_page( $uuid );
		$key  = \AIMultilingual\Block\SegmentKey::build( $uuid, \AIMultilingual\Block\Contract::FIELD_CONTENT );
		$this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => 'page',
				'language_id'     => $language_id,
				'field_key'       => 'content',
				'segment_key'     => $key,
				'segment_kind'    => Store::KIND_BLOCK,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_HTML,
				'source_text'     => 'Hello workspace',
				'translated_text' => 'Hej arbetsyta',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
			)
		);

		return $post;
	}

	/**
	 * @param array<int, \WP_Post> $posts       Fixture pages.
	 * @param int                  $language_id Target language id.
	 * @param string               $mode        Requested job type (or '').
	 * @return array<string, mixed> The coordinator result.
	 */
	private function create_bulk( array $posts, int $language_id, string $mode ): array {
		$scope = array_map(
			static fn( \WP_Post $post ): array => array(
				'source_type'  => Store::SOURCE_POST,
				'source_id'    => (int) $post->ID,
				'segment_keys' => array(),
			),
			$posts
		);

		$shared = array(
			'source_type' => Store::SOURCE_POST,
			'force_new'   => true,
		);
		if ( '' !== $mode ) {
			$shared['job_type'] = $mode;
		}

		return (array) $this->coordinator->create_bulk_resilient( $scope, $language_id, $shared, null, true );
	}

	/**
	 * @param array<string, mixed> $result Coordinator result.
	 * @return array<int, string>
	 */
	private function job_types( array $result ): array {
		return array_map( static fn( $job ): string => (string) $job->job_type, $result['jobs'] );
	}

	public function test_default_bulk_is_translate_missing_semantics(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page( '10000000-0000-4000-8000-000000000001' );

		$this->assertSame(
			array( JobTypes::BULK_TRANSLATE ),
			$this->job_types( $this->create_bulk( array( $post ), (int) $language->language_id, '' ) )
		);
	}

	public function test_machine_mode_creates_retranslate_machine_jobs_that_materialise(): void {
		$language = $this->add_language();
		$a        = $this->machine_page( '20000000-0000-4000-8000-000000000001', (int) $language->language_id );
		$b        = $this->machine_page( '20000000-0000-4000-8000-000000000002', (int) $language->language_id );

		$result = $this->create_bulk( array( $a, $b ), (int) $language->language_id, JobTypes::RETRANSLATE_MACHINE );

		$this->assertSame(
			array( JobTypes::RETRANSLATE_MACHINE, JobTypes::RETRANSLATE_MACHINE ),
			$this->job_types( $result )
		);

		$items = new BackgroundTranslationItemRepository();
		foreach ( $result['jobs'] as $job ) {
			$this->assertNotEmpty(
				$items->list_by_job( (int) $job->job_id ),
				'a machine-mode bulk job must materialise the machine segment'
			);
		}
	}

	public function test_translate_selected_is_rejected_as_a_bulk_type(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page( '30000000-0000-4000-8000-000000000001' );

		$this->assertSame(
			array( JobTypes::BULK_TRANSLATE ),
			$this->job_types(
				$this->create_bulk( array( $post ), (int) $language->language_id, JobTypes::TRANSLATE_SELECTED )
			)
		);
	}
}
