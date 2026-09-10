<?php
/**
 * Opt-in live OpenAI end-to-end translation test.
 *
 * Skipped unless the environment provides a real key:
 *   AIML_LIVE_OPENAI_API_KEY=sk-...  [AIML_LIVE_OPENAI_MODEL=gpt-5-mini] \
 *   vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *     --filter LiveOpenAiTranslationTest --group external-http
 *
 * This is the "use a real provider for at least one test" gate. It makes real
 * paid HTTP calls, so it is in the `external-http` group (excluded by the WP
 * test suite by default) and never runs in CI.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\AdapterRegistry;
use AIMultilingual\Block\BlockExtractionLogger;
use AIMultilingual\Block\BlockRegistry;
use AIMultilingual\Glossary\GlossaryMatcher;
use AIMultilingual\Glossary\GlossaryNormalizer;
use AIMultilingual\Glossary\GlossaryRepository;
use AIMultilingual\Glossary\GlossaryService;
use AIMultilingual\Jobs\BackgroundTranslationItemProcessor;
use AIMultilingual\Jobs\BackgroundTranslationItemRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\BackgroundTranslationJobService;
use AIMultilingual\Jobs\BackgroundTranslationWorker;
use AIMultilingual\Jobs\JobLeaseService;
use AIMultilingual\Jobs\JobProgressReconciler;
use AIMultilingual\Jobs\JobStatuses;
use AIMultilingual\Jobs\JobTypes;
use AIMultilingual\Settings;
use AIMultilingual\Translation\AI\OpenAIProvider;
use AIMultilingual\Translation\AI\PromptProfileRegistry;
use AIMultilingual\Translation\BlockExtractor;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\TranslationService;
use WP_Error;

/**
 * @group external-http
 */
final class LiveOpenAiTranslationTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	public function test_translate_missing_produces_a_real_translation(): void {
		$key = (string) getenv( 'AIML_LIVE_OPENAI_API_KEY' );
		if ( '' === $key ) {
			$this->markTestSkipped( 'Set AIML_LIVE_OPENAI_API_KEY to run the live provider test.' );
		}
		$model = (string) ( getenv( 'AIML_LIVE_OPENAI_MODEL' ) ?: 'gpt-5-mini' );

		$this->enable_strategy_f_flags();

		$adapters    = new AdapterRegistry();
		$blocks      = new BlockRegistry( $adapters );
		$extractor   = new Extractor(
			new Settings(),
			new BlockExtractor( $adapters, $blocks, new BlockExtractionLogger() )
		);
		$assembler   = new SegmentAssembler( $extractor, $this->store, $blocks );
		$glossary    = new GlossaryService(
			new GlossaryRepository(),
			new GlossaryNormalizer(),
			new GlossaryMatcher( new GlossaryNormalizer() )
		);
		$provider    = new OpenAIProvider( $key, $model, new PromptProfileRegistry(), null, 0.2, 0 );
		$translation = new TranslationService( $this->store, $assembler, $this->languages, $provider, null, null, $glossary );

		$jobs_repo   = new BackgroundTranslationJobRepository();
		$items_repo  = new BackgroundTranslationItemRepository();
		$leases      = new JobLeaseService( $jobs_repo, $items_repo );
		$reconciler  = new JobProgressReconciler( $jobs_repo, $items_repo );
		$job_service = new BackgroundTranslationJobService( $jobs_repo, $items_repo, $leases, $reconciler, $this->store, $assembler );
		$processor   = new BackgroundTranslationItemProcessor( $this->store, $translation, $glossary, $assembler );
		$worker      = new BackgroundTranslationWorker( $processor, $job_service, $jobs_repo, $items_repo, $leases, $reconciler );

		$language = $this->add_language();
		$source   = 'The peptide is studied for its role in neuroendocrine pathways and receptor binding.';
		$post     = $this->create_page( 'Live provider probe', '<p>' . $source . '</p>' );

		$job = $job_service->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_MISSING,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => (int) $language->language_id,
				'provider_id'    => OpenAIProvider::ID,
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job );

		$result = $worker->run( (int) $job->job_id, 'live-openai' );
		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( JobStatuses::COMPLETED, $result->status );
		$this->assertSame( 0, (int) $result->failed_items );

		$rows    = $this->store->load_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$content = null;
		foreach ( $rows as $row ) {
			if ( str_contains( (string) $row->field_key, 'content' ) || 'content' === (string) $row->field_key ) {
				$content = $row;
			}
		}
		$this->assertNotNull( $content, 'the paragraph segment must be translated' );

		$target = trim( wp_strip_all_tags( (string) $content->translated_text ) );
		$this->assertNotSame( '', $target, 'a live translation must not be empty' );
		$this->assertNotSame(
			trim( wp_strip_all_tags( $source ) ),
			$target,
			'a live translation must differ from the English source'
		);
		$this->assertStringNotContainsString(
			'[' . $language->locale . ']',
			$target,
			'a live translation must not carry the acceptance-fake locale marker'
		);
	}
}
