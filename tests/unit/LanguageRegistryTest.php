<?php
/**
 * Curated locale registry invariants.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit;

use AIMultilingual\Language\LanguageGroup;
use AIMultilingual\Language\LanguageRegistry;
use AIMultilingual\Language\Languages;
use AIMultilingual\Language\LocaleMetadata;
use PHPUnit\Framework\TestCase;

/**
 * The registry is the plugin-owned source of truth (ADR-0028). It must load
 * with no database, network or language pack, and every entry must satisfy the
 * production contracts.
 */
final class LanguageRegistryTest extends TestCase {

	private LanguageRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new LanguageRegistry();
	}

	public function test_registry_loads_without_wordpress(): void {
		$this->assertNotSame( array(), $this->registry->groups() );
		$this->assertGreaterThan( 100, count( $this->registry->groups() ) );
	}

	public function test_provenance_is_recorded(): void {
		$provenance = $this->registry->provenance();

		$this->assertArrayHasKey( 'source_revision', $provenance );
		$this->assertArrayHasKey( 'snapshot_date', $provenance );
		$this->assertNotSame( '', $provenance['source_revision'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $provenance['snapshot_date'] );
	}

	public function test_every_group_has_at_least_one_locale(): void {
		foreach ( $this->registry->groups() as $group ) {
			$this->assertNotSame( array(), $group->locales, "Group {$group->key} has no locales." );
		}
	}

	public function test_every_language_code_is_two_or_three_lowercase_letters(): void {
		foreach ( $this->registry->groups() as $group ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z]{2,3}$/',
				$group->language_code,
				"Group {$group->key} has a malformed language_code."
			);
		}
	}

	public function test_every_locale_passes_the_production_locale_validator(): void {
		foreach ( $this->registry->groups() as $group ) {
			foreach ( $group->locales as $locale ) {
				$this->assertTrue(
					Languages::is_valid_locale( $locale ),
					"Registry locale {$locale} fails Languages::is_valid_locale()."
				);
			}
		}
	}

	public function test_every_direction_is_ltr_or_rtl(): void {
		foreach ( $this->registry->groups() as $group ) {
			$this->assertContains( $group->direction, array( 'ltr', 'rtl' ) );
			foreach ( $this->registry->locales_for( $group->key ) as $meta ) {
				$this->assertContains( $meta->direction, array( 'ltr', 'rtl' ) );
			}
		}
	}

	public function test_group_for_locale_round_trips(): void {
		foreach ( $this->registry->groups() as $group ) {
			foreach ( $group->locales as $locale ) {
				$this->assertSame(
					$group->key,
					$this->registry->group_for_locale( $locale ),
					"group_for_locale({$locale}) did not round-trip to {$group->key}."
				);
			}
		}
	}

	public function test_metadata_lookups_return_typed_objects(): void {
		$meta = $this->registry->metadata_for_locale( 'sv_SE' );

		$this->assertInstanceOf( LocaleMetadata::class, $meta );
		$this->assertSame( 'sv', $meta->group );
		$this->assertSame( 'sv', $meta->language_code );
		$this->assertSame( 'Swedish', $meta->english_name );
		$this->assertSame( 'Svenska', $meta->native_name );
		$this->assertSame( 'ltr', $meta->direction );
		$this->assertFalse( $meta->is_explicit_variant );
	}

	public function test_swedish_group(): void {
		$group = $this->registry->group( 'sv' );

		$this->assertInstanceOf( LanguageGroup::class, $group );
		$this->assertSame( 'Swedish', $group->english_name );
		$this->assertSame( 'Svenska', $group->native_name );
		$this->assertSame( array( 'sv_SE' ), $group->locales );
		$this->assertFalse( $group->has_regional_variants() );
	}

	public function test_rtl_languages_are_marked_rtl(): void {
		foreach ( array( 'ar', 'he', 'fa', 'ur' ) as $code ) {
			$group = $this->registry->group( $code );
			$this->assertInstanceOf( LanguageGroup::class, $group, "Missing RTL group {$code}." );
			$this->assertSame( 'rtl', $group->direction, "Group {$code} should be rtl." );
		}
	}

	public function test_multi_locale_groups_expose_regional_variants(): void {
		foreach ( array( 'pt', 'en', 'zh' ) as $code ) {
			$group = $this->registry->group( $code );
			$this->assertInstanceOf( LanguageGroup::class, $group );
			$this->assertTrue(
				$group->has_regional_variants(),
				"Group {$code} should offer more than one locale."
			);
		}
	}

	public function test_default_english_locale_is_present(): void {
		$this->assertTrue( $this->registry->has_locale( 'en_US' ) );
		$this->assertSame( 'en', $this->registry->group_for_locale( 'en_US' ) );
	}

	public function test_explicit_formal_variant_is_first_class(): void {
		$meta = $this->registry->metadata_for_locale( 'de_DE_formal' );

		$this->assertInstanceOf( LocaleMetadata::class, $meta );
		$this->assertSame( 'de', $meta->group );
		$this->assertSame( 'de', $meta->region );
		$this->assertTrue( $meta->is_explicit_variant );
	}

	public function test_brazilian_portuguese_metadata(): void {
		$meta = $this->registry->metadata_for_locale( 'pt_BR' );

		$this->assertInstanceOf( LocaleMetadata::class, $meta );
		$this->assertSame( 'pt', $meta->group );
		$this->assertSame( 'br', $meta->region );
		$this->assertSame( 'Portuguese (Brazil)', $meta->english_name );
		$this->assertSame( 'Português do Brasil', $meta->native_name );
		$this->assertFalse( $meta->is_explicit_variant );
	}

	public function test_variant_only_locales_without_a_region_are_excluded(): void {
		// ca_valencia / art_xemoji / art_xpirate cannot fit the URL-code grammar.
		$this->assertFalse( $this->registry->has_locale( 'ca_valencia' ) );
		$this->assertFalse( $this->registry->has_locale( 'art_xemoji' ) );
	}

	public function test_unknown_lookups_are_null_not_fatal(): void {
		$this->assertNull( $this->registry->group( 'zz' ) );
		$this->assertNull( $this->registry->metadata_for_locale( 'zz_ZZ' ) );
		$this->assertNull( $this->registry->group_for_locale( 'zz_ZZ' ) );
		$this->assertSame( array(), $this->registry->locales_for( 'zz' ) );
	}
}
