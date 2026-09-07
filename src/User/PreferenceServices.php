<?php
/**
 * Bound preference services for public helpers.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

/**
 * Request/bootstrap binding for preferred-language public API.
 */
final class PreferenceServices {

	/**
	 * Preferred language service.
	 *
	 * @var PreferredLanguage|null
	 */
	private static ?PreferredLanguage $preferred_language = null;

	/**
	 * Binds the preferred-language service after plugin bootstrap.
	 *
	 * @param PreferredLanguage $service Service instance.
	 */
	public static function bind_preferred_language( PreferredLanguage $service ): void {
		self::$preferred_language = $service;
	}

	/**
	 * Bound preferred-language service, if any.
	 */
	public static function preferred_language(): ?PreferredLanguage {
		return self::$preferred_language;
	}
}
