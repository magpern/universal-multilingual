<?php
/**
 * Immutable metadata for one WordPress runtime locale.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Language;

/**
 * One row of the curated locale registry (ADR-0028).
 *
 * `locale` is the exact WordPress runtime locale string (the `WPLANG` / `.mo`
 * value). `language_code` is two or three lowercase ASCII letters — the ISO
 * 639-1 code where one exists, otherwise a 639-2/3 code (`ceb`), so it is
 * deliberately *not* named `iso_639_1`.
 *
 * `region` and `is_explicit_variant` are cached from parsing `locale`: `region`
 * is the lowercased ISO-3166 alpha-2 segment (`br`, `de`) or `''`;
 * `is_explicit_variant` is true when the locale carries a formal / informal /
 * orthography segment (`de_DE_formal`, `pt_PT_ao90`).
 */
final class LocaleMetadata {

	/**
	 * Builds one locale registry row.
	 *
	 * @param string $group               Language-group key (equals `language_code`).
	 * @param string $locale              Exact WordPress runtime locale string.
	 * @param string $language_code       Two or three lowercase ASCII letters.
	 * @param string $english_name        English display name (regional where applicable).
	 * @param string $native_name         Native display name.
	 * @param string $direction           `ltr` or `rtl`.
	 * @param string $script              Lowercased script/alphabet hint, or `''`.
	 * @param string $region_label        Human label for the regional-variant selector, or `''`.
	 * @param string $region              Lowercased ISO-3166 alpha-2, or `''`.
	 * @param bool   $is_explicit_variant Whether the locale carries a formal/orthography segment.
	 */
	public function __construct(
		public readonly string $group,
		public readonly string $locale,
		public readonly string $language_code,
		public readonly string $english_name,
		public readonly string $native_name,
		public readonly string $direction,
		public readonly string $script,
		public readonly string $region_label,
		public readonly string $region,
		public readonly bool $is_explicit_variant
	) {
	}

	/**
	 * Builds an instance from a registry data row.
	 *
	 * @param string               $locale Locale key.
	 * @param array<string, mixed> $row    Registry `locales` entry.
	 */
	public static function from_row( string $locale, array $row ): self {
		return new self(
			(string) ( $row['group'] ?? '' ),
			$locale,
			(string) ( $row['language_code'] ?? '' ),
			(string) ( $row['english_name'] ?? '' ),
			(string) ( $row['native_name'] ?? '' ),
			'rtl' === ( $row['direction'] ?? 'ltr' ) ? 'rtl' : 'ltr',
			(string) ( $row['script'] ?? '' ),
			(string) ( $row['region_label'] ?? '' ),
			(string) ( $row['region'] ?? '' ),
			'' !== (string) ( $row['variant'] ?? '' )
		);
	}
}
