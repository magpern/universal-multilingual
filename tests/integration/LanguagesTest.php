<?php
/**
 * Language CRUD and the state model.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Language\Languages;
use WP_Error;

/**
 * Two invariants live in PHP because SQL cannot express them: exactly one
 * default, and only legal state transitions. Both are checked here against a
 * real table.
 */
final class LanguagesTest extends AimlTestCase {

	public function test_insert_and_find_round_trip(): void {
		$language = $this->add_language( 'sv', 'sv_SE' );

		$this->assertSame( 'sv', $language->code );
		$this->assertSame( 'sv_SE', $language->locale );
		$this->assertFalse( (bool) $language->is_default );

		$found = $this->languages->find_by_code( 'sv' );

		$this->assertNotNull( $found );
		$this->assertSame( (int) $language->language_id, (int) $found->language_id );
	}

	public function test_codes_are_unique(): void {
		$this->add_language( 'sv', 'sv_SE' );

		$second = $this->languages->insert(
			array(
				'code'   => 'sv',
				'locale' => 'sv_SE',
				'name'   => 'Swedish again',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'aiml_duplicate_code', $second->get_error_code() );
	}

	public function test_locales_are_unique_on_insert(): void {
		$this->add_language( 'sv', 'sv_SE' );

		$second = $this->languages->insert(
			array(
				'code'   => 'se',
				'locale' => 'sv_SE',
				'name'   => 'Swedish clone',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'aiml_duplicate_locale', $second->get_error_code() );
	}

	public function test_update_cannot_take_another_rows_locale(): void {
		$this->add_language( 'sv', 'sv_SE' );
		$german = $this->add_language( 'de', 'de_DE' );

		$result = $this->languages->update( (int) $german->language_id, array( 'locale' => 'sv_SE' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_duplicate_locale', $result->get_error_code() );
	}

	public function test_update_may_keep_its_own_locale(): void {
		$german = $this->add_language( 'de', 'de_DE' );

		$this->assertTrue(
			$this->languages->update(
				(int) $german->language_id,
				array(
					'sort_order' => 5,
					'locale'     => 'de_DE',
				)
			)
		);
	}

	public function test_invalid_code_is_rejected(): void {
		$result = $this->languages->insert(
			array(
				'code'   => 'Svenska',
				'locale' => 'sv_SE',
				'name'   => 'Swedish',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_invalid_code', $result->get_error_code() );
	}

	public function test_new_languages_start_in_preview(): void {
		$id = $this->languages->insert(
			array(
				'code'   => 'de',
				'locale' => 'de_DE',
				'name'   => 'German',
			)
		);

		$language = $this->languages->find( (int) $id );

		$this->assertSame( Languages::STATUS_PREVIEW, $language->status );
	}

	public function test_exactly_one_default_exists(): void {
		$this->add_language( 'sv', 'sv_SE' );
		$this->add_language( 'de', 'de_DE' );

		$defaults = array_filter(
			$this->languages->all(),
			static function ( object $language ): bool {
				return ! empty( $language->is_default );
			}
		);

		$this->assertCount( 1, $defaults );
	}

	public function test_preview_can_be_published_and_returned_to_preview(): void {
		$language = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$id       = (int) $language->language_id;

		$this->assertTrue( $this->languages->update( $id, array( 'status' => Languages::STATUS_PUBLISHED ) ) );
		$this->assertSame( Languages::STATUS_PUBLISHED, $this->languages->find( $id )->status );

		$this->assertTrue( $this->languages->update( $id, array( 'status' => Languages::STATUS_PREVIEW ) ) );
		$this->assertSame( Languages::STATUS_PREVIEW, $this->languages->find( $id )->status );
	}

	public function test_disabled_cannot_jump_straight_to_published(): void {
		$language = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$id       = (int) $language->language_id;

		$this->assertTrue( $this->languages->update( $id, array( 'status' => Languages::STATUS_DISABLED ) ) );

		$result = $this->languages->update( $id, array( 'status' => Languages::STATUS_PUBLISHED ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_invalid_transition', $result->get_error_code() );
		$this->assertSame( Languages::STATUS_DISABLED, $this->languages->find( $id )->status );
	}

	public function test_default_language_stays_published(): void {
		$default = $this->languages->default();

		$this->assertTrue(
			$this->languages->update( (int) $default->language_id, array( 'status' => Languages::STATUS_DISABLED ) )
		);

		$this->assertSame(
			Languages::STATUS_PUBLISHED,
			$this->languages->find( (int) $default->language_id )->status,
			'The default language is the source content and cannot be taken offline.'
		);
	}

	public function test_default_language_cannot_be_deleted(): void {
		$default = $this->languages->default();

		$result = $this->languages->delete( (int) $default->language_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_default_language', $result->get_error_code() );
	}

	/**
	 * Deleting a language must not destroy the work done in it.
	 */
	public function test_deleting_a_language_keeps_its_translations(): void {
		global $wpdb;

		$language = $this->add_language( 'sv', 'sv_SE' );
		$post     = $this->create_page();

		$this->translate( $post, $language, 'post_title', 'Om oss' );

		$this->assertTrue( $this->languages->delete( (int) $language->language_id ) );

		$rows = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . \AIMultilingual\Database\Schema::translations() . ' WHERE language_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $language->language_id
			)
		);

		$this->assertSame( 1, $rows );
	}

	public function test_routable_hides_preview_from_anonymous_viewers(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );

		$codes = array_column( $this->languages->routable( false ), 'code' );

		$this->assertContains( 'en', $codes );
		$this->assertContains( 'sv', $codes );
		$this->assertNotContains( 'de', $codes );

		$codes_for_translator = array_column( $this->languages->routable( true ), 'code' );

		$this->assertContains( 'de', $codes_for_translator );
	}

	public function test_writes_invalidate_the_cached_list(): void {
		$this->assertCount( 1, $this->languages->all() );

		$this->add_language( 'sv', 'sv_SE' );

		$fresh = new Languages( $this->cache );

		$this->assertCount( 2, $fresh->all(), 'A new reader must see the language just written.' );
	}
}
