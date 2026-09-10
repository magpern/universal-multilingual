<?php
/**
 * Overlay rendering, source immutability and stale detection.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;

/**
 * The core claim of the whole design: the same WordPress object renders in two
 * languages, and translating never writes to it.
 */
final class TranslationRenderingTest extends AimlTestCase {

	public function test_title_renders_translated_only_in_the_target_language(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->register_renderer();

		// Default language: untouched.
		$this->assertSame( 'About Us', apply_filters( 'the_title', $post->post_title, $post->ID ) );

		$this->context->set_current( $swedish );

		$this->assertSame( 'Om oss', apply_filters( 'the_title', $post->post_title, $post->ID ) );
	}

	public function test_woocommerce_short_description_renders_translated(): void {
		$swedish = $this->add_language();
		$id      = self::factory()->post->create(
			array(
				'post_type'    => 'product',
				'post_title'   => 'IGF-1 LR3',
				'post_content' => 'Long English description.',
				'post_excerpt' => 'English short description.',
				'post_status'  => 'publish',
			)
		);
		$product = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $product );

		$this->translate( $product, $swedish, Extractor::FIELD_EXCERPT, 'Svensk kort beskrivning.' );
		$this->register_renderer();

		$GLOBALS['post'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $product );

		// Default language: untouched (WooCommerce's own wpautop still runs).
		$this->assertStringContainsString(
			'English short description.',
			(string) apply_filters( 'woocommerce_short_description', $product->post_excerpt )
		);

		$this->context->set_current( $swedish );

		// WooCommerce echoes the raw post_excerpt through this filter, never
		// get_the_excerpt — the overlay must catch it here too.
		$out = (string) apply_filters( 'woocommerce_short_description', $product->post_excerpt );
		$this->assertStringContainsString( 'Svensk kort beskrivning.', $out );
		$this->assertStringNotContainsString( 'English short description.', $out );

