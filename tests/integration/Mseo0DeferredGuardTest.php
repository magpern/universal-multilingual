<?php
/**
 * MSEO.0 deferred structural guards (updated for MSEO.2 activation).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Routing\EffectiveUrlService;
use AIMultilingual\Routing\PathHash;
use AIMultilingual\Settings;

/**
 * Proves MSEO foundation boundaries with MSEO.2 activation wired.
 */
final class Mseo0DeferredGuardTest extends AimlTestCase {

	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	public function test_target_is_eight(): void {
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_path_hash_uses_sha256(): void {
		$ref    = new \ReflectionClass( PathHash::class );
		$source = (string) file_get_contents( $ref->getFileName() );
		$this->assertStringContainsString( "hash( 'sha256'", $source );
		$this->assertStringNotContainsString( 'sha1(', $source );
	}

	public function test_slug_route_activation_job_class_exists(): void {
		$this->assertTrue( class_exists( 'AIMultilingual\\Jobs\\SlugRouteActivationJob' ) );
	}

	public function test_effective_url_service_wired_in_plugin(): void {
		$plugin = (string) file_get_contents( $this->root() . '/src/Plugin.php' );
		$this->assertStringContainsString( 'EffectiveUrlService', $plugin );
		$this->assertStringContainsString( 'SlugRouteActivationJob', $plugin );
	}

	public function test_router_has_mseo_inbound_recognition(): void {
		$router = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'SlugRouteRepository', $router );
		$this->assertStringContainsString( 'PathCanonicalizer', $router );
		$this->assertStringContainsString( 'RouteRecognitionContext', $router );
	}

	public function test_no_provider_calls_in_routing_namespace(): void {
		$dir      = $this->root() . '/src/Routing';
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			$this->assertStringNotContainsString( 'Provider', $code, $file->getPathname() );
			$this->assertStringNotContainsString( 'openai', strtolower( $code ), $file->getPathname() );
		}
	}

	public function test_localized_url_settings_page_control_present(): void {
		$page = (string) file_get_contents( $this->root() . '/src/Admin/SettingsPage.php' );
		$this->assertStringContainsString( 'render_localized_urls_settings', $page );
		$this->assertStringContainsString( 'Localized URLs', $page );
	}

	public function test_mseo_tables_exist_and_router_recognizes_routes(): void {
		global $wpdb;

		$this->assertSame(
			Schema::slug_routes(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::slug_routes() ) )
		);

		$router_source = (string) file_get_contents( $this->root() . '/src/Routing/Router.php' );
		$this->assertStringContainsString( 'EffectiveUrlService', $router_source );
		$this->assertStringContainsString( 'find_active_by_localized_path', $router_source );
	}

	public function test_effective_url_service_constructor_includes_route_dependencies(): void {
		$ref    = new \ReflectionClass( EffectiveUrlService::class );
		$params = $ref->getConstructor()->getParameters();
		$this->assertGreaterThanOrEqual( 4, count( $params ) );
		$this->assertSame( Settings::class, $params[0]->getType()->getName() );
	}

	public function test_activation_job_is_verification_only(): void {
		$job = (string) file_get_contents( $this->root() . '/src/Jobs/SlugRouteActivationJob.php' );
		$this->assertStringContainsString( 'aiml_localized_urls_activation_tick', $job );
		$this->assertStringNotContainsString( 'RoutePublicationService', $job );
		$this->assertStringNotContainsString( 'publish_route', $job );
	}
}
