<?php
/**
 * Promotion WP-CLI wiring (ADR-0030 §12).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Database\PromotionLogRepository;
use AIMultilingual\Database\PromotionStateRepository;
use AIMultilingual\Promotion\ObjectIdentityResolver;
use AIMultilingual\Promotion\PromotionAudit;
use AIMultilingual\Promotion\PromotionCli;
use AIMultilingual\Promotion\ReviewToken;
use AIMultilingual\Promotion\TranslationConflictDetector;
use AIMultilingual\Promotion\TranslationExportService;
use AIMultilingual\Promotion\TranslationImportService;
use AIMultilingual\Settings;

/**
 * `register()` is a safe no-op without WP_CLI and Plugin.php wires the tree;
 * every command delegates to the same services the REST API uses, which are
 * covered by PromotionExportTest / PromotionDryRunTest / PromotionApplyTest.
 */
final class PromotionCliTest extends AimlTestCase {

	public function test_register_does_not_throw(): void {
		$identities = new ObjectIdentityRepository();
		$export     = new TranslationExportService( $this->store, $this->languages, $identities, new PromotionLogRepository(), new PromotionAudit() );
		$import     = new TranslationImportService(
			$this->store,
			$this->languages,
			new ObjectIdentityResolver( $identities ),
			new PromotionStateRepository(),
			$identities,
			new PromotionLogRepository(),
			new PromotionAudit(),
			new Settings(),
			new TranslationConflictDetector(),
			new ReviewToken()
		);

		PromotionCli::register( $export, $import, new PromotionLogRepository(), $identities );

		$this->assertTrue( true, 'register() must not throw without WP_CLI' );
	}

	public function test_plugin_wires_the_promotion_command_tree(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$this->assertStringContainsString( 'PromotionCli::register(', $plugin );
		$this->assertStringContainsString( 'PromotionController(', $plugin );
	}
}
