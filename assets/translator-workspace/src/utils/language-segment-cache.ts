import type { SegmentRow } from '../types/segment-row';
import type { WorkspaceTranslationStatus } from '../types/view-models';

/**
 * MLW1a — per-language segment cache for the Workspace editor (WP3).
 *
 * When the operator switches language tabs the same object stays loaded; each
 * language's rows + status are cached so a revisited tab renders immediately
 * (and is then refreshed in the background). The cache is keyed by language
 * code and is dropped whenever the object changes — entries never leak between
 * objects or between languages.
 */

export interface LanguageCacheEntry {
	rows: SegmentRow[];
	status: WorkspaceTranslationStatus | null;
}

export type LanguageSegmentCache = Record< string, LanguageCacheEntry >;

/** A fresh, empty cache — use on every object change. */
export function emptyCache(): LanguageSegmentCache {
	return {};
}

/**
 * Cached entry for one language, or undefined on first open.
 * @param cache
 * @param code
 */
export function readCache(
	cache: LanguageSegmentCache,
	code: string
): LanguageCacheEntry | undefined {
	return Object.prototype.hasOwnProperty.call( cache, code )
		? cache[ code ]
		: undefined;
}

/**
 * Whether a language has been fetched at least once for the current object.
 * @param cache
 * @param code
 */
export function hasLanguage(
	cache: LanguageSegmentCache,
	code: string
): boolean {
	return Object.prototype.hasOwnProperty.call( cache, code );
}

/**
 * Returns a new cache with one language's entry written/replaced.
 * @param cache
 * @param code
 * @param entry
 */
export function writeCache(
	cache: LanguageSegmentCache,
	code: string,
	entry: LanguageCacheEntry
): LanguageSegmentCache {
	return { ...cache, [ code ]: entry };
}

/**
 * Returns a new cache with one language's entry removed (after a mutation).
 * @param cache
 * @param code
 */
export function dropLanguage(
	cache: LanguageSegmentCache,
	code: string
): LanguageSegmentCache {
	if ( ! hasLanguage( cache, code ) ) {
		return cache;
	}
	const next = { ...cache };
	delete next[ code ];
	return next;
}
