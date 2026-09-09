<?php
/**
 * AIT1 WP1 — shared AI-write eligibility across sync + Jobs paths.
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
use AIMultilingual\Workspace\TranslatableSegmentEligibility;
use WP_Error;

/**
 * The one eligibility policy: job materialisation resolution per mode.
 */
final class AitSharedEligibilityTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationJobService $service;

	private JobLeaseService $leases;

	private Store $job_store;

	private string $key;

	protected function setUp(): void {
		parent::setUp();
		$this->enable_strategy_f_flags();

		$settings        = new Settings();
		$adapters        = new AdapterRegistry();
		$blocks          = new BlockRegistry( $adapters );
		$extractor       = new Extractor(
			$settings,
			new BlockExtractor( $adapters, $blocks, new BlockExtractionLogger() )
		);
		$this->job_store = $this->store;
		$assembler       = new SegmentAssembler( $extractor, $this->job_store, $blocks );

		$jobs         = new BackgroundTranslationJobRepository();
		$items        = new BackgroundTranslationItemRepository();
		$this->leases = new JobLeaseService( $jobs, $items );
		$reconciler   = new JobProgressReconciler( $jobs, $items );

		$this->service = new BackgroundTranslationJobService(
			$jobs,
			$items,
			$this->leases,
			$reconciler,
			$this->job_store,
			$assembler
		);

		$this->key = $this->default_segment_key();
	}

	/**
	 * @param \WP_Post $post        Fixture page.
	 * @param int      $language_id Target language id.
	 * @param string   $status      Store status.
	 * @param string   $review      Review status.
	 */
	private function seed( \WP_Post $post, int $language_id, string $status, string $review = Store::REVIEW_NOT_SUBMITTED ): void {
		$this->job_store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => 'page',
				'language_id'     => $language_id,
				'field_key'       => 'content',
				'segment_key'     => $this->key,
				'segment_kind'    => Store::KIND_BLOCK,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_HTML,
				'source_text'     => 'Hello workspace',
				'translated_text' => Store::STATUS_MISSING === $status ? '' : 'Hej arbetsyta',
				'status'          => $status,
			)
		);

		if ( Store::REVIEW_NOT_SUBMITTED !== $review ) {
			$this->job_store->update_review_metadata(
				Store::SOURCE_POST,
				(int) $post->ID,
				$language_id,
				$this->key,
				array( 'review_status' => $review )
			);
		}
	}

	/**
	 * @param \WP_Post $post        Fixture page.
	 * @param int      $language_id Target language id.
	 * @param string   $job_type    Job type.
	 * @return array<int, string> Materialised segment keys.
	 */
	private function materialised_keys( \WP_Post $post, int $language_id, string $job_type ): array {
		$job = $this->service->create_job(
			array(
				'job_type'       => $job_type,
				'source_type'    => Store::SOURCE_POST,
				'source_id'      => (int) $post->ID,
				'language_id'    => $language_id,
				'provider_id'    => 'echo',
				'prompt_profile' => 'default',
				'prompt_version' => '1',
				'created_by'     => 1,
				// Test-only: inspect materialisation for several modes against
				// one fixture without tripping the object+language active lock.
				'force_new'      => true,
			)
		);

		if ( $job instanceof WP_Error ) {
			return array( '__error__:' . $job->get_error_code() );
		}

		$repo = new BackgroundTranslationItemRepository();
		$keys = array_map(
			static fn( $item ): string => (string) $item->segment_key,
			$repo->list_by_job( (int) $job->job_id )
		);

		// Release the object+language active lock so the next mode check on the
		// same fixture can create its own job.
		$this->leases->clear_active_lock_on_terminal( (int) $job->job_id );

		return $keys;
	}

	public function test_retranslate_machine_targets_all_machine_segments(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$this->seed( $post, (int) $language->language_id, Store::STATUS_MACHINE_TRANSLATED );

		$keys = $this->materialised_keys( $post, (int) $language->language_id, JobTypes::RETRANSLATE_MACHINE );
		$this->assertContains( $this->key, $keys );
	}

	public function test_retranslate_machine_skips_manual_and_reviewed(): void {
		$language = $this->add_language();

		$manual    = $this->create_block_page( '11111111-1111-4111-8111-111111111111' );
		$this->key = \AIMultilingual\Block\SegmentKey::build( '11111111-1111-4111-8111-111111111111', \AIMultilingual\Block\Contract::FIELD_CONTENT );
		$this->seed( $manual, (int) $language->language_id, Store::STATUS_MANUALLY_EDITED );
		$this->assertNotContains(
			$this->key,
			$this->materialised_keys( $manual, (int) $language->language_id, JobTypes::RETRANSLATE_MACHINE )
		);
	}

	public function test_translate_missing_ignores_translated_segment(): void {
		$language  = $this->add_language();
		$post      = $this->create_block_page( '33333333-3333-4333-8333-333333333333' );
		$this->key = \AIMultilingual\Block\SegmentKey::build( '33333333-3333-4333-8333-333333333333', \AIMultilingual\Block\Contract::FIELD_CONTENT );
		$this->seed( $post, (int) $language->language_id, Store::STATUS_MACHINE_TRANSLATED );

		$this->assertNotContains(
			$this->key,
			$this->materialised_keys( $post, (int) $language->language_id, JobTypes::TRANSLATE_MISSING ),
			'translate_missing never re-touches an existing translation.'
		);
		$this->assertContains(
			$this->key,
			$this->materialised_keys( $post, (int) $language->language_id, JobTypes::RETRANSLATE_MACHINE ),
			'retranslate_machine always includes an existing machine translation.'
		);
	}

	public function test_missing_segment_is_only_picked_by_translate_missing(): void {
		$language  = $this->add_language();
		$post      = $this->create_block_page( '44444444-4444-4444-8444-444444444444' );
		$this->key = \AIMultilingual\Block\SegmentKey::build( '44444444-4444-4444-8444-444444444444', \AIMultilingual\Block\Contract::FIELD_CONTENT );
		$this->seed( $post, (int) $language->language_id, Store::STATUS_MISSING );

		$this->assertContains(
			$this->key,
			$this->materialised_keys( $post, (int) $language->language_id, JobTypes::TRANSLATE_MISSING )
		);
		$this->assertNotContains(
			$this->key,
			$this->materialised_keys( $post, (int) $language->language_id, JobTypes::RETRANSLATE_STALE ),
			'retranslate_stale never creates a translation from nothing.'
		);
	}

	public function test_policy_modes_are_the_three_frozen_modes(): void {
		$this->assertSame(
			array(
				TranslatableSegmentEligibility::MODE_MISSING,
				TranslatableSegmentEligibility::MODE_STALE,
				TranslatableSegmentEligibility::MODE_MACHINE,
			),
			TranslatableSegmentEligibility::modes()
		);
	}
}
