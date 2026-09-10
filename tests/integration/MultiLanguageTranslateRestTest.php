<?php
/**
 * POST /aiml/v1/workspace/objects/translate integration tests (MLW1a / WP4).
 *
 * N objects × M languages cross-product job creation, C3 body-reading
 * authorization (deny one object => zero jobs), per-(object,language)
 * idempotency, bounded chunking, and the published-language acknowledgement
 * gate (ADR-0034 D5/D7/D8).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use WP_REST_Request;

/**
 * Multi-language translation coordinator REST surface.
 */
final class MultiLanguageTranslateRestTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	private BackgroundTranslationJobRepository $job_repo;

	protected function setUp(): void {
		parent::setUp();
		$this->enable_strategy_f_flags();
		$this->define_as_stubs();
		$this->job_repo = new BackgroundTranslationJobRepository();
	}

	/**
	 * Minimal Action Scheduler stubs so the batch coordinator's health check
	 * passes and autostart is a no-op.
	 */
	private function define_as_stubs(): void {
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
	}

	/**
	 * Editor-like translator that can edit any post but does NOT hold
	 * MANAGE_JOBS — exercises the C3 per-object edit_post path.
	 *
	 * @return int User id.
	 */
	private function translator_without_manage_jobs(): int {
		if ( null === get_role( 'aiml_mlw_translator' ) ) {
			add_role(
				'aiml_mlw_translator',
				'MLW Translator',
				array(
					'read'                 => true,
					'edit_posts'           => true,
					'edit_others_posts'    => true,
					'edit_published_posts' => true,
					'edit_pages'           => true,
					'edit_others_pages'    => true,
					'edit_published_pages' => true,
					Plugin::CAPABILITY     => true,
				)
			);
		}
		$role = get_role( 'aiml_mlw_translator' );
		if ( $role instanceof \WP_Role ) {
			$role->add_cap( Plugin::CAPABILITY );
			$role->remove_cap( JobsCapabilities::MANAGE_JOBS );
		}

		return (int) self::factory()->user->create( array( 'role' => 'aiml_mlw_translator' ) );
	}

	/**
	 * @param array<string, mixed> $body Request body.
	 */
	private function translate_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/aiml/v1/workspace/objects/translate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return $request;
	}

	public function test_creates_one_job_per_object_language_pair_under_one_batch(): void {
		$sv = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$de = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$p1 = $this->create_page( 'Alpha', '<p>Alpha body.</p>' );
		$p2 = $this->create_page( 'Beta', '<p>Beta body.</p>' );
		$p3 = $this->create_page( 'Gamma', '<p>Gamma body.</p>' );

		wp_set_current_user( $this->translator_without_manage_jobs() );

		$response = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'   => array( $p1->ID, $p2->ID, $p3->ID ),
					'language_ids' => array( (int) $sv->language_id, (int) $de->language_id ),
					'job_type'     => 'missing',
					'client_token' => 'tok-nm-1',
				)
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();

		$this->assertSame( 3, $data['planned']['objects'] );
		$this->assertSame( 2, $data['planned']['languages'] );
		$this->assertSame( 6, $data['planned']['operations'] );
		$this->assertNotEmpty( $data['batch_id'] );

		$jobs = $this->job_repo->list_by_batch_id( (string) $data['batch_id'] );
		$this->assertCount( 6, $jobs );

		$by_lang = array();
		foreach ( $jobs as $job ) {
			$by_lang[ (int) $job->language_id ] = ( $by_lang[ (int) $job->language_id ] ?? 0 ) + 1;
		}
		$this->assertSame( 3, $by_lang[ (int) $sv->language_id ] );
		$this->assertSame( 3, $by_lang[ (int) $de->language_id ] );
	}

	public function test_deny_one_object_creates_zero_jobs(): void {
		$sv   = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$page = $this->create_page( 'Alpha', '<p>Alpha body.</p>' );

		wp_set_current_user( $this->translator_without_manage_jobs() );

		$response = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'   => array( $page->ID, 999999 ),
					'language_ids' => array( (int) $sv->language_id ),
					'job_type'     => 'missing',
				)
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aiml_forbidden', $response->get_data()['code'] );
		$this->assertSame( 0, $this->job_repo->query( array( 'per_page' => 100 ) )['total'] );
	}

	public function test_manage_jobs_holder_allowed(): void {
		$sv   = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$page = $this->create_page( 'Alpha', '<p>Alpha body.</p>' );

		$user = $this->create_translator();
		( get_role( 'editor' ) )->add_cap( JobsCapabilities::MANAGE_JOBS );
		wp_set_current_user( $user );

		$response = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'   => array( $page->ID ),
					'language_ids' => array( (int) $sv->language_id ),
					'job_type'     => 'missing',
				)
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $response->get_data()['planned']['operations'] );
	}

	public function test_published_language_requires_acknowledgement(): void {
		$sv   = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$de   = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$page = $this->create_page( 'Alpha', '<p>Alpha body.</p>' );

		wp_set_current_user( $this->translator_without_manage_jobs() );

		$blocked = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'   => array( $page->ID ),
					'language_ids' => array( (int) $sv->language_id, (int) $de->language_id ),
					'job_type'     => 'missing',
				)
			)
		);
		$this->assertSame( 400, $blocked->get_status() );
		$this->assertSame( 'aiml_acknowledge_published_required', $blocked->get_data()['code'] );
		$this->assertContains( 'sv', $blocked->get_data()['data']['published_languages'] );
		$this->assertSame( 0, $this->job_repo->query( array( 'per_page' => 100 ) )['total'] );

		$ok = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'            => array( $page->ID ),
					'language_ids'          => array( (int) $sv->language_id, (int) $de->language_id ),
					'job_type'              => 'missing',
					'acknowledge_published' => true,
				)
			)
		);
		$this->assertSame( 201, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertSame( 2, $ok->get_data()['planned']['operations'] );
	}

	public function test_no_duplicate_active_jobs_on_rapid_resubmit(): void {
		$sv = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$p1 = $this->create_page( 'Alpha', '<p>Alpha body.</p>' );
		$p2 = $this->create_page( 'Beta', '<p>Beta body.</p>' );

		wp_set_current_user( $this->translator_without_manage_jobs() );

		$body = array(
			'object_ids'   => array( $p1->ID, $p2->ID ),
			'language_ids' => array( (int) $sv->language_id ),
			'job_type'     => 'missing',
			'client_token' => 'tok-dup-1',
		);

		$first  = rest_do_request( $this->translate_request( $body ) );
		$second = rest_do_request( $this->translate_request( $body ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );

		$this->assertSame( 2, $this->job_repo->query( array( 'per_page' => 100 ) )['total'] );
	}

	public function test_bounded_chunking_for_large_cross_product(): void {
		$sv  = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$de  = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$ids = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$ids[] = $this->create_page( 'Page ' . $i, '<p>Body ' . $i . '.</p>' )->ID;
		}

		wp_set_current_user( $this->translator_without_manage_jobs() );

		$response = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'   => $ids,
					'language_ids' => array( (int) $sv->language_id, (int) $de->language_id ),
					'job_type'     => 'missing',
					'client_token' => 'tok-chunk-1',
				)
			)
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();

		// 60 operations => 2 chunks of <= 50, one shared batch_id.
		$this->assertSame( 60, $data['planned']['operations'] );
		$this->assertSame( 2, $data['planned']['chunks'] );
		$this->assertCount( 60, $this->job_repo->list_by_batch_id( (string) $data['batch_id'] ) );
	}

	public function test_automatic_mode_is_rejected_in_mlw1a(): void {
		$sv   = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$page = $this->create_page( 'Alpha', '<p>Alpha body.</p>' );

		wp_set_current_user( $this->translator_without_manage_jobs() );

		$response = rest_do_request(
			$this->translate_request(
				array(
					'object_ids'   => array( $page->ID ),
					'language_ids' => array( (int) $sv->language_id ),
					'job_type'     => 'missing',
					'automatic'    => true,
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aiml_automatic_unavailable', $response->get_data()['code'] );
	}
}
