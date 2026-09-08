<?php
/**
 * Server-side derivation of a language's canonical metadata.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Language;

use WP_Error;

/**
 * Turns an administrator's *selection* — a WordPress locale plus its registry
 * group — into the canonical fields the language store persists: URL `code`,
 * English `name`, `native_name` and text `direction` (ADR-0028, ADR-0029).
 *
 * Pure and deterministic: no database, no globals, no WordPress calls beyond
 * `WP_Error` and translation. The admin handler never trusts client-supplied
 * values for these fields — it calls this and takes the result.
 *
 * URL-code rule:
 *  - an ordinary locale (or a plain regional variant) takes the first free
 *    candidate among the existing languages: `language`, then
 *    `language-region`;
 *  - a locale carrying an explicit formal / informal / orthography segment
 *    (`de_DE_formal`, `pt_PT_ao90`) takes a **fixed** `language-region-variant`
 *    code and is never shortened, even when the bare code is free — so
 *    creation order does not matter;
 *  - no numeric suffixing; an occupied deterministic code is an error.
 */
final class DerivedLanguageMetadata {

	/**
	 * Derives canonical metadata for a curated language selection.
	 *
	 * @param string             $locale     Exact WordPress runtime locale string.
	 * @param string             $group      Registry group key the locale must belong to.
	 * @param ExistingLanguage[] $existing   Identities of the languages already stored.
	 * @param int|null           $editing_id Row being edited (excluded from collisions), or null.
	 * @param LanguageRegistry   $registry   Canonical metadata source.
	 * @return array{code: string, name: string, native_name: string, direction: string}|WP_Error
	 */
	public static function derive( string $locale, string $group, array $existing, ?int $editing_id, LanguageRegistry $registry ) {
		$meta = $registry->metadata_for_locale( $locale );

		if ( null === $meta || $meta->group !== $group || '' === $group ) {
			return new WP_Error(
				'aiml_unknown_locale',
				__( 'That language and region combination is not in the locale registry.', 'universal-multilingual' )
			);
		}

		foreach ( $existing as $row ) {
			if ( $row->language_id === $editing_id ) {
				continue;
			}
			if ( $row->locale === $locale ) {
				return new WP_Error(
					'aiml_duplicate_locale',
					__( 'That locale is already registered as another language.', 'universal-multilingual' )
				);
			}
		}

		list( $region, $variant ) = self::parse( $locale );
		$lang                     = $meta->language_code;

		if ( '' !== $variant ) {
			if ( '' === $region ) {
				return new WP_Error(
					'aiml_unroutable_locale',
					__( 'This locale has no region and cannot be given a stable URL. Add it as a custom language.', 'universal-multilingual' )
				);
			}

			$code = $lang . '-' . $region . '-' . $variant;

			$holder = self::holder_of( $code, $existing, $editing_id );
			if ( null !== $holder ) {
				return self::conflict( $code, $holder, $group );
			}
		} else {
			$candidates = array( $lang );
			if ( '' !== $region ) {
				$candidates[] = $lang . '-' . $region;
			}

			$code = null;
			foreach ( $candidates as $candidate ) {
				if ( null === self::holder_of( $candidate, $existing, $editing_id ) ) {
					$code = $candidate;
					break;
				}
			}

			if ( null === $code ) {
				return self::conflict(
					(string) end( $candidates ),
					self::holder_of( (string) end( $candidates ), $existing, $editing_id ),
					$group
				);
			}
		}

		if ( ! Languages::is_valid_code( $code ) ) {
			return new WP_Error(
				'aiml_unroutable_locale',
				__( 'The derived URL code is not valid. Add this locale as a custom language.', 'universal-multilingual' )
			);
		}

		return array(
			'code'        => $code,
			'name'        => $meta->english_name,
			'native_name' => $meta->native_name,
			'direction'   => $meta->direction,
		);
	}

	/**
	 * Splits a WordPress locale into its region and variant segments.
	 *
	 * @param string $locale WordPress runtime locale string.
	 * @return array{0: string, 1: string} Lowercased region (or ''), lowercased variant (or '').
	 */
	private static function parse( string $locale ): array {
		$parts = explode( '_', $locale );
		array_shift( $parts );

		$region = '';
		if ( array() !== $parts && 1 === preg_match( '/^[A-Z]{2}$/', $parts[0] ) ) {
			$region = strtolower( (string) array_shift( $parts ) );
		}

		$variant = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', implode( '', $parts ) ) );

		return array( $region, $variant );
	}

	/**
	 * The existing language holding a code, or null when the code is free.
	 *
	 * @param string             $code       Candidate URL code.
	 * @param ExistingLanguage[] $existing   Existing identities.
	 * @param int|null           $editing_id Row to ignore.
	 */
	private static function holder_of( string $code, array $existing, ?int $editing_id ): ?ExistingLanguage {
		foreach ( $existing as $row ) {
			if ( $row->language_id !== $editing_id && $row->code === $code ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Builds the conflict error, naming the holder.
	 *
	 * @param string           $code   Contested URL code.
	 * @param ExistingLanguage $holder The language already using it.
	 * @param string           $group  Group of the language being added.
	 */
	private static function conflict( string $code, ExistingLanguage $holder, string $group ): WP_Error {
		if ( $holder->group === $group ) {
			$message = sprintf(
				/* translators: 1: URL code, 2: locale of the existing same-language variant. */
				__( 'URL code "%1$s" is already taken by the %2$s variant of this language. Add the more specific region first, or use a custom language.', 'universal-multilingual' ),
				$code,
				$holder->locale
			);
		} else {
			$message = sprintf(
				/* translators: 1: URL code, 2: locale of the unrelated language. */
				__( 'URL code "%1$s" is already used by another language (%2$s).', 'universal-multilingual' ),
				$code,
				$holder->locale
			);
		}

		return new WP_Error( 'aiml_conflicting_variant', $message );
	}
}
