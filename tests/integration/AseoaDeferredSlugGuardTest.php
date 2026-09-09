<?php
/**
 * A.SEOa Deferred admission guardrails (SA1–SA6 / SA8 / SA9).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Routing\Router;
use AIMultilingual\Translation\Extractor;
use AIMultilingual\Translation\Store;
use AIMultilingual\Translation\TermExtractor;
use ReflectionClass;

/**
 * Proves A.SEOa did not accidentally ship Deferred leaf-slug / history scope.
 */
final class AseoaDeferredSlugGuardTest extends AimlTestCase {

	public function test_target_is_eight(): void {
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_term_identity_exists_without_slug_translation(): void {
		// TSC.1 / ADR-0021 introduced SOURCE_TERM deliberately. What A.SEOa
		// deferred was translating the term *slug*, and that is still deferred:
		// only name and description are extractable fields.
		$ref = new ReflectionClass( Store::class );
		$this->assertTrue( $ref->hasConstant( 'SOURCE_TERM' ) );
		$this->assertSame( 'term', Store::SOURCE_TERM );
		$this->assertSame( 'post', Store::SOURCE_POST );

		$this->assertSame(
			array( TermExtractor::FIELD_NAME, TermExtractor::FIELD_DESCRIPTION ),
			array_keys( TermExtractor::fields() )
		);
	}

	public function test_store_has_no_reverse_translated_text_lookup_api(): void {
		$methods = get_class_methods( Store::class );
		foreach ( $methods as $method ) {
			$this->assertDoesNotMatchRegularExpression(
				'/translated_text|by_slug|reverse|lookup_slug|find_by_value/i',
				$method,
				'Store must not expose reverse slug lookup without ADR'
			);
		}
	}

	public function test_mseo_foundation_tables_exist_and_router_owns_active_path_recognition(): void {
		global $wpdb;

		$this->assertSame(
			Schema::slug_routes(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::slug_routes() ) )
		);
		$this->assertSame(
			Schema::route_history(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::route_history() ) )
		);

		$router_source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Routing/Router.php'
		);
		// MSEO.2 activates recognition via active SlugRouteRepository lookups (not rewrite rules).
		$this->assertStringContainsString( 'SlugRouteRepository', $router_source );
		$this->assertStringContainsString( 'find_active_by_localized_path', $router_source );
		$this->assertStringNotContainsString( 'add_rewrite_rule', $router_source );
		$this->assertStringNotContainsString( 'flush_rewrite_rules', $router_source );
	}

	public function test_extractor_emits_post_name_as_format_slug(): void {
		// MSEO.1 admits post_name as an editorial slug candidate segment.
		// Public routing / rewrite / canonical post_name mutation remain deferred.
		$post     = $this->create_page( 'About Us' );
		$segments = $this->extractor->extract( $post );
		$this->assertArrayHasKey( Extractor::FIELD_SLUG, $segments );
		$this->assertSame( Store::FORMAT_SLUG, $segments[ Extractor::FIELD_SLUG ]['text_format'] );
		$this->assertSame( (string) $post->post_name, $segments[ Extractor::FIELD_SLUG ]['source_text'] );
	}

	public function test_slug_candidate_does_not_mutate_canonical_post_name(): void {
		$post   = $this->create_page( 'About Us' );
		$before = (string) $post->post_name;
		$sv     = $this->add_language();

		$this->translate( $post, $sv, Extractor::FIELD_TITLE, 'Om Oss' );
		$candidates = new \AIMultilingual\Routing\SlugCandidateService( $this->store );
		$row        = $candidates->generate( $post, (int) $sv->language_id );
		$this->assertIsObject( $row );

		$fresh = get_post( (int) $post->ID );
		$this->assertInstanceOf( \WP_Post::class, $fresh );
		$this->assertSame( $before, (string) $fresh->post_name );
	}

	public function test_router_register_adds_no_rewrite_rules_and_no_add_rewrite_hooks(): void {
		$router = $this->make_router();
		$router->register();

		global $wp_filter;
		$plugins = $wp_filter['plugins_loaded'] ?? null;
		$this->assertNotNull( $plugins );

		// No AIML rewrite registration hooks for translated bases.
		foreach ( array( 'init', 'generate_rewrite_rules' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $prio => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$fn = $cb['function'] ?? null;
					if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof Router ) {
						$this->fail( 'Router must not attach rewrite generation callbacks' );
					}
				}
			}
		}

		$this->assertFalse( has_action( 'generate_rewrite_rules', array( $router, 'register' ) ) );
	}

	public function test_no_aiml_slug_uniqueness_or_history_classes(): void {
		$paths = array(
			'AIMultilingual\\Routing\\SlugResolver',
			'AIMultilingual\\Routing\\SlugHistory',
			'AIMultilingual\\Routing\\RedirectRegistry',
			'AIMultilingual\\Translation\\SlugStore',
		);
		foreach ( $paths as $class ) {
			$this->assertFalse( class_exists( $class ), $class . ' must not exist in A.SEOa' );
		}
	}

	public function test_format_slug_constant_exists_without_auto_candidate(): void {
		$this->assertSame( 'slug', Store::FORMAT_SLUG );
		// Fresh pages do not auto-create FORMAT_SLUG Store rows; generate is explicit.
		$post = $this->create_page();
		$sv   = $this->add_language();
		$map  = $this->store->load_object( Store::SOURCE_POST, (int) $post->ID, (int) $sv->language_id );
		foreach ( $map as $row ) {
			$this->assertNotSame( Store::FORMAT_SLUG, (string) ( $row->text_format ?? '' ) );
		}
		$this->assertNull(
			$this->store->get( Store::SOURCE_POST, (int) $post->ID, (int) $sv->language_id, Extractor::FIELD_SLUG )
		);
	}
}
