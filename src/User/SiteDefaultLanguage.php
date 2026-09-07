<?php
/**
 * Site-configured WordPress language for Regional Preferences fallbacks.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

/**
 * Resolves the site default language label without using the current user's UI locale.
 *
 * Authority is {@see get_option( 'WPLANG' )} only. Empty/unset means WordPress
 * core site default `en_US`. Must never call get_locale() / determine_locale().
 */
final class SiteDefaultLanguage {

	public const FALLBACK_LOCALE = 'en_US';

	/**
	 * Site-configured locale code (WPLANG or en_US).
	 */
	public static function locale(): string {
		$stored = get_option( 'WPLANG', '' );

		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			return self::FALLBACK_LOCALE;
		}

		return trim( $stored );
	}

	/**
	 * Human-readable label for the site-configured locale.
	 */
	public static function label(): string {
		$locale = self::locale();
		$label  = self::locale_display_name( $locale );

		return sprintf(
			/* translators: %s: language or locale name */
			__( '%s (site default)', 'universal-multilingual' ),
			$label
		);
	}

	/**
	 * Best-effort display name for a locale without depending on user locale.
	 *
	 * @param string $locale Locale code.
	 */
	private static function locale_display_name( string $locale ): string {
		if ( class_exists( '\WP_Locale' ) && function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
			$translations = wp_get_available_translations();
			if ( isset( $translations[ $locale ]['native_name'] ) && is_string( $translations[ $locale ]['native_name'] ) ) {
				return $translations[ $locale ]['native_name'];
			}
		}

		if ( 'en_US' === $locale ) {
			return 'English';
		}

		return $locale;
	}
}
