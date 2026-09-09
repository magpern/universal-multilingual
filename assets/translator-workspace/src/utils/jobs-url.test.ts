/**
 * URL sync helpers for Jobs tab deep links (OTL.4).
 */

import {
	clearJobsViewFromUrl,
	readJobsUrlState,
	writeJobsUrlState,
} from './jobs-url';

describe( 'jobs-url', () => {
	it( 'reads view=jobs with job_id and item_id', () => {
		expect(
			readJobsUrlState( '?page=aiml-translator&view=jobs&job_id=42&item_id=7' )
		).toEqual( {
			view: 'jobs',
			jobId: 42,
			itemId: 7,
			batchId: null,
		} );
	} );

	it( 'ignores non-jobs views and invalid ids', () => {
		expect(
			readJobsUrlState( '?view=operations&job_id=abc&item_id=-1' )
		).toEqual( {
			view: null,
			jobId: null,
			itemId: null,
			batchId: null,
		} );
	} );

	it( 'writes and clears jobs URL state', () => {
		const original = window.location.href;
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=aiml-translator&view=jobs&job_id=9'
		);

		writeJobsUrlState( { jobId: 9, itemId: 3 } );
		expect( window.location.search ).toContain( 'view=jobs' );
		expect( window.location.search ).toContain( 'job_id=9' );
		expect( window.location.search ).toContain( 'item_id=3' );

		clearJobsViewFromUrl();
		expect( window.location.search ).not.toContain( 'view=jobs' );
		expect( window.location.search ).not.toContain( 'job_id=' );
		expect( window.location.search ).not.toContain( 'item_id=' );

		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=aiml-translator&view=operations&job_id=1'
		);
		clearJobsViewFromUrl();
		expect( window.location.search ).toContain( 'view=operations' );
		expect( window.location.search ).not.toContain( 'job_id=' );

		window.history.replaceState( {}, '', original );
	} );
} );
