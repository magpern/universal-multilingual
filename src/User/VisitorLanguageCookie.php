<?php
/**
 * Authenticated-lifecycle sync for the anonymous visitor language cookie.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

use AIMultilingual\Language\Languages;
use WP_User;

/**
 * The ONE narrowly-scoped PHP path allowed to read/write `aiml_visitor_lang`
 * (ADR-0035, ADR-0024). Every other PHP path in this plugin — Router,
 * LanguageResolver, LanguageContext, Switcher, FloatingSelector, and anything
 * reachable from an anonymous page render — must never touch this cookie:
 * anonymous rendering must stay a pure function of `host + request_uri`.
 *
 * This class is reachable only from `wp_login` and `user_register`, both of
 * which are authenticated or in-progress-auth responses that were never part
 * of the anonymous full-page cache in the first place, so writing a cookie
 * here carries none of ADR-0024's cache-poisoning risk.
 *
 * This is a one-shot repair/seed at the login/registration event, not a
 * continuous sync: a valid account preference is authoritative on every
 * request regardless of the cookie's state (see ADR-0035), so there is no
 * benefit to reconciling the two anywhere else.
 */
final class VisitorLanguageCookie {

	public const COOKIE_NAME = 'aiml_visitor_lang';

	private const MAX_AGE_SECONDS = 31536000; // 1 year.

	/**
	 * Language configuration store.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Authenticated preferred-language service.
	 *
	 * @var PreferredLanguage
	 */
	private PreferredLanguage $preferred_language;

	/**
	 * Issues the cookie: `fn(string $name, string $value, array $options): void`.
	 * Defaults to a real `setcookie()` call, guarded by `headers_sent()`.
	 * Overridable only so tests can observe a write without depending on
	 * whether the test harness has already flushed output — never used to
	 * change production behavior.
	 *
	 * @var callable
	 */
	private $cookie_writer;

	/**
	 * Builds the sync service.
	 *
	 * @param Languages         $languages          Language configuration store.
	 * @param PreferredLanguage $preferred_language Authenticated preference service.
	 * @param callable|null     $cookie_writer      Optional cookie-writer override, for tests.
	 */
	public function __construct( Languages $languages, PreferredLanguage $preferred_language, ?callable $cookie_writer = null ) {
		$this->languages          = $languages;
		$this->preferred_language = $preferred_language;
		$this->cookie_writer      = $cookie_writer ?? static function ( string $name, string $value, array $options ): void {
			if ( headers_sent() ) {
				return;
			}
			setcookie( $name, $value, $options );
		};
	}

