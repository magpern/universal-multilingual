<?php
/**
 * Site Translate REST integration tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\FeatureFlags;
use AIMultilingual\Database\Schema;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Plugin;
use AIMultilingual\Settings;
use AIMultilingual\SiteTranslate\SiteTranslateLocalizedUrlBatchService;
use AIMultilingual\Translation\Assessment\AssessmentAssembler;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Publication\PublicationAuditLogger;
use AIMultilingual\Translation\Publication\PublicationPolicy;
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Translation\Store;
use WP_REST_Request;

/**
 * Site Translate coverage, admission, batch create, and run batch REST tests.
 */
final class SiteTranslateRestTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/UnavailableJobsSchedulerStub.php';
		$this->define_action_scheduler_stubs();
		JobsCapabilities::grant_default_roles();
	}

	/**
	 * Defines minimal Action Scheduler stubs for REST create tests.
	 */
	private function define_action_scheduler_stubs(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			/**
			 * @param mixed ...$args Unused Action Scheduler args.
			 */
			function as_enqueue_async_action( ...$args ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			/**
			 * @param mixed ...$args Unused Action Scheduler args.
			 */
			function as_schedule_single_action( ...$args ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			/**
			 * @param mixed ...$args Unused Action Scheduler args.
			 */
			function as_has_scheduled_action( ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return false;
			}
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			/**
			 * @param mixed ...$args Unused Action Scheduler args.
			 */
			function as_schedule_recurring_action( ...$args ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		}
	}

	public function test_site_translate_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/aiml/v1/site-translate/objects', $routes );
		$this->assertArrayHasKey( '/aiml/v1/site-translate/coverage', $routes );
		$this->assertArrayHasKey( '/aiml/v1/site-translate/admission', $routes );
		$this->assertArrayHasKey( '/aiml/v1/site-translate/jobs', $routes );
		$this->assertArrayHasKey( '/aiml/v1/site-translate/jobs/run', $routes );
		$this->assertArrayHasKey( '/aiml/v1/site-translate/routes', $routes );
	}

	public function test_strategy_f_blocks_gutenberg_selection_when_incomplete(): void {
		update_option(
			Settings::OPTION,
			Settings::sanitize(
				array(
					FeatureFlags::REGISTRATION    => false,
					FeatureFlags::INJECTION       => false,
					FeatureFlags::EXTRACTION      => false,
					FeatureFlags::FRONTEND_RENDER => false,
				)
			)
		);
		Plugin::instance()->reload_settings();

		$block_page = $this->create_block_page();
		$classic    = $this->create_page( 'Classic page', 'Classic body without blocks.' );

		wp_set_current_user( $this->create_translator() );

		$blocked = new WP_REST_Request( 'POST', '/aiml/v1/site-translate/admission' );
		$blocked->set_body_params(
			array(
				'post_ids' => array( (int) $block_page->ID ),
			)
		);
		$response = rest_do_request( $blocked );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aiml_site_translate_strategy_f_required', $response->as_error()->get_error_code() );

		$allowed = new WP_REST_Request( 'POST', '/aiml/v1/site-translate/admission' );
		$allowed->set_body_params(
			array(
				'post_ids' => array( (int) $classic->ID ),
			)
		);
		$ok = rest_do_request( $allowed );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertTrue( $ok->get_data()['allowed'] );
	}

	public function test_coverage_untranslated_page_reports_missing(): void {
		$language = $this->add_language();
		$post     = $this->create_page( 'Coverage missing', 'Body text' );
		wp_set_current_user( $this->create_translator() );

		$request = new WP_REST_Request( 'GET', '/aiml/v1/site-translate/coverage' );
		$request->set_param( 'language_id', (int) $language->language_id );
		$request->set_param( 'post_ids', array( (int) $post->ID ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$item     = $response->get_data()['items'][0];
		$coverage = $item['coverage'];
		$this->assertGreaterThan( 0, $coverage['eligible_total'] );
		$this->assertSame( $coverage['eligible_total'], $coverage['missing'] );
		$this->assertFalse( $coverage['translation_complete'] );
		$this->assertFalse( $coverage['no_extractable_work'] );
	}

	public function test_coverage_zero_eligible_reports_no_extractable_work(): void {
		$language = $this->add_language();
		$post_id  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => '',
				'post_name'    => 'zero-eligible-coverage',
				'post_status'  => 'publish',
			)
		);
		wp_set_current_user( $this->create_translator() );

		$request = new WP_REST_Request( 'GET', '/aiml/v1/site-translate/coverage' );
		$request->set_param( 'language_id', (int) $language->language_id );
		$request->set_param( 'post_ids', array( (int) $post_id ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$coverage = $response->get_data()['items'][0]['coverage'];
		$this->assertSame( 0, $coverage['eligible_total'] );
		$this->assertTrue( $coverage['no_extractable_work'] );
		$this->assertContains( 'zero_eligible', $coverage['blocked_or_unsupported'] );
		$this->assertFalse( $coverage['translation_complete'] );
	}

	public function test_chunked_create_shares_batch_id_and_run_batch_enqueues_waiting_only(): void {
		$language = $this->add_language();
		wp_set_current_user( $this->create_translator() );

		$post_ids = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$post_ids[] = (int) $this->create_page( 'Bulk ' . $i, 'Body ' . $i )->ID;
		}

		$request = new WP_REST_Request( 'POST', '/aiml/v1/site-translate/jobs' );
		$request->set_body_params(
			array(
				'post_ids'              => $post_ids,
				'language_id'           => (int) $language->language_id,
				'acknowledge_published' => true,
				'client_token'          => 'site-translate-test-token',
				'prompt_profile'        => 'default',
				'prompt_version'        => '1',
			)
		);

		$create = rest_do_request( $request );
		$this->assertContains( $create->get_status(), array( 201, 207 ), wp_json_encode( $create->get_data() ) );

		$data     = $create->get_data();
		$batch_id = (string) $data['batch_id'];
		$this->assertNotSame( '', $batch_id );
		$this->assertSame( 51, $data['created_count'] );
		$this->assertSame( 2, $data['chunk_count'] );

		$repo = new BackgroundTranslationJobRepository();
		$jobs = $repo->list_by_batch_id( $batch_id );
		$this->assertCount( 51, $jobs );

		$batch_ids = array_unique(
			array_map(
				static fn( object $job ): string => (string) $job->batch_id,
				$jobs
			)
		);
		$this->assertCount( 1, $batch_ids );

		wp_set_current_user( 1 );
		$run = new WP_REST_Request( 'POST', '/aiml/v1/site-translate/jobs/run' );
		$run->set_body_params(
			array(
				'batch_id' => $batch_id,
			)
		);
		$run_response = rest_do_request( $run );
		$this->assertSame( 202, $run_response->get_status() );
		$this->assertSame( 51, count( $run_response->get_data()['enqueued_job_ids'] ) );
	}

	public function test_localized_url_batch_reports_title_stale_without_generating(): void {
		$language = $this->add_language();
		$post     = $this->create_page( 'Stale title route', 'Body' );

		$this->translate( $post, $language, Extractor::FIELD_TITLE, 'Stale title sv' );

		$publication = new PublicationService(
			$this->store,
			new AssessmentAssembler(),
			new PublicationPolicy(),
			new PublicationAuditLogger(),
			new Settings()
		);
		$publication->publish(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			Extractor::FIELD_TITLE,
			false,
			1,
			'manual'
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			array( 'is_stale' => 1 ),
			array(
				'source_type' => Store::SOURCE_POST,
				'source_id'   => (int) $post->ID,
				'language_id' => (int) $language->language_id,
				'segment_key' => Extractor::FIELD_TITLE,
			)
		);

		wp_set_current_user( $this->create_translator() );

		$request = new WP_REST_Request( 'POST', '/aiml/v1/site-translate/routes' );
		$request->set_body_params(
			array(
				'post_ids'    => array( (int) $post->ID ),
				'language_id' => (int) $language->language_id,
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$outcome = $response->get_data()['outcomes'][0];
		$this->assertSame( SiteTranslateLocalizedUrlBatchService::OUTCOME_TITLE_STALE, $outcome['outcome'] );
	}
}
