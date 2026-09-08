<?php
/**
 * MSEO.1 slug candidate + prepared route lifecycle integration.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Routing\CanonicalPathCollisionChecker;
use AIMultilingual\Routing\ObjectLanguagePublicEligibility;
use AIMultilingual\Routing\PathCanonicalizer;
use AIMultilingual\Routing\RouteHistoryRepository;
use AIMultilingual\Routing\RoutePublicationService;
use AIMultilingual\Routing\RouteRecord;
use AIMultilingual\Routing\RoutingCapabilityRegistry;
use AIMultilingual\Routing\SlugCandidateService;
use AIMultilingual\Routing\SlugRouteRepository;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Assessment\AssessmentAssembler;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Publication\PublicationAuditLogger;
use AIMultilingual\Translation\Publication\PublicationMode;
use AIMultilingual\Translation\Publication\PublicationPolicy;
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Translation\Store;

/**
 * Covers M1AC slug/route lifecycle against real Store + route repositories.
 */
final class Mseo1SlugLifecycleTest extends AimlTestCase {

	private PublicationService $publication;
	private SlugCandidateService $candidates;
	private SlugRouteRepository $routes;
	private RouteHistoryRepository $history;
	private PathCanonicalizer $paths;
	private RoutePublicationService $route_publication;

	protected function setUp(): void {
		parent::setUp();

		$this->set_permalink_structure( '/%postname%/' );

		update_option(
			Settings::OPTION,
			Settings::sanitize(
				array_merge(
					Settings::defaults(),
					array(
						'segment_publication_gate_enabled' => false,
						'auto_publication_mode'            => PublicationMode::MANUAL,
						'localized_urls_state'             => 'off',
					)
				)
			)
		);

		$this->publication = new PublicationService(
			$this->store,
			new AssessmentAssembler(),
			new PublicationPolicy(),
			new PublicationAuditLogger(),
			new Settings()
		);

		$this->candidates        = new SlugCandidateService( $this->store );
		$this->routes            = new SlugRouteRepository();
		$this->history           = new RouteHistoryRepository();
		$this->paths             = new PathCanonicalizer();
		$this->route_publication = $this->make_route_publication();
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( '' );
		parent::tearDown();
	}

	/**
	 * Mirrors Plugin.php RoutePublicationService wiring.
	 */
	private function make_route_publication(): RoutePublicationService {
		$capabilities = new RoutingCapabilityRegistry();
		$collisions   = new CanonicalPathCollisionChecker( $this->routes, $this->history, $this->paths );
		$eligibility  = new ObjectLanguagePublicEligibility( $this->store, $this->languages, $capabilities, new Settings(), $this->routes );

		return new RoutePublicationService(
			$this->store,
			$this->publication,
			$this->routes,
			$this->history,
			$this->paths,
			$collisions,
			$eligibility,
			$capabilities
		);
	}

	/**
	 * Seeds a translated title overlay (required for generate + eligibility).
	 *
	 * @param \WP_Post $post     Canonical post.
	 * @param object   $language Target language.
	 * @param string   $title    Translated title.
	 */
	private function seed_translated_title( \WP_Post $post, object $language, string $title ): void {
		$this->translate( $post, $language, Extractor::FIELD_TITLE, $title );
	}

	public function test_migrator_target_stays_eight(): void {
		$this->assertSame( 9, Migrator::TARGET );
	}

	public function test_extract_emits_post_name(): void {
		$post     = $this->create_page( 'About Us' );
		$segments = $this->extractor->extract( $post );

		$this->assertArrayHasKey( Extractor::FIELD_SLUG, $segments );
		$this->assertSame( Extractor::FIELD_SLUG, $segments[ Extractor::FIELD_SLUG ]['field_key'] );
		$this->assertSame( Store::FORMAT_SLUG, $segments[ Extractor::FIELD_SLUG ]['text_format'] );
		$this->assertSame( (string) $post->post_name, $segments[ Extractor::FIELD_SLUG ]['source_text'] );
	}

