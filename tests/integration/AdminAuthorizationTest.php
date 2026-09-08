<?php
/**
 * Admin capability and nonce enforcement.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Admin\Editor;
use AIMultilingual\Admin\Languages\LanguagesScreen;
use AIMultilingual\Admin\SettingsPage;
use AIMultilingual\Plugin;

/**
 * Milestone 1 has no REST API, so `admin-post.php` handlers are the only write
 * path from a browser. Both halves of the check matter: the capability decides
 * who may write, the nonce decides that they meant to.
 */
final class AdminAuthorizationTest extends AimlTestCase {

	public function test_write_handlers_are_registered(): void {
		( new SettingsPage( new \AIMultilingual\Settings( array() ), $this->languages ) )->register();
		( new Editor( $this->languages, $this->store, $this->extractor ) )->register();

		foreach ( array( 'aiml_save_language', 'aiml_delete_language', 'aiml_save_translation' ) as $action ) {
			$this->assertNotFalse(
				has_action( 'admin_post_' . $action ),
				"Handler for {$action} should be registered."
			);
		}
	}

	/**
	 * The language write handlers now live on LanguagesScreen (extracted from
	 * SettingsPage), which SettingsPage still registers.
	 */
	public function test_language_handlers_resolve_to_the_languages_screen(): void {
		( new SettingsPage( new \AIMultilingual\Settings( array() ), $this->languages ) )->register();

		foreach ( array( 'aiml_save_language', 'aiml_delete_language' ) as $action ) {
			$callbacks = $GLOBALS['wp_filter'][ 'admin_post_' . $action ]->callbacks ?? array();
			$handler   = null;
			foreach ( $callbacks as $priority ) {
				foreach ( $priority as $entry ) {
					$handler = $entry['function'];
				}
			}

			$this->assertIsArray( $handler, "{$action} must be an object method callback." );
			$this->assertInstanceOf(
				LanguagesScreen::class,
				$handler[0],
				"{$action} must be handled by LanguagesScreen."
			);
		}
	}

	/**
	 * There is no `admin_post_nopriv_*` counterpart for any handler, so a
	 * logged-out request cannot reach one at all.
	 */
	public function test_no_handler_is_exposed_to_logged_out_users(): void {
		( new SettingsPage( new \AIMultilingual\Settings( array() ), $this->languages ) )->register();
		( new Editor( $this->languages, $this->store, $this->extractor ) )->register();

		foreach ( array( 'aiml_save_language', 'aiml_delete_language', 'aiml_save_translation' ) as $action ) {
			$this->assertFalse(
				has_action( 'admin_post_nopriv_' . $action ),
				"{$action} must not be reachable without logging in."
			);
		}
	}

	public function test_editor_role_can_translate_but_not_configure(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		$this->assertTrue( current_user_can( Plugin::CAPABILITY ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_administrator_can_do_both(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->assertTrue( current_user_can( Plugin::CAPABILITY ) );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	public function test_subscriber_can_do_neither(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$this->assertFalse( current_user_can( Plugin::CAPABILITY ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_anonymous_visitors_can_do_neither(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can( Plugin::CAPABILITY ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	public function test_settings_are_registered_with_the_sanitizer(): void {
		global $wp_registered_settings;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$page = new SettingsPage( new \AIMultilingual\Settings( array() ), $this->languages );
		$page->register_settings();

		$this->assertArrayHasKey( \AIMultilingual\Settings::OPTION, (array) $wp_registered_settings );
		$this->assertSame(
			array( $page, 'sanitize_settings' ),
			$wp_registered_settings[ \AIMultilingual\Settings::OPTION ]['sanitize_callback'],
			'Settings must be sanitized through the admin settings page callback.'
		);
	}

	/**
	 * The editor screen is gated by the translation capability rather than
	 * manage_options, so translators can work without site-admin rights.
	 */
	public function test_menu_capabilities_are_split(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		global $submenu;
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		( new SettingsPage( new \AIMultilingual\Settings( array() ), $this->languages ) )->add_menus();
		( new Editor( $this->languages, $this->store, $this->extractor ) )->add_menu();

		$capabilities = array();
		foreach ( (array) ( $submenu[ SettingsPage::MENU_SLUG ] ?? array() ) as $item ) {
			$capabilities[ $item[2] ] = $item[1];
		}

		$this->assertSame( 'manage_options', $capabilities[ SettingsPage::SETTINGS_SLUG ] ?? null );
		$this->assertSame( Plugin::CAPABILITY, $capabilities[ Editor::MENU_SLUG ] ?? null );

		set_current_screen( 'front' );
	}
}
