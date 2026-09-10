import { nextTabIndex } from './language-tabs-nav';

describe( 'nextTabIndex', () => {
	it( 'moves right/down with wrap-around', () => {
		expect( nextTabIndex( 'ArrowRight', 0, 3 ) ).toBe( 1 );
		expect( nextTabIndex( 'ArrowDown', 2, 3 ) ).toBe( 0 );
	} );

	it( 'moves left/up with wrap-around', () => {
		expect( nextTabIndex( 'ArrowLeft', 0, 3 ) ).toBe( 2 );
		expect( nextTabIndex( 'ArrowUp', 1, 3 ) ).toBe( 0 );
	} );

	it( 'jumps to first / last with Home / End', () => {
		expect( nextTabIndex( 'Home', 2, 12 ) ).toBe( 0 );
		expect( nextTabIndex( 'End', 0, 12 ) ).toBe( 11 );
	} );

	it( 'returns null for non-navigation keys and empty strips', () => {
		expect( nextTabIndex( 'Enter', 0, 3 ) ).toBeNull();
		expect( nextTabIndex( 'a', 0, 3 ) ).toBeNull();
		expect( nextTabIndex( 'ArrowRight', 0, 0 ) ).toBeNull();
	} );

	it( 'handles a 12-language strip end-to-end', () => {
		let i = 0;
		for ( let step = 0; step < 12; step++ ) {
			i = nextTabIndex( 'ArrowRight', i, 12 ) as number;
		}
		expect( i ).toBe( 0 );
	} );
} );
