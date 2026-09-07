<?php
/**
 * Floating selector frontend asset contracts.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CSS/JS must keep unenhanced links usable and must not wait on persistence.
 */
final class FloatingSelectorAssetContractTest extends TestCase {

	private function css(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/frontend/floating-selector.css' );
	}

	private function js(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/frontend/floating-selector.js' );
	}

	public function test_css_does_not_hide_panel_before_enhancement(): void {
		$css = $this->css();
		$this->assertDoesNotMatchRegularExpression(
			'/\.aiml-floating-selector__panel\s*\{[^}]*display\s*:\s*none/',
			$css
		);
		$this->assertStringContainsString( '[data-aiml-enhanced="1"]', $css );
		$this->assertStringContainsString( 'prefers-reduced-motion', $css );
		$this->assertStringContainsString( '--um-edge-z-index: 1000', $css );
		$this->assertStringContainsString( 'safe-area-inset-', $css );
		$this->assertStringContainsString( '--wp-admin--admin-bar--height', $css );
		$this->assertStringContainsString( 'max-width: 781px', $css );
		$this->assertStringContainsString( '--hide-mobile', $css );
		$this->assertStringContainsString( '--hide-desktop', $css );
	}

	public function test_js_uses_keepalive_and_does_not_await_navigation(): void {
		$js = $this->js();
		$this->assertStringContainsString( 'keepalive: true', $js );
		$this->assertStringContainsString( "method: 'POST'", $js );
		$this->assertStringContainsString( 'data-aiml-enhanced', $js );
		$this->assertStringContainsString( 'Escape', $js );
		$this->assertDoesNotMatchRegularExpression(
			'/a\[data-aiml-code\][\s\S]{0,200}preventDefault/',
			$js
		);
		$this->assertStringNotContainsString( 'await ', $js );
		$this->assertStringNotContainsString( 'listbox', $js );
	}
}
