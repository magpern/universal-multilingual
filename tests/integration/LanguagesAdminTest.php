<?php
/**
 * Redesigned Languages admin — the trusted save handler.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Admin\Languages\LanguagesScreen;

/**
 * The server derives every canonical field for a curated language and ignores
 * anything the browser submits for `code`, `locale`, `name` or `direction`
 * (ADR-0028). `locale` and `code` are immutable on edit for every row.
 */
final class LanguagesAdminTest extends AimlTestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'aiml_allow_custom_language' );
		parent::tearDown();
	}

	/**
	 * Runs handle_save() with the given POST and returns the redirect query args.
	 *
	 * @param array<string, string> $post Raw POST fields (nonce added automatically).
	 * @return array<string, string>
	 */
	private function save( array $post ): array {
		$_POST             = $post;
		$_POST['_wpnonce'] = wp_create_nonce( 'aiml_save_language' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test harness sets the nonce it just created.
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $target ) use ( &$location ) {
				$location = (string) $target;
				throw new \RuntimeException( 'aiml-redirect-stop' );
			}
		);

		try {
			( new LanguagesScreen( $this->languages ) )->handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'aiml-redirect-stop', $e->getMessage() );
		}

		remove_all_filters( 'wp_redirect' );
		$this->languages->flush();

		$query = (string) ( wp_parse_url( $location, PHP_URL_QUERY ) ?? '' );
		wp_parse_str( $query, $args );

		return array_map( 'strval', (array) $args );
	}

	private function error_code( array $redirect ): string {
		return $redirect['aiml_error_code'] ?? '';
	}

	public function test_curated_swedish_is_fully_derived_and_tampering_is_ignored(): void {
		$this->save(
			array(
				'registry_group' => 'sv',
				'locale'         => 'sv_SE',
				'status'         => 'preview',
				'sort_order'     => '10',
				// Tampered canonical fields — must be ignored.
				'code'           => 'hacked',
				'name'           => 'Hacked',
				'native_name'    => 'Hacked',
				'direction'      => 'rtl',
			)
		);

		$row = $this->languages->find_by_locale( 'sv_SE' );
		$this->assertNotNull( $row );
		$this->assertSame( 'sv', $row->code );
		$this->assertSame( 'Swedish', $row->name );
		$this->assertSame( 'Svenska', $row->native_name );
		$this->assertSame( 'ltr', $row->direction );
		$this->assertSame( 'preview', $row->status );
		$this->assertSame( 10, (int) $row->sort_order );
	}

	public function test_arabic_derives_rtl(): void {
		$this->save(
			array(
				'registry_group' => 'ar',
				'locale'         => 'ar',
			)
		);

		$row = $this->languages->find_by_locale( 'ar' );
		$this->assertNotNull( $row );
		$this->assertSame( 'rtl', $row->direction );
	}

	public function test_second_portuguese_region_gets_a_regional_code(): void {
		$this->save(
			array(
				'registry_group' => 'pt',
				'locale'         => 'pt_PT',
			)
		);
		$this->save(
			array(
				'registry_group' => 'pt',
				'locale'         => 'pt_BR',
			)
		);

		$this->assertSame( 'pt', $this->languages->find_by_locale( 'pt_PT' )->code );
		$this->assertSame( 'pt-br', $this->languages->find_by_locale( 'pt_BR' )->code );
	}

	public function test_explicit_formal_variant_first_does_not_consume_de(): void {
		$this->save(
			array(
				'registry_group' => 'de',
				'locale'         => 'de_DE_formal',
			)
		);
		$this->save(
			array(
				'registry_group' => 'de',
				'locale'         => 'de_DE',
			)
		);

		$this->assertSame( 'de-de-formal', $this->languages->find_by_locale( 'de_DE_formal' )->code );
		$this->assertSame( 'de', $this->languages->find_by_locale( 'de_DE' )->code );
	}

	public function test_adding_en_gb_when_default_is_en_us_succeeds(): void {
		// The bootstrap default is en_US.
		$redirect = $this->save(
			array(
				'registry_group' => 'en',
				'locale'         => 'en_GB',
			)
		);

		$this->assertArrayHasKey( 'aiml_updated', $redirect );
		$this->assertSame( 'en-gb', $this->languages->find_by_locale( 'en_GB' )->code );
	}

	public function test_re_adding_the_default_locale_is_rejected(): void {
		$default  = $this->languages->default();
		$redirect = $this->save(
			array(
				'registry_group' => 'en',
				'locale'         => (string) $default->locale,
			)
		);

		$this->assertSame( 'aiml_duplicate_locale', $this->error_code( $redirect ) );
	}

	public function test_malformed_group_is_rejected(): void {
		$redirect = $this->save(
			array(
				'registry_group' => 'zz',
				'locale'         => 'zz_ZZ',
			)
		);

		$this->assertSame( 'aiml_unknown_locale', $this->error_code( $redirect ) );
	}

	public function test_custom_add_works_when_allowed_and_is_blocked_by_the_filter(): void {
		$this->save(
			array(
				'aiml_custom' => '1',
				'code'        => 'art-xx',
				'locale'      => 'art_xpirate',
				'name'        => 'Pirate',
				'native_name' => 'Pirate',
				'direction'   => 'ltr',
			)
		);
		$this->assertNotNull( $this->languages->find_by_locale( 'art_xpirate' ) );

		add_filter( 'aiml_allow_custom_language', '__return_false' );

		$blocked = false;
		try {
			$this->save(
				array(
					'aiml_custom' => '1',
					'code'        => 'xx',
					'locale'      => 'xx_XX',
					'name'        => 'Nope',
				)
			);
		} catch ( \WPDieException $e ) {
			$blocked = true;
		}

		$this->assertTrue( $blocked || null === $this->languages->find_by_locale( 'xx_XX' ) );
	}

	public function test_curated_edit_keeps_identity_and_re_derives_display_fields(): void {
		$this->save(
			array(
				'registry_group' => 'sv',
				'locale'         => 'sv_SE',
				'status'         => 'preview',
			)
		);
		$row = $this->languages->find_by_locale( 'sv_SE' );

		$this->save(
			array(
				'language_id' => (string) $row->language_id,
				'status'      => 'published',
				'sort_order'  => '42',
				'native_name' => 'Svenska (test)',
				// Tampered identity — ignored.
				'code'        => 'zz',
				'locale'      => 'zz_ZZ',
				'name'        => 'Nope',
				'direction'   => 'rtl',
			)
		);

		$updated = $this->languages->find( (int) $row->language_id );
		$this->assertSame( 'sv', $updated->code );
		$this->assertSame( 'sv_SE', $updated->locale );
		$this->assertSame( 'Swedish', $updated->name );
		$this->assertSame( 'ltr', $updated->direction );
		$this->assertSame( 'published', $updated->status );
		$this->assertSame( 42, (int) $updated->sort_order );
		$this->assertSame( 'Svenska (test)', $updated->native_name );
	}

	public function test_the_screen_renders_the_add_and_edit_cards(): void {
		$screen = new LanguagesScreen( $this->languages );

		ob_start();
		$screen->render();
		$add = (string) ob_get_clean();

		$this->assertStringContainsString( 'aiml-language-combobox', $add );
		$this->assertStringContainsString( 'name="registry_group"', $add );
		$this->assertStringContainsString( 'data-aiml-region-field', $add );
		$this->assertStringContainsString( 'data-aiml-summary', $add );
		$this->assertStringContainsString( 'Advanced: add a custom language', $add );

		$row                 = $this->add_language( 'sv', 'sv_SE' );
		$_GET['language_id'] = (string) $row->language_id;

		ob_start();
		$screen->render();
		$edit = (string) ob_get_clean();
		unset( $_GET['language_id'] );

		$this->assertStringContainsString( 'name="language_id"', $edit );
		$this->assertStringNotContainsString( 'name="registry_group"', $edit );
		$this->assertStringNotContainsString( 'name="locale"', $edit );
		$this->assertStringNotContainsString( 'name="code"', $edit );
	}

	public function test_custom_form_is_hidden_when_the_filter_is_false(): void {
		add_filter( 'aiml_allow_custom_language', '__return_false' );

		ob_start();
		( new LanguagesScreen( $this->languages ) )->render();
		$out = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'aiml-ui-advanced', $out );
	}

	public function test_custom_edit_keeps_identity_but_allows_name_and_direction(): void {
		$this->save(
			array(
				'aiml_custom' => '1',
				'code'        => 'art-xx',
				'locale'      => 'art_xpirate',
				'name'        => 'Pirate',
				'native_name' => 'Pirate',
				'direction'   => 'ltr',
			)
		);
		$row = $this->languages->find_by_locale( 'art_xpirate' );

		$this->save(
			array(
				'language_id' => (string) $row->language_id,
				'aiml_custom' => '1',
				'name'        => 'Pirate English',
				'native_name' => 'Arrr',
				'direction'   => 'rtl',
				'status'      => 'preview',
				'sort_order'  => '3',
				'code'        => 'zz',
				'locale'      => 'zz_ZZ',
			)
		);

		$updated = $this->languages->find( (int) $row->language_id );
		$this->assertSame( 'art-xx', $updated->code );
		$this->assertSame( 'art_xpirate', $updated->locale );
		$this->assertSame( 'Pirate English', $updated->name );
		$this->assertSame( 'rtl', $updated->direction );
		$this->assertSame( 'Arrr', $updated->native_name );
	}
}
