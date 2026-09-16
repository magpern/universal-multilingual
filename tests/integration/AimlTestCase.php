<?php
/**
 * Shared integration test base.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Cache\Cache;
use AIMultilingual\Database\Migrator;
use AIMultilingual\Language\LanguageContext;
use AIMultilingual\Language\LanguageResolver;
use AIMultilingual\Language\Languages;
use AIMultilingual\Seo\LanguageRelationshipService;
use AIMultilingual\Routing\EffectiveUrlService;
use AIMultilingual\Routing\ObjectLanguagePublicEligibility;
use AIMultilingual\Routing\PathCanonicalizer;
use AIMultilingual\Routing\RouteHistoryRepository;
use AIMultilingual\Routing\RoutingCapabilityRegistry;
use AIMultilingual\Routing\SlugRouteRepository;
use AIMultilingual\Routing\Router;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Renderer;
use AIMultilingual\Translation\Store;
use AIMultilingual\User\PreferredLanguage;
use WP_UnitTestCase;

/**
 * Builds a fresh service graph per test.
 *
 * The plugin's own graph was already wired during bootstrap, but its language
 * context resolved before any language existed, so every one of its filters
 * short-circuits. Rather than tear that down, each test registers its own graph
 * alongside it and drives the context directly — which is also a useful check
 * that the overlays really are inert until a language is resolved.
 * WP_UnitTestCase restores the global hook state between tests, so the extra
 * registrations do not leak.
 */
abstract class AimlTestCase extends WP_UnitTestCase {

	protected Cache $cache;
	protected Languages $languages;
	protected LanguageResolver $resolver;
	protected LanguageContext $context;
	protected Store $store;
	protected Extractor $extractor;

	protected function setUp(): void {
		parent::setUp();

		$this->cache     = new Cache();
		$this->languages = new Languages( $this->cache );
		$this->resolver  = new LanguageResolver();
		$this->context   = new LanguageContext();
		$this->store     = new Store( $this->cache );
		$this->extractor = new Extractor();

		$this->context->set_default( $this->languages->default() );
	}

	protected function tearDown(): void {
		// Language rows survive the test transaction only if something outside
		// it wrote them; clear the shared object cache so the next test does
		// not read a list that no longer matches the database.
		$this->languages->flush();

		// Reset rather than unset: WordPress reads REQUEST_URI again during
		// shutdown when it considers spawning cron.
		$_SERVER['REQUEST_URI'] = '/';

		parent::tearDown();

		// Migration steps issue DDL, which implicitly commits the PHPUnit
		// transaction. A later update_option(TARGET) can then sit in a fresh
		// transaction and be rolled back, leaving aiml_db_version one step
		// behind (e.g. 6 after TARGET 7). Pin after rollback so the suite
		// does not observe a stuck intermediate version.
		if ( (int) get_option( Migrator::OPTION, 0 ) !== Migrator::TARGET ) {
			update_option( Migrator::OPTION, Migrator::TARGET, true );
		}
	}

	/**
	 * Adds a target language and returns its row.
	 *
	 * @param string $code   URL code.
	 * @param string $locale WordPress locale.
	 * @param string $status Language state.
	 */
	protected function add_language( string $code = 'sv', string $locale = 'sv_SE', string $status = Languages::STATUS_PUBLISHED ): object {
		$id = $this->languages->insert(
			array(
				'code'        => $code,
				'locale'      => $locale,
				'name'        => strtoupper( $code ),
				'native_name' => strtoupper( $code ),
				'status'      => Languages::STATUS_PREVIEW,
			)
		);

		$this->assertIsInt( $id, 'Language insert should return an id, not an error: ' . wp_json_encode( $id ) );
		$this->assertGreaterThan( 0, $id, 'Language insert returned a falsy id.' );

		if ( Languages::STATUS_PREVIEW !== $status ) {
			$this->assertTrue( $this->languages->update( $id, array( 'status' => $status ) ) );
		}

		$language = $this->languages->find( $id );

		$this->assertNotNull( $language );

		return $language;
	}

