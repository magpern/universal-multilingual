<?php
/**
 * Workspace REST integration tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Rest\WorkspaceController;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use WP_REST_Request;

/**
 * REST surface for translator workspace (F10.1).
 */
final class WorkspaceRestTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	protected function setUp(): void {
		parent::setUp();

		$this->enable_strategy_f_flags();
	}

	public function test_workspace_routes_are_registered_under_aiml_v1(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/aiml/v1/workspace/posts', $routes );
		$this->assertArrayHasKey( '/aiml/v1/workspace/(?P<post_id>\d+)/segments', $routes );
	}

	public function test_get_segments_returns_view_models_only(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$user_id  = $this->create_translator();
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request(
			'GET',
			'/aiml/v1/workspace/' . (int) $post->ID . '/segments'
		);
		$request->set_param( 'language', 'sv' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'segments', $data );
		$this->assertNotEmpty( $data['segments'] );

		$row = $data['segments'][0];
		foreach ( array(
			'segment_key',
			'field_key',
			'block_name',
			'uuid',
			'segment_order',
			'source_text',
			'source_hash',
			'translated_text',
			'status',
			'is_stale',
			'text_format',
			'can_edit',
			'meta',
		) as $field ) {
			$this->assertArrayHasKey( $field, $row, "Missing ViewModel field {$field}" );
		}

		$this->assertStringStartsWith( 'b:', (string) $row['segment_key'] );
		$this->assertArrayNotHasKey( 'translation_id', $row );
	}

	public function test_save_segment_persists_manual_translation(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		$user_id  = $this->create_translator();
		wp_set_current_user( $user_id );

		$load = new WP_REST_Request(
			'GET',
			'/aiml/v1/workspace/' . (int) $post->ID . '/segments'
		);
		$load->set_param( 'language', 'sv' );
		$segment = $this->first_block_segment( rest_do_request( $load )->get_data()['segments'] );

		$save = $this->workspace_save_request(
			(int) $post->ID,
			$segment,
			array(
				'translated_text' => 'Hej workspace',
				'source_hash'     => $segment['source_hash'],
				'status'          => Store::STATUS_MANUALLY_EDITED,
			)
		);

		$response = rest_do_request( $save );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Hej workspace', $response->get_data()['translated_text'] );
		$this->assertSame( Store::STATUS_MANUALLY_EDITED, $response->get_data()['status'] );
	}

	public function test_list_posts_returns_summaries(): void {
		$language = $this->add_language();
		$this->create_block_page();
		wp_set_current_user( $this->create_translator() );

		$request = new WP_REST_Request( 'GET', '/aiml/v1/workspace/posts' );
		$request->set_param( 'language', 'sv' );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['items'] );
	}

	public function test_segment_list_excludes_the_localized_url_slug(): void {
		$this->add_language();
		$post = $this->create_page( 'About Us' );
		wp_set_current_user( $this->create_translator() );

		$load = new WP_REST_Request( 'GET', '/aiml/v1/workspace/' . (int) $post->ID . '/segments' );
		$load->set_param( 'language', 'sv' );
		$segments = rest_do_request( $load )->get_data()['segments'];

		foreach ( $segments as $segment ) {
			$this->assertNotSame( Store::FORMAT_SLUG, (string) ( $segment['text_format'] ?? '' ) );
			$this->assertNotSame( Extractor::FIELD_SLUG, (string) ( $segment['segment_key'] ?? '' ) );
			$this->assertNotSame( Extractor::FIELD_SLUG, (string) ( $segment['field_key'] ?? '' ) );
		}
	}

	public function test_ensure_slug_candidate_generates_from_translated_title(): void {
		$language = $this->add_language();
		$post     = $this->create_page( 'About Us' );
		wp_set_current_user( $this->create_translator() );

		$this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => 'page',
				'language_id'     => (int) $language->language_id,
				'field_key'       => Extractor::FIELD_TITLE,
				'segment_key'     => Extractor::FIELD_TITLE,
				'segment_kind'    => Store::KIND_FIELD,
				'text_format'     => Store::FORMAT_PLAIN,
				'source_text'     => 'About Us',
				'translated_text' => 'Om oss',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
			)
		);

		$ensure = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . (int) $post->ID . '/slug/ensure' );
		$ensure->set_param( 'language', 'sv' );
		$view = rest_do_request( $ensure )->get_data();

		$this->assertSame( 'om-oss', $view['slug_candidate'] );
		$this->assertSame( 'generated', $view['slug_origin'] );
		$this->assertArrayHasKey( 'state', $view );
		$this->assertArrayHasKey( 'localized_url', $view );

		// Idempotent: a second ensure with the same title changes nothing.
		$again = rest_do_request( $ensure )->get_data();
		$this->assertSame( 'om-oss', $again['slug_candidate'] );
		$this->assertSame( 'generated', $again['slug_origin'] );
	}

	public function test_ensure_never_overwrites_a_manual_slug(): void {
		$language = $this->add_language();
		$post     = $this->create_page( 'About Us' );
		wp_set_current_user( $this->create_translator() );

		$this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => 'page',
				'language_id'     => (int) $language->language_id,
				'field_key'       => Extractor::FIELD_TITLE,
				'segment_key'     => Extractor::FIELD_TITLE,
				'segment_kind'    => Store::KIND_FIELD,
				'text_format'     => Store::FORMAT_PLAIN,
				'source_text'     => 'About Us',
				'translated_text' => 'Om oss',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
			)
		);

		$save = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . (int) $post->ID . '/slug' );
		$save->set_query_params( array( 'language' => 'sv' ) );
		$save->set_body_params( array( 'slug_candidate' => 'min-egen-url' ) );
		$saved = rest_do_request( $save );
		$this->assertSame( 200, $saved->get_status(), wp_json_encode( $saved->get_data() ) );
		$this->assertSame( 'manual', $saved->get_data()['slug_origin'] );

		$ensure = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . (int) $post->ID . '/slug/ensure' );
		$ensure->set_param( 'language', 'sv' );
		$view = rest_do_request( $ensure )->get_data();

		$this->assertSame( 'min-egen-url', $view['slug_candidate'] );
		$this->assertSame( 'manual', $view['slug_origin'] );
	}

	public function test_delete_translation_removes_every_segment_and_the_slug(): void {
		$language = $this->add_language();
		$post     = $this->create_page( 'About Us', '<p>English body.</p>' );
		wp_set_current_user( $this->create_translator() );

		foreach (
			array(
				Extractor::FIELD_TITLE   => 'Om oss',
				Extractor::FIELD_CONTENT => '<p>Svensk text.</p>',
			) as $field => $text
		) {
			$this->store->save_translation(
				array(
					'source_type'     => Store::SOURCE_POST,
					'source_id'       => (int) $post->ID,
					'source_subtype'  => 'page',
					'language_id'     => (int) $language->language_id,
					'field_key'       => $field,
					'segment_key'     => $field,
					'segment_kind'    => Store::KIND_FIELD,
					'text_format'     => Extractor::FIELD_CONTENT === $field ? Store::FORMAT_HTML : Store::FORMAT_PLAIN,
					'source_text'     => Extractor::FIELD_CONTENT === $field ? '<p>English body.</p>' : 'About Us',
					'translated_text' => $text,
					'status'          => Store::STATUS_MACHINE_TRANSLATED,
				)
			);
		}

		$ensure = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . (int) $post->ID . '/slug/ensure' );
		$ensure->set_param( 'language', 'sv' );
		$this->assertSame( 'om-oss', rest_do_request( $ensure )->get_data()['slug_candidate'] );

		$delete = new WP_REST_Request( 'DELETE', '/aiml/v1/workspace/' . (int) $post->ID . '/translation' );
		$delete->set_param( 'language', 'sv' );
		$response = rest_do_request( $delete );
		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$this->assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}aiml_translations WHERE source_id = %d AND language_id = %d",
					(int) $post->ID,
					(int) $language->language_id
				)
			)
		);

		$view = rest_do_request( $ensure )->get_data();
		// After delete + re-ensure there is no translated title, so no candidate.
		$this->assertSame( '', $view['slug_candidate'] );

		// Canonical page untouched.
		$fresh = get_post( (int) $post->ID );
		$this->assertSame( 'About Us', $fresh->post_title );
	}

	public function test_untranslated_segments_carry_no_qa_noise(): void {
		$this->add_language();
		$post = $this->create_block_page();
		wp_set_current_user( $this->create_translator() );

		$load = new WP_REST_Request( 'GET', '/aiml/v1/workspace/' . (int) $post->ID . '/segments' );
		$load->set_param( 'language', 'sv' );
		$segments = rest_do_request( $load )->get_data()['segments'];
		$this->assertNotEmpty( $segments );

		foreach ( $segments as $segment ) {
			$this->assertSame( '', trim( (string) ( $segment['translated_text'] ?? '' ) ) );
			$summary = $segment['meta']['qa']['summary'] ?? array();
			$this->assertSame( 0, (int) ( $summary['errors'] ?? -1 ) );
			$this->assertSame( 0, (int) ( $summary['warnings'] ?? -1 ) );
			$this->assertSame( 0, (int) ( $summary['info'] ?? -1 ) );
			$this->assertEmpty( $segment['meta']['qa']['issues'] ?? array() );
		}
	}

	public function test_empty_translation_becomes_missing(): void {
		$language = $this->add_language();
		$post     = $this->create_block_page();
		wp_set_current_user( $this->create_translator() );

		$load = new WP_REST_Request(
			'GET',
			'/aiml/v1/workspace/' . (int) $post->ID . '/segments'
		);
		$load->set_param( 'language', 'sv' );
		$segment = $this->first_block_segment( rest_do_request( $load )->get_data()['segments'] );

		$save          = $this->workspace_save_request(
			(int) $post->ID,
			$segment,
			array(
				'translated_text' => 'Temporary text',
				'source_hash'     => $segment['source_hash'],
				'status'          => Store::STATUS_MANUALLY_EDITED,
			)
		);
		$save_response = rest_do_request( $save );
		$this->assertSame( 200, $save_response->get_status() );
		$saved = $save_response->get_data();

		$clear = $this->workspace_save_request(
			(int) $post->ID,
			$saved,
			array(
				'translated_text' => '   ',
				'source_hash'     => $saved['source_hash'],
			)
		);

		$response = rest_do_request( $clear );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Store::STATUS_MISSING, $response->get_data()['status'] );
		$this->assertSame( '', $response->get_data()['translated_text'] );
	}

	public function test_save_rejects_unknown_language(): void {
		$this->add_language();
		$post = $this->create_block_page();
		wp_set_current_user( $this->create_translator() );

		$load = new WP_REST_Request(
			'GET',
			'/aiml/v1/workspace/' . (int) $post->ID . '/segments'
		);
		$load->set_param( 'language', 'sv' );
		$segment = $this->first_block_segment( rest_do_request( $load )->get_data()['segments'] );

		$save = $this->workspace_save_request(
			(int) $post->ID,
			$segment,
			array(
				'translated_text' => 'Hej',
				'source_hash'     => $segment['source_hash'],
			)
		);
		$save->set_param( 'language', 'de' );

		$response = rest_do_request( $save );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_cross_post_segment_save_is_rejected(): void {
		$this->add_language();
		$post_a = $this->create_block_page( 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' );
		$post_b = $this->create_block_page( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' );
		wp_set_current_user( $this->create_translator() );

		$load = new WP_REST_Request(
			'GET',
			'/aiml/v1/workspace/' . (int) $post_a->ID . '/segments'
		);
		$load->set_param( 'language', 'sv' );
		$segment = $this->first_block_segment( rest_do_request( $load )->get_data()['segments'] );

		$save = $this->workspace_save_request(
			(int) $post_b->ID,
			$segment,
			array(
				'translated_text' => 'Wrong post',
				'source_hash'     => $segment['source_hash'],
			)
		);

		$response = rest_do_request( $save );
		$this->assertSame( 422, $response->get_status() );
	}

	public function test_response_includes_api_version_header(): void {
		wp_set_current_user( $this->create_translator() );

		$request  = new WP_REST_Request( 'GET', '/aiml/v1/workspace/posts' );
		$response = rest_do_request( $request );

		$headers = $response->get_headers();
		$this->assertSame(
			'1',
			$headers['X-AIML-Workspace-Api-Version'][0] ?? $headers['x-aiml-workspace-api-version'][0] ?? ''
		);
	}
}
