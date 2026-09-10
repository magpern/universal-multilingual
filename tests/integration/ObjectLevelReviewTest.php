<?php
/**
 * Object-level ("Approve page") review integration tests (RVQ1 / WP2-WP3).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\Contract;
use AIMultilingual\Block\SegmentKey;
use AIMultilingual\Translation\Store;
use WP_REST_Request;

/**
 * Grouped queue + safe-subset object approval built on ReviewBatchCoordinator.
 */
final class ObjectLevelReviewTest extends AimlTestCase {

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

	private function approve_object_request( int $post_id, string $language = 'sv' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', sprintf( '/aiml/v1/workspace/%d/review/approve-object', $post_id ) );
		$request->set_url_params( array( 'post_id' => $post_id ) );
		$request->set_param( 'language', $language );

		return $request;
	}

	public function test_queue_groups_rows_by_object_and_language_with_labels(): void {
		$language = $this->add_language();
		$lid      = (int) $language->language_id;
		$page     = $this->create_page( 'About page', '<p>English body.</p>' );

		$this->seed_pending( (int) $page->ID, $lid, 'post_title', 'About page', 'Om sidan' );
		$this->seed_pending( (int) $page->ID, $lid, 'post_excerpt', 'Summary', 'Sammanfattning' );

		wp_set_current_user( $this->create_reviewer() );
		$data = rest_do_request( $this->review_queue_request() )->get_data();

		$this->assertArrayHasKey( 'objects', $data );
		$this->assertCount( 1, $data['objects'] );

		$group = $data['objects'][0];
		$this->assertSame( (int) $page->ID, $group['post_id'] );
		$this->assertSame( 'About page', $group['post_title'] );
		$this->assertSame( 'Page', $group['object_noun'] );
		$this->assertSame( 'sv', $group['language_code'] );
		$this->assertSame( 2, $group['summary']['pending'] );

		$labels = array_column( $group['items'], 'field_label', 'field_key' );
		$this->assertSame( 'Title', $labels['post_title'] );
		$this->assertSame( 'Excerpt', $labels['post_excerpt'] );
	}

