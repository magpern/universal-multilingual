import { __, sprintf } from '@wordpress/i18n';

import type {
	AiTranslateMode,
	BatchProgressSummary,
	JobAction,
	JobStatus,
	JobType,
	TranslationJobItemSummary,
	TranslationJobSummary,
} from '../types/jobs';
import type { LanguageOption } from '../types/view-models';

/** Frozen MVP limits (plan §5 / JobBounds.php). */
export const JOB_BOUNDS = {
	maxPostsPerBulk: 50,
	maxItemsPerJob: 500,
	maxSelectedSegments: 50,
	maxConcurrentRunning: 20,
} as const;

const PAUSABLE_STATUSES = new Set( [ 'queued', 'running', 'retry_wait' ] );
const RESUMABLE_STATUSES = new Set( [ 'paused' ] );
const CANCELLABLE_STATUSES = new Set( [
	'queued',
	'running',
	'paused',
	'retry_wait',
] );
const RETRYABLE_STATUSES = new Set( [ 'completed_with_errors', 'failed', 'paused' ] );
const RUNNABLE_STATUSES = new Set( [
	'queued',
	'paused',
	'retry_wait',
	'running',
	'completed_with_errors',
] );

export function jobTypeLabel( jobType: string ): string {
	switch ( jobType ) {
		case 'translate_selected':
			return __( 'Translate selected', 'ai-multilingual' );
		case 'translate_missing':
			return __( 'Translate missing', 'ai-multilingual' );
		case 'retranslate_stale':
			return __( 'Retranslate stale', 'ai-multilingual' );
		case 'retranslate_machine':
			return __( 'Retranslate AI translations', 'ai-multilingual' );
		case 'bulk_translate':
			return __( 'Bulk translate', 'ai-multilingual' );
		default:
			return jobType;
	}
}

/**
 * Shared `.aiml-ui-badge--*` modifier for a job execution status (ADR-0031).
 * Execution state only — review state is a separate badge.
 */
export function jobStatusBadgeVariant( status: string ): string {
	switch ( status ) {
		case 'running':
			return 'translating';
		case 'completed':
			return 'complete';
		case 'completed_with_errors':
			return 'completed-with-skips';
		case 'failed':
		case 'cancelled':
			return 'failed';
		case 'queued':
		case 'paused':
		case 'retry_wait':
		default:
			return 'queued';
	}
}

/**
 * The frozen three-mode "Translate with AI" vocabulary and its job-type mapping
 * (ADR-0031). `bulkJobType` is what a multi-post batch uses for the same mode.
 */
export function aiTranslateModeOptions(): Array< {
	value: AiTranslateMode;
	label: string;
	jobType: JobType;
	bulkJobType: JobType;
} > {
	return [
		{
			value: 'missing',
			label: __( 'Translate missing', 'ai-multilingual' ),
			jobType: 'translate_missing',
			bulkJobType: 'bulk_translate',
		},
		{
			value: 'stale',
			label: __( 'Retranslate stale', 'ai-multilingual' ),
			jobType: 'retranslate_stale',
			bulkJobType: 'retranslate_stale',
		},
		{
			value: 'machine',
			label: __( 'Retranslate AI translations', 'ai-multilingual' ),
			jobType: 'retranslate_machine',
			bulkJobType: 'retranslate_machine',
		},
	];
}

export function jobStatusLabel( status: string ): string {
	switch ( status ) {
		case 'queued':
			return __( 'Queued', 'ai-multilingual' );
		case 'running':
			return __( 'Translating', 'ai-multilingual' );
		case 'paused':
			return __( 'Paused', 'ai-multilingual' );
		case 'retry_wait':
			return __( 'Retry wait', 'ai-multilingual' );
		case 'completed':
			return __( 'Completed', 'ai-multilingual' );
		case 'completed_with_errors':
			return __( 'Completed with skips', 'ai-multilingual' );
		case 'failed':
			return __( 'Failed', 'ai-multilingual' );
		case 'cancelled':
			return __( 'Cancelled', 'ai-multilingual' );
		default:
			return status;
	}
}

export function jobActionLabel( action: JobAction ): string {
	switch ( action ) {
		case 'pause':
			return __( 'Pause', 'ai-multilingual' );
		case 'resume':
			return __( 'Resume', 'ai-multilingual' );
		case 'cancel':
			return __( 'Cancel', 'ai-multilingual' );
		case 'retry-failed':
			return __( 'Retry failed items', 'ai-multilingual' );
		case 'run':
			return __( 'Run now', 'ai-multilingual' );
	}
}

export function jobActionConfirmMessage( action: JobAction ): string {
	switch ( action ) {
		case 'pause':
			return __(
				'Pause this job at the next safe item boundary. The current item will finish first.',
				'ai-multilingual'
			);
		case 'resume':
			return __(
				'Resume this paused job and return it to the queue.',
				'ai-multilingual'
			);
		case 'cancel':
			return __(
				'Cancel this job at the next safe item boundary. Cancelled jobs cannot be resumed.',
				'ai-multilingual'
			);
		case 'retry-failed':
			return __(
				'Reset failed items to queued and wake the worker.',
				'ai-multilingual'
			);
		case 'run':
			return __(
				'Enqueue a worker wake for this job.',
				'ai-multilingual'
			);
	}
}

