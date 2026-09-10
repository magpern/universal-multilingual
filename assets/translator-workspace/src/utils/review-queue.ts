import type {
	LanguageOption,
	ReviewObjectGroup,
	ReviewQueueItem,
	ReviewQueueResponse,
} from '../types/view-models';

/**
 * The review queue can span multiple posts and languages, so a segment key
 * alone is not a unique row identity — unlike the single-post segment table.
 */
export function queueItemKey( item: ReviewQueueItem ): string {
	return `${ item.post_id }:${ item.language_id }:${ item.segment_key }`;
}

/**
 * Only `pending` rows have a legal approve/reject transition; other rows
 * stay visible (for context / filtering) but are excluded from selection.
 */
export function isQueueItemSelectable( item: ReviewQueueItem ): boolean {
	return 'pending' === item.review_status;
}

export function selectableQueueItems(
	items: ReviewQueueItem[]
): ReviewQueueItem[] {
	return items.filter( isQueueItemSelectable );
}

export function toggleQueueSelection(
	selected: Set< string >,
	key: string,
	checked: boolean
): Set< string > {
	const next = new Set( selected );
	if ( checked ) {
		next.add( key );
	} else {
		next.delete( key );
	}
	return next;
}

export function clearQueueSelection(): Set< string > {
	return new Set();
}

export function selectAllQueueVisible(
	selected: Set< string >,
	visibleItems: ReviewQueueItem[]
): Set< string > {
	const next = new Set( selected );
	for ( const item of selectableQueueItems( visibleItems ) ) {
		next.add( queueItemKey( item ) );
	}
	return next;
}

export function deselectAllQueueVisible(
	selected: Set< string >,
	visibleItems: ReviewQueueItem[]
): Set< string > {
	const next = new Set( selected );
	for ( const item of selectableQueueItems( visibleItems ) ) {
		next.delete( queueItemKey( item ) );
	}
	return next;
}

export function allQueueVisibleSelected(
	visibleItems: ReviewQueueItem[],
	selected: Set< string >
): boolean {
	const selectable = selectableQueueItems( visibleItems );
	if ( 0 === selectable.length ) {
		return false;
	}

	return selectable.every( ( item ) => selected.has( queueItemKey( item ) ) );
}

export function selectedQueueItems(
	items: ReviewQueueItem[],
	selected: Set< string >
): ReviewQueueItem[] {
	return items.filter( ( item ) => selected.has( queueItemKey( item ) ) );
}

export interface QueueBatchGroup {
	postId: number;
	languageId: number;
	items: ReviewQueueItem[];
}

/**
 * The batch-review REST route is scoped to one post + one language
 * (`/{post_id}/segments/batch-review?language=...`), but the queue itself is
 * a global filtered view. Group the current selection so the caller can
 * issue one REST call per (post, language) pair and merge the per-item
 * results back together.
 */
export function groupSelectedByPostLanguage(
	items: ReviewQueueItem[]
): QueueBatchGroup[] {
	const groups = new Map< string, QueueBatchGroup >();

	for ( const item of items ) {
		const groupKey = `${ item.post_id }:${ item.language_id }`;
		const existing = groups.get( groupKey );
		if ( existing ) {
			existing.items.push( item );
		} else {
			groups.set( groupKey, {
				postId: item.post_id,
				languageId: item.language_id,
				items: [ item ],
			} );
		}
	}

	return Array.from( groups.values() );
}

/**
 * The queue is reviewed object-first (CONTENT OBJECT -> LANGUAGE -> FIELDS).
 * Prefer the server's `objects` block (it carries the completeness summary,
 * which is global to the object, not just the current page); fall back to a
 * shallow client grouping only when an older payload omits it.
 *
 * @param response Review queue REST response.
 * @return One group per object + language.
 */
export function groupQueueByObject(
	response: ReviewQueueResponse
): ReviewObjectGroup[] {
	if ( Array.isArray( response.objects ) ) {
		return response.objects;
	}

	const groups = new Map< string, ReviewObjectGroup >();
	for ( const item of response.items ) {
		const groupKey = `${ item.post_id }:${ item.language_id }`;
		const existing = groups.get( groupKey );
		if ( existing ) {
			existing.items.push( item );
			continue;
		}
		groups.set( groupKey, {
			post_id: item.post_id,
			post_title: item.post_title ?? String( item.post_id ),
			post_type: item.post_type ?? '',
			object_noun: 'Post',
			post_status: '',
			language_id: item.language_id,
			language_code: '',
			language_name: '',
			edit_link: '',
			summary: {
				total: 0,
				approved: 0,
				pending: 0,
				rejected: 0,
				untranslated: 0,
				stale: 0,
				translated: 0,
				state: 'ready',
				is_fully_reviewed: false,
			},
			items: [ item ],
		} );
	}

	return Array.from( groups.values() );
}

export function languageCodeForId(
	languages: LanguageOption[],
	languageId: number
): string {
	return languages.find( ( language ) => language.language_id === languageId )
		?.code ?? '';
}
