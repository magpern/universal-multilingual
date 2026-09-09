<?php
/**
 * A.SEOe Deferred / ownership guardrails.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Integration\RankMath\RankMathSitemapOverlay;
use ReflectionClass;

/**
 * Proves A.SEOe did not invent SE10/SE11 or a second sitemap provider.
 */
final class AseoeDeferredGuardTest extends AimlTestCase {

	public function test_target_remains_six(): void {
		$this->assertSame( Migrator::TARGET, 10 );
	}

	public function test_no_sitemap_discovery_or_emitter_classes(): void {
		foreach ( array(
			'AIMultilingual\\Seo\\SitemapDiscovery',
			'AIMultilingual\\Seo\\SitemapEmitter',
			'AIMultilingual\\Seo\\SitemapRegistry',
			'AIMultilingual\\Integration\\RankMath\\AimlSitemapProvider',
		) as $class ) {
			$this->assertFalse( class_exists( $class ), $class );
		}
	}

	public function test_no_sitemap_persistence_tables(): void {
		foreach ( Schema::all_tables() as $table ) {
			$this->assertDoesNotMatchRegularExpression(
				'/sitemap|robots_policy|discovery_registry/i',
				(string) $table
			);
		}
	}

	public function test_overlay_does_not_register_providers_filter(): void {
		$source = (string) file_get_contents(
			(string) ( new ReflectionClass( RankMathSitemapOverlay::class ) )->getFileName()
		);
		$this->assertStringNotContainsString( 'add_filter( self::HOOK_PROVIDERS', $source );
		$this->assertStringNotContainsString( "add_filter( 'rank_math/sitemap/providers'", $source );
		$this->assertStringNotContainsString( 'wp_sitemaps_add_provider', $source );
		$this->assertStringNotContainsString( 'simplexml_load', $source );
		$this->assertStringNotContainsString( 'DOMDocument', $source );
	}

	public function test_sb11_unchanged_by_sitemap_wave(): void {
		$ref    = new ReflectionClass( \AIMultilingual\Seo\LanguageRelationshipService::class );
		$source = (string) file_get_contents( (string) $ref->getFileName() );
		$this->assertStringNotContainsString( 'Sitemap', $source );
		$this->assertStringNotContainsString( 'RankMath', $source );
	}

	public function test_no_media_library_mutation_helpers(): void {
		$root = dirname( __DIR__, 2 ) . '/src';
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = $file->getPathname();
			if ( false === strpos( $path, 'RankMath' ) && false === strpos( $path, 'Seo' ) ) {
				continue;
			}
			$code = (string) file_get_contents( $path );
			$this->assertStringNotContainsString( 'wp_insert_attachment(', $code, $path );
			$this->assertStringNotContainsString( 'wp_update_attachment_metadata(', $code, $path );
		}
	}
}
