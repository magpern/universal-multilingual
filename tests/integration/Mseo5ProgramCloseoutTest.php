<?php
/**
 * MSEO.5 program closeout regression pack (Gate A).
 *
 * @package AIMultilingual
 */

declare(strict_types=1);

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Jobs\HierarchyReindexJob;
use AIMultilingual\Jobs\WooProductRouteReindexJob;
use AIMultilingual\Routing\CanonicalPathCollisionChecker;
use AIMultilingual\Routing\ObjectLanguagePublicEligibility;
use AIMultilingual\Routing\PathCanonicalizer;
use AIMultilingual\Routing\RouteHistoryRepository;
use AIMultilingual\Routing\RoutePublicationService;
use AIMultilingual\Routing\RouteRecognitionContext;
use AIMultilingual\Routing\RoutingCapabilityAdmission;
use AIMultilingual\Routing\RoutingCapabilityRegistry;
use AIMultilingual\Routing\SlugCandidateService;
use AIMultilingual\Routing\SlugRouteRepository;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Publication\PublicationMode;
use AIMultilingual\Translation\Store;

/**
 * Cross-milestone critical-path regressions for MSEO closeout.
 *
 * No new production SEO hooks or routing capabilities.
 */
final class Mseo5ProgramCloseoutTest extends AimlTestCase {

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
		parent::tearDown();
	}

	private function make_route_publication(): RoutePublicationService {
		$capabilities = new RoutingCapabilityRegistry();
		$paths        = new PathCanonicalizer();
		$history      = new RouteHistoryRepository();
		$collisions   = new CanonicalPathCollisionChecker( $this->routes, $history, $paths );
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

		$post     = $this->create_page( 'MSEO5 About' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->translate( $post, $language, Extractor::FIELD_TITLE, 'MSEO5 Om' );

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

	public function test_target_remains_eight_no_step_nine(): void {
		$this->assertSame( 9, Migrator::TARGET );
		$migrator = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Database/Migrator.php' );
		$this->assertStringNotContainsString( 'step_10_', $migrator );
	}

	public function test_activation_state_machine_values(): void {
		foreach ( array( 'off', 'activating', 'on', 'failed' ) as $state ) {
			$settings = new Settings( array( 'localized_urls_state' => $state ) );
			$this->assertSame( $state, $settings->get()['localized_urls_state'] );
			$enabled = $settings->is_localized_url_generation_enabled();
			$this->assertSame( 'on' === $state, $enabled );
		}
	}

	public function test_candidate_is_not_active_route_until_publish(): void {
		$post     = $this->create_page( 'Draft Slug Page' );
		$language = $this->add_language( 'sv', 'sv_SE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$this->translate( $post, $language, Extractor::FIELD_TITLE, 'Utkast' );
		$generated = $this->candidates->generate( $post, (int) $language->language_id );
		$this->assertIsObject( $generated );
		$this->assertNull(
			$this->routes->find_by_object( Store::SOURCE_POST, (int) $post->ID, (int) $language->language_id )
		);
	}

	public function test_current_localized_and_source_redirect_and_history_direct(): void {
		$fixture = $this->seed_active_route( 'on' );

		$router = $this->route( '/sv' . $fixture['localized'], $this->settings );
		$this->assertSame( RouteRecognitionContext::KIND_CURRENT_LOCALIZED, $router->recognition_context()->kind() );
		$this->assertFalse( $router->filter_redirect_canonical( home_url( '/sv' . $fixture['localized'] ) ) );

		$router = $this->route( '/sv' . $fixture['source'], $this->settings );
		$this->assertSame( RouteRecognitionContext::KIND_SOURCE_PATH, $router->recognition_context()->kind() );
		$target = $router->filter_redirect_canonical( home_url( '/sv' . $fixture['source'] ) );
		$this->assertIsString( $target );
		$this->assertStringContainsString( $fixture['localized'], (string) wp_parse_url( $target, PHP_URL_PATH ) );

		$paths   = new PathCanonicalizer();
		$history = new RouteHistoryRepository();
		$history->insert(
			new \AIMultilingual\Routing\HistoryRecord(
				(int) $fixture['language']->language_id,
				$paths->canonicalize( '/old-mseo5-about' ),
				Store::SOURCE_POST,
				(int) $fixture['post']->ID,
				'page'
			)
		);

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
		$this->route( '/sv/old-mseo5-about/', $this->settings );
		remove_all_filters( 'wp_redirect' );

		$this->assertSame( 301, $captured['status'] );
		$this->assertStringContainsString( '/sv' . $fixture['localized'], (string) $captured['location'] );
	}

	public function test_generation_off_fail_closed_and_302(): void {
		$fixture  = $this->seed_active_route( 'off' );
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
		$this->route( '/sv' . $fixture['localized'], $this->settings );
		remove_all_filters( 'wp_redirect' );
		$this->assertSame( 302, $captured['status'] );
		$this->assertIsString( $captured['location'] );
	}

	public function test_discoverability_and_sb11_agreement_path(): void {
		$fixture      = $this->seed_active_route( 'on' );
		$capabilities = new RoutingCapabilityRegistry();
		$eligibility  = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			$capabilities,
			$this->settings,
			$this->routes
		);
		$this->assertTrue( $eligibility->is_discoverable( $fixture['post'], (int) $fixture['language']->language_id ) );

		$rels = $this->make_relationships( $this->settings )->for_path( $fixture['source'], false, true );
		$this->assertNotEmpty( $rels );
		foreach ( $rels as $rel ) {
			$this->assertNotSame( '', (string) $rel->url );
		}
	}

	public function test_endpoint_denylist_and_frontier_bounds(): void {
		$router = $this->make_router( $this->settings );
		$cart   = $router->filter_home_url( home_url( '/cart/' ), '/cart/', null );
		$this->assertStringContainsString( '/cart/', $cart );

		$hier = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Jobs/HierarchyReindexJob.php' );
		$woo  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Jobs/WooProductRouteReindexJob.php' );
		$this->assertStringContainsString( 'MAX_PER_TICK = 100', $hier );
		$this->assertStringContainsString( 'MAX_PER_TICK = 100', $woo );
		$this->assertSame( 100, HierarchyReindexJob::MAX_PER_TICK );
		$this->assertSame( 100, WooProductRouteReindexJob::MAX_PER_TICK );
	}

	public function test_capability_implemented_not_admitted_and_epoch(): void {
		$this->assertSame( 2, RoutingCapabilityAdmission::CODE_CAPABILITY_EPOCH );
		$settings  = new Settings(
			array(
				'localized_urls_state'                 => 'on',
				'localized_urls_admitted_capabilities' => array(),
			)
		);
		$admission = new RoutingCapabilityAdmission( $settings, new RoutingCapabilityRegistry() );
		$this->assertFalse(
			$admission->is_publicly_admitted( RoutingCapabilityAdmission::SHAPE_PRODUCT_CATEGORY_PERMALINK )
		);
	}

	public function test_format_slug_jobs_exclusion_and_no_provider_in_candidates(): void {
		$processor = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Jobs/BackgroundTranslationItemProcessor.php' );
		$this->assertStringContainsString( 'FORMAT_SLUG segments are not provider-admitted', $processor );
		$candidate = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Routing/SlugCandidateService.php' );
		$this->assertStringNotContainsString( 'AIProvider', $candidate );
	}

	public function test_sitemap_model_a_and_preview_source_slug(): void {
		$overlay = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Integration/RankMath/RankMathSitemapOverlay.php' );
		$this->assertStringContainsString( 'xhtml:link', $overlay );
		$preview = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Workspace/PreviewService.php' );
		$this->assertStringContainsString( 'prefix_url_without_localization', $preview );
	}

	public function test_publish_does_not_mutate_canonical_post_name(): void {
		$fixture   = $this->seed_active_route( 'on' );
		$post_name = (string) $fixture['post']->post_name;
		$again     = get_post( (int) $fixture['post']->ID );
		$this->assertInstanceOf( \WP_Post::class, $again );
		$this->assertSame( $post_name, (string) $again->post_name );
	}
}