	/**
	 * Registers the login/registration lifecycle hooks.
	 */
	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'user_register', array( $this, 'on_register' ) );
	}

	/**
	 * Registration: seeds an empty account preference from a valid, explicit
	 * visitor cookie. The cookie value is untrusted input — normalized and
	 * validated against currently routable languages before any use — and may
	 * only ever initialize the preference of the account being created in this
	 * same request. It never overwrites a preference some other flow already
	 * set (registration only seeds an empty slot).
	 *
	 * `PreferredLanguage::get()/set()` are permission-gated (self or
	 * `edit_user`), and `user_register` fires inside `wp_insert_user()` before
	 * WordPress establishes any current user for the request — so those checks
	 * would otherwise always fail. Two things make it safe to bridge that gap
	 * here: (1) only a genuinely anonymous registration (no other user already
	 * authenticated in this same request) is eligible at all — an admin or
	 * staff member creating an account for someone else must never have their
	 * own cookie seed a stranger's preference; (2) the elevation is scoped to
	 * exactly this one call and always restored, and the value it seeds still
	 * comes from `$_COOKIE`, which reflects the actual requesting browser
	 * regardless of who `get_current_user_id()` reports.
	 *
	 * @param int $user_id Newly created user id.
	 */
	public function on_register( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		if ( get_current_user_id() > 0 ) {
			// Someone else (admin/staff) is creating this account — not a
			// genuine anonymous self-registration. Their cookie must never
			// seed a different account's preference.
			return;
		}

		$code = $this->read_valid_cookie_value();
		if ( null === $code ) {
			return;
		}

		$this->as_user(
			$user_id,
			function () use ( $user_id, $code ): void {
				if ( null !== $this->preferred_language->get( $user_id ) ) {
					return;
				}

				$this->preferred_language->set( $user_id, $code );
			}
		);
	}

	/**
	 * Login: an existing explicit account preference always wins. If the
	 * account has none yet, seed it from a valid visitor cookie (same rule as
	 * registration). Either way, reissue the cookie once to match the
	 * now-effective account preference.
	 *
	 * `wp_login` fires inside `wp_signon()` — `wp_set_auth_cookie()` has
	 * already run, but WordPress does not populate `get_current_user_id()`
	 * for the rest of this request until a later request re-derives it from
	 * the auth cookie. `PreferredLanguage::get()/set()` are permission-gated
	 * on the current user, so this method must temporarily establish it for
	 * exactly the user who just authenticated (see {@see self::as_user()}).
	 *
	 * Accepts a nullable, defaulted `$user` because a third-party caller that
	 * fires `do_action( 'wp_login', $user_login )` with only one argument
	 * must not fatal a strictly-typed two-argument callback.
	 *
	 * @param string       $user_login Unused; required by the `wp_login` signature.
	 * @param WP_User|null $user       Logged-in user, when supplied.
	 */
	public function on_login( string $user_login, ?WP_User $user = null ): void {
		unset( $user_login );

		if ( null === $user || ! isset( $user->ID ) ) {
			return;
		}

		$user_id = (int) $user->ID;
		if ( $user_id <= 0 ) {
			return;
		}

		$existing = null;

		$this->as_user(
			$user_id,
			function () use ( $user_id, &$existing ): void {
				$existing = $this->preferred_language->get( $user_id );

				if ( null === $existing ) {
					$code = $this->read_valid_cookie_value();
					if ( null !== $code ) {
						$result = $this->preferred_language->set( $user_id, $code );
						if ( true === $result ) {
							$existing = $code;
						}
					}
				}
			}
		);

		if ( null !== $existing ) {
			$this->write_cookie( $existing );
		}
	}

	/**
	 * Runs `$callback` with the current user temporarily set to `$user_id`,
	 * always restoring the prior current user afterward (even on exception).
	 * Scoped to this class only — never used from an anonymous render path.
	 *
	 * This fires `set_current_user` from inside `wp_insert_user()`/
	 * `wp_signon()` — a somewhat unusual, reentrant position. A third-party
	 * handler of that action that itself creates or authenticates a user
	 * could in principle recurse; no such handler is known to exist in this
	 * stack, and the callback here is a small, bounded meta read/write.
	 *
	 * @param int      $user_id  User id to act as.
	 * @param callable $callback `fn(): void`.
	 */
	private function as_user( int $user_id, callable $callback ): void {
		$previous_id = get_current_user_id();
		wp_set_current_user( $user_id );

		try {
			$callback();
		} finally {
			wp_set_current_user( $previous_id );
		}
	}

	/**
	 * Reads, normalizes and validates the raw visitor cookie.
	 *
	 * Malformed or unsupported values are treated as absent — never partially
	 * trusted, never surfaced as an error.
	 */
	private function read_valid_cookie_value(): ?string {
		$raw = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized/validated below, never used unsanitized.
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$code = strtolower( trim( $raw ) );
		if ( '' === $code || ! Languages::is_valid_code( $code ) ) {
			return null;
		}

		$row = $this->languages->find_by_code( $code );
		if ( null === $row || Languages::STATUS_PUBLISHED !== (string) $row->status ) {
			return null;
		}

		return $code;
	}

	/**
	 * Reissues the visitor cookie to match a given language code.
	 *
	 * Fires only from an authenticated login response, never from an
	 * anonymous render path — see the class docblock.
	 *
	 * @param string $code Validated language code.
	 */
	private function write_cookie( string $code ): void {
		( $this->cookie_writer )(
			self::COOKIE_NAME,
			$code,
			array(
				'expires'  => time() + self::MAX_AGE_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}
}
