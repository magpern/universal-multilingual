<?php
/**
 * Language prefix routing.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Language\Languages;

/**
 * The prefix-strip model has to satisfy two things at once: `/sv/x/` must reach
 * the same canonical object as `/x/`, and nothing about the stripping may
 * disturb ordinary requests.
 */
final class RoutingTest extends AimlTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Matches the target site's permalink structure.
		$this->set_permalink_structure( '/%postname%/' );
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( '' );

		parent::tearDown();
	}

	public function test_prefixed_url_resolves_the_same_canonical_post(): void {
		$swedish = $this->add_language();
		$post    = $this->create_page( 'About Us' );

		$router = $this->route( '/sv/' . $post->post_name . '/' );

		$this->assertTrue( $router->is_prefixed() );
		$this->assertSame( 'sv', $this->context->current()->code );
		$this->assertSame( (int) $swedish->language_id, $this->context->current_id() );
		$this->assertSame( '/' . $post->post_name . '/', $_SERVER['REQUEST_URI'] );

		$this->go_to( $_SERVER['REQUEST_URI'] );

		$this->assertTrue( is_page() );
		$this->assertSame( (int) $post->ID, (int) get_queried_object_id() );
	}

	public function test_three_segment_code_resolves_and_strips(): void {
		$formal = $this->add_language( 'de-de-formal', 'de_DE_formal' );
		$post   = $this->create_page( 'Impressum' );

		$router = $this->route( '/de-de-formal/' . $post->post_name . '/' );

		$this->assertTrue( $router->is_prefixed() );
		$this->assertSame( 'de-de-formal', $this->context->current()->code );
		$this->assertSame( (int) $formal->language_id, $this->context->current_id() );
		$this->assertSame( '/' . $post->post_name . '/', $_SERVER['REQUEST_URI'] );
	}

	public function test_unprefixed_url_stays_in_the_default_language(): void {
		$this->add_language();
		$post = $this->create_page( 'About Us' );

		$router = $this->route( '/' . $post->post_name . '/' );

		$this->assertFalse( $router->is_prefixed() );
		$this->assertTrue( $this->context->is_default() );
		$this->assertFalse( $this->context->is_translated() );

		$this->go_to( '/' . $post->post_name . '/' );

		$this->assertSame( (int) $post->ID, (int) get_queried_object_id() );
	}

	/**
	 * The prefix is stripped with an exact segment match, not a string prefix.
	 * Getting this wrong would truncate `/svenska-sidan/` to `enska-sidan/`.
	 */
	public function test_slug_beginning_with_the_language_code_is_not_truncated(): void {
		$this->add_language();

		$post = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Svenska sidan',
				'post_name'   => 'svenska-sidan',
				'post_status' => 'publish',
			)
		);

		$this->route( '/sv/svenska-sidan/' );

		$this->assertSame( '/svenska-sidan/', $_SERVER['REQUEST_URI'] );

		$this->go_to( $_SERVER['REQUEST_URI'] );

		$this->assertSame( (int) $post->ID, (int) get_queried_object_id() );
		$this->assertFalse( is_404() );
	}

	public function test_bare_prefix_resolves_the_front_page(): void {
		$this->add_language();

		$router = $this->route( '/sv/' );

		$this->assertTrue( $router->is_prefixed() );
		$this->assertSame( '/', $_SERVER['REQUEST_URI'] );
	}

	public function test_query_string_survives_the_strip(): void {
		$this->add_language();
		$post = $this->create_page();

		$this->route( '/sv/' . $post->post_name . '/?preview=1' );

		$this->assertSame( '/' . $post->post_name . '/?preview=1', $_SERVER['REQUEST_URI'] );
	}

	public function test_unknown_prefix_falls_through_and_404s(): void {
		$this->add_language();

		$router = $this->route( '/xx/nothing-here/' );

		$this->assertFalse( $router->is_prefixed() );
		$this->assertSame( '/xx/nothing-here/', $_SERVER['REQUEST_URI'] );

		$this->go_to( '/xx/nothing-here/' );

		$this->assertTrue( is_404() );
	}

	public function test_preview_language_is_not_routable_for_anonymous_visitors(): void {
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$post = $this->create_page();

		wp_set_current_user( 0 );

		$router = $this->route( '/de/' . $post->post_name . '/' );

		$this->assertFalse( $router->is_prefixed() );
		$this->assertTrue( $this->context->is_default() );

		$this->go_to( '/de/' . $post->post_name . '/' );

		$this->assertTrue( is_404(), 'An unpublished language must not expose content.' );
	}

	public function test_preview_language_is_routable_for_a_translator(): void {
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		$post = $this->create_page();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$router = $this->route( '/de/' . $post->post_name . '/' );

		$this->assertTrue( $router->is_prefixed() );
		$this->assertSame( 'de', $this->context->current()->code );
	}

	public function test_disabled_language_is_never_routable(): void {
		$language = $this->add_language( 'sv', 'sv_SE', Languages::STATUS_PREVIEW );
		$this->languages->update( (int) $language->language_id, array( 'status' => Languages::STATUS_DISABLED ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$router = $this->route( '/sv/anything/' );

		$this->assertFalse( $router->is_prefixed() );
	}

	public function test_default_language_prefix_is_not_a_route(): void {
		$this->add_language();
		$post = $this->create_page();

		$router = $this->route( '/en/' . $post->post_name . '/' );

		$this->assertFalse(
			$router->is_prefixed(),
			'The default language is unprefixed in this milestone; /en/ is not a route.'
		);
		$this->assertSame( '/en/' . $post->post_name . '/', $_SERVER['REQUEST_URI'] );
	}

	public function test_locale_and_language_attributes_follow_the_language(): void {
		$this->add_language();

		$this->route( '/sv/' );

		$this->assertSame( 'sv_SE', get_locale() );
		$this->assertStringContainsString( 'lang="sv-SE"', get_language_attributes() );
		$this->assertStringContainsString( 'dir="ltr"', get_language_attributes() );
	}

	public function test_locale_is_untouched_without_a_prefix(): void {
		$this->add_language();

		$this->route( '/' );

		$this->assertSame( 'en_US', get_locale() );
	}

	public function test_home_url_is_prefixed_only_after_routing(): void {
		$this->add_language();
		$post = $this->create_page();

		$this->route( '/sv/' . $post->post_name . '/' );

		// Before parse_request, home_url() must stay unprefixed: WP::parse_request()
		// subtracts it from the request URI, and a prefixed value there would eat
		// the leading characters of the path.
		$this->assertStringNotContainsString( '/sv', home_url( '/' ) );

		$this->go_to( $_SERVER['REQUEST_URI'] );

		$this->assertStringContainsString( '/sv/', home_url( '/' ) );
		$this->assertSame( (int) $post->ID, (int) get_queried_object_id() );
	}

	public function test_rest_urls_are_never_prefixed(): void {
		$this->add_language();

		$this->route( '/sv/' );
		$this->go_to( '/' );

		$this->assertStringNotContainsString( '/sv/wp-json', rest_url() );
		$this->assertStringNotContainsString( '/sv/', rest_url() );
	}

	public function test_canonical_redirect_is_suppressed_for_prefixed_requests(): void {
		$this->add_language();

		$this->route( '/sv/' );

		$this->assertFalse(
			apply_filters( 'redirect_canonical', 'https://example.org/somewhere/' ),
			'Core must not be allowed to "correct" a prefixed URL back to the unprefixed one.'
		);
	}

	public function test_canonical_redirect_is_untouched_without_a_prefix(): void {
		$this->add_language();

		$this->route( '/' );

		$this->assertSame(
			'https://example.org/somewhere/',
			apply_filters( 'redirect_canonical', 'https://example.org/somewhere/' )
		);
	}

	/**
	 * The URL is the only language authority in this milestone. Setting a
	 * cookie on ordinary front-end responses would make them less cacheable at
	 * the edge for no benefit.
	 */
	public function test_routing_sets_no_cookie(): void {
		$this->add_language();

		$before = $_COOKIE;

		$this->route( '/sv/about/' );

		$this->assertSame( $before, $_COOKIE );
		$this->assertArrayNotHasKey( 'aiml_lang', $_COOKIE );
	}

	/**
	 * The URL is the only anonymous language authority (ADR-0024): for a
	 * cacheable request, host + request URI alone must determine the
	 * rendered language. A cookie, an Accept-Language header or a geo-style
	 * header naming a real, configured language must not change resolution
	 * for the same URL — otherwise a shared full-page cache keyed on the URL
	 * could serve one visitor's language to another.
	 */
	public function test_visitor_state_does_not_change_anonymous_resolution_for_the_same_url(): void {
		$this->add_language(); // 'sv', published.
		$post = $this->create_page( 'About Us' );
		$uri  = '/' . $post->post_name . '/';

		$this->route( $uri );
		$baseline_code    = $this->context->current()->code;
		$baseline_default = $this->context->is_default();

		$_COOKIE['aiml_lang']            = 'sv';
		$_COOKIE['language']             = 'sv';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'sv-SE,sv;q=0.9';
		$_SERVER['HTTP_CF_IPCOUNTRY']    = 'SE';
		$_SERVER['HTTP_X_GEOIP_COUNTRY'] = 'SE';

		try {
			$router = $this->route( $uri );

			$this->assertFalse(
				$router->is_prefixed(),
				'A cookie/header naming a configured language must not turn an unprefixed URL into a prefixed one.'
			);
			$this->assertSame( $baseline_default, $this->context->is_default() );
			$this->assertSame(
				$baseline_code,
				$this->context->current()->code,
				'Resolved language must not change for the same URL based on cookie/Accept-Language/geo state.'
			);
		} finally {
			unset(
				$_COOKIE['aiml_lang'],
				$_COOKIE['language'],
				$_SERVER['HTTP_ACCEPT_LANGUAGE'],
				$_SERVER['HTTP_CF_IPCOUNTRY'],
				$_SERVER['HTTP_X_GEOIP_COUNTRY']
			);
		}
	}

	public function test_admin_requests_are_not_routed(): void {
		$this->add_language();

		set_current_screen( 'dashboard' );

		$router = $this->route( '/sv/about/' );

		$this->assertFalse( $router->is_prefixed() );
		$this->assertSame( '/sv/about/', $_SERVER['REQUEST_URI'] );

		set_current_screen( 'front' );
	}

	/**
	 * Issue #64: a bare non-default language home must not enter a
	 * `redirect_canonical()` self-loop.
	 *
	 * `resolve()` rewrites `REQUEST_URI` to the unprefixed path, so core's own
	 * `$redirect_url !== $requested_url` guard compares against `/` and never
	 * sees that the front-page canonical target — `home_url( '/' )`, which the
	 * `home_url` filter turns back into `/sv/` — is the very URL the visitor
	 * requested. `filter_redirect_canonical()` has to suppress it.
	 *
	 * @dataProvider language_home_provider
	 *
	 * @param string $code   URL code.
	 * @param string $locale WordPress locale.
	 */
	public function test_language_home_does_not_self_redirect( string $code, string $locale ): void {
		$this->add_language( $code, $locale );

		$router = $this->route( '/' . $code . '/' );
		$router->enable_url_prefixing(); // The parse_request hook in production.

		$canonical = home_url( '/' );
		$this->assertStringContainsString( '/' . $code . '/', $canonical, 'sanity: home_url is prefixed' );

		$this->assertFalse(
			$router->filter_redirect_canonical( $canonical ),
			'A canonical redirect straight back to the same language home is a loop.'
		);
	}

	/**
	 * Data for {@see self::test_language_home_does_not_self_redirect()}.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function language_home_provider(): array {
		return array(
			'simple two-letter'  => array( 'sv', 'sv_SE' ),
			'another two-letter' => array( 'de', 'de_DE' ),
			'three-segment code' => array( 'de-de-formal', 'de_DE_formal' ),
		);
	}

	/**
	 * The default (unprefixed) home is untouched — the routing filter is not
	 * even attached for it.
	 */
	public function test_default_language_home_canonical_is_unchanged(): void {
		$this->add_language();

		$router = $this->route( '/' );

		$this->assertSame(
			'https://example.org/somewhere/',
			$router->filter_redirect_canonical( 'https://example.org/somewhere/' )
		);
	}

	/**
	 * `/sv` (no trailing slash) → `/sv/` is a real canonicalisation to a
	 * different URL and must still be issued.
	 */
	public function test_language_home_still_redirects_to_add_a_trailing_slash(): void {
		$this->add_language();

		$router = $this->route( '/sv' );
		$router->enable_url_prefixing();

		$target = home_url( '/' ); // http://example.org/sv/ once filtered.

		$this->assertSame(
			$target,
			$router->filter_redirect_canonical( $target ),
			'/sv must still be allowed to redirect to /sv/.'
		);
	}

	/**
	 * An in-language canonical redirect to a genuinely different path (adding a
	 * trailing slash to an inner route) is preserved.
	 */
	public function test_in_language_trailing_slash_redirect_is_preserved(): void {
		$this->add_language();
		$post = $this->create_page( 'About Us' );

		$router = $this->route( '/sv/' . $post->post_name ); // No trailing slash.
		$router->enable_url_prefixing();

		$target = home_url( '/' . $post->post_name . '/' ); // http://example.org/sv/about-us/ once filtered.

		$this->assertSame(
			$target,
			$router->filter_redirect_canonical( $target ),
			'An in-language redirect to a different URL must still fire.'
		);
	}

	/**
	 * Core trying to "correct" a prefixed URL back to the unprefixed one stays
	 * blocked (ADR-SEOb) — the self-loop guard does not change this.
	 */
	public function test_language_strip_canonical_is_still_blocked(): void {
		$this->add_language();

		$router = $this->route( '/sv/' );

		$this->assertFalse(
			$router->filter_redirect_canonical( 'https://example.org/some-inner-page/' )
		);
	}

	/**
	 * An unknown prefixed inner path still 404s with no canonical loop.
	 */
	public function test_unknown_prefixed_inner_path_404s_without_a_loop(): void {
		$this->add_language();

		$router = $this->route( '/sv/no-such-thing/' );
		$this->go_to( $_SERVER['REQUEST_URI'] );
		$this->assertTrue( is_404() );

		$router->enable_url_prefixing();
		$this->assertFalse(
			$router->filter_redirect_canonical( home_url( '/no-such-thing/' ) ),
			'A canonical redirect back to the same 404 URL would loop.'
		);
	}

	/**
	 * A genuine scheme upgrade (http → https) for the language home is a
	 * different URL, not a self-loop, and must still redirect.
	 */
	public function test_scheme_upgrade_on_the_language_home_is_not_a_self_loop(): void {
		$this->add_language();

		$router = $this->route( '/sv/' );
		$router->enable_url_prefixing();

		$target = set_url_scheme( home_url( '/' ), 'https' ); // https://example.org/sv/ — a real upgrade.

		$this->assertSame(
			$target,
			$router->filter_redirect_canonical( $target ),
			'A scheme upgrade must not be suppressed as a self-redirect.'
		);
	}

	/**
	 * The self-loop guard reads only the request path — no cookie, header or
	 * other visitor state (ADR-0024).
	 */
	public function test_self_loop_guard_ignores_visitor_state(): void {
		$this->add_language();

		$router = $this->route( '/sv/' );
		$router->enable_url_prefixing();

		$_COOKIE['aiml_lang']            = 'en';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';

		try {
			$this->assertFalse( $router->filter_redirect_canonical( home_url( '/' ) ) );
		} finally {
			unset( $_COOKIE['aiml_lang'], $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		}
	}
}
