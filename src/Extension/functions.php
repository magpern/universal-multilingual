<?php
/**
 * Public Extension API v1 global helpers.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

use AIMultilingual\Extension\ExtensionServices;
use AIMultilingual\Extension\VisitorLanguageContext;
use AIMultilingual\User\PreferenceServices;

/**
 * Marks a source identity dirty for coalesced request-local sync.
 *
 * Validates admitted source only; never syncs immediately.
 * M5-A: also accepts activated chrome CPT sources under ownership/admission checks.
 *
 * @param string $source_type post|term.
 * @param int    $source_id   Source object id.
 */
function aiml_mark_source_dirty( string $source_type, int $source_id ): bool {
	return ExtensionServices::mark_source_dirty( $source_type, $source_id );
}

/**
 * Returns AIML’s current request language from URL/host resolution.
 *
 * Valid only after AIML request routing has established language context
 * (same window as visitor overlays — typically after late plugins_loaded
 * routing / request bootstrap). Returns null when AIML is inactive, too
 * early, or no language was resolved. Does not read cookies, geo, or
 * Accept-Language.
 *
 * @since 1.7.0
 */
function aiml_visitor_language(): ?VisitorLanguageContext {
	return ExtensionServices::visitor_language();
}

/**
 * Returns the stored valid preferred language code for a user, or null.
 *
 * Does not influence URL/host language resolution. Unauthorized callers receive null.
 *
 * @since 1.12.0
 *
 * @param int $user_id User id.
 */
function aiml_get_preferred_language( int $user_id ): ?string {
	$service = PreferenceServices::preferred_language();
	if ( null === $service ) {
		return null;
	}

	return $service->get( $user_id );
}

/**
 * Returns the preferred-language effective state for a user, or null if unbound.
 *
 * @since 1.12.0
 *
 * @param int $user_id User id.
 * @return array<string, mixed>|null
 */
function aiml_get_preferred_language_state( int $user_id ): ?array {
	$service = PreferenceServices::preferred_language();
	if ( null === $service ) {
		return null;
	}

	return $service->get_state( $user_id );
}

/**
 * Sets or clears a user's preferred language.
 *
 * @since 1.12.0
 *
 * @param int         $user_id User id.
 * @param string|null $code    Language code, or null/empty to clear.
 * @return true|WP_Error
 */
function aiml_set_preferred_language( int $user_id, ?string $code ) {
	$service = PreferenceServices::preferred_language();
	if ( null === $service ) {
		return new WP_Error(
			'aiml_unavailable',
			__( 'Multilingual support is not currently available.', 'universal-multilingual' )
		);
	}

	return $service->set( $user_id, $code );
}
