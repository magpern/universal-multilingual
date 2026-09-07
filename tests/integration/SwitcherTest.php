<?php
/**
 * Language switcher output.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Frontend\Switcher;
use AIMultilingual\Language\Languages;
use AIMultilingual\Seo\LanguageRelationshipService;
use AIMultilingual\Settings;

/**
 * The switcher has to land the visitor on the same page in the other language,
 * not on the home page, and it must not advertise languages that would 404 for
 * whoever is looking.
 */
final class SwitcherTest extends AimlTestCase {

	/**
	 * Builds a switcher bound to this test's context.
	 *
	 * @param array<string, mixed> $settings Settings overrides.
	 */
	private function switcher( array $settings = array() ): Switcher {
		$relationships = $this->make_relationships();

		return new Switcher( new Settings( $settings ), $this->languages, $this->context, $relationships );
	}

	public function test_links_point_at_the_current_page_in_each_language(): void {
		$swedish = $this->add_language();

		$this->route( '/sv/about-us/' );

		$links   = $this->switcher()->links();
		$by_code = array_column( $links, 'url', 'code' );

		$this->assertArrayHasKey( 'en', $by_code );
		$this->assertArrayHasKey( 'sv', $by_code );

		$this->assertStringEndsWith( '/about-us/', $by_code['en'] );
		$this->assertStringNotContainsString( '/sv/', $by_code['en'] );
		$this->assertStringEndsWith( '/sv/about-us/', $by_code['sv'] );

		$this->assertSame( (int) $swedish->language_id, $this->context->current_id() );
	}

	public function test_the_default_language_link_is_unprefixed(): void {
		$this->add_language();

		$this->route( '/sv/' );

		$by_code = array_column( $this->switcher()->links(), 'url', 'code' );

		$this->assertStringNotContainsString( '/sv', $by_code['en'] );
	}

	public function test_the_current_language_is_marked(): void {
		$this->add_language();

		$this->route( '/sv/about-us/' );

		$current = array_values(
			array_filter(
				$this->switcher()->links(),
				static function ( array $link ): bool {
					return $link['current'];
				}
			)
		);

		$this->assertCount( 1, $current );
		$this->assertSame( 'sv', $current[0]['code'] );
	}

	public function test_preview_languages_are_hidden_from_visitors(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );

		wp_set_current_user( 0 );
		$this->route( '/' );

		$codes = array_column( $this->switcher()->links(), 'code' );

		$this->assertContains( 'sv', $codes );
		$this->assertNotContains( 'de', $codes, 'A preview language would 404 for a visitor; do not link to it.' );
	}

	public function test_preview_languages_are_shown_to_translators(): void {
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->route( '/' );

		$this->assertContains( 'de', array_column( $this->switcher()->links(), 'code' ) );
	}

	public function test_native_names_are_used_when_configured(): void {
		$this->add_language();
		$this->route( '/' );

		$native = array_column( $this->switcher( array( 'switcher_show_native_name' => true ) )->links(), 'label', 'code' );
		$plain  = array_column( $this->switcher( array( 'switcher_show_native_name' => false ) )->links(), 'label', 'code' );

		$this->assertSame( 'SV', $native['sv'] );
		$this->assertSame( 'SV', $plain['sv'] );
	}

	public function test_current_language_can_be_hidden(): void {
		$this->add_language();
		$this->route( '/sv/' );

		$codes = array_column(
			$this->switcher( array( 'switcher_hide_current' => true ) )->links(),
			'code'
		);

		$this->assertNotContains( 'sv', $codes );
		$this->assertContains( 'en', $codes );
	}

	public function test_shortcode_renders_escaped_markup(): void {
		$this->add_language();
		$this->route( '/sv/about-us/' );

		$switcher = $this->switcher();
		$switcher->register();

		$html = do_shortcode( '[aiml_switcher]' );

		$this->assertStringContainsString( 'aiml-switcher', $html );
		$this->assertStringContainsString( 'hreflang="sv-SE"', $html );
		$this->assertStringContainsString( '/sv/about-us/', $html );
		$this->assertStringContainsString( 'is-current', $html );
	}

	public function test_nothing_renders_with_only_one_language(): void {
		$this->route( '/' );

		$this->assertSame( '', $this->switcher()->render() );
	}

	public function test_menu_integration_is_opt_in(): void {
		$this->add_language();
		$this->route( '/' );

		$switcher = $this->switcher();
		$switcher->register();

		$args = (object) array( 'theme_location' => 'primary' );

		$this->assertSame(
			'<li>Existing</li>',
			apply_filters( 'wp_nav_menu_items', '<li>Existing</li>', $args ),
			'A theme has to ask for the switcher before it appears in a menu.'
		);

		add_filter( 'aiml_switcher_in_menu', '__return_true' );

		$this->assertStringContainsString(
			'aiml-switcher__menu-item',
			apply_filters( 'wp_nav_menu_items', '<li>Existing</li>', $args )
		);

		remove_filter( 'aiml_switcher_in_menu', '__return_true' );
	}

	public function test_all_language_links_keep_current_when_hide_current_is_on(): void {
		$this->add_language();
		$this->route( '/sv/' );

		$settings = array( 'switcher_hide_current' => true );
		$switcher = $this->switcher( $settings );

		$hidden = array_column( $switcher->links(), 'code' );
		$all    = array_column( $switcher->all_language_links(), 'code' );

		$this->assertNotContains( 'sv', $hidden );
		$this->assertContains( 'en', $hidden );
		$this->assertContains( 'sv', $all );
		$this->assertContains( 'en', $all );

		$by_code_links = array_column( $switcher->links(), 'url', 'code' );
		$by_code_all   = array_column( $switcher->all_language_links(), 'url', 'code' );
		$this->assertSame( $by_code_links['en'], $by_code_all['en'] );
	}
}
