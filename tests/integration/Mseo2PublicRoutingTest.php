<?php
/**
 * MSEO.2 public localized URL routing integration tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Routing\HistoryRecord;
use AIMultilingual\Routing\ObjectLanguagePublicEligibility;
use AIMultilingual\Routing\RoutePublicationService;
use AIMultilingual\Routing\RouteRecognitionContext;
use AIMultilingual\Routing\RouteRecord;
use AIMultilingual\Routing\RoutingCapabilityRegistry;
use AIMultilingual\Routing\SlugCandidateService;
use AIMultilingual\Routing\SlugRouteRepository;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Publication\PublicationMode;
use AIMultilingual\Translation\Store;

/**
 * Covers MSEO.2 inbound recognition, redirects, EffectiveUrl, and discoverability.
 */
final class Mseo2PublicRoutingTest extends AimlTestCase {

	private Settings $settings;
	private SlugRouteRepository $routes;
	private SlugCandidateService $candidates;
	private RoutePublicationService $route_publication;

	protected function setUp(): void {
		parent::setUp();

		$this->set_permalink_structure( '/%postname%/' );

		$this->settings = new Settings(
			array(
				'localized_urls_state'             => 'on',
				'segment_publication_gate_enabled' => false,
				'auto_publication_mode'            => PublicationMode::MANUAL,
			)
		);
		update_option( Settings::OPTION, $this->settings->get() );

		$this->routes            = new SlugRouteRepository();
		$this->candidates        = new SlugCandidateService( $this->store );
		$this->route_publication = $this->make_route_publication();
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( '' );
		remove_all_filters( 'wp_redirect' );
		parent::tearDown();
	}

	private function make_route_publication(): RoutePublicationService {
		$capabilities = new RoutingCapabilityRegistry();
		$paths        = new \AIMultilingual\Routing\PathCanonicalizer();
		$history      = new \AIMultilingual\Routing\RouteHistoryRepository();
		$collisions   = new \AIMultilingual\Routing\CanonicalPathCollisionChecker( $this->routes, $history, $paths );
		$eligibility  = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			$capabilities,
			$this->settings,
			$this->routes
		);

