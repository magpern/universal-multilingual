<?php
/**
 * Server-side URL-code and metadata derivation.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit;

use AIMultilingual\Language\DerivedLanguageMetadata;
use AIMultilingual\Language\ExistingLanguage;
use AIMultilingual\Language\LanguageRegistry;
use AIMultilingual\Language\Languages;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The deriver is the single authority for a curated language's `code`, `name`,
 * `native_name` and `direction` (ADR-0028, ADR-0029). It is pure — no database,
 * no globals.
 */
final class DerivedLanguageMetadataTest extends TestCase {

	private LanguageRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new LanguageRegistry();
	}

	/**
	 * @param array<int, array{0:string,1:string,2:string,3:bool}> $existing_spec id/code/locale/default tuples.
	 * @return ExistingLanguage[]
	 */
	private function existing( array $existing_spec ): array {
		$out = array();
		foreach ( $existing_spec as $spec ) {
			$out[] = new ExistingLanguage(
				$spec[0],
				$spec[1],
				$spec[2],
				$this->registry->group_for_locale( $spec[2] ),
				$spec[3] ?? false
			);
		}
		return $out;
	}

	/**
	 * @param string $locale WordPress locale.
	 * @param string $group  Registry group.
	 * @return array{code:string,name:string,native_name:string,direction:string}
	 */
	private function derive_ok( string $locale, string $group, array $existing = array(), ?int $editing_id = null ): array {
		$result = DerivedLanguageMetadata::derive( $locale, $group, $existing, $editing_id, $this->registry );
		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	public function test_swedish_derives_the_full_row(): void {
		$row = $this->derive_ok( 'sv_SE', 'sv' );

		$this->assertSame( 'sv', $row['code'] );
		$this->assertSame( 'Swedish', $row['name'] );
		$this->assertSame( 'Svenska', $row['native_name'] );
		$this->assertSame( 'ltr', $row['direction'] );
	}

	public function test_portuguese_base_takes_the_bare_code(): void {
		$this->assertSame( 'pt', $this->derive_ok( 'pt_PT', 'pt' )['code'] );
	}

	public function test_second_portuguese_region_falls_back_to_language_region(): void {
		$existing = $this->existing( array( array( 1, 'pt', 'pt_PT', false ) ) );

		$row = $this->derive_ok( 'pt_BR', 'pt', $existing );

		$this->assertSame( 'pt-br', $row['code'] );
	}

	public function test_brazilian_alone_takes_the_bare_code(): void {
		$this->assertSame( 'pt', $this->derive_ok( 'pt_BR', 'pt' )['code'] );
	}

	public function test_bare_code_held_by_an_unrelated_row_still_yields_the_regional_code(): void {
		$existing = $this->existing( array( array( 9, 'pt', 'art_custom', false ) ) );

		$row = DerivedLanguageMetadata::derive( 'pt_BR', 'pt', $existing, null, $this->registry );

		$this->assertSame( 'pt-br', $row['code'] );
	}

	public function test_arabic_is_rtl(): void {
		$this->assertSame( 'rtl', $this->derive_ok( 'ar', 'ar' )['direction'] );
	}

	public function test_explicit_formal_variant_is_fixed_regardless_of_order(): void {
		// de_DE already present, add the formal variant.
		$existing = $this->existing( array( array( 1, 'de', 'de_DE', false ) ) );
		$this->assertSame( 'de-de-formal', $this->derive_ok( 'de_DE_formal', 'de', $existing )['code'] );
	}

	public function test_explicit_variant_added_first_does_not_consume_the_bare_code(): void {
		// Nothing exists yet — the formal variant must NOT become `de`.
		$this->assertSame( 'de-de-formal', $this->derive_ok( 'de_DE_formal', 'de' )['code'] );

		// Then the ordinary locale still gets `de`.
		$existing = $this->existing( array( array( 1, 'de-de-formal', 'de_DE_formal', false ) ) );
		$this->assertSame( 'de', $this->derive_ok( 'de_DE', 'de', $existing )['code'] );
	}

	public function test_ao90_variant_added_first_does_not_consume_pt(): void {
		$this->assertSame( 'pt-pt-ao90', $this->derive_ok( 'pt_PT_ao90', 'pt' )['code'] );

		$existing = $this->existing( array( array( 1, 'pt-pt-ao90', 'pt_PT_ao90', false ) ) );
		$this->assertSame( 'pt', $this->derive_ok( 'pt_PT', 'pt', $existing )['code'] );
	}

	public function test_re_adding_the_same_explicit_variant_conflicts(): void {
		$existing = $this->existing( array( array( 1, 'de-de-formal', 'de_DE_formal', false ) ) );

		$result = DerivedLanguageMetadata::derive( 'de_DE_formal', 'de', $existing, null, $this->registry );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Duplicate-locale is caught before the code-collision check.
		$this->assertSame( 'aiml_duplicate_locale', $result->get_error_code() );
	}

	public function test_both_ordinary_candidates_taken_conflicts(): void {
		$existing = $this->existing(
			array(
				array( 1, 'pt', 'pt_PT', false ),
				array( 2, 'pt-br', 'art_x', false ),
			)
		);

		$result = DerivedLanguageMetadata::derive( 'pt_BR', 'pt', $existing, null, $this->registry );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_conflicting_variant', $result->get_error_code() );
	}

	public function test_unknown_locale_is_rejected(): void {
		$result = DerivedLanguageMetadata::derive( 'zz_ZZ', 'zz', array(), null, $this->registry );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_unknown_locale', $result->get_error_code() );
	}

	public function test_locale_not_in_the_submitted_group_is_rejected(): void {
		$result = DerivedLanguageMetadata::derive( 'pt_BR', 'de', array(), null, $this->registry );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_unknown_locale', $result->get_error_code() );
	}

	public function test_exact_locale_already_registered_is_rejected(): void {
		$existing = $this->existing( array( array( 5, 'sv', 'sv_SE', false ) ) );

		$result = DerivedLanguageMetadata::derive( 'sv_SE', 'sv', $existing, null, $this->registry );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aiml_duplicate_locale', $result->get_error_code() );
	}

	public function test_adding_en_gb_when_default_is_en_us_succeeds(): void {
		$existing = $this->existing( array( array( 1, 'en', 'en_US', true ) ) );

		$row = $this->derive_ok( 'en_GB', 'en', $existing );

		// `en` is held by the default language, so en_GB falls back to en-gb.
		$this->assertSame( 'en-gb', $row['code'] );
	}

	public function test_editing_id_is_excluded_from_the_collision_set(): void {
		$existing = $this->existing( array( array( 7, 'pt', 'pt_PT', false ) ) );

		// Re-deriving row 7 itself must still see `pt` as free.
		$row = $this->derive_ok( 'pt_PT', 'pt', $existing, 7 );

		$this->assertSame( 'pt', $row['code'] );
	}

	public function test_every_registry_locale_derives_a_grammar_valid_code(): void {
		foreach ( $this->registry->groups() as $group ) {
			foreach ( $group->locales as $locale ) {
				$row = DerivedLanguageMetadata::derive( $locale, $group->key, array(), null, $this->registry );
				$this->assertNotInstanceOf(
					WP_Error::class,
					$row,
					"derive({$locale}) failed: " . ( is_wp_error( $row ) ? $row->get_error_message() : '' )
				);
				$this->assertTrue(
					Languages::is_valid_code( $row['code'] ),
					"derive({$locale}) produced an invalid code: {$row['code']}"
				);
			}
		}
	}
}