		wp_reset_postdata();
	}

	public function test_body_renders_translated(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us', '<p>English body.</p>' );

		$this->translate( $post, $swedish, Extractor::FIELD_CONTENT, '<p>Svensk text.</p>' );
		$this->register_renderer();

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$this->context->set_current( $swedish );

		// Trimmed because the overlay runs at priority 1 and core's formatting
		// filters (wpautop and friends) still run afterwards, as they should.
		$this->assertSame(
			'<p>Svensk text.</p>',
			trim( apply_filters( 'the_content', $post->post_content ) )
		);

		wp_reset_postdata();
	}

	/**
	 * `the_content` is applied by plugins to arbitrary strings. Only the queried
	 * post's own stored body may be substituted.
	 */
	public function test_body_overlay_ignores_unrelated_strings(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us', '<p>English body.</p>' );

		$this->translate( $post, $swedish, Extractor::FIELD_CONTENT, '<p>Svensk text.</p>' );
		$this->register_renderer();

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->context->set_current( $swedish );

		$unrelated = '<p>A widget rendered this.</p>';

		$this->assertSame( $unrelated, trim( apply_filters( 'the_content', $unrelated ) ) );

		wp_reset_postdata();
	}

	public function test_manual_excerpt_renders_translated(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us', '<p>Body.</p>', 'English summary.' );

		$this->translate( $post, $swedish, Extractor::FIELD_EXCERPT, 'Svensk sammanfattning.' );
		$this->register_renderer();

		$this->context->set_current( $swedish );

		$this->assertSame(
			'Svensk sammanfattning.',
			apply_filters( 'get_the_excerpt', $post->post_excerpt, $post )
		);
	}

	public function test_document_title_uses_the_translated_title(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->register_renderer();

		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( '/' . $post->post_name . '/' );

		$this->context->set_current( $swedish );

		$parts = apply_filters( 'document_title_parts', array( 'title' => 'About Us' ) );

		$this->assertSame( 'Om oss', $parts['title'] );

		$this->set_permalink_structure( '' );
	}

	public function test_untranslated_fields_fall_back_to_the_source(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->register_renderer();
		$this->context->set_current( $swedish );

		$this->assertSame( 'About Us', apply_filters( 'the_title', $post->post_title, $post->ID ) );
	}

	public function test_overlays_are_inert_in_the_admin(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->register_renderer();
		$this->context->set_current( $swedish );

		set_current_screen( 'edit-page' );

		$this->assertSame(
			'About Us',
			apply_filters( 'the_title', $post->post_title, $post->ID ),
			'The admin must always see canonical content.'
		);

		set_current_screen( 'front' );
	}

	/**
	 * Invariant I1-I3: translating must never write to the canonical object.
	 */
	public function test_the_canonical_post_is_never_modified(): void {
		global $wpdb;

		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us', '<p>English body.</p>', 'English summary.' );

		$before = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT post_title, post_content, post_excerpt, post_name FROM {$wpdb->posts} WHERE ID = %d", $post->ID )
		);

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->translate( $post, $swedish, Extractor::FIELD_CONTENT, '<p>Svensk text.</p>' );
		$this->translate( $post, $swedish, Extractor::FIELD_EXCERPT, 'Svensk sammanfattning.' );

		$this->register_renderer();
		$this->context->set_current( $swedish );

		// Render everything.
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		apply_filters( 'the_title', $post->post_title, $post->ID );
		apply_filters( 'the_content', $post->post_content );
		apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );
		wp_reset_postdata();

		$after = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT post_title, post_content, post_excerpt, post_name FROM {$wpdb->posts} WHERE ID = %d", $post->ID )
		);

		$this->assertEquals( $before, $after, 'A full render cycle must leave the canonical row byte-identical.' );
	}

	public function test_editing_the_source_marks_the_translation_stale_without_touching_it(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );

		$fresh = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $swedish->language_id, Extractor::FIELD_TITLE );
		$this->assertFalse( (bool) $fresh->is_stale );

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'About Our Company',
			)
		);
		// TSC.0: invalidation coalesces until shutdown flush (final-state sync).
		do_action( 'aiml_flush_surface_invalidations' );

		$stale = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $swedish->language_id, Extractor::FIELD_TITLE );

		$this->assertTrue( (bool) $stale->is_stale, 'A changed source must flag the translation for review.' );
		$this->assertSame( 'Om oss', $stale->translated_text, 'The translation itself must survive untouched.' );
		$this->assertSame( Store::STATUS_MANUALLY_EDITED, $stale->status, 'Workflow status must survive untouched.' );
		$this->assertSame( 'About Our Company', $stale->source_text, 'The stored source snapshot should catch up.' );
	}

	/**
	 * Invariant I7: a stale translation keeps rendering. Falling back to the
	 * source mid-page would splice two languages together.
	 */
	public function test_a_stale_translation_still_renders(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'About Our Company',
			)
		);
		do_action( 'aiml_flush_surface_invalidations' );

		$this->register_renderer();
		$this->context->set_current( $swedish );

		$this->assertSame( 'Om oss', apply_filters( 'the_title', 'About Our Company', $post->ID ) );
	}

	public function test_cosmetic_source_edits_do_not_mark_a_translation_stale(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );

		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => '  About   Us  ',
			)
		);
		do_action( 'aiml_flush_surface_invalidations' );

		$segment = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $swedish->language_id, Extractor::FIELD_TITLE );

		$this->assertFalse(
			(bool) $segment->is_stale,
			'Whitespace-only changes to a plain field must not create review work.'
		);
	}

	public function test_saving_an_empty_translation_reverts_the_segment(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, '' );

		$this->register_renderer();
		$this->context->set_current( $swedish );

		$this->assertSame( 'About Us', apply_filters( 'the_title', $post->post_title, $post->ID ) );

		$segment = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $swedish->language_id, Extractor::FIELD_TITLE );

		$this->assertSame( Store::STATUS_MISSING, $segment->status );
	}

	public function test_repeated_saves_upsert_rather_than_duplicate(): void {
		global $wpdb;

		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om företaget' );

		$rows = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . \AIMultilingual\Database\Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE source_id = %d AND language_id = %d',
				$post->ID,
				(int) $swedish->language_id
			)
		);

		$this->assertSame( 1, $rows, 'Segment identity must make writes idempotent.' );

		$segment = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $swedish->language_id, Extractor::FIELD_TITLE );

		$this->assertSame( 'Om företaget', $segment->translated_text );
	}

	public function test_translations_are_isolated_between_languages(): void {
		$swedish = $this->add_language( 'sv', 'sv_SE' );
		$german  = $this->add_language( 'de', 'de_DE' );
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );
		$this->translate( $post, $german, Extractor::FIELD_TITLE, 'Über uns' );

		$this->register_renderer();

		$this->context->set_current( $swedish );
		$this->assertSame( 'Om oss', apply_filters( 'the_title', $post->post_title, $post->ID ) );

		$this->context->set_current( $german );
		$this->assertSame( 'Über uns', apply_filters( 'the_title', $post->post_title, $post->ID ) );

		$this->context->set_current( $swedish );
		$this->assertSame(
			'Om oss',
			apply_filters( 'the_title', $post->post_title, $post->ID ),
			'Alternating languages must not bleed through the object cache.'
		);
	}

	public function test_source_subtype_is_recorded_for_admin_queries(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$this->translate( $post, $swedish, Extractor::FIELD_TITLE, 'Om oss' );

		$segment = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $swedish->language_id, Extractor::FIELD_TITLE );

		$this->assertSame( 'page', $segment->source_subtype );
	}
}
