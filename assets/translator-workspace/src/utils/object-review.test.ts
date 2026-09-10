import type { ObjectReviewSummary } from '../types/view-models';
import { objectStateChip, objectSummaryLine } from './object-review';

function summary(
	overrides: Partial< ObjectReviewSummary > = {}
): ObjectReviewSummary {
	return {
		total: 10,
		approved: 10,
		pending: 0,
		rejected: 0,
		untranslated: 0,
		stale: 0,
		translated: 10,
		state: 'complete',
		is_fully_reviewed: true,
		...overrides,
	};
}

describe( 'objectStateChip', () => {
	it( 'maps each state to a tone', () => {
		expect( objectStateChip( summary() ).tone ).toBe( 'success' );
		expect(
			objectStateChip( summary( { state: 'incomplete' } ) ).tone
		).toBe( 'warning' );
		expect( objectStateChip( summary( { state: 'ready' } ) ).tone ).toBe(
			'info'
		);
	} );
} );

describe( 'objectSummaryLine', () => {
	it( 'stays quiet when the object is fully reviewed', () => {
		expect( objectSummaryLine( summary(), 'page' ) ).toBe( '10 approved' );
	} );

	it( 'spells out an incomplete review and never claims done', () => {
		const line = objectSummaryLine(
			summary( {
				approved: 27,
				pending: 0,
				rejected: 2,
				untranslated: 3,
				is_fully_reviewed: false,
				state: 'incomplete',
			} ),
			'page'
		);
		expect( line ).toBe(
			'27 approved · 2 need attention · 3 untranslated — page not fully reviewed'
		);
	} );
} );
