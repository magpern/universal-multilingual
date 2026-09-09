<?php
/**
 * A.SEOf Deferred / mutation / ownership guards.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Admin\SeoDiagnosticsAdminPage;
use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Seo\Diagnostics\SeoDiagnosticsAdmissions;
use AIMultilingual\Seo\Diagnostics\SeoDiagnosticsService;
use ReflectionClass;

/**
 * Proves A.SEOf stayed observational and did not invent Deferred surfaces.
 */
final class AseofDeferredGuardTest extends AimlTestCase {

	public function test_target_remains_six(): void {
		$this->assertSame( Migrator::TARGET, 9 );
	}

	public function test_admissions_do_not_widen_deferred_upstream(): void {
		foreach ( SeoDiagnosticsAdmissions::deferred_upstream() as $id ) {
			$this->assertFalse( SeoDiagnosticsAdmissions::is_admitted( $id ), $id );
		}
		$this->assertFalse( class_exists( 'AIMultilingual\\Seo\\SitemapDiscovery', false ) );
		$this->assertFalse( class_exists( 'AIMultilingual\\Seo\\SocialMeta', false ) );
		$this->assertFalse( class_exists( 'AIMultilingual\\Seo\\Diagnostics\\SearchConsoleClient', false ) );
		$this->assertFalse( class_exists( 'AIMultilingual\\Seo\\Diagnostics\\SeoCrawlState', false ) );
	}

	public function test_no_diagnostics_persistence_tables(): void {
		foreach ( Schema::all_tables() as $table ) {
			$this->assertDoesNotMatchRegularExpression(
				'/seo_diag|seo_health|seo_crawl|search_console/i',
				(string) $table
			);
		}
	}

	public function test_admin_ui_is_presentation_only(): void {
		$source = (string) file_get_contents(
			(string) ( new ReflectionClass( SeoDiagnosticsAdminPage::class ) )->getFileName()
		);
		$this->assertStringContainsString( 'SeoDiagnosticsService', $source );
		$this->assertStringContainsString( '->scan(', $source );
		$this->assertStringNotContainsString( 'wp_remote_get(', $source );
		$this->assertStringNotContainsString( 'wp_remote_head(', $source );
		$this->assertStringNotContainsString( 'LanguageRelationshipService', $source );
		$this->assertStringNotContainsString( 'preg_match_all', $source );
		$this->assertStringNotContainsString( 'update_option(', $source );
	}

	public function test_diagnostics_core_has_http_budgets(): void {
		$service = (string) file_get_contents(
			(string) ( new ReflectionClass( SeoDiagnosticsService::class ) )->getFileName()
		);
		$options = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Seo/Diagnostics/SeoDiagnosticsOptions.php'
		);
		$this->assertStringContainsString( 'MAX_HTTP_FETCHES', $options );
		$this->assertStringContainsString( 'MAX_REDIRECT_DEPTH', $options );
		$this->assertStringContainsString( 'SeoDiagnosticsOptions::MAX_HTTP_FETCHES', $service );
		$this->assertStringContainsString( 'capped_redirect_depth(', $service );
		$this->assertStringNotContainsString( 'google.com/webmasters', $service );
		$this->assertStringNotContainsString( 'googleapis.com', $service );
		$this->assertStringNotContainsString( 'OAuth', $service );
		$this->assertStringNotContainsString( 'client_secret', $service );
	}

	public function test_sb11_unchanged_by_diagnostics_wave(): void {
		$ref    = new ReflectionClass( \AIMultilingual\Seo\LanguageRelationshipService::class );
		$source = (string) file_get_contents( (string) $ref->getFileName() );
		$this->assertStringNotContainsString( 'Diagnostics', $source );
		$this->assertStringNotContainsString( 'SeoDiagnostics', $source );
	}

	public function test_no_store_mutation_helpers_in_diagnostics(): void {
		$dir = dirname( __DIR__, 2 ) . '/src/Seo/Diagnostics';
		foreach ( glob( $dir . '/*.php' ) as $path ) {
			$code = (string) file_get_contents( $path );
			$this->assertStringNotContainsString( '->upsert(', $code, $path );
			$this->assertStringNotContainsString( '->set_field(', $code, $path );
			$this->assertStringNotContainsString( 'wpdb->query(', $code, $path );
		}
	}
}
