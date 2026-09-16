<?php
/**
 * Authenticated preferred-language redirect off unprefixed URLs.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\User\PreferredLanguage;

/**
 * The stored `aiml_preferred_language` user preference never touches anonymous
 * resolution (URL-authoritative, ADR-0024), but a logged-in visitor landing on
 * a bare unprefixed URL should be sent to their preference exactly once.
 */
final class PreferredLanguageAuthenticatedRedirectTest extends AimlTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->set_permalink_structure( '/%postname%/' );
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( '' );
		remove_all_filters( 'wp_redirect' );

		parent::tearDown();
	}

	public function test_logged_in_user_with_preference_is_redirected_from_unprefixed_url(): void {
		$this->add_language();
		$preferred = new PreferredLanguage( $this->languages );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$preferred->set( $user_id, 'sv' );

		$captured = $this->capture_redirect(
			function () use ( $preferred ): void {
				$this->route( '/', null, $preferred );
			}
		);

		$this->assertSame( 'http://example.org/sv/', $captured['location'] );
		$this->assertSame( 302, $captured['status'] );
	}

	public function test_anonymous_visitor_is_never_redirected(): void {
		$this->add_language();
		$preferred = new PreferredLanguage( $this->languages );

		wp_set_current_user( 0 );

		$captured = $this->capture_redirect(
			function () use ( $preferred ): void {
				$this->route( '/', null, $preferred );
			}
		);

		$this->assertNull( $captured['location'] );
	}

	public function test_post_requests_are_never_redirected(): void {
		$this->add_language();
		$preferred = new PreferredLanguage( $this->languages );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$preferred->set( $user_id, 'sv' );

		$_SERVER['REQUEST_METHOD'] = 'POST';

		$captured = $this->capture_redirect(
			function () use ( $preferred ): void {
				$this->route( '/', null, $preferred );
			}
		);

		unset( $_SERVER['REQUEST_METHOD'] );

		$this->assertNull( $captured['location'] );
	}

	public function test_preference_matching_the_default_language_does_not_redirect(): void {
		$this->add_language();
		$preferred = new PreferredLanguage( $this->languages );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		// No stored preference at all: PreferredLanguage::get() returns null.

		$captured = $this->capture_redirect(
			function () use ( $preferred ): void {
				$this->route( '/', null, $preferred );
			}
		);

		$this->assertNull( $captured['location'] );
	}

	public function test_already_prefixed_url_is_never_overridden(): void {
		$this->add_language();
		$de        = $this->add_language( 'de', 'de_DE' );
		$preferred = new PreferredLanguage( $this->languages );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$preferred->set( $user_id, 'sv' );

		$captured = $this->capture_redirect(
			function () use ( $preferred, $de ): void {
				$this->route( '/' . $de->code . '/', null, $preferred );
			}
		);

		$this->assertNull( $captured['location'] );
	}

	/**
	 * Runs a callback with `wp_redirect` cancelled, capturing location + status.
	 *
	 * @param callable $callback Callback to invoke.
	 * @return array{location: ?string, status: ?int}
	 */
	private function capture_redirect( callable $callback ): array {
		$captured = array(
			'location' => null,
			'status'   => null,
		);

		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$captured ) {
				$captured['location'] = is_string( $location ) ? $location : null;
				$captured['status']   = is_int( $status ) ? $status : null;

				return false;
			},
			1,
			2
		);

		$callback();

		remove_all_filters( 'wp_redirect' );

		return $captured;
	}
}
