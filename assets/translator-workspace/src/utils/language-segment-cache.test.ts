import type { SegmentRow } from '../types/segment-row';
import {
	dropLanguage,
	emptyCache,
	hasLanguage,
	readCache,
	writeCache,
} from './language-segment-cache';

const entry = ( key: string ) => ( {
	rows: [ { segmentKey: key } as unknown as SegmentRow ],
	status: null,
} );

describe( 'language-segment-cache', () => {
	it( 'starts empty and reports first-open per language', () => {
		const cache = emptyCache();
		expect( hasLanguage( cache, 'sv' ) ).toBe( false );
		expect( readCache( cache, 'sv' ) ).toBeUndefined();
	} );

	it( 'keeps per-language entries isolated', () => {
		let cache = emptyCache();
		cache = writeCache( cache, 'sv', entry( 'sv' ) );
		cache = writeCache( cache, 'de', entry( 'de' ) );

		expect( readCache( cache, 'sv' )?.rows[ 0 ].segmentKey ).toBe( 'sv' );
		expect( readCache( cache, 'de' )?.rows[ 0 ].segmentKey ).toBe( 'de' );

		// Overwriting one language never touches another.
		cache = writeCache( cache, 'sv', entry( 'sv2' ) );
		expect( readCache( cache, 'sv' )?.rows[ 0 ].segmentKey ).toBe( 'sv2' );
		expect( readCache( cache, 'de' )?.rows[ 0 ].segmentKey ).toBe( 'de' );
	} );

	it( 'drops exactly one language on mutation, leaves the rest', () => {
		let cache = emptyCache();
		cache = writeCache( cache, 'sv', entry( 'sv' ) );
		cache = writeCache( cache, 'de', entry( 'de' ) );
		cache = dropLanguage( cache, 'sv' );

		expect( hasLanguage( cache, 'sv' ) ).toBe( false );
		expect( hasLanguage( cache, 'de' ) ).toBe( true );
	} );

	it( 'returns a new object on write/drop (no mutation of the input)', () => {
		const cache = emptyCache();
		const next = writeCache( cache, 'sv', entry( 'sv' ) );
		expect( next ).not.toBe( cache );
		expect( hasLanguage( cache, 'sv' ) ).toBe( false );
	} );
} );
