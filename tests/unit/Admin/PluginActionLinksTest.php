<?php
/**
 * PluginActionLinks unit tests (AIT1 / WP8).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Admin;

use AIMultilingual\Admin\PluginActionLinks;
use PHPUnit\Framework\TestCase;

/**
 * The pure link builder: ordering and capability gating.
 */
final class PluginActionLinksTest extends TestCase {

	private PluginActionLinks $links;

	protected function setUp(): void {
		parent::setUp();
		$this->links = new PluginActionLinks();
	}

	/**
	 * @param array<int|string, string> $existing Existing links.
	 * @return array<int|string, string>
	 */
	private function build( array $existing, bool $settings, bool $overview ): array {
		return $this->links->build_action_links(
			$existing,
			$settings,
			$overview,
			'https://example.test/wp-admin/admin.php?page=aiml-settings',
			'https://example.test/wp-admin/admin.php?page=aiml-translator'
		);
	}

	public function test_both_capabilities_prepend_settings_then_overview(): void {
		$result = $this->build( array( 'deactivate' => '<a>Deactivate</a>' ), true, true );

		$this->assertSame(
			array( 'aiml_settings', 'aiml_overview', 'deactivate' ),
			array_keys( $result )
		);
		$this->assertStringContainsString( 'page=aiml-settings', $result['aiml_settings'] );
		$this->assertStringContainsString( '>Settings<', $result['aiml_settings'] );
		$this->assertStringContainsString( 'page=aiml-translator', $result['aiml_overview'] );
		$this->assertStringContainsString( '>Overview<', $result['aiml_overview'] );
	}

	public function test_missing_manage_options_drops_settings_only(): void {
		$result = $this->build( array( 'deactivate' => '<a>Deactivate</a>' ), false, true );

		$this->assertSame( array( 'aiml_overview', 'deactivate' ), array_keys( $result ) );
	}

	public function test_missing_workspace_access_drops_overview_only(): void {
		$result = $this->build( array( 'deactivate' => '<a>Deactivate</a>' ), true, false );

		$this->assertSame( array( 'aiml_settings', 'deactivate' ), array_keys( $result ) );
	}

	public function test_no_capabilities_leaves_existing_links_untouched(): void {
		$existing = array( 'deactivate' => '<a>Deactivate</a>' );

		$this->assertSame( $existing, $this->build( $existing, false, false ) );
	}
}