	/**
	 * Creates a plain classic-content page.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post body.
	 * @param string $excerpt Post excerpt.
	 */
	protected function create_page( string $title = 'About Us', string $content = '<p>English body.</p>', string $excerpt = '' ): \WP_Post {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => 'publish',
			)
		);

		$post = get_post( $id );

		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post;
	}

	/**
	 * Stores a translation for one field of a post.
	 *
	 * @param \WP_Post $post        Canonical post.
	 * @param object   $language    Target language.
	 * @param string   $field_key   Field key.
	 * @param string   $translation Translated text.
	 */
	protected function translate( \WP_Post $post, object $language, string $field_key, string $translation ): void {
		$spec    = Extractor::fields()[ $field_key ];
		$sources = $this->extractor->extract( $post );

		$this->assertArrayHasKey( $field_key, $sources, 'The field must be extractable to be translated.' );

		$result = $this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'source_subtype'  => (string) $post->post_type,
				'language_id'     => (int) $language->language_id,
				'field_key'       => $field_key,
				'segment_key'     => $field_key,
				'segment_order'   => (int) $spec['order'],
				'text_format'     => (string) $spec['format'],
				'source_text'     => (string) $sources[ $field_key ]['source_text'],
				'translated_text' => $translation,
			)
		);

		$this->assertTrue( $result );
	}

	/**
	 * Registers overlay filters bound to this test's context.
	 */
	protected function register_renderer(): void {
		( new Renderer( $this->context, $this->store ) )->register();
	}

	/**
	 * Builds a production-shaped SB11 service for integration tests.
	 */
	protected function make_relationships( ?Settings $settings = null ): LanguageRelationshipService {
		$settings = $settings ?? new Settings();

		$paths        = new PathCanonicalizer();
		$routes       = new SlugRouteRepository();
		$capabilities = new RoutingCapabilityRegistry();
		$admission    = new \AIMultilingual\Routing\RoutingCapabilityAdmission( $settings, $capabilities );
		$effective    = new EffectiveUrlService( $settings, $routes, $capabilities, $paths, $this->languages, $admission );
		$eligibility  = new ObjectLanguagePublicEligibility(
			$this->store,
			$this->languages,
			$capabilities,
			$settings,
			$routes,
			$admission
		);

		return new LanguageRelationshipService(
			$this->languages,
			$this->context,
			$effective,
			$eligibility,
			$settings
		);
	}

	/**
	 * Builds a production-shaped Router for integration tests.
	 */
	protected function make_router( ?Settings $settings = null, ?PreferredLanguage $preferred_language = null ): Router {
		$settings = $settings ?? new Settings();

		$paths        = new PathCanonicalizer();
		$routes       = new SlugRouteRepository();
		$history      = new RouteHistoryRepository();
		$capabilities = new RoutingCapabilityRegistry();
		$admission    = new \AIMultilingual\Routing\RoutingCapabilityAdmission( $settings, $capabilities );
		$hierarchy    = new \AIMultilingual\Routing\HierarchyPathBuilder( $routes, $paths );
		$effective    = new EffectiveUrlService( $settings, $routes, $capabilities, $paths, $this->languages, $admission );

		return new Router(
			$this->languages,
			$this->resolver,
			$this->context,
			$effective,
			$settings,
			$paths,
			$routes,
			$history,
			$hierarchy,
			$preferred_language
		);
	}

	/**
	 * Runs the router against a URI the way a real request would.
	 *
	 * Mirrors production ordering: the router resolves on plugins_loaded, well
	 * before WP::main() parses the request.
	 *
	 * @param string $uri Request URI including any language prefix.
	 */
	protected function route( string $uri, ?Settings $settings = null, ?PreferredLanguage $preferred_language = null ): Router {
		$_SERVER['REQUEST_URI'] = $uri;

		$router = $this->make_router( $settings, $preferred_language );
		$router->resolve();

		return $router;
	}
}
