import type {
	ObjectLanguageState,
	ObjectLanguageStatus,
	ObjectLanguagesSummary,
} from '../types/view-models';
import {
	languageTabHint,
	languagesReadyLabel,
	stateBadgeVariant,
	stateLabel,
	stateTone,
} from './object-language-status';

const STATES: ObjectLanguageState[] = [
	'translating',
	'not_translated',
	'missing_fields',
	'needs_attention',
	'pending_review',
	'reviewed',
	'preview',
	'published',
	'translated',
];

function status(
	overrides: Partial< ObjectLanguageStatus > = {}
): ObjectLanguageStatus {
	return {
		language_id: 2,
		language_code: 'sv',
		language_name: 'Swedish',
		language_status: 'preview',
		total: 10,
		translated: 10,
		missing: 0,
		stale: 0,
		pending: 0,
		approved: 0,
		rejected: 0,
		published_segments: 0,
		has_active_job: false,
		has_qa_errors: false,
		state: 'translated',
		is_ready: true,
		is_forgotten: false,
		is_approvable: false,
		...overrides,
	};
}

describe( 'object-language-status presentation maps', () => {
	it( 'has a label, badge variant and tone for every canonical state', () => {
		for ( const state of STATES ) {
			expect( stateLabel( state ) ).not.toBe( '' );
			expect( stateBadgeVariant( state ) ).not.toBe( '' );
			expect( [ 'success', 'warning', 'info', 'neutral' ] ).toContain(
				stateTone( state )
			);
		}
	} );

	it( 'falls back to the raw value for an unknown state (never throws, never guesses)', () => {
		expect( stateLabel( 'weird_new_state' ) ).toBe( 'weird_new_state' );
		expect( stateBadgeVariant( 'weird_new_state' ) ).toBe( 'missing' );
		expect( stateTone( 'weird_new_state' ) ).toBe( 'neutral' );
	} );

	it( 'maps tones the way the frozen states imply', () => {
		expect( stateTone( 'published' ) ).toBe( 'success' );
		expect( stateTone( 'reviewed' ) ).toBe( 'success' );
		expect( stateTone( 'not_translated' ) ).toBe( 'warning' );
		expect( stateTone( 'translating' ) ).toBe( 'info' );
	} );
} );

describe( 'languagesReadyLabel', () => {
	it( 'formats exactly ready_count / target_count', () => {
		const summary: ObjectLanguagesSummary = {
			target_count: 5,
			ready_count: 2,
			not_translated_count: 1,
			incomplete_count: 1,
			translating_count: 1,
			approvable_count: 1,
			forgotten: true,
			forgotten_languages: [ 'da' ],
		};
		expect( languagesReadyLabel( summary ) ).toBe(
			'2 / 5 languages ready'
		);
	} );
} );

describe( 'languageTabHint', () => {
	it( 'summarises server counts by the server-decided state', () => {
		expect(
			languageTabHint( status( { state: 'missing_fields', missing: 3 } ) )
		).toBe( '3 missing' );
		expect(
			languageTabHint( status( { state: 'pending_review', pending: 5 } ) )
		).toBe( '5 pending' );
		expect( languageTabHint( status( { state: 'published' } ) ) ).toBe(
			'Published'
		);
	} );
} );
