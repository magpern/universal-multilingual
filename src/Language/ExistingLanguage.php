<?php
/**
 * Identity of a language row already in the registry table.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Language;

/**
 * The subset of a stored language that URL-code derivation needs (ADR-0029).
 *
 * Passing full identities — not a flat list of codes — lets the deriver tell
 * "the canonical same-group language already holds `/pt/`" apart from "an
 * unrelated legacy or custom row holds `/pt/`", and produce a precise conflict
 * message. `group` is null when the stored locale is not in the curated
 * registry (a custom row).
 */
final class ExistingLanguage {

	/**
	 * Builds one identity.
	 *
	 * @param int         $language_id Row primary key.
	 * @param string      $code        Current URL code.
	 * @param string      $locale      Current WordPress locale.
	 * @param string|null $group       Registry group key, or null for a custom row.
	 * @param bool        $is_default  Whether this is the site's source language.
	 */
	public function __construct(
		public readonly int $language_id,
		public readonly string $code,
		public readonly string $locale,
		public readonly ?string $group,
		public readonly bool $is_default
	) {
	}

	/**
	 * Builds an identity from a stored language row, resolving its group.
	 *
	 * @param object           $row      Hydrated `aiml_languages` row.
	 * @param LanguageRegistry $registry Registry, for group resolution.
	 */
	public static function from_row( object $row, LanguageRegistry $registry ): self {
		return new self(
			(int) ( $row->language_id ?? 0 ),
			(string) ( $row->code ?? '' ),
			(string) ( $row->locale ?? '' ),
			$registry->group_for_locale( (string) ( $row->locale ?? '' ) ),
			! empty( $row->is_default )
		);
	}
}
