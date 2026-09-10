<?php
/**
 * GET /aiml/v1/workspace/{post_id}/languages integration tests (MLW1a / WP1).
 *
 * The endpoint returns one server-computed ObjectLanguageStatus per eligible
 * configured target language (M) plus the ObjectLanguagesSummary aggregate —
 * never limited to an operator-selected subset (ADR-0034 D2/D3).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Translation\Store;
use WP_REST_Request;

/**
 * Object × language completeness read model.
 */
final class ObjectLanguagesRestTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	protected function setUp(): void {
		parent::setUp();
		$this->enable_strategy_f_flags();
	}

	/**
	 * Seeds one core field as translated + pending review.
	 *
	 * @param int    $post_id     Post id.
	 * @param int    $language_id Language id.
	 * @param string $field_key   Core field key.
	 * @param string $source      Source text.
	 * @param string $target      Target text.
	 */
	private function seed_pending( int $post_id, int $language_id, string $field_key, string $source, string $target ): void {
		$this->store->save_translation(
			array(
				'source_id'       => $post_id,
				'language_id'     => $language_id,
				'field_key'       => $field_key,
				'segment_key'     => $field_key,
				'source_text'     => $source,
				'translated_text' => $target,
				'status'          => Store::STATUS_MANUALLY_EDITED,
			)
		);

		$row = $this->store->get( Store::SOURCE_POST, $post_id, $language_id, $field_key );
		$this->store->update_review_metadata(
			Store::SOURCE_POST,
			$post_id,
			$language_id,
			$field_key,
			array(
				'review_status'              => Store::REVIEW_PENDING,
				'review_submitted_by'        => 1,
				'review_submitted_at'        => current_time( 'mysql', true ),
				'submitted_translation_hash' => (string) $row->translation_hash,
			)
		);
	}

	private function languages_request( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', sprintf( '/aiml/v1/workspace/%d/languages', $post_id ) );
		$request->set_url_params( array( 'post_id' => $post_id ) );

		return $request;
	}

	public function test_returns_one_status_per_eligible_target_language_with_server_state(): void {
		$sv = $this->add_language( 'sv', 'sv_SE' );
		$de = $this->add_language( 'de', 'de_DE' );

		$page = $this->create_page( 'About page', '<p>English body.</p>', 'Summary' );

		// Swedish fully translated + pending review; German never touched.
		$this->seed_pending( (int) $page->ID, (int) $sv->language_id, 'post_title', 'About page', 'Om sidan' );
		$this->seed_pending( (int) $page->ID, (int) $sv->language_id, 'post_excerpt', 'Summary', 'Sammanfattning' );

		wp_set_current_user( $this->create_translator() );
		$response = rest_do_request( $this->languages_request( (int) $page->ID ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		foreach ( array( 'post_id', 'post_title', 'post_type', 'object_noun', 'languages', 'summary' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertSame( (int) $page->ID, $data['post_id'] );
		$this->assertSame( 'page', $data['post_type'] );

		// M = every eligible configured target language, not the selected subset.
		$this->assertCount( 2, $data['languages'] );
		$by_code = array();
		foreach ( $data['languages'] as $lang ) {
			$by_code[ $lang['language_code'] ] = $lang;
			$this->assertArrayHasKey( 'state', $lang, 'state is server-computed and always present' );
		}

		// Swedish has translated + pending-review rows; its exact state depends
		// on whether the block body is also translated, but it is never
		// "not_translated" and its pending count is exposed verbatim.
		$this->assertGreaterThan( 0, $by_code['sv']['pending'] );
		$this->assertNotSame( 'not_translated', $by_code['sv']['state'] );
		$this->assertSame( 'not_translated', $by_code['de']['state'] );
		$this->assertTrue( $by_code['de']['is_forgotten'] );
		$this->assertFalse( $by_code['de']['is_ready'] );

		$summary = $data['summary'];
		$this->assertSame( 2, $summary['target_count'] );
		$this->assertTrue( $summary['forgotten'] );
		$this->assertContains( 'de', $summary['forgotten_languages'] );
		$this->assertSame( 1, $summary['not_translated_count'] );
	}

	public function test_forbidden_without_edit_post_capability(): void {
		$this->add_language( 'sv', 'sv_SE' );
		$page = $this->create_page( 'About page' );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_do_request( $this->languages_request( (int) $page->ID ) );

		$this->assertSame( 403, $response->get_status() );
	}
}
