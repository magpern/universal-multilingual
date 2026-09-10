<?php
/**
 * AIT1 — shared internal admin navigation across Universal Multilingual screens.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Admin\AdminNavigation;
use AIMultilingual\Glossary\GlossaryCapabilities;
use AIMultilingual\Jobs\JobsCapabilities;

/**
 * The nav model + how it behaves per real user.
 */
final class AitAdminNavigationTest extends AimlTestCase {

	protected function setUp(): void {
		parent::setUp();
		JobsCapabilities::grant_default_roles();
		( new \AIMultilingual\Admin\TranslatorWorkspace( $this->languages ) )->register();
	}

	private function render_for( int $user_id, string $current ): string {
		wp_set_current_user( $user_id );
		ob_start();
		AdminNavigation::render( $current );
		return (string) ob_get_clean();
	}

	public function test_administrator_sees_all_eight_sections_with_the_active_tab(): void {
		$html = $this->render_for(
			(int) self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'aiml-settings'
		);

		foreach ( AdminNavigation::slugs() as $slug ) {
			$this->assertStringContainsString( 'page=' . $slug . '"', $html, "missing tab for {$slug}" );
		}
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertStringContainsString( 'page=aiml-settings" aria-current="page"', $html );
	}

	public function test_editor_without_manage_options_does_not_see_settings_or_diagnostics(): void {
		// The built-in editor role gets aiml_translate + workspace access.
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$html   = $this->render_for( $editor, 'aiml-translator' );

		$this->assertStringNotContainsString( 'page=aiml-settings"', $html );
		$this->assertStringNotContainsString( 'page=aiml-seo-diagnostics"', $html );
		$this->assertStringNotContainsString( 'page=ai-multilingual"', $html );
		// but does see the workspace + translate tabs it can reach.
		$this->assertStringContainsString( 'page=aiml-translator"', $html );
		$this->assertStringContainsString( 'page=aiml-translate"', $html );
	}

	public function test_subscriber_sees_no_navigation_at_all(): void {
		$html = $this->render_for(
			(int) self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			'aiml-settings'
		);
		$this->assertSame( '', $html );
	}

	public function test_glossary_tab_follows_the_glossary_capability(): void {
		$user = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		( new \WP_User( $user ) )->add_cap( GlossaryCapabilities::MANAGE_GLOSSARY );

		$html = $this->render_for( $user, 'aiml-glossary' );
		$this->assertStringContainsString( 'page=aiml-glossary" aria-current="page"', $html );
		// The glossary cap only unlocks the glossary tab — nothing else leaks in.
		$this->assertSame( 1, substr_count( $html, 'aiml-ui-subnav__item' ) );
	}

	public function test_nav_renders_on_the_settings_screen_and_not_on_an_unrelated_screen(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'multilingual_page_aiml-settings' );
		$_GET['page'] = 'aiml-settings';
		$this->assertTrue( AdminNavigation::is_plugin_screen() );

		$_GET['page'] = 'edit-comments';
		$this->assertFalse( AdminNavigation::is_plugin_screen() );
		unset( $_GET['page'] );
		$this->assertFalse( AdminNavigation::is_plugin_screen() );
	}

	public function test_every_slug_is_an_owning_class_constant_not_a_hardcoded_string(): void {
		$this->assertSame( \AIMultilingual\Admin\SettingsPage::MENU_SLUG, AdminNavigation::slugs()[0] );
		$this->assertSame( \AIMultilingual\Admin\SettingsPage::SETTINGS_SLUG, AdminNavigation::slugs()[1] );
		$this->assertSame( \AIMultilingual\Admin\RolloutAdminPage::SLUG, AdminNavigation::slugs()[2] );
		$this->assertSame( \AIMultilingual\Admin\SeoDiagnosticsAdminPage::SLUG, AdminNavigation::slugs()[3] );
		$this->assertSame( \AIMultilingual\Admin\Editor::MENU_SLUG, AdminNavigation::slugs()[4] );
		$this->assertSame( \AIMultilingual\Admin\TranslatorWorkspace::MENU_SLUG, AdminNavigation::slugs()[5] );
		$this->assertSame( \AIMultilingual\Admin\GlossaryAdminPage::MENU_SLUG, AdminNavigation::slugs()[6] );
		$this->assertSame( \AIMultilingual\Admin\PromotionAdminPage::MENU_SLUG, AdminNavigation::slugs()[7] );
	}

	public function test_plugin_wires_the_navigation(): void {
		$plugin = (string) file_get_contents( __DIR__ . '/../../src/Plugin.php' );
		$this->assertStringContainsString( 'AdminNavigation() )->register()', $plugin );
	}
}
