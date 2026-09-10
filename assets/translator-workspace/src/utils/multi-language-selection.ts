import type { LanguageOption } from '../types/view-models';

/**
 * MLW1a — pure helpers for the multi-language selection model (WP2/WP3).
 *
 * The `languages` list handed in is always the workspace bootstrap set, which
 * the server has ALREADY filtered to eligible configured target languages
 * (default/source language and disabled languages removed —
 * TranslatorWorkspace::language_bootstrap()). These helpers never re-derive
 * eligibility; they only manage which of those codes are checked.
 */

/**
 * Eligible target language codes, in registry order.
 * @param languages
 */
export function eligibleCodes( languages: LanguageOption[] ): string[] {
	return languages.map( ( language ) => language.code ).filter( Boolean );
}

/**
 * Default selection: every eligible target language checked.
 * @param languages
 */
export function defaultSelection( languages: LanguageOption[] ): string[] {
	return eligibleCodes( languages );
}

/**
 * Drops any selected code that is no longer an eligible target — keeps a
 * session selection stable and valid when the object (not the language list)
 * changes.
 *
 * @param selected  Currently selected codes.
 * @param languages Eligible target languages.
 */
export function normalizeSelection(
	selected: string[],
	languages: LanguageOption[]
): string[] {
	const eligible = new Set( eligibleCodes( languages ) );
	// Preserve registry order rather than selection order.
	return eligibleCodes( languages ).filter(
		( code ) => eligible.has( code ) && selected.includes( code )
	);
}

/**
 * Adds or removes one code, returning a new array in registry order.
 * @param selected
 * @param code
 * @param languages
 */
export function toggleLanguage(
	selected: string[],
	code: string,
	languages: LanguageOption[]
): string[] {
	const next = selected.includes( code )
		? selected.filter( ( c ) => c !== code )
		: [ ...selected, code ];
	return normalizeSelection( next, languages );
}

/**
 * Whether every eligible target language is currently selected.
 * @param selected
 * @param languages
 */
export function isAllSelected(
	selected: string[],
	languages: LanguageOption[]
): boolean {
	const eligible = eligibleCodes( languages );
	return (
		eligible.length > 0 &&
		eligible.every( ( code ) => selected.includes( code ) )
	);
}

/**
 * Whether the selection is a non-empty strict subset (for the master checkbox indeterminate state).
 * @param selected
 * @param languages
 */
export function isSomeSelected(
	selected: string[],
	languages: LanguageOption[]
): boolean {
	const chosen = normalizeSelection( selected, languages );
	return chosen.length > 0 && ! isAllSelected( chosen, languages );
}

/**
 * "All languages" master toggle: select every eligible target, or clear.
 * @param selected
 * @param languages
 */
export function toggleAll(
	selected: string[],
	languages: LanguageOption[]
): string[] {
	return isAllSelected( selected, languages )
		? []
		: defaultSelection( languages );
}

/**
 * Pre-run operation count: object-language operations, NOT a provider-call or
 * token estimate (ADR-0034 / F1 — no fabricated cost).
 *
 * @param objectCount   Number of selected objects.
 * @param languageCount Number of selected languages.
 */
export function operationCount(
	objectCount: number,
	languageCount: number
): number {
	return Math.max( 0, objectCount ) * Math.max( 0, languageCount );
}
