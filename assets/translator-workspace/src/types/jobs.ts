export type JobType =
	| 'translate_selected'
	| 'translate_missing'
	| 'retranslate_stale'
	| 'retranslate_machine'
	| 'bulk_translate';

/**
 * The three user-facing "Translate with AI" modes (ADR-0031). "Force" is
 * deliberately not offered: manual, reviewed and in-review translations are
 * never replaced by a page or bulk AI action.
 */
export type AiTranslateMode = 'missing' | 'stale' | 'machine';

export type JobStatus =
	| 'queued'
	| 'running'
	| 'paused'
	| 'retry_wait'
	| 'completed'
	| 'completed_with_errors'
	| 'failed'
	| 'cancelled';

export type JobRequestedAction = 'none' | 'pause' | 'cancel';

export interface JobOperationDescriptor {
	operation_id: string;
	allowed: boolean;
	reason_code: string | null;
	mutation_scope: string;
}

export interface TranslationJobSummary {
	job_id: number;
	job_type: JobType | string;
	status: JobStatus | string;
	requested_action: JobRequestedAction | string;
	batch_id: string | null;
	source_type: string;
	source_id: number;
	language_id: number;
	provider_id: string;
	prompt_profile: string;
	prompt_version: string;
	total_items: number;
	queued_items: number;
	running_items: number;
	completed_items: number;
	failed_items: number;
	skipped_items: number;
	stale_items: number;
	cancelled_items: number;
	last_error_code: string;
	last_error_class: string;
	last_error_message: string;
	created_at: string;
	updated_at: string;
	started_at: string | null;
	finished_at: string | null;
	/** Authoritative TI.6 admission when present (OTL.4 / JI51). */
	operations?: JobOperationDescriptor[];
}

export interface TranslationJobItemSummary {
	item_id: number;
	job_id: number;
	segment_key: string;
	status: string;
	result_code: string;
	attempt_count: number;
	last_error_code: string;
	last_error_class: string;
	last_error_message: string;
	created_at: string;
	updated_at: string;
	started_at: string | null;
	finished_at: string | null;
}

export interface TranslationJobDetail extends TranslationJobSummary {
	items?: TranslationJobItemSummary[];
}

export interface JobsListResponse {
	items: TranslationJobSummary[];
	total: number;
	page: number;
	per_page: number;
}

export interface BatchProgressSummary {
	batch_id: string;
	job_count: number;
	total_items: number;
	queued_items: number;
	running_items: number;
	completed_items: number;
	failed_items: number;
	skipped_items: number;
	stale_items: number;
	cancelled_items: number;
	jobs_by_status: Record< string, number >;
}

export interface JobsHealthResponse {
	available: boolean;
	message: string;
	provider?: {
		available: boolean;
		message: string;
		provider_id?: string;
	};
}

export interface CreateSingleJobPayload {
	job_type: JobType;
	source_type?: string;
	source_id: number;
	language_id: number;
	segment_keys?: string[];
	client_token?: string;
	prompt_profile?: string;
	prompt_version?: string;
	provider_id?: string;
	force_new?: boolean;
	/** User-facing "Translate with AI" actions enqueue immediately (ADR-0031). */
	autostart?: boolean;
}

export interface CreateBulkJobPayload {
	language_id: number;
	autostart?: boolean;
	posts: Array< {
		source_id: number;
		segment_keys?: string[];
	} >;
	client_token?: string;
	prompt_profile?: string;
	prompt_version?: string;
	provider_id?: string;
	force_new?: boolean;
}

export interface CreateBulkJobResponse {
	batch_id: string;
	jobs: TranslationJobSummary[];
}

export type JobAction = 'pause' | 'resume' | 'cancel' | 'retry-failed' | 'run';
