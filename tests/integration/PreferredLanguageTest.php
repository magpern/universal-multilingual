<?php
/**
 * Preferred language storage and authorization.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Language\Languages;
use AIMultilingual\User\PreferredLanguage;
use AIMultilingual\User\SiteDefaultLanguage;
use WP_Error;

/**
 * Covers preference meta, fallbacks, and capability gates.
 */
final class PreferredLanguageTest extends AimlTestCase {

	private PreferredLanguage $preference;

	protected function setUp(): void {
		parent::setUp();
		$this->preference = new PreferredLanguage( $this->languages );
	}

	public function test_valid_save_clear_and_get(): void {
		$default = $this->languages->default();
		$this->assertNotNull( $default );

		$sv = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->preference->set( $user_id, 'sv' );
		$this->assertTrue( $result );
		$this->assertSame( 'sv', $this->preference->get( $user_id ) );
		$this->assertSame( 'sv', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );

		$state = $this->preference->get_state( $user_id );
		$this->assertIsArray( $state );
		$this->assertTrue( $state['available'] );
		$this->assertTrue( $state['editable'] );
		$this->assertSame( PreferredLanguage::SOURCE_STORED, $state['source'] );
		$this->assertSame( 'sv', $state['effective'] );

		$this->assertTrue( $this->preference->set( $user_id, null ) );
		$this->assertSame( '', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
		$state = $this->preference->get_state( $user_id );
		$this->assertSame( PreferredLanguage::SOURCE_FALLBACK_DEFAULT, $state['source'] );
		$this->assertSame( (string) $default->code, $state['effective'] );
	}

	public function test_invalid_save_rejected_and_stale_retained(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$err = $this->preference->set( $user_id, 'xx' );
		$this->assertInstanceOf( WP_Error::class, $err );

		update_user_meta( $user_id, PreferredLanguage::META_KEY, 'gone' );
		$state = $this->preference->get_state( $user_id );
		$this->assertSame( PreferredLanguage::SOURCE_INVALID_FALLBACK, $state['source'] );
		$this->assertSame( 'gone', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
		$this->assertNull( $this->preference->get( $user_id ) );
	}

	public function test_preview_language_not_allowed(): void {
		$preview = $this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$err = $this->preference->set( $user_id, 'de' );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_unauthorized_edit_other_rejected(): void {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $other );

		$err = $this->preference->set( $owner, 'en' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'aiml_forbidden', $err->get_error_code() );
	}

	public function test_admin_can_edit_other_user(): void {
		$sv = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );

		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( $this->preference->set( $owner, 'sv' ) );
		$this->assertSame( 'sv', get_user_meta( $owner, PreferredLanguage::META_KEY, true ) );
	}

	public function test_site_default_language_uses_wplang_not_user_locale(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'locale', 'de_DE' );

		// WP sanitizes WPLANG against installed language packs on update_option.
		// Simulate a configured site language via the option read path without
		// requiring a language pack install in the test harness.
		$filter = static function () {
			return 'sv_SE';
		};
		add_filter( 'pre_option_WPLANG', $filter );

		$this->assertSame( 'sv_SE', SiteDefaultLanguage::locale() );
		$this->assertStringContainsString( '(site default)', SiteDefaultLanguage::label() );
		$this->assertNotSame( 'de_DE', SiteDefaultLanguage::locale() );
		$this->assertNotSame( determine_locale(), SiteDefaultLanguage::locale() );

		remove_filter( 'pre_option_WPLANG', $filter );

		add_filter(
			'pre_option_WPLANG',
			static function () {
				return '';
			}
		);
		$this->assertSame( 'en_US', SiteDefaultLanguage::locale() );
	}
}
