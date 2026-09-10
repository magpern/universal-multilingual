<?php
/**
 * MLW1a / WP7 — Site Translate N objects × M languages matrix.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Language\Languages;
use WP_REST_Request;

/**
 * Cross-product Site Translate job creation, dedupe and the published gate.
 */
final class SiteTranslateMatrixRestTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationJobRepository $job_repo;

	protected function setUp(): void {
		parent::setUp();
		$this->enable_strategy_f_flags();
		JobsCapabilities::grant_default_roles();
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			/**
			 * @param mixed ...$args Unused.
			 */
			function as_enqueue_async_action( ...$args ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return 1;
			}
		}
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			/**
			 * @param mixed ...$args Unused.
			 */
			function as_has_scheduled_action( ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				return false;
			}
		}
		$this->job_repo = new BackgroundTranslationJobRepository();
	}

	/**
	 * @param array<string, mixed> $body Request body.
	 */
	private function create_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/aiml/v1/site-translate/jobs' );
		$request->set_body_params( $body );

		return $request;
	}

	/**
	 * @param int $count Pages to create.
	 * @return array<int, int>
	 */
	private function pages( int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = (int) $this->create_page( 'Matrix ' . $i, '<p>Body ' . $i . '.</p>' )->ID;
		}

		return $ids;
	}

	public function test_four_objects_times_three_languages_is_twelve_under_one_batch(): void {
		$sv  = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$de  = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$da  = $this->add_language( 'da', 'da_DK', Languages::STATUS_PREVIEW );
		$ids = $this->pages( 4 );

		wp_set_current_user( $this->create_translator() );
		$response = rest_do_request(
			$this->create_request(
				array(
					'post_ids'     => $ids,
					'language_ids' => array(
						(int) $sv->language_id,
						(int) $de->language_id,
						(int) $da->language_id,
					),
					'client_token' => 'matrix-4x3',
				)
			)
		);

		$this->assertContains( $response->get_status(), array( 201, 207 ), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 12, $data['operations'] );

		$jobs = $this->job_repo->list_by_batch_id( (string) $data['batch_id'] );
		$this->assertCount( 12, $jobs );

		$pairs = array();
		foreach ( $jobs as $job ) {
			$pairs[ (int) $job->source_id . ':' . (int) $job->language_id ] = true;
		}
		$this->assertCount( 12, $pairs, 'one job per (object, language) pair' );
	}

	public function test_five_by_three_is_fifteen(): void {
		$sv  = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$de  = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$da  = $this->add_language( 'da', 'da_DK', Languages::STATUS_PREVIEW );
		$ids = $this->pages( 5 );

		wp_set_current_user( $this->create_translator() );
		$response = rest_do_request(
			$this->create_request(
				array(
					'post_ids'     => $ids,
					'language_ids' => array(
						(int) $sv->language_id,
						(int) $de->language_id,
						(int) $da->language_id,
					),
					'client_token' => 'matrix-5x3',
				)
			)
		);

		$this->assertSame( 15, $response->get_data()['operations'] );
		$this->assertCount( 15, $this->job_repo->list_by_batch_id( (string) $response->get_data()['batch_id'] ) );
	}

	public function test_partial_retry_dedupes_by_object_and_language_not_object_alone(): void {
		$sv  = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$de  = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$ids = $this->pages( 2 );

		wp_set_current_user( $this->create_translator() );

		// First run: 2 pages × Swedish only.
		$first    = rest_do_request(
			$this->create_request(
				array(
					'post_ids'     => $ids,
					'language_ids' => array( (int) $sv->language_id ),
					'client_token' => 'matrix-retry',
				)
			)
		);
		$batch_id = (string) $first->get_data()['batch_id'];
		$this->assertCount( 2, $this->job_repo->list_by_batch_id( $batch_id ) );

		// Retry into the same batch: 2 pages × [Swedish, German]. Swedish is
		// already done for both pages; German is new — it must NOT be dropped
		// just because the page already has a Swedish job.
		$retry = rest_do_request(
			$this->create_request(
				array(
					'post_ids'     => $ids,
					'language_ids' => array( (int) $sv->language_id, (int) $de->language_id ),
					'client_token' => 'matrix-retry',
					'batch_id'     => $batch_id,
				)
			)
		);

		$data = $retry->get_data();
		$this->assertSame( 2, $data['created_count'], 'only the two German jobs are new' );
		$this->assertSame( 2, $data['skipped_count'], 'the two Swedish pairs already exist' );

		$jobs = $this->job_repo->list_by_batch_id( $batch_id );
		$this->assertCount( 4, $jobs );
		$by_lang = array();
		foreach ( $jobs as $job ) {
			$by_lang[ (int) $job->language_id ] = ( $by_lang[ (int) $job->language_id ] ?? 0 ) + 1;
		}
		$this->assertSame( 2, $by_lang[ (int) $sv->language_id ] );
		$this->assertSame( 2, $by_lang[ (int) $de->language_id ] );
	}

	public function test_published_language_requires_acknowledgement_and_is_enforced_server_side(): void {
		$sv   = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$page = $this->create_page( 'Pub', '<p>Body.</p>' );

		wp_set_current_user( $this->create_translator() );

		$blocked = rest_do_request(
			$this->create_request(
				array(
					'post_ids'     => array( (int) $page->ID ),
					'language_ids' => array( (int) $sv->language_id ),
					'client_token' => 'matrix-pub-1',
				)
			)
		);
		$this->assertSame( 400, $blocked->get_status() );
		$this->assertSame( 'aiml_acknowledge_published_required', $blocked->get_data()['code'] );
		$this->assertSame( 0, $this->job_repo->query( array( 'per_page' => 50 ) )['total'] );

		$ok = rest_do_request(
			$this->create_request(
				array(
					'post_ids'              => array( (int) $page->ID ),
					'language_ids'          => array( (int) $sv->language_id ),
					'acknowledge_published' => true,
					'client_token'          => 'matrix-pub-2',
				)
			)
		);
		$this->assertContains( $ok->get_status(), array( 201, 207 ) );
		$this->assertSame( 1, $ok->get_data()['operations'] );
	}

	public function test_source_language_is_rejected_as_a_target(): void {
		$sv      = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$default = $this->languages->default();
		$page    = $this->create_page( 'Src', '<p>Body.</p>' );

		wp_set_current_user( $this->create_translator() );
		$response = rest_do_request(
			$this->create_request(
				array(
					'post_ids'     => array( (int) $page->ID ),
					'language_ids' => array(
						(int) $sv->language_id,
						(int) $default->language_id,
					),
					'client_token' => 'matrix-src',
				)
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aiml_source_language', $response->get_data()['code'] );
	}
}
