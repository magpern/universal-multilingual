import { __, sprintf } from '@wordpress/i18n';

import type { ObjectReviewSummary } from '../types/view-models';

export type ObjectReviewTone = 'success' | 'warning' | 'info';

/**
 * Plain-language state chip text + tone for one object group.
 *
 * @param summary Completeness summary from the server.
 */
export function objectStateChip( summary: ObjectReviewSummary ): {
	label: string;
	tone: ObjectReviewTone;
} {
	switch ( summary.state ) {
		case 'complete':
			return {
				label: __( 'Review complete', 'ai-multilingual' ),
				tone: 'success',
			};
		case 'incomplete':
			return {
				label: __( 'Incomplete', 'ai-multilingual' ),
				tone: 'warning',
			};
		case 'needs_attention':
			return {
				label: __( 'Needs attention', 'ai-multilingual' ),
				tone: 'warning',
			};
		default:
			return {
				label: __( 'Ready to approve', 'ai-multilingual' ),
				tone: 'info',
			};
	}
}

/**
 * Completeness-honest one-liner, e.g.
 * "27 approved · 2 need attention · 3 untranslated — page not fully reviewed".
 *
 * @param summary Completeness summary.
 * @param noun    Object-type noun ("page", "product", ...), lower-cased.
 */
export function objectSummaryLine(
	summary: ObjectReviewSummary,
	noun: string
): string {
	const parts: string[] = [];
	parts.push(
		sprintf(
			/* translators: %d: approved field count */
			__( '%d approved', 'ai-multilingual' ),
			summary.approved
		)
	);
	if ( summary.pending > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: pending field count */
				__( '%d pending', 'ai-multilingual' ),
				summary.pending
			)
		);
	}
	if ( summary.rejected > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: rejected field count */
				__( '%d need attention', 'ai-multilingual' ),
				summary.rejected
			)
		);
	}
	if ( summary.untranslated > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: untranslated field count */
				__( '%d untranslated', 'ai-multilingual' ),
				summary.untranslated
			)
		);
	}

	const joined = parts.join( ' · ' );
	if ( summary.is_fully_reviewed ) {
		return joined;
	}

	return sprintf(
		/* translators: 1: counts summary, 2: object noun (page/product) */
		__( '%1$s — %2$s not fully reviewed', 'ai-multilingual' ),
		joined,
		noun
	);
}