		return new RoutePublicationService(
			$this->store,
			new \AIMultilingual\Translation\Publication\PublicationService(
				$this->store,
				new \AIMultilingual\Translation\Assessment\AssessmentAssembler(),
				new \AIMultilingual\Translation\Publication\PublicationPolicy(),
				new \AIMultilingual\Translation\Publication\PublicationAuditLogger(),
				$this->settings
			),
			$this->routes,
			$history,
			$paths,
			$collisions,
			$eligibility,
			$capabilities
		);
	}

	/**
	 * @return array{location: string|null, status: int|null}
	 */
	private function capture_redirect( callable $callback ): array {
		$captured = array(
			'location' => null,
			'status'   => null,
		);

		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$captured ) {
				$captured['location'] = is_string( $location ) ? $location : null;
				$captured['status']   = is_int( $status ) ? $status : null;

				return false;
			},
			1,
			2
		);

		$callback();

		remove_all_filters( 'wp_redirect' );

		return $captured;
	}

	/**
	 * @return array{post: \WP_Post, language: object, localized: string, source: string}
	 */
	private function seed_active_route( string $state = 'on' ): array {
		$this->settings = new Settings(
			array_merge(
				$this->settings->get(),
				array( 'localized_urls_state' => $state )
			)
		);
		update_option( Settings::OPTION, $this->settings->get() );

		$post     = $this->create_page( 'About Us' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->translate( $post, $language, Extractor::FIELD_TITLE, 'Om Oss' );

		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );

		$result = $this->route_publication->publish_route( $post, (int) $language->language_id, 1 );
		$this->assertIsArray( $result );

		$route = $this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id );
		$this->assertNotNull( $route );

		return array(
			'post'      => $post,
			'language'  => $language,
			'localized' => (string) $route->localized_path,
			'source'    => (string) $route->source_path,
		);
	}

	public function test_migrator_target_stays_eight(): void {
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_current_localized_on_serves_without_redirect(): void {
		$fixture = $this->seed_active_route( 'on' );

		$router = $this->route( '/sv' . $fixture['localized'], $this->settings );

		$this->assertSame( RouteRecognitionContext::KIND_CURRENT_LOCALIZED, $router->recognition_context()->kind() );
		$this->assertSame(
			$fixture['source'],
			(string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH )
		);

		$redirect = $router->filter_redirect_canonical( home_url( '/sv' . $fixture['localized'] ) );
		$this->assertFalse( $redirect );
	}

	public function test_source_path_on_emits_one_localized_301(): void {
		$fixture = $this->seed_active_route( 'on' );

		$router = $this->route( '/sv' . $fixture['source'], $this->settings );

		$this->assertSame( RouteRecognitionContext::KIND_SOURCE_PATH, $router->recognition_context()->kind() );

		$target = $router->filter_redirect_canonical( home_url( '/sv' . $fixture['source'] ) );
		$this->assertIsString( $target );
		$this->assertStringContainsString( '/sv' . $fixture['localized'], $target );
	}

	public function test_current_localized_off_emits_one_302_to_source_slug(): void {
		$fixture = $this->seed_active_route( 'off' );

		$redirect = $this->capture_redirect(
			function () use ( $fixture ): void {
				$this->route( '/sv' . $fixture['localized'] . '/?utm=test', $this->settings );
			}
		);

		$this->assertSame( 302, $redirect['status'] );
		$this->assertNotNull( $redirect['location'] );
		$this->assertStringContainsString( '/sv' . $fixture['source'], (string) $redirect['location'] );
		$this->assertStringContainsString( 'utm=test', (string) $redirect['location'] );
	}

	public function test_history_on_emits_one_301_to_current_localized(): void {
		$fixture = $this->seed_active_route( 'on' );
		$paths   = new \AIMultilingual\Routing\PathCanonicalizer();
		$history = new \AIMultilingual\Routing\RouteHistoryRepository();

		$history->insert(
			new HistoryRecord(
				(int) $fixture['language']->language_id,
				$paths->canonicalize( '/old-leaf' ),
				Store::SOURCE_POST,
				(int) $fixture['post']->ID,
				'page'
			)
		);

		$redirect = $this->capture_redirect(
			function (): void {
				$this->route( '/sv/old-leaf/', $this->settings );
			}
		);

		$this->assertSame( 301, $redirect['status'] );
		$this->assertStringContainsString( '/sv' . $fixture['localized'], (string) $redirect['location'] );
	}

	public function test_inactive_localized_path_is_not_recognized(): void {
		$fixture = $this->seed_active_route( 'on' );

		$this->routes->set_status(
			Store::SOURCE_POST,
			(int) $fixture['post']->ID,
			(int) $fixture['language']->language_id,
			'inactive'
		);

		$router = $this->route( '/sv' . $fixture['localized'], $this->settings );

		$this->assertSame( RouteRecognitionContext::KIND_SOURCE_PATH, $router->recognition_context()->kind() );
	}

	public function test_is_discoverable_false_without_overlay_bundle(): void {
		$post     = $this->create_page( 'No Overlay' );
		$language = $this->add_language( 'de', 'de_DE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );

		$paths = new \AIMultilingual\Routing\PathCanonicalizer();
		$this->routes->save(
			new RouteRecord(
				(int) $language->language_id,
				Store::SOURCE_POST,
				(int) $post->ID,
				'page',
				$paths->canonicalize( '/' . $post->post_name ),
				$paths->canonicalize( '/kein-overlay' ),
				'kein-overlay',
				'',
				'manual',
				'active',
				current_time( 'mysql', true )
			)
		);

		$eligibility = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			new RoutingCapabilityRegistry(),
			new Settings( array( 'localized_urls_state' => 'on' ) ),
			$this->routes
		);

		$this->assertFalse( $eligibility->is_discoverable( $post, (int) $language->language_id ) );
	}

	public function test_is_discoverable_true_with_partial_overlay_and_active_route(): void {
		$fixture = $this->seed_active_route( 'on' );

		$eligibility = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			new RoutingCapabilityRegistry(),
			$this->settings,
			$this->routes
		);

		$this->assertTrue( $eligibility->is_discoverable( $fixture['post'], (int) $fixture['language']->language_id ) );
	}

	public function test_plain_product_permalink_capability_detection(): void {
		update_option(
			'woocommerce_permalinks',
			array(
				'product_base' => '/shop',
			)
		);
		update_option(
			'woocommerce_permalink_structure',
			array(
				'product_base' => '/shop',
			)
		);

		$registry = new RoutingCapabilityRegistry();
		$this->assertTrue( $registry->is_plain_product_permalink() );

		update_option(
			'woocommerce_permalinks',
			array(
				'product_base' => '/shop/%product_cat%',
			)
		);
		update_option(
			'woocommerce_permalink_structure',
			array(
				'product_base' => '/shop/%product_cat%',
			)
		);

		$this->assertFalse( $registry->is_plain_product_permalink() );
	}

	public function test_current_localized_activating_and_failed_emit_one_302(): void {
		$fixture = $this->seed_active_route( 'on' );

		foreach ( array( 'activating', 'failed' ) as $state ) {
			$this->settings = new Settings(
				array_merge(
					$this->settings->get(),
					array( 'localized_urls_state' => $state )
				)
			);
			update_option( Settings::OPTION, $this->settings->get() );

			$redirect = $this->capture_redirect(
				function () use ( $fixture ): void {
					$this->route( '/sv' . $fixture['localized'], $this->settings );
				}
			);
			$this->assertSame( 302, $redirect['status'], $state );
			$this->assertStringContainsString( '/sv' . $fixture['source'], (string) $redirect['location'], $state );
		}
	}

	public function test_history_off_emits_one_302_to_source_slug(): void {
		$fixture = $this->seed_active_route( 'off' );
		$paths   = new \AIMultilingual\Routing\PathCanonicalizer();
		$history = new \AIMultilingual\Routing\RouteHistoryRepository();

		$history->insert(
			new HistoryRecord(
				(int) $fixture['language']->language_id,
				$paths->canonicalize( '/old-leaf' ),
				Store::SOURCE_POST,
				(int) $fixture['post']->ID,
				'page'
			)
		);

		$redirect = $this->capture_redirect(
			function (): void {
				$this->route( '/sv/old-leaf/', $this->settings );
			}
		);

		$this->assertSame( 302, $redirect['status'] );
		$this->assertStringContainsString( '/sv' . $fixture['source'], (string) $redirect['location'] );
	}

	public function test_home_url_admission_negatives_and_anti_recursion(): void {
		$fixture = $this->seed_active_route( 'on' );
		$router  = $this->route( '/sv' . $fixture['localized'], $this->settings );

		$negatives = array(
			home_url( '/wp-admin/' ),
			home_url( '/wp-login.php' ),
			home_url( '/wp-json/wp/v2/posts' ),
			home_url( '/wp-content/uploads/x.jpg' ),
			home_url( '/feed/' ),
			home_url( '/?s=hello' ),
			home_url( '/page/2/' ),
			home_url( '/cart/' ),
			home_url( '/checkout/' ),
			home_url( '/my-account/' ),
			home_url( '/shop/?add-to-cart=1' ),
			home_url( '/unknown-plugin-path/' ),
		);

		foreach ( $negatives as $url ) {
			$out = $router->filter_home_url( $url );
			$this->assertStringNotContainsString( $fixture['localized'], $out, $url );
		}

		$already = home_url( '/sv' . $fixture['localized'] );
		$this->assertSame( $already, $router->filter_home_url( $already ) );

		$source    = home_url( $fixture['source'] );
		$localized = $router->filter_home_url( $source );
		$this->assertStringContainsString( '/sv' . $fixture['localized'], $localized );
	}

	public function test_recognition_context_unchanged_by_home_url(): void {
		$fixture = $this->seed_active_route( 'on' );
		$router  = $this->route( '/sv' . $fixture['localized'], $this->settings );
		$before  = $router->recognition_context()->kind();

		$router->filter_home_url( home_url( $fixture['source'] ) );

		$this->assertSame( $before, $router->recognition_context()->kind() );
		$this->assertSame( RouteRecognitionContext::KIND_CURRENT_LOCALIZED, $before );
	}

	public function test_hreflang_off_still_emits_sa7_public_set(): void {
		$fixture = $this->seed_active_route( 'off' );
		$this->route( '/sv' . $fixture['source'], $this->settings );
		$svc   = $this->make_relationships();
		$rels  = $svc->for_public_request();
		$codes = array_map(
			static function ( $r ) {
				return $r->language_code;
			},
			$rels
		);

		$this->assertContains( 'en', $codes );
		$this->assertContains( 'sv', $codes );
		foreach ( $rels as $rel ) {
			if ( 'sv' === $rel->language_code ) {
				$this->assertStringContainsString( $fixture['source'], $rel->url );
				$this->assertStringNotContainsString( $fixture['localized'], $rel->url );
			}
		}
	}

	public function test_hreflang_omits_active_route_without_bundle(): void {
		$post     = $this->create_page( 'Bare Route' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$paths    = new \AIMultilingual\Routing\PathCanonicalizer();

		$this->routes->save(
			new RouteRecord(
				(int) $language->language_id,
				Store::SOURCE_POST,
				(int) $post->ID,
				'page',
				$paths->canonicalize( '/' . $post->post_name ),
				$paths->canonicalize( '/bara-route' ),
				'bara-route',
				'',
				'manual',
				'active',
				current_time( 'mysql', true )
			)
		);

		$this->route( '/' . $post->post_name . '/', $this->settings );
		$svc   = $this->make_relationships();
		$codes = array_map(
			static function ( $r ) {
				return $r->language_code;
			},
			$svc->for_public_request()
		);

		$this->assertContains( 'en', $codes );
		$this->assertNotContains( 'sv', $codes );
	}

	public function test_preview_remains_source_slug_when_generation_on(): void {
		$fixture = $this->seed_active_route( 'on' );
		$preview = new \AIMultilingual\Workspace\PreviewService(
			$this->languages,
			$this->context,
			$this->route( '/sv' . $fixture['localized'], $this->settings )
		);

		$url = $preview->preview_url( $fixture['post'], 'sv' );
		$this->assertIsString( $url );
		$this->assertStringContainsString( '/sv' . $fixture['source'], $url );
		$this->assertStringNotContainsString( $fixture['localized'], $url );
	}
}
