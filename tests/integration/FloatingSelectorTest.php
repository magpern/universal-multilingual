<?php
/**
 * Floating language selector presentation.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Frontend\FloatingSelector;
use AIMultilingual\Frontend\Switcher;
use AIMultilingual\Language\Languages;
use AIMultilingual\Settings;
use AIMultilingual\User\PreferenceServices;
use AIMultilingual\User\PreferredLanguage;
use WP_Error;

/**
 * Selector consumes Switcher/SB11 links and must not become language authority.
 */
final class FloatingSelectorTest extends AimlTestCase {

	/**
	 * @param array<string, mixed> $settings Settings overrides.
	 */
	private function selector( array $settings = array() ): FloatingSelector {
		$merged = array_merge(
			array( 'floating_selector_enabled' => true ),
			$settings
		);
		$s      = new Settings( $merged );
		$sw     = new Switcher( $s, $this->languages, $this->context, $this->make_relationships( $s ) );

		return new FloatingSelector( $s, $this->languages, $this->context, $sw );
	}

	private function html( FloatingSelector $selector ): string {
		$selector->prepare_and_enqueue();
		ob_start();
		$selector->render();

		return (string) ob_get_clean();
	}

	public function test_disabled_selector_enqueues_nothing_and_renders_nothing(): void {
		$this->add_language();
		$this->route( '/' );

		$selector = $this->selector( array( 'floating_selector_enabled' => false ) );
		$this->assertNull( $selector->prepared_model() );

		$selector->prepare_and_enqueue();
		$this->assertFalse( wp_style_is( FloatingSelector::STYLE_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( FloatingSelector::SCRIPT_HANDLE, 'enqueued' ) );

		ob_start();
		$selector->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_one_language_does_not_render(): void {
		$this->route( '/' );

		$selector = $this->selector();
		$this->assertNull( $selector->prepared_model() );
		$selector->prepare_and_enqueue();
		$this->assertFalse( wp_style_is( FloatingSelector::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_both_viewport_flags_off_does_not_render(): void {
		$this->add_language();
		$this->route( '/' );

		$selector = $this->selector(
			array(
				'floating_selector_show_desktop' => false,
				'floating_selector_show_mobile'  => false,
			)
		);
		$this->assertNull( $selector->prepared_model() );
	}

	public function test_eligible_selector_enqueues_on_scripts_and_footer_prints_cached_markup(): void {
		$this->add_language();
		$this->route( '/sv/about-us/' );

		$selector = $this->selector();
		$selector->prepare_and_enqueue();

		$this->assertTrue( wp_style_is( FloatingSelector::STYLE_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( FloatingSelector::SCRIPT_HANDLE, 'enqueued' ) );

		$model = $selector->prepared_model();
		$this->assertIsArray( $model );
		$this->assertCount( 2, $model['links'] );

		ob_start();
		$selector->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aiml-floating-selector', $html );
		$this->assertStringContainsString( 'aria-label="Language"', $html );
		$this->assertStringContainsString( 'data-um-edge-control="language"', $html );
		$this->assertStringContainsString( 'data-um-edge="right"', $html );
		$this->assertStringContainsString( 'data-um-edge-slot="1"', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( '/sv/about-us/', $html );
		$this->assertStringNotContainsString( 'data-aiml-enhanced', $html );
	}

	public function test_render_without_enqueue_prints_nothing(): void {
		$this->add_language();
		$this->route( '/' );

		$selector = $this->selector();
		ob_start();
		$selector->render();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_hide_current_does_not_remove_current_from_selector(): void {
		$this->add_language();
		$this->route( '/sv/' );

		$settings = array(
			'switcher_hide_current'     => true,
			'floating_selector_enabled' => true,
		);
		$s        = new Settings( $settings );
		$sw       = new Switcher( $s, $this->languages, $this->context, $this->make_relationships( $s ) );
		$selector = new FloatingSelector( $s, $this->languages, $this->context, $sw );

		$switcher_codes = array_column( $sw->links(), 'code' );
		$model          = $selector->prepared_model();

		$this->assertNotContains( 'sv', $switcher_codes );
		$this->assertIsArray( $model );
		$this->assertContains( 'sv', array_column( $model['links'], 'code' ) );
		$this->assertSame( 'sv', $model['current']['code'] );
		$this->assertSame(
			array_column( $sw->all_language_links(), 'url', 'code' ),
			array_column( $model['links'], 'url', 'code' )
		);
	}

	public function test_preferred_language_does_not_change_selector_current(): void {
		$this->add_language();
		$this->route( '/' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		PreferenceServices::bind_preferred_language( new PreferredLanguage( $this->languages ) );
		$this->assertTrue( aiml_set_preferred_language( $user_id, 'sv' ) );

		$model = $this->selector()->prepared_model();
		$this->assertIsArray( $model );
		$this->assertSame( 'en', $model['current']['code'] );
	}

	public function test_preview_language_hidden_from_anonymous_visitors(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		wp_set_current_user( 0 );
		$this->route( '/' );

		$codes = array_column( $this->selector()->prepared_model()['links'], 'code' );
		$this->assertContains( 'sv', $codes );
		$this->assertNotContains( 'de', $codes );
	}

	public function test_preview_language_visible_to_translator(): void {
		$this->add_language( 'de', 'de_DE', Languages::STATUS_PREVIEW );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->route( '/' );

		$codes = array_column( $this->selector()->prepared_model()['links'], 'code' );
		$this->assertContains( 'de', $codes );
	}

	public function test_disabled_language_is_hidden(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );
		$de = $this->add_language( 'de', 'de_DE', Languages::STATUS_PUBLISHED );
		$this->languages->update( (int) $de->language_id, array( 'status' => Languages::STATUS_DISABLED ) );
		$this->route( '/' );

		$model = $this->selector()->prepared_model();
		$this->assertIsArray( $model );
		$this->assertNotContains( 'de', array_column( $model['links'], 'code' ) );
	}

	public function test_viewport_classes_are_deterministic(): void {
		$this->add_language();
		$this->route( '/' );

		$mobile = $this->html(
			$this->selector(
				array(
					'floating_selector_show_desktop' => false,
					'floating_selector_show_mobile'  => true,
				)
			)
		);
		$this->assertStringContainsString( 'aiml-floating-selector--hide-desktop', $mobile );
		$this->assertStringNotContainsString( 'aiml-floating-selector--hide-mobile', $mobile );

		$desktop = $this->html(
			$this->selector(
				array(
					'floating_selector_show_desktop' => true,
					'floating_selector_show_mobile'  => false,
				)
			)
		);
		$this->assertStringContainsString( 'aiml-floating-selector--hide-mobile', $desktop );
		$this->assertStringNotContainsString( 'aiml-floating-selector--hide-desktop', $desktop );
	}

	public function test_globe_mode_includes_accessible_language_text(): void {
		$this->add_language();
		$this->route( '/' );

		$html = $this->html( $this->selector( array( 'floating_selector_collapsed' => 'globe' ) ) );
		$this->assertStringContainsString( 'aiml-floating-selector__globe', $html );
		$this->assertStringContainsString( 'aiml-floating-selector__sr', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	public function test_source_does_not_build_urls_or_sniff_language(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Frontend/FloatingSelector.php' );
		$this->assertStringNotContainsString( 'EffectiveUrl', $code );
		$this->assertStringNotContainsString( 'HTTP_ACCEPT_LANGUAGE', $code );
		$this->assertStringNotContainsString( '$_COOKIE', $code );
		$this->assertStringNotContainsString( 'setcookie', $code );
		$this->assertStringNotContainsString( 'wp_ajax_nopriv_', $code );
		$this->assertStringNotContainsString( 'wp_enqueue_style', explode( 'function render', $code )[1] );
	}

	public function test_ajax_action_is_authenticated_only(): void {
		$selector = $this->selector();
		$selector->register();
		$this->assertNotFalse( has_action( 'wp_ajax_' . FloatingSelector::AJAX_ACTION ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . FloatingSelector::AJAX_ACTION ) );
	}

	public function test_ajax_persists_through_preferred_language_api(): void {
		$this->add_language();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		PreferenceServices::bind_preferred_language( new PreferredLanguage( $this->languages ) );

		$_POST['_ajax_nonce']    = wp_create_nonce( FloatingSelector::AJAX_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture.
		$_POST['code']           = 'sv';
		$_REQUEST['code']        = 'sv';
		$_POST['user_id']        = (string) ( $user_id + 99 );

		$result = $this->selector()->handle_prefer_request();
		$this->assertTrue( $result );
		$this->assertSame( 'sv', aiml_get_preferred_language( $user_id ) );
		$this->assertSame( '', get_user_meta( $user_id + 99, PreferredLanguage::META_KEY, true ) );
	}

	public function test_ajax_rejects_invalid_nonce_and_code_and_anonymous(): void {
		$this->add_language();
		PreferenceServices::bind_preferred_language( new PreferredLanguage( $this->languages ) );

		wp_set_current_user( 0 );
		$anon = $this->selector()->handle_prefer_request();
		$this->assertInstanceOf( WP_Error::class, $anon );
		$this->assertSame( 'aiml_forbidden', $anon->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$_POST['_ajax_nonce']    = 'nope';
		$_REQUEST['_ajax_nonce'] = 'nope';
		$_POST['code']           = 'sv';
		$_REQUEST['code']        = 'sv';
		$bad_nonce               = $this->selector()->handle_prefer_request();
		$this->assertInstanceOf( WP_Error::class, $bad_nonce );
		$this->assertSame( 'aiml_invalid_nonce', $bad_nonce->get_error_code() );

		$_POST['_ajax_nonce']    = wp_create_nonce( FloatingSelector::AJAX_ACTION );
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture.
		$_POST['code']           = 'xx';
		$_REQUEST['code']        = 'xx';
		$bad_code                = $this->selector()->handle_prefer_request();
		$this->assertInstanceOf( WP_Error::class, $bad_code );
		$this->assertSame( 'aiml_invalid_language', $bad_code->get_error_code() );
	}

	public function test_source_does_not_enqueue_in_footer(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Frontend/FloatingSelector.php' );
		$this->assertStringContainsString( "add_action( 'wp_enqueue_scripts'", $code );
		$this->assertStringNotContainsString( 'HTTP_USER_AGENT', $code );
	}
}
