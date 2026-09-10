import type { LanguageOption, ReviewQueueItem } from '../types/view-models';
import {
	allQueueVisibleSelected,
	deselectAllQueueVisible,
	groupQueueByObject,
	groupSelectedByPostLanguage,
	isQueueItemSelectable,
	languageCodeForId,
	queueItemKey,
	readyLanguageCodes,
	reviewObjectCards,
	selectAllQueueVisible,
	selectableQueueItems,
	selectedQueueItems,
	toggleQueueSelection,
} from './review-queue';

function queueItem(
	overrides: Partial< ReviewQueueItem > = {}
): ReviewQueueItem {
	return {
		source_type: 'post',
		post_id: 10,
		language_id: 2,
		segment_key: 'b:test:content',
		field_key: 'content',
		source_text: 'Hello',
		translated_text: 'Hej',
		status: 'manually_edited',
		review_status: 'pending',
		submitted_translation_hash: 'abc123',
		review_submitted_by: 4,
		review_submitted_at: '2026-08-05 12:00:00',
		reviewed_by: null,
		reviewed_at: null,
		rejection_reason: '',
		rejected_by: null,
		rejected_at: null,
		translation_id: 1001,
		...overrides,
	};
}

const LANGUAGES: LanguageOption[] = [
	{
		language_id: 2,
		code: 'sv',
		name: 'Swedish',
		native_name: 'Svenska',
		status: 'active',
	},
	{
		language_id: 3,
		code: 'no',
		name: 'Norwegian',
		native_name: 'Norsk',
		status: 'active',
	},
];

describe( 'review-queue', () => {
	it( 'builds a composite key across post, language and segment', () => {
		expect( queueItemKey( queueItem() ) ).toBe( '10:2:b:test:content' );
	} );

	it( 'treats only pending rows as selectable', () => {
		expect( isQueueItemSelectable( queueItem() ) ).toBe( true );
		expect(
			isQueueItemSelectable( queueItem( { review_status: 'approved' } ) )
		).toBe( false );
		expect(
			selectableQueueItems( [
				queueItem( { segment_key: 'a' } ),
				queueItem( { segment_key: 'b', review_status: 'rejected' } ),
			] )
		).toHaveLength( 1 );
	} );

	it( 'toggles selection membership', () => {
		const selected = toggleQueueSelection( new Set(), '10:2:a', true );
		expect( selected.has( '10:2:a' ) ).toBe( true );
		expect(
			toggleQueueSelection( selected, '10:2:a', false ).has( '10:2:a' )
		).toBe( false );
	} );

	it( 'selects and deselects all visible selectable rows without dropping hidden selections', () => {
		const visible = [
			queueItem( { segment_key: 'one' } ),
			queueItem( { segment_key: 'two', review_status: 'approved' } ),
		];
		const selected = selectAllQueueVisible(
			new Set( [ '99:9:hidden' ] ),
			visible
		);

		expect( selected.has( '99:9:hidden' ) ).toBe( true );
		expect( selected.has( queueItemKey( visible[ 0 ] ) ) ).toBe( true );
		expect( selected.has( queueItemKey( visible[ 1 ] ) ) ).toBe( false );

		const deselected = deselectAllQueueVisible( selected, visible );
		expect( deselected.has( queueItemKey( visible[ 0 ] ) ) ).toBe( false );
		expect( deselected.has( '99:9:hidden' ) ).toBe( true );
	} );

	it( 'detects when all visible selectable rows are selected', () => {
		const visible = [ queueItem( { segment_key: 'one' } ) ];
		expect(
			allQueueVisibleSelected(
				visible,
				new Set( [ queueItemKey( visible[ 0 ] ) ] )
			)
		).toBe( true );
		expect( allQueueVisibleSelected( visible, new Set() ) ).toBe( false );
		expect(
			allQueueVisibleSelected(
				[
					queueItem( {
						segment_key: 'x',
						review_status: 'approved',
					} ),
				],
				new Set()
			)
		).toBe( false );
	} );

	it( 'returns only the currently selected items', () => {
		const items = [
			queueItem( { segment_key: 'one' } ),
			queueItem( { segment_key: 'two' } ),
		];
		const selected = new Set( [ queueItemKey( items[ 0 ] ) ] );
		expect( selectedQueueItems( items, selected ) ).toEqual( [
			items[ 0 ],
		] );
	} );

	it( 'groups a cross-post, cross-language selection for batched REST calls', () => {
		const items = [
			queueItem( { post_id: 1, language_id: 2, segment_key: 'a' } ),
			queueItem( { post_id: 1, language_id: 2, segment_key: 'b' } ),
			queueItem( { post_id: 2, language_id: 3, segment_key: 'c' } ),
		];

		const groups = groupSelectedByPostLanguage( items );

		expect( groups ).toHaveLength( 2 );
		expect( groups[ 0 ] ).toEqual( {
			postId: 1,
			languageId: 2,
			items: [ items[ 0 ], items[ 1 ] ],
		} );
		expect( groups[ 1 ] ).toEqual( {
			postId: 2,
			languageId: 3,
			items: [ items[ 2 ] ],
		} );
	} );

	it( 'resolves a language code from a language id', () => {
		expect( languageCodeForId( LANGUAGES, 2 ) ).toBe( 'sv' );
		expect( languageCodeForId( LANGUAGES, 999 ) ).toBe( '' );
	} );

	it( 'prefers the server objects block when grouping', () => {
		const serverGroup = {
			post_id: 5,
			post_title: 'Hexarelin',
			post_type: 'product',
			object_noun: 'Product',
			post_status: 'publish',
			language_id: 2,
			language_code: 'sv',
			language_name: 'Swedish',
			edit_link: '',
			summary: {
				total: 14,
				approved: 0,
				pending: 14,
				rejected: 0,
				untranslated: 0,
				stale: 0,
				translated: 14,
				state: 'ready' as const,
				is_fully_reviewed: false,
			},
			items: [],
		};
		expect(
			groupQueueByObject( {
				items: [],
				objects: [ serverGroup ],
				total: 14,
				page: 1,
				per_page: 100,
			} )
		).toEqual( [ serverGroup ] );
	} );

	it( 'derives shallow groups when objects is absent', () => {
		const groups = groupQueueByObject( {
			items: [
				queueItem( { post_id: 1, language_id: 2 } ),
				queueItem( { post_id: 1, language_id: 2, segment_key: 'x' } ),
				queueItem( { post_id: 2, language_id: 2 } ),
			],
			total: 3,
			page: 1,
			per_page: 100,
		} );
		expect( groups ).toHaveLength( 2 );
		expect( groups[ 0 ].items ).toHaveLength( 2 );
	} );
} );

