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
}
