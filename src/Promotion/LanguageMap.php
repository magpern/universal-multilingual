<?php
/**
 * Conservative package-language → local-language resolution (ADR-0030 §5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

use AIMultilingual\Language\Languages;

/**
 * Rules:
 *
 * 1. Exact `locale` match → the local language.
 * 2. The package carries an explicit locale and this site has no exact locale
 *    → `missing_language`.
 * 3. Bare `code` fallback only when the locale is genuinely absent / unspecified
 *    on one side *and* exactly one compatible local language exists.
 * 4. Two different explicit locales are never cross-mapped
 *    (`pt_BR` → `pt_PT` is `missing_language`).
 *
 * A language is never auto-created. Pure — no WordPress functions.
 */
final class LanguageMap {

	/**
	 * Package language code => locale, from the package payload.
	 *
	 * @var array<string, string>
	 */
	private array $package_locales;

	/**
	 * Local rows: {language_id, code, locale}.
	 *
	 * @var array<int, array{language_id:int,code:string,locale:string}>
	 */
	private array $local_languages;

	/**
	 * Builds the map for one package.
	 *
	 * @param array<int, array<string, mixed>> $local_languages   Local language rows.
	 * @param array<int, array<string, mixed>> $package_languages Package `languages` rows.
	 */
	public function __construct( array $local_languages, array $package_languages ) {
		$this->local_languages = array();
		foreach ( $local_languages as $row ) {
			$this->local_languages[] = array(
				'language_id' => (int) ( $row['language_id'] ?? 0 ),
				'code'        => strtolower( (string) ( $row['code'] ?? '' ) ),
				'locale'      => (string) ( $row['locale'] ?? '' ),
			);
		}

		$this->package_locales = array();
		foreach ( $package_languages as $row ) {
			$code = strtolower( trim( (string) ( $row['code'] ?? '' ) ) );
			if ( '' !== $code ) {
				$this->package_locales[ $code ] = trim( (string) ( $row['locale'] ?? '' ) );
			}
		}
	}

	/**
	 * Builds a map from the live {@see Languages} model.
	 *
	 * @param Languages                        $languages         Language model.
	 * @param array<int, array<string, mixed>> $package_languages Package rows.
	 */
	public static function from_languages( Languages $languages, array $package_languages ): self {
		$rows = array();
		foreach ( $languages->all() as $language ) {
			$rows[] = array(
				'language_id' => (int) $language->language_id,
				'code'        => (string) $language->code,
				'locale'      => (string) $language->locale,
			);
		}

		return new self( $rows, $package_languages );
	}

	/**
	 * Resolves a package language code to a local language id, or null.
	 *
	 * @param string $package_code The `language_code` on a package segment.
	 */
	public function resolve( string $package_code ): ?int {
		$package_code = strtolower( trim( $package_code ) );
		$locale       = $this->package_locales[ $package_code ] ?? '';

		if ( '' !== $locale ) {
			foreach ( $this->local_languages as $row ) {
				if ( $row['locale'] === $locale ) {
					return $row['language_id'];
				}
			}

			// Rule 2 / 4: an explicit locale with no exact match is missing —
			// never fall through to a code match against a different locale.
			return null;
		}

		// Rule 3: locale unspecified in the package. Fall back to code only when
		// exactly one local language uses that code.
		$matches = array();
		foreach ( $this->local_languages as $row ) {
			if ( $row['code'] === $package_code ) {
				$matches[] = $row['language_id'];
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * The stable local language code for a local language id (for the baseline key).
	 *
	 * @param int $language_id Local language id.
	 */
	public function local_code( int $language_id ): string {
		foreach ( $this->local_languages as $row ) {
			if ( $row['language_id'] === $language_id ) {
				return $row['code'];
			}
		}

		return '';
	}
}
