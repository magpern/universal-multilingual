import { __ } from '@wordpress/i18n';

import type { ReviewQueueItem } from '../types/view-models';

/**
 * Field labels are produced server-side by FieldLabelResolver and sent as
 * `field_label`. This helper only renders that value, with a tiny generic
 * fallback for older payloads — the real mapping lives in one place (PHP) so
 * the two cannot drift.
 *
 * @param item Review queue row.
 */
export function fieldLabel(
	item: Pick< ReviewQueueItem, 'field_label' | 'field_key' | 'segment_key' >
): string {
	const provided = ( item.field_label ?? '' ).trim();
	if ( provided ) {
		return provided;
	}

	const key = item.field_key || item.segment_key || '';
	if ( ! key ) {
		return __( 'Segment', 'ai-multilingual' );
	}

	return key
		.replace( /[_:-]+/g, ' ' )
		.trim()
		.replace( /\b\w/g, ( char ) => char.toUpperCase() );
}