export function jobTypeOptions(): Array< { value: JobType; label: string } > {
	return [
		{
			value: 'translate_selected',
			label: jobTypeLabel( 'translate_selected' ),
		},
		{
			value: 'translate_missing',
			label: jobTypeLabel( 'translate_missing' ),
		},
		{
			value: 'retranslate_stale',
			label: jobTypeLabel( 'retranslate_stale' ),
		},
		{
			value: 'bulk_translate',
			label: jobTypeLabel( 'bulk_translate' ),
		},
	];
}

export function jobStatusFilterOptions(): Array< {
	value: JobStatus | 'all';
	label: string;
} > {
	return [
		{ value: 'all', label: __( 'All statuses', 'ai-multilingual' ) },
		{ value: 'queued', label: jobStatusLabel( 'queued' ) },
		{ value: 'running', label: jobStatusLabel( 'running' ) },
		{ value: 'paused', label: jobStatusLabel( 'paused' ) },
		{ value: 'retry_wait', label: jobStatusLabel( 'retry_wait' ) },
		{
			value: 'completed_with_errors',
			label: jobStatusLabel( 'completed_with_errors' ),
		},
		{ value: 'completed', label: jobStatusLabel( 'completed' ) },
		{ value: 'failed', label: jobStatusLabel( 'failed' ) },
		{ value: 'cancelled', label: jobStatusLabel( 'cancelled' ) },
	];
}

export function boundsHelpMessage( jobType: JobType ): string {
	switch ( jobType ) {
		case 'translate_selected':
			return sprintf(
				/* translators: %d: max segment count */
				__(
					'Up to %d segment keys per job.',
					'ai-multilingual'
				),
				JOB_BOUNDS.maxSelectedSegments
			);
		case 'bulk_translate':
			return sprintf(
				/* translators: 1: max posts, 2: max items */
				__(
					'Up to %1$d posts per bulk request; missing segments are resolved automatically (no segment keys). Each job may materialize up to %2$d items. After create, use Run now (administrators) to start processing.',
					'ai-multilingual'
				),
				JOB_BOUNDS.maxPostsPerBulk,
				JOB_BOUNDS.maxItemsPerJob
			);
		default:
			return sprintf(
				/* translators: %d: max items per job */
				__(
					'Each job may materialize up to %d items.',
					'ai-multilingual'
				),
				JOB_BOUNDS.maxItemsPerJob
			);
	}
}

export function parseSegmentKeysInput( raw: string ): string[] {
	const keys = raw
		.split( /[\n,]+/ )
		.map( ( key ) => key.trim() )
		.filter( ( key ) => key.length > 0 );

	return Array.from( new Set( keys ) );
}

export function parsePostIdsInput( raw: string ): number[] {
	const ids = raw
		.split( /[\n,\s]+/ )
		.map( ( value ) => Number( value.trim() ) )
		.filter( ( id ) => Number.isInteger( id ) && id > 0 );

	return Array.from( new Set( ids ) );
}

export function languageLabelForId(
	languages: LanguageOption[],
	languageId: number
): string {
	const match = languages.find(
		( language ) => language.language_id === languageId
	);
	if ( ! match ) {
		return String( languageId );
	}

	return `${ match.name } (${ match.code })`;
}

export function formatJobProgress( job: TranslationJobSummary ): string {
	const skipped = job.skipped_items + job.stale_items;
	if ( skipped > 0 ) {
		return sprintf(
			/* translators: 1: completed, 2: failed, 3: skipped+stale, 4: total */
			__(
				'%1$d done · %2$d failed · %3$d skipped/stale · %4$d total',
				'ai-multilingual'
			),
			job.completed_items,
			job.failed_items,
			skipped,
			job.total_items
		);
	}

	return sprintf(
		/* translators: 1: completed, 2: failed, 3: total */
		__( '%1$d done · %2$d failed · %3$d total', 'ai-multilingual' ),
		job.completed_items,
		job.failed_items,
		job.total_items
	);
}

export function formatBatchProgress( batch: BatchProgressSummary ): string {
	const skipped = batch.skipped_items + batch.stale_items;
	if ( skipped > 0 ) {
		return sprintf(
			/* translators: 1: job count, 2: completed, 3: failed, 4: skipped+stale, 5: total items */
			__(
				'%1$d jobs · %2$d done · %3$d failed · %4$d skipped/stale · %5$d items',
				'ai-multilingual'
			),
			batch.job_count,
			batch.completed_items,
			batch.failed_items,
			skipped,
			batch.total_items
		);
	}

	return sprintf(
		/* translators: 1: job count, 2: completed, 3: failed, 4: total items */
		__(
			'%1$d jobs · %2$d done · %3$d failed · %4$d items',
			'ai-multilingual'
		),
		batch.job_count,
		batch.completed_items,
		batch.failed_items,
		batch.total_items
	);
}

