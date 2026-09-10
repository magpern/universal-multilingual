<?php
/**
 * MLW1a / WP5 — object-level Review Queue pagination (C1) + multi-language
 * "Approve all ready languages" (C4 / D6).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Block\Contract;
use AIMultilingual\Translation\Store;
use WP_REST_Request;

/**
 * Grouped read model + bounded per-language approval.
 */
final class ReviewQueueObjectPaginationTest extends AimlTestCase {

	use WorkspaceTestHelpers;

	protected function setUp(): void {
		parent::setUp();
		$this->enable_strategy_f_flags();
	}

	/**
	 * Seeds one field as translated + pending review for an object + language.
	 *
	 * @param int    $post_id     Post id.
	 * @param int    $language_id Language id.
	 * @param string $field_key   Field/segment key.
	 * @param string $target      Target text.
	 * @param string $submitted   Optional explicit review_submitted_at (UTC mysql).
	 * @param string $status      Review status (pending|rejected|...).
	 */
	private function seed_row(
		int $post_id,
		int $language_id,
		string $field_key,
		string $target,
		string $submitted = '',
		string $status = Store::REVIEW_PENDING
	): void {
		$this->store->save_translation(
			array(
				'source_id'       => $post_id,
				'language_id'     => $language_id,
				'field_key'       => $field_key,
				'segment_key'     => $field_key,
				'source_text'     => 'Source ' . $field_key,
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
				'review_status'              => $status,
				'review_submitted_by'        => 1,
				'review_submitted_at'        => '' !== $submitted ? $submitted : current_time( 'mysql', true ),
				'submitted_translation_hash' => (string) $row->translation_hash,
			)
		);
	}

	public function test_total_is_distinct_object_count_not_segment_count(): void {
		$sv = $this->add_language( 'sv', 'sv_SE' );
		$a  = $this->create_page( 'A' );
		$b  = $this->create_page( 'B' );

		foreach ( array( 'f1', 'f2', 'f3' ) as $field ) {
			$this->seed_row( (int) $a->ID, (int) $sv->language_id, $field, 'x' );
			$this->seed_row( (int) $b->ID, (int) $sv->language_id, $field, 'y' );
		}

		$result = $this->store->query_review_object_ids(
			array( 'review_status' => Store::REVIEW_PENDING )
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['object_ids'] );
	}

	public function test_card_shows_every_target_language_even_with_no_review_rows(): void {
		$sv   = $this->add_language( 'sv', 'sv_SE' );
		$de   = $this->add_language( 'de', 'de_DE' );
		$da   = $this->add_language( 'da', 'da_DK' );
		$page = $this->create_page( 'About page', '<p>Body.</p>' );

		// Only Swedish has anything submitted for review.
		$this->seed_row( (int) $page->ID, (int) $sv->language_id, 'post_title', 'Om sidan' );

		wp_set_current_user( $this->create_reviewer() );
		$request = new WP_REST_Request( 'GET', '/aiml/v1/workspace/review-queue' );
		$request->set_param( 'per_page', 50 );
		$data = rest_do_request( $request )->get_data();

		$card = null;
		foreach ( (array) ( $data['object_groups'] ?? array() ) as $group ) {
			if ( (int) $group['post_id'] === (int) $page->ID ) {
				$card = $group;
			}
		}
		$this->assertNotNull( $card, 'the object appears as an object_groups card' );

		$by_code = array();
		foreach ( $card['languages'] as $lang ) {
			$by_code[ $lang['language_code'] ] = $lang;
		}
		$this->assertArrayHasKey( 'sv', $by_code );
		$this->assertArrayHasKey( 'de', $by_code, 'German is a first-class tab even with zero review rows' );
		$this->assertArrayHasKey( 'da', $by_code, 'Danish is a first-class tab even with zero review rows' );

		$this->assertNotEmpty( $by_code['sv']['items'] );
		$this->assertSame( array(), $by_code['de']['items'] );
		$this->assertSame( 'not_translated', $by_code['de']['summary']['state'] );
		$this->assertTrue( (bool) $card['object_languages_summary']['forgotten'] );
	}

	public function test_object_pagination_is_stable_with_tied_timestamps(): void {
		$sv    = $this->add_language( 'sv', 'sv_SE' );
		$stamp = gmdate( 'Y-m-d H:i:s' );
		$ids   = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$page  = $this->create_page( 'Page ' . $i );
			$ids[] = (int) $page->ID;
			$this->seed_row( (int) $page->ID, (int) $sv->language_id, 'f1', 'x', $stamp );
		}
		sort( $ids );

