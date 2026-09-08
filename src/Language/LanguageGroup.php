<?php
/**
 * Immutable metadata for one language group in the locale registry.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Language;

/**
 * A language and the set of WordPress runtime locales the registry offers for
 * it (ADR-0028). The `english_name` / `native_name` here are the base-language
 * names ("Portuguese" / "Português"), without a regional qualifier; per-locale
 * names live on {@see LocaleMetadata}.
 */
final class LanguageGroup {

	/**
	 * Builds one language group.
	 *
	 * @param string   $key           Group key (equals `language_code`).
	 * @param string   $language_code  Two or three lowercase ASCII letters.
	 * @param string   $english_name   Base-language English name.
	 * @param string   $native_name    Base-language native name.
	 * @param string   $direction      `ltr` or `rtl`.
	 * @param string   $script         Lowercased script/alphabet hint, or `''`.
	 * @param string[] $locales        WordPress runtime locale strings, primary first.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $language_code,
		public readonly string $english_name,
		public readonly string $native_name,
		public readonly string $direction,
		public readonly string $script,
		public readonly array $locales
	) {
	}

	/**
	 * Whether this group offers more than one locale (so the UI shows a region
	 * selector).
	 */
	public function has_regional_variants(): bool {
		return count( $this->locales ) > 1;
	}

	/**
	 * Builds an instance from a registry data row.
	 *
	 * @param string               $key Group key.
	 * @param array<string, mixed> $row Registry `groups` entry.
	 */
	public static function from_row( string $key, array $row ): self {
		return new self(
			$key,
			(string) ( $row['language_code'] ?? $key ),
			(string) ( $row['english_name'] ?? '' ),
			(string) ( $row['native_name'] ?? '' ),
			'rtl' === ( $row['direction'] ?? 'ltr' ) ? 'rtl' : 'ltr',
			(string) ( $row['script'] ?? '' ),
			array_values( array_map( 'strval', (array) ( $row['locales'] ?? array() ) ) )
		);
	}
}