	public function test_generate_from_title_no_provider(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss Sidan' );

		$row = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $row );
		$this->assertSame( 'om-oss-sidan', (string) $row->translated_text );
		$this->assertSame( 'generated', (string) $row->slug_origin );
		$this->assertSame( Store::FORMAT_SLUG, (string) $row->text_format );
		$this->assertSame( '', (string) ( $row->provider ?? '' ) );
	}

	public function test_manual_save_origin_manual_generate_rejected(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$manual = $this->candidates->save_manual( $post, (int) $language->language_id, 'handgjord-slug' );
		$this->assertIsObject( $manual );
		$this->assertSame( 'manual', (string) $manual->slug_origin );
		$this->assertSame( 'handgjord-slug', (string) $manual->translated_text );

		$rejected = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'aiml_slug_manual_locked', $rejected->get_error_code() );

		$still = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, Extractor::FIELD_SLUG );
		$this->assertNotNull( $still );
		$this->assertSame( 'manual', (string) $still->slug_origin );
		$this->assertSame( 'handgjord-slug', (string) $still->translated_text );
	}

	public function test_generic_save_translation_preserves_slug_origin(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$manual = $this->candidates->save_manual( $post, (int) $language->language_id, 'behall-origin' );
		$this->assertIsObject( $manual );

		$ok = $this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => (string) $post->post_type,
				'language_id'     => (int) $language->language_id,
				'field_key'       => Extractor::FIELD_SLUG,
				'segment_key'     => Extractor::FIELD_SLUG,
				'segment_order'   => 1,
				'text_format'     => Store::FORMAT_SLUG,
				'source_text'     => (string) $post->post_name,
				'translated_text' => 'behall-origin',
				'status'          => Store::STATUS_MANUALLY_EDITED,
				'slug_origin'     => 'generated',
			)
		);
		$this->assertTrue( $ok );

		$row = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, Extractor::FIELD_SLUG );
		$this->assertNotNull( $row );
		$this->assertSame( 'manual', (string) $row->slug_origin );
	}

	public function test_generic_publication_rejects_format_slug(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$manual = $this->candidates->save_manual( $post, (int) $language->language_id, 'blockera-publicering' );
		$this->assertIsObject( $manual );

		$result = $this->publication->publish(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			Extractor::FIELD_SLUG,
			false,
			1,
			'manual'
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aiml_slug_publish_requires_route', $result->get_error_code() );

		$row = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, Extractor::FIELD_SLUG );
		$this->assertNotNull( $row );
		$this->assertSame( Store::PUBLISH_UNPUBLISHED, (string) $row->publish_status );
	}

	public function test_publish_route_atomic_while_localized_urls_state_off(): void {
		$settings = new Settings();
		$this->assertSame( 'off', $settings->localized_urls_state() );
		$this->assertFalse( $settings->is_localized_url_generation_enabled() );

		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );

		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $result );
		$this->assertSame( 'published', $result['status'] );
		$this->assertSame( 'synchronized', $result['route_sync_state'] );
		$this->assertTrue( (bool) $result['route_prepared'] );
		$this->assertSame( 'active', $result['active_route_status'] );

		$route = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route );
		$this->assertSame( 'active', (string) $route->route_status );

		$slug = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, Extractor::FIELD_SLUG );
		$this->assertNotNull( $slug );
		$this->assertSame( Store::PUBLISH_PUBLISHED, (string) $slug->publish_status );
		$this->assertSame( (string) $slug->translated_text, (string) $route->localized_slug );
	}

	public function test_collision_adjusts_generated_to_foo_2_candidate_stays_foo(): void {
		$post     = $this->create_page( 'About Us' );
		$other    = $this->create_page( 'Other Page' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Foo' );

		$saved = $this->routes->save(
			new RouteRecord(
				(int) $language->language_id,
				Store::SOURCE_POST,
				(int) $other->ID,
				'page',
				$this->paths->canonicalize( '/' . $other->post_name ),
				$this->paths->canonicalize( '/foo' ),
				'foo',
				'',
				'generated',
				'active',
				current_time( 'mysql', true )
			)
		);
		$this->assertIsObject( $saved );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$this->assertSame( 'foo', (string) $generated->translated_text );

		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $result );
		$this->assertSame( 'foo', $result['slug_candidate'] );
		$this->assertSame( 'foo-2', $result['active_route_slug'] );
		$this->assertSame( 'synchronized', $result['route_sync_state'] );
		$this->assertTrue( (bool) $result['collision_adjusted'] );

		$candidate = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, Extractor::FIELD_SLUG );
		$this->assertNotNull( $candidate );
		$this->assertSame( 'foo', (string) $candidate->translated_text );
		$this->assertSame( 'generated', (string) $candidate->slug_origin );
		$this->assertSame( Store::PUBLISH_PUBLISHED, (string) $candidate->publish_status );
	}

	public function test_edit_candidate_after_route_pending_route_unchanged(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Original Leaf' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$leaf = (string) $generated->translated_text;

		$published = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $published );
		$this->assertSame( 'synchronized', $published['route_sync_state'] );

		$edited = $this->candidates->save_manual( $post, (int) $language->language_id, 'ny-kandidat' );
		$this->assertIsObject( $edited );

		$view = $this->route_publication->sync_view( $post, (int) $language->language_id );
		$this->assertSame( 'pending', $view['route_sync_state'] );
		$this->assertSame( 'ny-kandidat', $view['slug_candidate'] );
		$this->assertSame( $leaf, $view['active_route_slug'] );
		$this->assertSame( 'active', $view['active_route_status'] );
		$this->assertSame( Store::PUBLISH_UNPUBLISHED, $view['slug_candidate_publish_status'] );

		$route = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route );
		$this->assertSame( $leaf, (string) $route->localized_slug );
	}

	public function test_idempotent_republish_preserves_foo_2(): void {
		$post     = $this->create_page( 'About Us' );
		$other    = $this->create_page( 'Other Page' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Foo' );

		$this->routes->save(
			new RouteRecord(
				(int) $language->language_id,
				Store::SOURCE_POST,
				(int) $other->ID,
				'page',
				$this->paths->canonicalize( '/' . $other->post_name ),
				$this->paths->canonicalize( '/foo' ),
				'foo',
				'',
				'generated',
				'active',
				current_time( 'mysql', true )
			)
		);

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );

		$first = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $first );
		$this->assertSame( 'foo-2', $first['active_route_slug'] );
		$this->assertFalse( (bool) $first['idempotent'] );

		// Collision disappears — re-publish must not churn the effective route.
		$this->routes->delete_by_source( Store::SOURCE_POST, (int) $other->ID );

		$second = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $second );
		$this->assertTrue( (bool) $second['idempotent'] );
		$this->assertSame( 'foo-2', $second['active_route_slug'] );
		$this->assertSame( 'foo', $second['slug_candidate'] );
		$this->assertSame( 'synchronized', $second['route_sync_state'] );
	}

	public function test_history_max_and_same_object_reuse_red_blue_red(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$leaves = array( 'red', 'blue', 'green', 'yellow', 'orange', 'purple' );
		foreach ( $leaves as $leaf ) {
			$saved = $this->candidates->save_manual( $post, (int) $language->language_id, $leaf );
			$this->assertIsObject( $saved );
			$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
			$this->assertIsArray( $result, 'publish failed for ' . $leaf . ': ' . wp_json_encode( $result ) );
			$this->assertSame( $leaf, $result['active_route_slug'] );
		}

		$history = $this->history->find_by_source(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			20
		);
		$this->assertCount( RoutePublicationService::HISTORY_MAX, $history );
		$this->assertSame( 5, RoutePublicationService::HISTORY_MAX );

		// red→blue→red reuse: start fresh object so history is deterministic.
		$reuse     = $this->create_page( 'Reuse Page' );
		$language2 = $this->add_language( 'de', 'de_DE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $reuse, $language2, 'Wiederverwenden' );

		foreach ( array( 'red', 'blue' ) as $leaf ) {
			$saved = $this->candidates->save_manual( $reuse, (int) $language2->language_id, $leaf );
			$this->assertIsObject( $saved );
			$result = $this->route_publication->publish_route( $reuse, (int) $language2->language_id, 1 );
			$this->assertIsArray( $result );
		}

		$hist_after_blue = $this->history->find_by_historical_path(
			(int) $language2->language_id,
			$this->paths->canonicalize( '/red' )
		);
		$this->assertNotNull( $hist_after_blue );

		$saved = $this->candidates->save_manual( $reuse, (int) $language2->language_id, 'red' );
		$this->assertIsObject( $saved );
		$back = $this->route_publication->publish_route( $reuse, (int) $language2->language_id, 1 );
		$this->assertIsArray( $back );
		$this->assertSame( 'red', $back['active_route_slug'] );
		$this->assertSame( 'synchronized', $back['route_sync_state'] );

		$this->assertNull(
			$this->history->find_by_historical_path(
				(int) $language2->language_id,
				$this->paths->canonicalize( '/red' )
			)
		);

		$blue_hist = $this->history->find_by_historical_path(
			(int) $language2->language_id,
			$this->paths->canonicalize( '/blue' )
		);
		$this->assertNotNull( $blue_hist );
	}

	public function test_hierarchical_page_can_publish_prepared_route(): void {
		$parent   = $this->create_page( 'Parent Page' );
		$child_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Child Page',
				'post_content' => '<p>Child.</p>',
				'post_status'  => 'publish',
				'post_parent'  => (int) $parent->ID,
			)
		);
		$child    = get_post( $child_id );
		$this->assertInstanceOf( \WP_Post::class, $child );

		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $child, $language, 'Barnsida' );

		$manual = $this->candidates->save_manual( $child, (int) $language->language_id, 'barnsida' );
		$this->assertIsObject( $manual );

		$result = $this->route_publication->publish_route( $child, (int) $language->language_id, 1 );
		$this->assertIsArray( $result );
		$this->assertTrue( ! empty( $result['route_prepared'] ) );

		$view = $this->route_publication->sync_view( $child, (int) $language->language_id );
		$this->assertTrue( (bool) $view['route_prepared'] );
		$this->assertSame( 'barnsida', (string) $view['active_route_slug'] );
	}

	public function test_publish_route_does_not_mutate_canonical_post_name(): void {
		$post     = $this->create_page( 'About Us' );
		$before   = (string) $post->post_name;
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $result );

		$fresh = get_post( (int) $post->ID );
		$this->assertInstanceOf( \WP_Post::class, $fresh );
		$this->assertSame( $before, (string) $fresh->post_name );
	}

	public function test_manual_collision_returns_409_candidate_unchanged(): void {
		$post     = $this->create_page( 'About Us' );
		$other    = $this->create_page( 'Other Page' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$this->routes->save(
			new RouteRecord(
				(int) $language->language_id,
				Store::SOURCE_POST,
				(int) $other->ID,
				'page',
				$this->paths->canonicalize( '/' . $other->post_name ),
				$this->paths->canonicalize( '/handgjord' ),
				'handgjord',
				'',
				'manual',
				'active',
				current_time( 'mysql', true )
			)
		);

		$manual = $this->candidates->save_manual( $post, (int) $language->language_id, 'handgjord' );
		$this->assertIsObject( $manual );

		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aiml_slug_route_collision', $result->get_error_code() );
		$this->assertSame( 409, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		$row = $this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, Extractor::FIELD_SLUG );
		$this->assertNotNull( $row );
		$this->assertSame( 'handgjord', (string) $row->translated_text );
		$this->assertSame( Store::PUBLISH_UNPUBLISHED, (string) $row->publish_status );
		$this->assertNull( $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id ) );
	}

	public function test_foreign_history_reservation_blocks_publish(): void {
		$post     = $this->create_page( 'About Us' );
		$other    = $this->create_page( 'Other Page' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$inserted = $this->history->insert(
			new \AIMultilingual\Routing\HistoryRecord(
				(int) $language->language_id,
				$this->paths->canonicalize( '/reserverad' ),
				Store::SOURCE_POST,
				(int) $other->ID,
				'page'
			)
		);
		$this->assertIsObject( $inserted );

		$manual = $this->candidates->save_manual( $post, (int) $language->language_id, 'reserverad' );
		$this->assertIsObject( $manual );

		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aiml_slug_history_collision', $result->get_error_code() );
		$this->assertSame( 409, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_refresh_source_path_updates_source_keeps_leaf(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$published = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $published );

		$route_before = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route_before );
		$leaf_before = (string) $route_before->localized_slug;
		$path_before = (string) $route_before->localized_path;

		wp_update_post(
			array(
				'ID'        => (int) $post->ID,
				'post_name' => 'about-us-renamed',
			)
		);
		$post = get_post( (int) $post->ID );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$refreshed = $this->route_publication->refresh_source_path( $post, (int) $language->language_id );
		$this->assertIsObject( $refreshed );

		$route = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route );
		$this->assertSame( $leaf_before, (string) $route->localized_slug );
		$this->assertSame( $path_before, (string) $route->localized_path );
		$this->assertStringContainsString( 'about-us-renamed', (string) $route->source_path );
		$this->assertSame( 0, count( $this->history->find_by_source( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, 20 ) ) );
	}

	public function test_trash_deactivates_untrash_does_not_reactivate(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$published = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $published );

		$this->route_publication->deactivate_for_source( (int) $post->ID );
		$route = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route );
		$this->assertSame( 'inactive', (string) $route->route_status );

		$view = $this->route_publication->sync_view( $post, (int) $language->language_id );
		$this->assertSame( 'inconsistent', $view['route_sync_state'] );

		// Untrash must not silently reactivate — status stays inactive until explicit re-publish.
		$this->assertSame( 'inactive', (string) $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id )->route_status );
	}

	public function test_purge_removes_routes_and_history(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		foreach ( array( 'alpha', 'beta' ) as $leaf ) {
			$saved = $this->candidates->save_manual( $post, (int) $language->language_id, $leaf );
			$this->assertIsObject( $saved );
			$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
			$this->assertIsArray( $result );
		}

		$this->assertNotEmpty( $this->history->find_by_source( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, 20 ) );

		$this->route_publication->purge_for_source( (int) $post->ID );
		$this->assertNull( $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id ) );
		$this->assertSame( array(), $this->history->find_by_source( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id, 20 ) );
	}

	public function test_clear_candidate_resets_origin_route_intact(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$leaf      = (string) $generated->translated_text;
		$published = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $published );

		$cleared = $this->candidates->clear( $post, (int) $language->language_id );
		$this->assertIsObject( $cleared );
		$this->assertSame( '', (string) $cleared->slug_origin );
		$this->assertSame( Store::STATUS_MISSING, (string) $cleared->status );

		$route = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route );
		$this->assertSame( $leaf, (string) $route->localized_slug );
		$this->assertSame( 'active', (string) $route->route_status );
	}

	public function test_is_discoverable_requires_state_on_and_active_route(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->seed_translated_title( $post, $language, 'Om Oss' );

		$settings    = new Settings( array( 'localized_urls_state' => 'off' ) );
		$eligibility = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			new RoutingCapabilityRegistry(),
			$settings,
			$this->routes
		);
		$this->assertFalse( $eligibility->is_discoverable( $post, (int) $language->language_id ) );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $result );

		$settings_on    = new Settings( array( 'localized_urls_state' => 'on' ) );
		$eligibility_on = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			new RoutingCapabilityRegistry(),
			$settings_on,
			$this->routes
		);
		$this->assertTrue( $eligibility_on->is_discoverable( $post, (int) $language->language_id ) );
	}

	public function test_manual_sanitize_drift_rejected(): void {
		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$rejected = $this->candidates->save_manual( $post, (int) $language->language_id, 'Hello World!' );
		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'aiml_slug_sanitize_drift', $rejected->get_error_code() );
	}
}