		$seen = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$result = $this->store->query_review_object_ids(
				array(
					'review_status' => Store::REVIEW_PENDING,
					'page'          => $page,
					'per_page'      => 2,
				)
			);
			$this->assertSame( 5, $result['total'] );
			foreach ( $result['object_ids'] as $id ) {
				$this->assertArrayNotHasKey( $id, $seen, 'object appears on two pages' );
				$seen[ $id ] = $page;
			}
		}

		$this->assertSame( $ids, array_keys( $seen ), 'deterministic first_submitted ASC, source_id ASC order' );
	}

	public function test_one_objects_languages_are_never_split_across_pages(): void {
		$sv = $this->add_language( 'sv', 'sv_SE' );
		$de = $this->add_language( 'de', 'de_DE' );
		$a  = $this->create_page( 'A' );
		$b  = $this->create_page( 'B' );

		// A submitted first, in BOTH languages; B second.
		$this->seed_row( (int) $a->ID, (int) $sv->language_id, 'f1', 'x', '2026-01-01 00:00:00' );
		$this->seed_row( (int) $a->ID, (int) $de->language_id, 'f1', 'x', '2026-01-01 00:00:01' );
		$this->seed_row( (int) $b->ID, (int) $sv->language_id, 'f1', 'y', '2026-01-02 00:00:00' );

		$page1 = $this->store->query_review_object_ids(
			array(
				'review_status' => Store::REVIEW_PENDING,
				'page'          => 1,
				'per_page'      => 1,
			)
		);
		$this->assertSame( array( (int) $a->ID ), $page1['object_ids'] );

		$rows     = $this->store->review_rows_for_objects( $page1['object_ids'], array(), Store::REVIEW_PENDING );
		$langs    = array_values( array_unique( array_map( static fn( object $r ): int => (int) $r->language_id, $rows ) ) );
		$expected = array( (int) $sv->language_id, (int) $de->language_id );
		sort( $langs );
		sort( $expected );
		$this->assertSame( $expected, $langs, 'both of object A\'s languages load together on its page' );
		$this->assertCount( 2, $rows );
	}

	public function test_approve_all_ready_languages_processes_more_than_50_pending_in_two_languages(): void {
		$sv = $this->add_language( 'sv', 'sv_SE' );
		$de = $this->add_language( 'de', 'de_DE' );

		// Same page, >50 pending segments in EACH language.
		$blocks = '';
		for ( $i = 0; $i < 55; $i++ ) {
			$uuid    = sprintf( '550e8400-e29b-41d4-a716-%012d', $i );
			$blocks .= sprintf(
				'<!-- wp:paragraph {"%1$s":"%2$s"} --><p>Paragraph %3$d</p><!-- /wp:paragraph -->',
				Contract::ATTR_NAME,
				$uuid,
				$i
			);
		}
		$page = $this->create_page( 'Big dual', $blocks );

		foreach ( array( (int) $sv->language_id, (int) $de->language_id ) as $lid ) {
			$load = new WP_REST_Request( 'GET', '/aiml/v1/workspace/' . (int) $page->ID . '/segments' );
			$load->set_param( 'language', $this->languages->find( $lid )->code );
			wp_set_current_user( $this->create_translator_reviewer() );
			$segments = rest_do_request( $load )->get_data()['segments'];
			foreach ( $segments as $segment ) {
				$key = (string) $segment['segment_key'];
				$this->store->save_translation(
					array(
						'source_id'       => (int) $page->ID,
						'language_id'     => $lid,
						'field_key'       => (string) $segment['field_key'],
						'segment_key'     => $key,
						'source_text'     => (string) $segment['source_text'],
						'translated_text' => 'T ' . $lid . ' ' . $key,
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
			$this->assertGreaterThan(
				50,
				(int) $this->store->review_status_counts( Store::SOURCE_POST, (int) $page->ID, $lid )[ Store::REVIEW_PENDING ]
			);
		}

		wp_set_current_user( $this->create_reviewer() );
		$request = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . (int) $page->ID . '/review/approve-object' );
		$request->set_url_params( array( 'post_id' => (int) $page->ID ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'languages' => array( 'sv', 'de' ) ) ) );

		$data = rest_do_request( $request )->get_data();

		$this->assertCount( 2, $data['approved_languages'] );
		foreach ( $data['approved_languages'] as $entry ) {
			$this->assertGreaterThan( 50, $entry['approved_count'], 'all pending approved in ' . $entry['language_code'] );
		}
		foreach ( array( (int) $sv->language_id, (int) $de->language_id ) as $lid ) {
			$this->assertSame(
				0,
				(int) $this->store->review_status_counts( Store::SOURCE_POST, (int) $page->ID, $lid )[ Store::REVIEW_PENDING ],
				'no pending rows left'
			);
		}
	}

	public function test_needs_attention_language_is_not_approved_and_object_is_not_fully_reviewed(): void {
		$sv = $this->add_language( 'sv', 'sv_SE' );
		$de = $this->add_language( 'de', 'de_DE' );

		$page = $this->create_page( 'Mixed', '<p>Body.</p>' );

		// Swedish: clean pending. German: a rejected row => needs_attention.
		$this->seed_row( (int) $page->ID, (int) $sv->language_id, 'post_title', 'Titel sv' );
		$this->seed_row( (int) $page->ID, (int) $sv->language_id, 'post_excerpt', 'Utdrag sv' );
		$this->seed_row( (int) $page->ID, (int) $de->language_id, 'post_title', 'Titel de', '', Store::REVIEW_REJECTED );

		wp_set_current_user( $this->create_reviewer() );
		$request = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . (int) $page->ID . '/review/approve-object' );
		$request->set_url_params( array( 'post_id' => (int) $page->ID ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'languages' => array( 'sv', 'de' ) ) ) );

		$data = rest_do_request( $request )->get_data();

		$approved_codes = array_map( static fn( array $e ): string => $e['language_code'], $data['approved_languages'] );
		$skipped_codes  = array_map( static fn( array $e ): string => $e['language_code'], $data['skipped_languages'] );

		$this->assertContains( 'de', $skipped_codes );
		$this->assertNotContains( 'de', $approved_codes );
		$this->assertTrue( $data['not_fully_reviewed'] );
	}
}
