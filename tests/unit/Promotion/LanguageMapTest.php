<?php
/**
 * Conservative package-language → local-language mapping (ADR-0030 §5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\LanguageMap;
use PHPUnit\Framework\TestCase;

/**
 * Exact locale wins; explicit locales are never cross-mapped; code fallback is
 * only used when a locale is genuinely unspecified.
 */
final class LanguageMapTest extends TestCase {

	public function test_exact_locale_match(): void {
		$map = new LanguageMap(
			array(
				array(
					'language_id' => 5,
					'code'        => 'sv',
					'locale'      => 'sv_SE',
				),
			),
			array(
				array(
					'code'   => 'sv',
					'locale' => 'sv_SE',
				),
			)
		);

		$this->assertSame( 5, $map->resolve( 'sv' ) );
		$this->assertSame( 'sv', $map->local_code( 5 ) );
	}

	public function test_explicit_locale_with_no_exact_match_is_missing(): void {
		$map = new LanguageMap(
			array(
				array(
					'language_id' => 9,
					'code'        => 'pt',
					'locale'      => 'pt_PT',
				),
			),
			array(
				array(
					'code'   => 'pt-br',
					'locale' => 'pt_BR',
				),
			)
		);

		// pt_BR must NOT cross-map to pt_PT.
		$this->assertNull( $map->resolve( 'pt-br' ) );
	}

	public function test_code_fallback_only_when_locale_unspecified_and_unique(): void {
		$map = new LanguageMap(
			array(
				array(
					'language_id' => 3,
					'code'        => 'de',
					'locale'      => 'de_DE',
				),
			),
			array(
				array(
					'code'   => 'de',
					'locale' => '',
				),
			)
		);

		$this->assertSame( 3, $map->resolve( 'de' ) );
	}

	public function test_code_fallback_is_refused_when_ambiguous(): void {
		$map = new LanguageMap(
			array(
				array(
					'language_id' => 3,
					'code'        => 'de',
					'locale'      => 'de_DE',
				),
				array(
					'language_id' => 4,
					'code'        => 'de',
					'locale'      => 'de_AT',
				),
			),
			array(
				array(
					'code'   => 'de',
					'locale' => '',
				),
			)
		);

		$this->assertNull( $map->resolve( 'de' ) );
	}

	public function test_unknown_language_is_missing(): void {
		$map = new LanguageMap(
			array(
				array(
					'language_id' => 5,
					'code'        => 'sv',
					'locale'      => 'sv_SE',
				),
			),
			array(
				array(
					'code'   => 'ja',
					'locale' => 'ja',
				),
			)
		);

		$this->assertNull( $map->resolve( 'ja' ) );
	}
}
