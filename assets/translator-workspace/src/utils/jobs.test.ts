import type { TranslationJobSummary } from '../types/jobs';
import {
	boundedItemError,
	boundedJobError,
	canCancelJob,
	canPauseJob,
	canResumeJob,
	canRetryFailedJob,
	canRunJob,
	formatJobProgress,
	groupJobsByBatch,
	JOB_BOUNDS,
	jobStatusLabel,
	jobTypeLabel,
	parsePostIdsInput,
	parseSegmentKeysInput,
	validateCreateJobInput,
} from './jobs';

function job( overrides: Partial< TranslationJobSummary > = {} ): TranslationJobSummary {
	return {
		job_id: 1,
		job_type: 'translate_missing',
		status: 'queued',
		requested_action: 'none',
		batch_id: null,
		source_type: 'post',
		source_id: 10,
		language_id: 2,
		provider_id: '',
		prompt_profile: 'default',
		prompt_version: '1',
		total_items: 5,
		queued_items: 5,
		running_items: 0,
		completed_items: 0,
		failed_items: 0,
		skipped_items: 0,
		stale_items: 0,
		cancelled_items: 0,
		last_error_code: '',
		last_error_class: '',
		last_error_message: '',
		created_at: '2026-08-06 08:00:00',
		updated_at: '2026-08-06 08:00:00',
		started_at: null,
		finished_at: null,
		...overrides,
	};
}

describe( 'jobs utils', () => {
	it( 'parses segment keys from comma and newline input', () => {
		expect(
			parseSegmentKeysInput( 'a\nb, c\na' )
		).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'parses unique post ids and ignores invalid values', () => {
		expect( parsePostIdsInput( '10\n20 30, bad' ) ).toEqual( [
			10, 20, 30,
		] );
	} );

	it( 'validates translate selected segment bounds', () => {
		const tooMany = Array.from(
			{ length: JOB_BOUNDS.maxSelectedSegments + 1 },
			( _, index ) => `seg-${ index }`
		);

		expect(
			validateCreateJobInput( {
				jobType: 'translate_selected',
				postId: 10,
				languageId: 2,
				segmentKeys: tooMany,
				bulkPostIds: [],
			} )
		).not.toBe( '' );
	} );

	it( 'exposes bounded job errors without extra fields', () => {
		expect(
			boundedJobError(
				job( {
					last_error_code: 'provider_unavailable',
					last_error_message: 'Configured provider is not available.',
				} )
			)
		).toEqual( {
			code: 'provider_unavailable',
			message: 'Configured provider is not available.',
		} );
	} );

	it( 'returns null when no bounded item error exists', () => {
		expect(
			boundedItemError( {
				last_error_code: '',
				last_error_message: '',
			} )
		).toBeNull();
	} );

	it( 'groups jobs by batch id', () => {
		const grouped = groupJobsByBatch( [
			job( { job_id: 1, batch_id: 'batch-a' } ),
			job( { job_id: 2, batch_id: 'batch-a' } ),
			job( { job_id: 3, batch_id: null } ),
		] );

		expect( grouped.get( 'batch-a' ) ).toHaveLength( 2 );
		expect( grouped.get( null ) ).toHaveLength( 1 );
	} );

	it( 'derives action availability from status and counters', () => {
		expect( canPauseJob( job( { status: 'running' } ) ) ).toBe( true );
		expect( canResumeJob( job( { status: 'paused' } ) ) ).toBe( true );
		expect(
			canCancelJob( job( { status: 'running', requested_action: 'cancel' } ) )
		).toBe( false );
		expect(
			canRetryFailedJob(
				job( { status: 'completed_with_errors', failed_items: 2 } )
			)
		).toBe( true );
		expect( canRunJob( job( { status: 'queued' } ) ) ).toBe( true );
	} );

	it( 'prefers server operations over legacy client predicates', () => {
		expect(
			canResumeJob(
				job( {
					status: 'queued',
					operations: [
						{
							operation_id: 'resume',
							allowed: true,
							reason_code: null,
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( true );

		expect(
			canResumeJob(
				job( {
					status: 'paused',
					operations: [
						{
							operation_id: 'resume',
							allowed: false,
							reason_code: 'state_ineligible',
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( false );

		expect(
			canRetryFailedJob(
				job( {
					status: 'failed',
					failed_items: 0,
					operations: [
						{
							operation_id: 'retry-failed',
							allowed: true,
							reason_code: null,
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( true );

		expect(
			canPauseJob(
				job( {
					status: 'completed',
					operations: [
						{
							operation_id: 'pause',
							allowed: true,
							reason_code: null,
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( true );

		expect(
			canCancelJob(
				job( {
					status: 'completed',
					operations: [
						{
							operation_id: 'cancel',
							allowed: false,
							reason_code: 'state_ineligible',
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( false );

		expect(
			canRunJob(
				job( {
					status: 'cancelled',
					operations: [
						{
							operation_id: 'run',
							allowed: true,
							reason_code: null,
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( true );
	} );

	it( 'denies unknown operations when operations array is present', () => {
		expect(
			canResumeJob(
				job( {
					status: 'paused',
					operations: [
						{
							operation_id: 'pause',
							allowed: true,
							reason_code: null,
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( false );
	} );

	it( 'labels job types and statuses', () => {
		expect( jobTypeLabel( 'bulk_translate' ) ).toBeTruthy();
		expect( jobStatusLabel( 'completed_with_errors' ).toLowerCase() ).toContain(
			'skip'
		);
		expect( jobStatusLabel( 'queued' ).toLowerCase() ).toContain( 'queue' );
		expect( jobStatusLabel( 'running' ).toLowerCase() ).toContain(
			'translat'
		);
	} );

	it( 'includes skipped and stale in progress when present', () => {
		expect(
			formatJobProgress(
				job( {
					completed_items: 2,
					failed_items: 1,
					skipped_items: 1,
					stale_items: 1,
					total_items: 5,
				} )
			)
		).toMatch( /skipped\/stale/ );
	} );

	it( 'A3 capability denial: Run hidden when canRunJob false via operations', () => {
		expect(
			canRunJob(
				job( {
					status: 'queued',
					operations: [
						{
							operation_id: 'run',
							allowed: false,
							reason_code: 'capability_denied',
							mutation_scope: 'job',
						},
					],
				} )
			)
		).toBe( false );
	} );
} );
