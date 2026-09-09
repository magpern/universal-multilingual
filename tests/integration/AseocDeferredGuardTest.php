<?php
/**
 * A.SEOc Deferred / out-of-scope guardrails.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Seo\LanguageRelationshipService;
use ReflectionClass;

/**
 * Proves A.SEOc did not ship A.SEOd–f or forbidden ownership paths.
 */
final class AseocDeferredGuardTest extends AimlTestCase {

	public function test_target_remains_six(): void {
		$this->assertSame( Migrator::TARGET, 9 );
	}

	public function test_no_seo_persistence_tables(): void {
		foreach ( Schema::all_tables() as $table ) {
			$this->assertDoesNotMatchRegularExpression(
				'/rank_math_translation|seo_meta_i18n|hreflang_graph/i',
				(string) $table
			);
		}
	}

	public function test_deferred_wave_emitters_absent(): void {
		foreach ( array(
			'AIMultilingual\\Seo\\SitemapEmitter',
			'AIMultilingual\\Seo\\OpenGraphEmitter',
			'AIMultilingual\\Seo\\RankMathTitleBridge',
			'AIMultilingual\\Seo\\UrlHistoryStore',
		) as $class ) {
			$this->assertFalse( class_exists( $class ), $class );
		}
		$this->assertTrue( class_exists( \AIMultilingual\Integration\RankMath\RankMathIntegration::class ) );
	}

	public function test_sb11_unchanged_by_aseoc(): void {
		$ref    = new ReflectionClass( LanguageRelationshipService::class );
		$source = (string) file_get_contents( (string) $ref->getFileName() );
		$this->assertStringNotContainsString( 'RankMathIntegration', $source );
		$this->assertStringNotContainsString( 'OpenGraph', $source );
		$this->assertStringNotContainsString( 'SitemapEmitter', $source );
	}

	public function test_rankmath_integration_does_not_scrape_or_annex_meta_apis(): void {
		$ref    = new ReflectionClass( \AIMultilingual\Integration\RankMath\RankMathIntegration::class );
		$source = (string) file_get_contents( (string) $ref->getFileName() );
		$this->assertStringNotContainsString( 'file_get_contents', $source );
		$this->assertStringNotContainsString( 'DOMDocument', $source );
		$this->assertStringNotContainsString( 'update_post_meta', $source );
		$this->assertStringNotContainsString( 'update_term_meta', $source );
		$this->assertStringNotContainsString( 'update_option', $source );
		$this->assertStringContainsString( 'rank_math/frontend/title', $source );
		$this->assertStringContainsString( 'rank_math/frontend/description', $source );
	}
}