describe( 'MLW1a object-first cards', () => {
	const languageGroup = ( code: string, approvable: boolean ) => ( {
		language_id: code === 'sv' ? 2 : 3,
		language_code: code,
		language_name: code.toUpperCase(),
		summary: {
			language_id: code === 'sv' ? 2 : 3,
			language_code: code,
			language_name: code.toUpperCase(),
			language_status: 'preview',
			total: 5,
			translated: 5,
			missing: 0,
			stale: 0,
			pending: approvable ? 3 : 0,
			approved: 0,
			rejected: 0,
			published_segments: 0,
			has_active_job: false,
			has_qa_errors: false,
			state: approvable
				? ( 'pending_review' as const )
				: ( 'reviewed' as const ),
			is_ready: true,
			is_forgotten: false,
			is_approvable: approvable,
		},
		items: [],
	} );

	const card = {
		post_id: 3602,
		post_title: 'Hexarelin',
		post_type: 'product',
		object_noun: 'Product',
		post_status: 'publish',
		edit_link: '',
		languages: [
			languageGroup( 'sv', true ),
			languageGroup( 'de', false ),
		],
		object_languages_summary: {
			target_count: 3,
			ready_count: 2,
			not_translated_count: 1,
			incomplete_count: 0,
			translating_count: 0,
			approvable_count: 1,
			forgotten: true,
			forgotten_languages: [ 'da' ],
		},
	};

	it( 'returns object_groups verbatim, or [] for a legacy payload', () => {
		expect(
			reviewObjectCards( {
				items: [],
				object_groups: [ card ],
				object_total: 1,
				total: 3,
				page: 1,
				per_page: 100,
			} )
		).toEqual( [ card ] );

		expect(
			reviewObjectCards( { items: [], total: 0, page: 1, per_page: 100 } )
		).toEqual( [] );
	} );

	it( 'readyLanguageCodes filters on the server is_approvable flag only', () => {
		expect( readyLanguageCodes( card ) ).toEqual( [ 'sv' ] );
	} );
} );
