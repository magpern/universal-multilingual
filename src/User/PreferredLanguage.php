<?php
/**
 * Authenticated preferred-language preference (storage + state).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

use AIMultilingual\Language\Languages;
use WP_Error;

/**
 * Owns `aiml_preferred_language` user meta.
 *
 * Does not influence LanguageResolver / Router. Invalid stored values are
 * retained and fall back effectively.
 */
final class PreferredLanguage {

	public const META_KEY = 'aiml_preferred_language';

	public const SOURCE_STORED           = 'stored';
	public const SOURCE_FALLBACK_DEFAULT = 'fallback_default';
	public const SOURCE_INVALID_FALLBACK = 'invalid_fallback';

	/**
	 * Language configuration store.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Builds the preference service.
	 *
	 * @param Languages $languages Language configuration store.
	 */
	public function __construct( Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Whether the preference authority is healthy enough to own the language slot.
	 */
	public function is_available(): bool {
		return null !== $this->languages->default();
	}

	/**
	 * Stored valid preferred language code, or null.
	 *
	 * @param int $user_id User id.
	 */
	public function get( int $user_id ): ?string {
		if ( $user_id <= 0 || ! $this->can_view( $user_id ) ) {
			return null;
		}

		$raw = $this->raw( $user_id );
		if ( null === $raw ) {
			return null;
		}

		return $this->is_allowed_code( $raw ) ? $raw : null;
	}

	/**
	 * Effective preference state for UI / future consumers.
	 *
	 * @param int $user_id User id.
	 * @return array{
	 *   available: bool,
	 *   editable: bool,
	 *   stored: ?string,
	 *   effective: string,
	 *   source: string,
	 *   label: string,
	 *   options: array<string, string>,
	 *   unavailable_message: ?string
	 * }|null
	 */
	public function get_state( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		$available = $this->is_available();
		$can_view  = $this->can_view( $user_id );
		$can_edit  = $this->can_edit( $user_id );
		$default   = $this->languages->default();
		$options   = $this->options();

		$stored_raw = ( $can_view && $available ) ? $this->raw( $user_id ) : null;
		$source     = self::SOURCE_FALLBACK_DEFAULT;
		$effective  = null !== $default ? (string) $default->code : SiteDefaultLanguage::locale();
		$label      = null !== $default
			? $this->language_label( $default ) . ' ' . __( '(site default)', 'universal-multilingual' )
			: SiteDefaultLanguage::label();

		if ( null !== $stored_raw && '' !== $stored_raw ) {
			if ( $this->is_allowed_code( $stored_raw ) ) {
				$row = $this->languages->find_by_code( $stored_raw );
				if ( null !== $row ) {
					$source    = self::SOURCE_STORED;
					$effective = $stored_raw;
					$label     = $this->language_label( $row );
				}
			} else {
				$source = self::SOURCE_INVALID_FALLBACK;
			}
		}

		$unavailable = null;
		if ( ! $available ) {
			$unavailable = __( 'Multilingual support is not currently available.', 'universal-multilingual' );
		}

		return array(
			'available'           => $available,
			'editable'            => $available && $can_edit && array() !== $options,
			'stored'              => $can_view ? $stored_raw : null,
			'effective'           => $effective,
			'source'              => $source,
			'label'               => $label,
			'options'             => $options,
			'unavailable_message' => $unavailable,
		);
	}

	/**
	 * Sets or clears the preferred language.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $code    Language code, or null/empty to clear.
	 * @return true|WP_Error
	 */
	public function set( int $user_id, ?string $code ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'aiml_invalid_user', __( 'Invalid user.', 'universal-multilingual' ) );
		}

		if ( ! $this->can_edit( $user_id ) ) {
			return new WP_Error( 'aiml_forbidden', __( 'You are not allowed to edit this preference.', 'universal-multilingual' ) );
		}

		if ( ! $this->is_available() ) {
			return new WP_Error( 'aiml_unavailable', __( 'Multilingual support is not currently available.', 'universal-multilingual' ) );
		}

		if ( null === $code || '' === trim( $code ) ) {
			delete_user_meta( $user_id, self::META_KEY );
			return true;
		}

		$normalized = trim( $code );
		if ( ! $this->is_allowed_code( $normalized ) ) {
			return new WP_Error( 'aiml_invalid_language', __( 'That language is not available.', 'universal-multilingual' ) );
		}

		update_user_meta( $user_id, self::META_KEY, $normalized );
		return true;
	}

	/**
	 * Published language options (code => label).
	 *
	 * @return array<string, string>
	 */
	public function options(): array {
		$out = array();
		foreach ( $this->languages->all() as $language ) {
			if ( Languages::STATUS_PUBLISHED !== (string) $language->status ) {
				continue;
			}
			$code         = (string) $language->code;
			$out[ $code ] = $this->language_label( $language );
		}

		return $out;
	}

	/**
	 * Raw stored meta (may be invalid).
	 *
	 * @param int $user_id User id.
	 */
	public function raw( int $user_id ): ?string {
		$value = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Whether a code is an allowed published preference.
	 *
	 * @param string $code Language code.
	 */
	private function is_allowed_code( string $code ): bool {
		$row = $this->languages->find_by_code( $code );
		if ( null === $row ) {
			return false;
		}

		return Languages::STATUS_PUBLISHED === (string) $row->status;
	}

	/**
	 * Human label for a language row.
	 *
	 * @param object $language Language row.
	 */
	private function language_label( object $language ): string {
		$native = isset( $language->native_name ) ? (string) $language->native_name : '';
		$name   = isset( $language->name ) ? (string) $language->name : '';
		if ( '' !== $native ) {
			return $native;
		}
		if ( '' !== $name ) {
			return $name;
		}

		return (string) $language->code;
	}

	/**
	 * Whether the current user may view the target preference.
	 *
	 * @param int $user_id Target user id.
	 */
	private function can_view( int $user_id ): bool {
		$current = (int) get_current_user_id();
		if ( $current > 0 && $current === $user_id ) {
			return true;
		}

		return current_user_can( 'edit_user', $user_id );
	}

	/**
	 * Whether the current user may edit the target preference.
	 *
	 * @param int $user_id Target user id.
	 */
	private function can_edit( int $user_id ): bool {
		return $this->can_view( $user_id );
	}
}
