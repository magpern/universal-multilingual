<?php
/**
 * AIT1 WP10 — Elementor safety: an AI job changes only allowlisted control
 * values and never rewrites _elementor_data.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\BlockRegistry;
use AIMultilingual\Elementor\Contract;
use AIMultilingual\Elementor\ElementorControlRegistry;
use AIMultilingual\Elementor\ElementorDocumentDetector;
use AIMultilingual\Elementor\ElementorExtractor;
use AIMultilingual\Elementor\ElementorIdentity;
use AIMultilingual\Elementor\ElementorOverlayApplier;
use AIMultilingual\Elementor\ElementorOverlayResolver;
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
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\SegmentAssembler;
use AIMultilingual\Workspace\TranslationService;
use WP_Error;

/**
 * Extract → AI job → persist → overlay roundtrip for an Elementor page.
 */
final class ElementorAiTranslationRoundtripTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationWorker $worker;

	private BackgroundTranslationJobService $jobs;

	private SegmentAssembler $assembler;

	protected function setUp(): void {
		parent::setUp();

		$extractor       = new Extractor(
			new Settings( array( 'elementor_extraction_enabled' => true ) ),
			null,
			new ElementorExtractor(
				new ElementorDocumentDetector(),
				new ElementorControlRegistry(),
				new ElementorIdentity()
			)
		);
		$this->assembler = new SegmentAssembler( $extractor, $this->store, new BlockRegistry() );

		$glossary    = new GlossaryService(
			new GlossaryRepository(),
			new GlossaryNormalizer(),
			new GlossaryMatcher( new GlossaryNormalizer() )
		);
		$translation = new TranslationService(
			$this->store,
			$this->assembler,
			$this->languages,
			new EchoAIProvider(),
			null,
			null,
			$glossary
		);

		$jobsRepo     = new BackgroundTranslationJobRepository();
		$items        = new BackgroundTranslationItemRepository();
		$leases       = new JobLeaseService( $jobsRepo, $items );
		$recon        = new JobProgressReconciler( $jobsRepo, $items );
		$this->jobs   = new BackgroundTranslationJobService(
			$jobsRepo,
			$items,
			$leases,
			$recon,
			$this->store,
			$this->assembler
		);
		$processor    = new BackgroundTranslationItemProcessor(
			$this->store,
			$translation,
			$glossary,
			$this->assembler
		);
		$this->worker = new BackgroundTranslationWorker( $processor, $this->jobs, $jobsRepo, $items, $leases, $recon );
	}

	/**
	 * @return array{0: int, 1: string} Post id and the raw _elementor_data string.
	 */
	private function seed_page(): array {
		$data = array(
			array(
				'id'       => 'sec1',
				'elType'   => 'section',
				'settings' => array( 'background_color' => '#ffffff' ),
				'elements' => array(
					array(
						'id'         => 'hd1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title'       => 'Hello Elementor',
							'header_size' => 'h2',
							'link'        => array( 'url' => 'https://example.test/x' ),
						),
						'elements'   => array(),
					),
					array(
						'id'         => 'btn1',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array(
							'text'        => 'Buy now',
							'button_type' => 'success',
						),
						'elements'   => array(),
					),
					array(
						'id'         => 'raw1',
						'elType'     => 'widget',
						'widgetType' => 'html',
						'settings'   => array( 'html' => '<div>[my_shortcode] Untranslatable raw markup</div>' ),
						'elements'   => array(),
					),
				),
			),
		);

		$post_id = (int) self::factory()->post->create(
			array(
				'post_title'   => 'Elementor AI Roundtrip',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- elementor -->',
			)
		);
		$raw     = (string) wp_json_encode( $data );
		update_post_meta( $post_id, Contract::META_DATA, $raw );
		update_post_meta( $post_id, Contract::META_EDIT_MODE, 'builder' );

		return array( $post_id, (string) get_post_meta( $post_id, Contract::META_DATA, true ) );
	}

	public function test_ai_job_translates_only_allowlisted_controls_and_leaves_elementor_data_untouched(): void {
		[ $post_id, $before ] = $this->seed_page();
		$language             = $this->add_language( 'sv', 'sv_SE', 'published' );
		$language_id          = (int) $language->language_id;

		$job = $this->jobs->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_MISSING,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => $post_id,
				'language_id'    => $language_id,
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $job, $job instanceof WP_Error ? $job->get_error_message() : '' );

		$run = $this->worker->run( (int) $job->job_id );
		$this->assertNotInstanceOf( WP_Error::class, $run );
		$this->assertContains( (string) $run->status, array( JobStatuses::COMPLETED, JobStatuses::COMPLETED_WITH_ERRORS ) );

		// Allowlisted controls were translated.
		$heading = $this->store->get( Store::SOURCE_POST, $post_id, $language_id, 'e:d:' . $post_id . ':hd1:title' );
		$button  = $this->store->get( Store::SOURCE_POST, $post_id, $language_id, 'e:d:' . $post_id . ':btn1:text' );
		$this->assertNotNull( $heading );
		$this->assertSame( 'SV: Hello Elementor', (string) $heading->translated_text );
		$this->assertNotNull( $button );
		$this->assertSame( 'SV: Buy now', (string) $button->translated_text );

		// The raw html widget is never a segment → never translated.
		$this->assertNull(
			$this->store->get( Store::SOURCE_POST, $post_id, $language_id, 'e:d:' . $post_id . ':raw1:html' )
		);

		// _elementor_data is byte-identical: structure, ids, links, styles intact.
		$after = (string) get_post_meta( $post_id, Contract::META_DATA, true );
		$this->assertSame( $before, $after, '_elementor_data must not be rewritten by an AI job' );

		$decoded = json_decode( $after, true );
		$this->assertSame( 'sec1', $decoded[0]['id'] );
		$this->assertSame( 'hd1', $decoded[0]['elements'][0]['id'] );
		$this->assertSame( 'Hello Elementor', $decoded[0]['elements'][0]['settings']['title'], 'source control value unchanged in the document' );
		$this->assertSame( 'https://example.test/x', $decoded[0]['elements'][0]['settings']['link']['url'] );
		$this->assertSame( '<div>[my_shortcode] Untranslatable raw markup</div>', $decoded[0]['elements'][2]['settings']['html'] );
	}

	public function test_visitor_render_applies_the_translated_control_values(): void {
		[ $post_id ] = $this->seed_page();
		$language    = $this->add_language( 'sv', 'sv_SE', 'published' );
		$language_id = (int) $language->language_id;

		$job = $this->jobs->create_job(
			array(
				'job_type'       => JobTypes::TRANSLATE_MISSING,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => $post_id,
				'language_id'    => $language_id,
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
			)
		);
		$this->worker->run( (int) $job->job_id );

		$extractor = new ElementorExtractor(
			new ElementorDocumentDetector(),
			new ElementorControlRegistry(),
			new ElementorIdentity()
		);
		$units     = $extractor->extract( $post_id );
		$overlays  = ( new ElementorOverlayResolver( $this->store ) )->resolve( $post_id, $language_id, $units );
		$this->assertNotEmpty( $overlays );

		$nodes   = ( new ElementorDocumentDetector() )->decode_elements( $post_id );
		$applied = ( new ElementorOverlayApplier( new ElementorControlRegistry() ) )->apply( (array) $nodes, $overlays, $units );

		$this->assertSame( 'SV: Hello Elementor', $applied[0]['elements'][0]['settings']['title'] );
		$this->assertSame( 'SV: Buy now', $applied[0]['elements'][1]['settings']['text'] );
		// Structure and ids in the in-memory copy are unchanged.
		$this->assertSame( 'hd1', $applied[0]['elements'][0]['id'] );
		$this->assertSame( '<div>[my_shortcode] Untranslatable raw markup</div>', $applied[0]['elements'][2]['settings']['html'] );
	}
}