	public function test_approve_object_approves_every_pending_segment_for_that_object_language(): void {
		$language = $this->add_language();
		$lid      = (int) $language->language_id;
		$page     = $this->create_page( 'Doc', '<p>English body.</p>' );

		$this->seed_pending( (int) $page->ID, $lid, 'post_title', 'Doc', 'Dokument' );
		$this->seed_pending( (int) $page->ID, $lid, 'post_content', 'English body.', 'Svensk text.' );

		wp_set_current_user( $this->create_reviewer() );
		$response = rest_do_request( $this->approve_object_request( (int) $page->ID ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 2, $data['approved_count'] );
		$this->assertSame( array(), $data['skipped'] );
		$this->assertSame( 0, $data['summary']['pending'] );
		$this->assertSame( 2, $data['summary']['approved'] );

		foreach ( array( 'post_title', 'post_content' ) as $field ) {
			$row = $this->store->get( Store::SOURCE_POST, (int) $page->ID, $lid, $field );
			$this->assertSame( Store::REVIEW_APPROVED, $row->review_status );
		}
	}

	public function test_approve_object_does_not_touch_another_object_or_language(): void {
		$language = $this->add_language();
		$other    = $this->add_language( 'de', 'de_DE' );
		$lid      = (int) $language->language_id;
		$did      = (int) $other->language_id;

		$page_a = $this->create_page( 'Page A' );
		$page_b = $this->create_page( 'Page B' );

		$this->seed_pending( (int) $page_a->ID, $lid, 'post_title', 'Page A', 'Sida A' );
		$this->seed_pending( (int) $page_a->ID, $did, 'post_title', 'Page A', 'Seite A' );
		$this->seed_pending( (int) $page_b->ID, $lid, 'post_title', 'Page B', 'Sida B' );

		wp_set_current_user( $this->create_reviewer() );
		rest_do_request( $this->approve_object_request( (int) $page_a->ID, 'sv' ) );

		$this->assertSame(
			Store::REVIEW_APPROVED,
			$this->store->get( Store::SOURCE_POST, (int) $page_a->ID, $lid, 'post_title' )->review_status
		);
		$this->assertSame(
			Store::REVIEW_PENDING,
			$this->store->get( Store::SOURCE_POST, (int) $page_a->ID, $did, 'post_title' )->review_status
		);
		$this->assertSame(
			Store::REVIEW_PENDING,
			$this->store->get( Store::SOURCE_POST, (int) $page_b->ID, $lid, 'post_title' )->review_status
		);
	}

	public function test_qa_blocked_segment_is_reported_not_silently_approved(): void {
		$language = $this->add_language();
		$lid      = (int) $language->language_id;
		$page     = $this->create_page( 'QA page', '<p>Hello {name} today.</p>' );

		$this->seed_pending( (int) $page->ID, $lid, 'post_title', 'QA page', 'QA sida' );
		$this->seed_pending( (int) $page->ID, $lid, 'post_content', 'Hello {name} today.', 'Hej idag.' );

		wp_set_current_user( $this->create_reviewer() );
		$data = rest_do_request( $this->approve_object_request( (int) $page->ID ) )->get_data();

		$this->assertSame( 1, $data['approved_count'] );
		$this->assertCount( 1, $data['skipped'] );
		$this->assertSame( Store::REVIEW_PENDING, $this->store->get( Store::SOURCE_POST, (int) $page->ID, $lid, 'post_content' )->review_status );
		$this->assertSame( 'post_content', (string) $data['skipped'][0]['segment_key'] );
		$this->assertArrayHasKey( 'field_label', $data['skipped'][0] );
		$this->assertFalse( $data['summary']['is_fully_reviewed'] );
	}

	public function test_untranslated_field_keeps_the_object_incomplete(): void {
		$language = $this->add_language();
		$lid      = (int) $language->language_id;
		$page     = $this->create_page( 'Half page', '<p>English body.</p>' );

		// Only the title is translated + pending; content + excerpt are missing.
		$this->seed_pending( (int) $page->ID, $lid, 'post_title', 'Half page', 'Halv sida' );

		wp_set_current_user( $this->create_reviewer() );
		$data = rest_do_request( $this->approve_object_request( (int) $page->ID ) )->get_data();

		$this->assertSame( 1, $data['approved_count'] );
		$this->assertGreaterThan( 0, $data['summary']['untranslated'] );
		$this->assertSame( 'incomplete', $data['summary']['state'] );
		$this->assertFalse( $data['summary']['is_fully_reviewed'] );
	}

	public function test_approve_object_processes_more_than_one_batch(): void {
		$language = $this->add_language();
		$lid      = (int) $language->language_id;

		$count  = 55;
		$blocks = '';
		$keys   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$uuid    = sprintf( '550e8400-e29b-41d4-a716-%012d', $i );
			$blocks .= sprintf(
				'<!-- wp:paragraph {"%1$s":"%2$s"} --><p>Paragraph %3$d</p><!-- /wp:paragraph -->',
				Contract::ATTR_NAME,
				$uuid,
				$i
			);
			$keys[]  = SegmentKey::build( $uuid, Contract::FIELD_CONTENT );
		}
		$page = $this->create_page( 'Big page', $blocks );

		$load = new WP_REST_Request( 'GET', '/aiml/v1/workspace/' . (int) $page->ID . '/segments' );
		$load->set_param( 'language', 'sv' );
		wp_set_current_user( $this->create_translator_reviewer() );
		$segments = rest_do_request( $load )->get_data()['segments'];
		$by_key   = array();
		foreach ( $segments as $row ) {
			$by_key[ (string) $row['segment_key'] ] = $row;
		}

		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $by_key );
			$segment = $by_key[ $key ];
			$this->store->save_translation(
				array(
					'source_id'       => (int) $page->ID,
					'language_id'     => $lid,
					'field_key'       => (string) $segment['field_key'],
					'segment_key'     => $key,
					'source_text'     => (string) $segment['source_text'],
					'translated_text' => 'Stycke ' . $key,
					'status'          => Store::STATUS_MANUALLY_EDITED,
					'text_format'     => (string) $segment['text_format'],
					'segment_kind'    => (string) ( $segment['segment_kind'] ?? Store::KIND_BLOCK ),
				)
			);
			$stored = $this->store->get( Store::SOURCE_POST, (int) $page->ID, $lid, $key );
			$this->store->update_review_metadata(
				Store::SOURCE_POST,
				(int) $page->ID,
				$lid,
				$key,
				array(
					'review_status'              => Store::REVIEW_PENDING,
					'review_submitted_by'        => 1,
					'review_submitted_at'        => current_time( 'mysql', true ),
					'submitted_translation_hash' => (string) $stored->translation_hash,
				)
			);
		}

		$this->assertSame(
			$count,
			$this->store->review_status_counts( Store::SOURCE_POST, (int) $page->ID, $lid )[ Store::REVIEW_PENDING ]
		);

		wp_set_current_user( $this->create_reviewer() );
		$data = rest_do_request( $this->approve_object_request( (int) $page->ID ) )->get_data();

		$this->assertSame( $count, $data['approved_count'], 'Every pending segment beyond the first batch must be approved.' );
		$this->assertSame( 0, $data['summary']['pending'] );
	}
}
