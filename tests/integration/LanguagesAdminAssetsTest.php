<?php
/**
 * Languages screen asset scoping.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Admin\Languages\LanguagesScreen;
use AIMultilingual\Admin\SettingsPage;

/**
 * The redesigned screen ships CSS and a ComboboxControl script. They must load
 * only on the Languages screen — never globally across wp-admin.
 */
final class LanguagesAdminAssetsTest extends AimlTestCase {

	private function screen(): LanguagesScreen {
		return new LanguagesScreen( $this->languages );
	}

	protected function tearDown(): void {
		// wp_styles()/wp_scripts() are process globals, not rolled back with the
		// test transaction, so clean up what this test enqueued.
		wp_dequeue_style( LanguagesScreen::ASSET_HANDLE );
		wp_deregister_style( LanguagesScreen::ASSET_HANDLE );
		wp_dequeue_script( LanguagesScreen::ASSET_HANDLE );
		wp_deregister_script( LanguagesScreen::ASSET_HANDLE );

		parent::tearDown();
	}

	public function test_assets_enqueue_on_the_languages_screen(): void {
		$this->screen()->enqueue_assets( 'toplevel_page_' . SettingsPage::MENU_SLUG );

		$this->assertTrue( wp_style_is( LanguagesScreen::ASSET_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( LanguagesScreen::ASSET_HANDLE, 'enqueued' ) );

		$script = wp_scripts()->registered[ LanguagesScreen::ASSET_HANDLE ];
		$this->assertContains( 'wp-components', $script->deps );
		$this->assertContains( 'wp-element', $script->deps );
	}

	public function test_assets_do_not_enqueue_on_other_admin_screens(): void {
		foreach ( array( 'index.php', 'edit.php', 'options-general.php', 'plugins.php' ) as $hook ) {
			$this->screen()->enqueue_assets( $hook );
		}

		$this->assertFalse( wp_style_is( LanguagesScreen::ASSET_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( LanguagesScreen::ASSET_HANDLE, 'enqueued' ) );
	}

	public function test_body_class_is_added_only_on_the_languages_screen(): void {
		set_current_screen( 'toplevel_page_' . SettingsPage::MENU_SLUG );
		$this->assertStringContainsString(
			LanguagesScreen::BODY_CLASS,
			$this->screen()->body_class( 'wp-admin' )
		);

		set_current_screen( 'edit.php' );
		$this->assertStringNotContainsString(
			LanguagesScreen::BODY_CLASS,
			$this->screen()->body_class( 'wp-admin' )
		);

		set_current_screen( 'front' );
	}

	public function test_the_asset_files_exist(): void {
		$root = dirname( __DIR__, 2 );
		$this->assertFileExists( $root . '/assets/languages-admin/languages-admin.css' );
		$this->assertFileExists( $root . '/assets/languages-admin/languages-admin.js' );
	}
}
