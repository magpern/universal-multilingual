import type { LanguageOption } from '../types/view-models';
import {
	defaultSelection,
	isAllSelected,
	isSomeSelected,
	normalizeSelection,
	operationCount,
	toggleAll,
	toggleLanguage,
} from './multi-language-selection';

const LANGS: LanguageOption[] = [
	{
		language_id: 2,
		code: 'sv',
		name: 'Swedish',
		native_name: 'Svenska',
		status: 'published',
	},
	{
		language_id: 3,
		code: 'de',
		name: 'German',
		native_name: 'Deutsch',
		status: 'preview',
	},
	{
		language_id: 4,
		code: 'da',
		name: 'Danish',
		native_name: 'Dansk',
		status: 'preview',
	},
];

describe( 'multi-language-selection', () => {
	it( 'defaults to every eligible target language selected', () => {
		expect( defaultSelection( LANGS ) ).toEqual( [ 'sv', 'de', 'da' ] );
	} );

	it( 'toggles a single language on and off, keeping registry order', () => {
		let sel = defaultSelection( LANGS );
		sel = toggleLanguage( sel, 'de', LANGS );
		expect( sel ).toEqual( [ 'sv', 'da' ] );
		sel = toggleLanguage( sel, 'de', LANGS );
		expect( sel ).toEqual( [ 'sv', 'de', 'da' ] );
	} );

	it( 'reports all / some selected for the master checkbox', () => {
		expect( isAllSelected( [ 'sv', 'de', 'da' ], LANGS ) ).toBe( true );
		expect( isAllSelected( [ 'sv', 'de' ], LANGS ) ).toBe( false );
		expect( isSomeSelected( [ 'sv' ], LANGS ) ).toBe( true );
		expect( isSomeSelected( [], LANGS ) ).toBe( false );
		expect( isSomeSelected( [ 'sv', 'de', 'da' ], LANGS ) ).toBe( false );
	} );

	it( 'master toggle selects all when not all, clears when all', () => {
		expect( toggleAll( [ 'sv' ], LANGS ) ).toEqual( [ 'sv', 'de', 'da' ] );
		expect( toggleAll( [ 'sv', 'de', 'da' ], LANGS ) ).toEqual( [] );
	} );

	it( 'never selects the source/default or an unknown language (normalizeSelection)', () => {
		// 'en' (source) and 'xx' (unknown) are not in the eligible list.
		expect(
			normalizeSelection( [ 'en', 'sv', 'xx', 'da' ], LANGS )
		).toEqual( [ 'sv', 'da' ] );
	} );

	it( 'keeps a session selection stable across an object switch', () => {
		const chosen = [ 'sv', 'da' ];
		expect( normalizeSelection( chosen, LANGS ) ).toEqual( [ 'sv', 'da' ] );
	} );

	it( 'operation count is objects × languages, not a provider estimate', () => {
		expect( operationCount( 5, 3 ) ).toBe( 15 );
		expect( operationCount( 0, 3 ) ).toBe( 0 );
		expect( operationCount( -1, 3 ) ).toBe( 0 );
	} );
} );
