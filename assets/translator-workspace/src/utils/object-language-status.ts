import { __, sprintf } from '@wordpress/i18n';

import type {
	ObjectLanguageState,
	ObjectLanguageStatus,
	ObjectLanguagesSummary,
} from '../types/view-models';

/**
 * MLW1a presentation helpers for the server-authoritative "object × language"
 * state (ADR-0034 D2).
 *
 * This module is PRESENTATION ONLY. It maps a `state` the server already
 * decided to a label / badge variant / tone. It never inspects counts to
 * re-derive `state` — that is the server's job. The only fallback here is for
 * a missing/unknown `state` string.
 */

export type ObjectLanguageTone = 'success' | 'warning' | 'info' | 'neutral';

const LABELS: Record< ObjectLanguageState, string > = {
	translating: __( 'Translating…', 'ai-multilingual' ),
	not_translated: __( 'Not translated', 'ai-multilingual' ),
	missing_fields: __( 'Missing fields', 'ai-multilingual' ),
	needs_attention: __( 'Needs attention', 'ai-multilingual' ),
	pending_review: __( 'Pending review', 'ai-multilingual' ),
	reviewed: __( 'Reviewed', 'ai-multilingual' ),
	preview: __( 'Preview', 'ai-multilingual' ),
	published: __( 'Published', 'ai-multilingual' ),
	translated: __( 'Translated', 'ai-multilingual' ),
};

const BADGE_VARIANTS: Record< ObjectLanguageState, string > = {
	translating: 'translating',
	not_translated: 'missing',
	missing_fields: 'stale',
	needs_attention: 'failed',
	pending_review: 'needs-review',
	reviewed: 'reviewed',
	preview: 'preview',
	published: 'published',
	translated: 'complete',
};

const TONES: Record< ObjectLanguageState, ObjectLanguageTone > = {
	translating: 'info',
	not_translated: 'warning',
	missing_fields: 'warning',
	needs_attention: 'warning',
	pending_review: 'info',
	reviewed: 'success',
	preview: 'info',
	published: 'success',
	translated: 'success',
};

function isKnownState( state: string ): state is ObjectLanguageState {
	return Object.prototype.hasOwnProperty.call( LABELS, state );
}

/**
 * Human label for a canonical state; the raw value is the fallback.
 * @param state
 */
export function stateLabel( state: string ): string {
	return isKnownState( state ) ? LABELS[ state ] : state;
}

/**
 * `aiml-ui-badge--<variant>` modifier for a canonical state.
 * @param state
 */
export function stateBadgeVariant( state: string ): string {
	return isKnownState( state ) ? BADGE_VARIANTS[ state ] : 'missing';
}

/**
 * Tone token for a canonical state.
 * @param state
 */
export function stateTone( state: string ): ObjectLanguageTone {
	return isKnownState( state ) ? TONES[ state ] : 'neutral';
}

/**
 * "N / M languages ready" — a trivial format of two server numbers, not a
 * state decision.
 *
 * @param summary Object languages summary from the server.
 */
export function languagesReadyLabel( summary: ObjectLanguagesSummary ): string {
	return sprintf(
		/* translators: 1: ready language count, 2: total target language count */
		__( '%1$d / %2$d languages ready', 'ai-multilingual' ),
		summary.ready_count,
		summary.target_count
	);
}

/**
 * Short per-tab hint such as "5 pending" / "3 missing" — again a plain format
 * of server counts, chosen by the server-decided `state`.
 *
 * @param status One object-language status from the server.
 */
export function languageTabHint( status: ObjectLanguageStatus ): string {
	switch ( status.state ) {
		case 'missing_fields':
			return sprintf(
				/* translators: %d: missing field count */
				__( '%d missing', 'ai-multilingual' ),
				status.missing
			);
		case 'pending_review':
			return sprintf(
				/* translators: %d: pending review count */
				__( '%d pending', 'ai-multilingual' ),
				status.pending
			);
		case 'needs_attention':
			return __( 'Needs attention', 'ai-multilingual' );
		default:
			return stateLabel( status.state );
	}
}