export interface BoundedJobError {
	code: string;
	message: string;
}

export function boundedJobError(
	job: Pick<
		TranslationJobSummary,
		'last_error_code' | 'last_error_message'
	>
): BoundedJobError | null {
	const code = job.last_error_code?.trim() ?? '';
	const message = job.last_error_message?.trim() ?? '';
	if ( ! code && ! message ) {
		return null;
	}

	return {
		code: code || __( 'unknown', 'ai-multilingual' ),
		message: message || __( 'An error occurred.', 'ai-multilingual' ),
	};
}

export function boundedItemError(
	item: Pick<
		TranslationJobItemSummary,
		'last_error_code' | 'last_error_message'
	>
): BoundedJobError | null {
	const code = item.last_error_code?.trim() ?? '';
	const message = item.last_error_message?.trim() ?? '';
	if ( ! code && ! message ) {
		return null;
	}

	return {
		code: code || __( 'unknown', 'ai-multilingual' ),
		message: message || __( 'An error occurred.', 'ai-multilingual' ),
	};
}

/**
 * Prefer server `operations[]` when present (TI.6 admission authority).
 * Returns null only when the operations array is missing (legacy payloads).
 */
function operationAllowedFromServer(
	job: TranslationJobSummary,
	operationId: string
): boolean | null {
	if ( ! Array.isArray( job.operations ) ) {
		return null;
	}

	const match = job.operations.find(
		( operation ) => operation.operation_id === operationId
	);
	return match ? Boolean( match.allowed ) : false;
}

export function canPauseJob( job: TranslationJobSummary ): boolean {
	const fromServer = operationAllowedFromServer( job, 'pause' );
	if ( null !== fromServer ) {
		return fromServer;
	}

	return (
		PAUSABLE_STATUSES.has( job.status ) &&
		'pause' !== job.requested_action &&
		'cancel' !== job.requested_action
	);
}

export function canResumeJob( job: TranslationJobSummary ): boolean {
	const fromServer = operationAllowedFromServer( job, 'resume' );
	if ( null !== fromServer ) {
		return fromServer;
	}

	return RESUMABLE_STATUSES.has( job.status );
}

export function canCancelJob( job: TranslationJobSummary ): boolean {
	const fromServer = operationAllowedFromServer( job, 'cancel' );
	if ( null !== fromServer ) {
		return fromServer;
	}

	return (
		CANCELLABLE_STATUSES.has( job.status ) &&
		'cancel' !== job.requested_action
	);
}

export function canRetryFailedJob( job: TranslationJobSummary ): boolean {
	const fromServer = operationAllowedFromServer( job, 'retry-failed' );
	if ( null !== fromServer ) {
		return fromServer;
	}

	return RETRYABLE_STATUSES.has( job.status ) && job.failed_items > 0;
}

export function canRunJob( job: TranslationJobSummary ): boolean {
	const fromServer = operationAllowedFromServer( job, 'run' );
	if ( null !== fromServer ) {
		return fromServer;
	}

	return RUNNABLE_STATUSES.has( job.status );
}

export function groupJobsByBatch(
	jobs: TranslationJobSummary[]
): Map< string | null, TranslationJobSummary[] > {
	const groups = new Map< string | null, TranslationJobSummary[] >();

	for ( const job of jobs ) {
		const key = job.batch_id || null;
		const bucket = groups.get( key ) ?? [];
		bucket.push( job );
		groups.set( key, bucket );
	}

	return groups;
}

export function validateCreateJobInput( args: {
	jobType: JobType;
	postId: number | null;
	languageId: number | null;
	segmentKeys: string[];
	bulkPostIds: number[];
} ): string {
	if ( ! args.languageId ) {
		return __( 'Select a target language.', 'ai-multilingual' );
	}

	if ( 'bulk_translate' === args.jobType ) {
		if ( 0 === args.bulkPostIds.length ) {
			return __( 'Enter at least one post ID for bulk translation.', 'ai-multilingual' );
		}
		if ( args.bulkPostIds.length > JOB_BOUNDS.maxPostsPerBulk ) {
			return sprintf(
				/* translators: %d: max posts */
				__(
					'Bulk requests are limited to %d posts.',
					'ai-multilingual'
				),
				JOB_BOUNDS.maxPostsPerBulk
			);
		}
		return '';
	}

	if ( ! args.postId ) {
		return __( 'Select a post for this job.', 'ai-multilingual' );
	}

	if ( 'translate_selected' === args.jobType ) {
		if ( 0 === args.segmentKeys.length ) {
			return __(
				'Enter at least one segment key for translate selected.',
				'ai-multilingual'
			);
		}
		if ( args.segmentKeys.length > JOB_BOUNDS.maxSelectedSegments ) {
			return sprintf(
				/* translators: %d: max segments */
				__(
					'Translate selected is limited to %d segment keys.',
					'ai-multilingual'
				),
				JOB_BOUNDS.maxSelectedSegments
			);
		}
	}

	return '';
}
