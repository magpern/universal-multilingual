<?php
/**
 * Anonymous visitor language cookie: registration/login synchronization (ADR-0035).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Language\Languages;
use AIMultilingual\User\PreferredLanguage;
use AIMultilingual\User\VisitorLanguageCookie;

/**
 * Covers the one narrowly-scoped PHP path allowed to read/write
 * `aiml_visitor_lang` — exercised only via the authenticated `wp_login` and
 * `user_register` hooks, never from an anonymous render path (see
 * PluginGuardTest::test_no_cookie_is_set for the enforced boundary).
 *
 * Deliberately does NOT call `wp_set_current_user()` before invoking
 * `on_register()`/`on_login()` (except where a test specifically wants a
 * *different* already-authenticated actor): `user_register` fires inside
 * `wp_insert_user()` and `wp_login` fires inside `wp_signon()`, both before
 * WordPress core establishes any current user for the request. A test that
 * pre-authenticates as the target user before calling these methods would
 * not reproduce the conditions the hooks actually fire under.
 */
final class VisitorLanguageCookieTest extends AimlTestCase {

	private PreferredLanguage $preference;
	private VisitorLanguageCookie $cookie_service;

	/**
	 * Captured (name, value, options) triples from the injected cookie writer.
	 * The real harness (wp-phpunit) has already flushed output by the time
	 * tests run, so a genuine `setcookie()` call cannot be observed via
	 * `headers_list()` here — this spy exercises the exact same call site
	 * `VisitorLanguageCookie::write_cookie()` would otherwise make.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $cookie_writes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->preference    = new PreferredLanguage( $this->languages );
		$this->cookie_writes = array();

		$this->cookie_service = new VisitorLanguageCookie(
			$this->languages,
			$this->preference,
			function ( string $name, string $value, array $options ): void {
				$this->cookie_writes[] = array( $name, $value, $options );
			}
		);

		$_COOKIE = array();
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Asserts the cookie was written with the given value.
	 *
	 * @param string $expected_value Expected cookie value.
	 */
	private function assert_cookie_set_to( string $expected_value ): void {
		$found = false;
		foreach ( $this->cookie_writes as $write ) {
			if ( VisitorLanguageCookie::COOKIE_NAME === $write[0] && $expected_value === $write[1] ) {
				$found = true;
				// SameSite=Lax + Secure are always requested; is_ssl() drives
				// 'secure' at runtime, so only the always-present keys are checked here.
				$this->assertSame( 'Lax', $write[2]['samesite'] );
				$this->assertFalse( $write[2]['httponly'] );
				$this->assertSame( '/', $write[2]['path'] );
				break;
			}
		}
		$this->assertTrue( $found, 'Expected a cookie write for ' . VisitorLanguageCookie::COOKIE_NAME . '=' . $expected_value );
	}

	private function assert_no_cookie_header_emitted(): void {
		$this->assertSame( array(), $this->cookie_writes );
	}

	public function test_registration_inherits_valid_explicit_cookie(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = 'sv';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->cookie_service->on_register( $user_id );

		$this->assertSame( 'sv', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
	}

	public function test_registration_ignores_malformed_cookie(): void {
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = '../../etc/passwd';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->cookie_service->on_register( $user_id );

		$this->assertSame( '', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
	}

	public function test_registration_ignores_cookie_naming_unrouted_language(): void {
		// 'sv' is never registered, so it is a well-formed but non-routable code.
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = 'sv';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->cookie_service->on_register( $user_id );

		$this->assertSame( '', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
	}

	public function test_registration_never_overwrites_existing_stronger_preference(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PUBLISHED );
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = 'sv';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, PreferredLanguage::META_KEY, 'de' );

		$this->cookie_service->on_register( $user_id );

		$this->assertSame( 'de', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
	}

	public function test_login_seeds_and_reissues_cookie_when_account_has_no_preference(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = 'sv';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );

		$this->cookie_service->on_login( (string) $user->user_login, $user );

		$this->assertSame( 'sv', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
		$this->assert_cookie_set_to( 'sv' );
	}

	public function test_login_existing_account_preference_wins_over_conflicting_cookie(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PUBLISHED );
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = 'sv';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, PreferredLanguage::META_KEY, 'de' );

		$user = get_userdata( $user_id );
		$this->cookie_service->on_login( (string) $user->user_login, $user );

		// Account preference is untouched...
		$this->assertSame( 'de', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
		// ...and the cookie is repaired to match it, not left conflicting.
		$this->assert_cookie_set_to( 'de' );
	}

	public function test_login_repairs_missing_cookie_from_account_preference(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		// No cookie present at all.

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, PreferredLanguage::META_KEY, 'sv' );

		$user = get_userdata( $user_id );
		$this->cookie_service->on_login( (string) $user->user_login, $user );

		$this->assert_cookie_set_to( 'sv' );
	}

	public function test_login_with_no_account_preference_and_no_cookie_writes_nothing(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$user = get_userdata( $user_id );
		$this->cookie_service->on_login( (string) $user->user_login, $user );

		$this->assert_no_cookie_header_emitted();
	}

	public function test_login_ignores_malformed_cookie_and_leaves_account_preference_unset(): void {
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = '<script>';

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$user = get_userdata( $user_id );
		$this->cookie_service->on_login( (string) $user->user_login, $user );

		$this->assertSame( '', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
		$this->assert_no_cookie_header_emitted();
	}

	/**
	 * An admin/staff member creating an account for someone else (current
	 * user already authenticated as someone other than the new account) must
	 * never have their own visitor cookie seed a stranger's preference.
	 */
	public function test_registration_skipped_when_another_user_is_already_authenticated(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$_COOKIE[ VisitorLanguageCookie::COOKIE_NAME ] = 'sv';

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$new_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->cookie_service->on_register( $new_user_id );

		$this->assertSame( '', get_user_meta( $new_user_id, PreferredLanguage::META_KEY, true ) );
	}

	/**
	 * A third-party caller firing `do_action( 'wp_login', $user_login )` with
	 * only one argument must not fatal a strictly-typed two-argument
	 * callback, and must not write anything.
	 */
	public function test_login_with_missing_user_argument_does_nothing(): void {
		$this->cookie_service->on_login( 'someone' );

		$this->assert_no_cookie_header_emitted();
	}
}
