export interface LanguageOption {
	language_id: number;
	code: string;
	name: string;
	native_name: string;
	status: string;
}

export interface WorkspacePageSummary {
	post_id: number;
	post_title: string;
	post_type: string;
	post_status: string;
	modified_gmt: string;
	language_id: number;
	total_segments: number;
	stale_count: number;
}

export type ReviewStatus =
	| 'not_submitted'
	| 'pending'
	| 'approved'
	| 'rejected';

export interface ReviewMetadata {
	review_status: ReviewStatus | string;
	submitted_translation_hash: string;
	review_submitted_by: number | null;
	review_submitted_at: string | null;
	reviewed_by: number | null;
	reviewed_at: string | null;
	rejection_reason: string;
	rejected_by: number | null;
	rejected_at: string | null;
}

export interface WorkspaceSegment extends ReviewMetadata {
	segment_key: string;
	field_key: string;
	block_name: string;
	uuid: string;
	segment_order: number;
	source_text: string;
	source_hash: string;
	translation_hash?: string;
	translated_text: string;
	status: string;
	is_stale: boolean;
	/** Present on assembled wire payloads; also mirrored under meta.publish_status. */
	publish_status?: string;
	text_format: string;
	can_edit: boolean;
	meta: WorkspaceSegmentMeta;
}

export interface ReviewQueueItem extends ReviewMetadata {
	source_type: string;
	post_id: number;
	language_id: number;
	segment_key: string;
	field_key: string;
	source_text: string;
	translated_text: string;
	status: string;
	translation_id: number;
	/** Server-authoritative human label (RVQ1); absent on older payloads. */
	field_label?: string;
	post_title?: string;
	post_type?: string;
}

export type ObjectReviewState =
	| 'ready'
	| 'needs_attention'
	| 'incomplete'
	| 'complete';

export interface ObjectReviewSummary {
	total: number;
	approved: number;
	pending: number;
	rejected: number;
	untranslated: number;
	stale: number;
	translated: number;
	state: ObjectReviewState;
	is_fully_reviewed: boolean;
}

/**
 * MLW1a — canonical "object × language" state. Server-authoritative
 * (ADR-0034 D2): the client renders `state` verbatim and never re-derives it.
 */
export type ObjectLanguageState =
	| 'translating'
	| 'not_translated'
	| 'missing_fields'
	| 'needs_attention'
	| 'pending_review'
	| 'reviewed'
	| 'preview'
	| 'published'
	| 'translated';

export interface ObjectLanguageStatus {
	language_id: number;
	language_code: string;
	language_name: string;
	language_status: string;
	total: number;
	translated: number;
	missing: number;
	stale: number;
	pending: number;
	approved: number;
	rejected: number;
	published_segments: number;
	has_active_job: boolean;
	has_qa_errors: boolean;
	state: ObjectLanguageState;
	is_ready: boolean;
	is_forgotten: boolean;
	is_approvable: boolean;
}

export interface ObjectLanguagesSummary {
	target_count: number;
	ready_count: number;
	not_translated_count: number;
	incomplete_count: number;
	translating_count: number;
	approvable_count: number;
	forgotten: boolean;
	forgotten_languages: string[];
}

export interface ObjectLanguagesResponse {
	post_id: number;
	post_title: string;
	post_type: string;
	post_status: string;
	object_noun: string;
	edit_link: string;
	languages: ObjectLanguageStatus[];
	summary: ObjectLanguagesSummary;
}

export interface ReviewObjectGroup {
	post_id: number;
	post_title: string;
	post_type: string;
	object_noun: string;
	post_status: string;
	language_id: number;
	language_code: string;
	language_name: string;
	edit_link: string;
	summary: ObjectReviewSummary;
	items: ReviewQueueItem[];
}

/**
 * MLW1a (WP5/WP6) — one target language inside a Review Queue object card.
 * `summary` is the server-authoritative ObjectLanguageStatus (state decided
 * in PHP; the client renders it verbatim).
 */
export interface ReviewObjectLanguageGroup {
	language_id: number;
	language_code: string;
	language_name: string;
	summary: ObjectLanguageStatus;
	items: ReviewQueueItem[];
}

/** MLW1a (WP5/WP6) — one Review Queue card = one content object, all languages. */
export interface ReviewObjectCard {
	post_id: number;
	post_title: string;
	post_type: string;
	object_noun: string;
	post_status: string;
	edit_link: string;
	languages: ReviewObjectLanguageGroup[];
	object_languages_summary: ObjectLanguagesSummary;
}

export interface TranslateObjectsResult {
	batch_id: string;
	planned: {
		objects: number;
		languages: number;
		operations: number;
		chunks: number;
	};
	created: number;
	failed: unknown[];
	autostarted: boolean;
}

export interface ReviewQueueResponse {
	items: ReviewQueueItem[];
	objects?: ReviewObjectGroup[];
	/** MLW1a object-first read model (ADR-0034 C1). */
	object_groups?: ReviewObjectCard[];
	/** Distinct-object count for object-level pagination. */
	object_total?: number;
	total: number;
	page: number;
	per_page: number;
}

export interface ApproveObjectLanguageResult {
	language_id: number;
	language_code: string;
	approved_count: number;
	skipped: Array< {
		segment_key: string;
		code: string;
		message: string;
		field_label?: string;
	} >;
	summary: ObjectReviewSummary;
}

export interface ApproveObjectLanguagesResult {
	post_id: number;
	post_title: string;
	post_type: string;
	approved_languages: ApproveObjectLanguageResult[];
	skipped_languages: Array< {
		language_id: number;
		language_code: string;
		state: string;
		pending: number;
	} >;
	summary: ObjectLanguagesSummary;
	not_fully_reviewed: boolean;
}

export interface ApproveObjectResult {
	post_id: number;
	post_title: string;
	post_type: string;
	language_id: number;
	approved_count: number;
	approved: WorkspaceSegment[];
	skipped: Array< {
		segment_key: string;
		code: string;
		message: string;
		field_label?: string;
	} >;
	summary: ObjectReviewSummary;
}

export interface ReviewErrorContext {
	review_status?: string;
	submitted_translation_hash?: string;
	translation_hash?: string;
	review_submitted_by?: number | null;
	review_submitted_at?: string | null;
	reviewed_by?: number | null;
	reviewed_at?: string | null;
	rejection_reason?: string;
	rejected_by?: number | null;
	rejected_at?: string | null;
	expected_review_status?: string;
	[ key: string ]: unknown;
}

export interface NormalizedSuggestion {
	provider_id: string;
	target_text: string;
	confidence: number;
	rank_tier: number;
	metadata: Record< string, unknown >;
}

export interface QAIssue {
	code: string;
	severity: 'error' | 'warning' | 'info' | string;
	message: string;
	details: Record< string, unknown >;
}

export interface QASummary {
	errors: number;
	warnings: number;
	info: number;
}

export interface SegmentQA {
	issues: QAIssue[];
	summary: QASummary;
}

export interface WorkspaceSegmentMeta {
	suggestions?: NormalizedSuggestion[];
	qa?: SegmentQA;
	[ key: string ]: unknown;
}

export interface WorkspaceSegmentsResponse {
	post_id: number;
	language_id: number;
	segments: WorkspaceSegment[];
	status: WorkspaceTranslationStatus;
}

export interface WorkspaceTranslationStatus {
	post_id: number;
	post_title: string;
	post_status: string;
	post_type: string;
	language_id: number;
	total_segments: number;
	missing_count: number;
	stale_count: number;
	translated_count: number;
	reviewed_count: number;
	overall_state: string;
	is_published: boolean;
	edit_link: string;
}

export interface WorkspacePostsResponse {
	items: WorkspacePageSummary[];
	page: number;
	per_page: number;
	total: number;
	total_pages: number;
}

export interface TranslatorWorkspaceConfig {
	restNamespace: string;
	nonce: string;
	languages: LanguageOption[];
	initialPostId?: number;
	initialLanguageCode?: string;
	canTranslate: boolean;
	canReview: boolean;
	canAccessOperations?: boolean;
	canViewJobs: boolean;
	canManageJobs: boolean;
	canRunJobs: boolean;
	canCancelJobs: boolean;
	/** Whether AI translation is configured (ADR-0031). */
	aiConfigured?: boolean;
	/** Settings screen URL for admins when AI is not configured. */
	aiSettingsUrl?: string;
}

export interface AllowedActionDescriptor {
	id: string;
	allowed: boolean;
	reason_code: string;
}

export interface OperationsListItem {
	translation_id: number;
	source_type: string;
	source_id: number;
	source_subtype: string;
	language_id: number;
	language_code: string;
	segment_key: string;
	field_key: string;
	status: string;
	review_status: string;
	publish_status: string;
	is_stale: boolean;
	attention_reasons: string[];
	source_preview: string;
	target_preview: string;
	updated_at: string;
	created_at: string;
	provider: string;
	model: string;
	error_code: string;
	error_message: string;
	links: Record< string, string >;
	allowed_actions: AllowedActionDescriptor[];
	/** List payloads always omit Jobs enrichment (OTL.4). */
	jobs: null;
}

export interface OperationsListResponse {
	items: OperationsListItem[];
	total: number;
	page: number;
	per_page: number;
}

export interface OperationsPublicationSettings {
	segment_publication_gate_enabled?: boolean;
	auto_publication_mode?: string;
}

/** TI.6 JobsOperationAdmission row mapped into OTL / Jobs ViewModels. */
export interface JobsOperationDescriptor {
	operation_id: string;
	allowed: boolean;
	reason_code: string | null;
	mutation_scope: string;
}

export interface OperationsJobsAssociationJob {
	job_id: number;
	status: string;
	job_type: string;
	source_type: string;
	source_id: number;
	language_id: number;
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
	budget_max_requests?: number;
	budget_max_tokens?: number;
	budget_used_requests?: number;
	budget_used_tokens?: number;
}

export interface OperationsJobsAssociationItem {
	item_id: number;
	segment_key: string;
	status: string;
	attempt_count: number;
	result_code: string;
	last_error_code: string;
	last_error_class: string;
}

export interface OperationsJobsAssociation {
	job: OperationsJobsAssociationJob;
	item: OperationsJobsAssociationItem;
	failed_items_in_job: number;
	mutation_scope: string;
	operations?: JobsOperationDescriptor[];
}

export interface OperationsJobsLookup {
	bounded: boolean;
	job_scan_limit: number;
	matched: boolean;
	exhausted: boolean;
}

export interface OperationsJobsRetention {
	applies: boolean;
}

export interface OperationsJobsFailurePresentation {
	category: string;
	code: string;
	message: string;
}

export interface OperationsJobsUsagePresentation {
	budget_max_requests?: number;
	budget_max_tokens?: number;
	budget_used_requests?: number;
	budget_used_tokens?: number;
	usage_known: boolean;
	scope?: string;
}

export interface OperationsJobsExactlyOnceHelp {
	code: string;
	message: string;
}

export interface OperationsJobsPresentation {
	failure: OperationsJobsFailurePresentation | null;
	usage: OperationsJobsUsagePresentation | null;
	exactly_once_help: OperationsJobsExactlyOnceHelp | null;
}

export interface OperationsJobsNavigation {
	jobs_tab: boolean;
	job_id?: number;
	item_id?: number;
}

/** Detail-only Jobs subtree (null when Jobs view is denied). */
export interface OperationsJobsSubtree {
	association: OperationsJobsAssociation | null;
	lookup: OperationsJobsLookup;
	retention: OperationsJobsRetention;
	presentation: OperationsJobsPresentation;
	navigation: OperationsJobsNavigation;
}

export interface OperationsDetailResponse
	extends Omit< OperationsListItem, 'jobs' > {
	source_text?: string;
	translated_text?: string;
	text_format?: string;
	source_hash?: string;
	translation_hash?: string;
	tm_id?: number | string | null;
	published_at?: string;
	published_by?: number | null;
	qa?: Record< string, unknown >;
	assessment?: Record< string, unknown >;
	publication?: Record< string, unknown >;
	publication_settings?: OperationsPublicationSettings;
	/** Detail may include Jobs linkage; list always uses `jobs: null`. */
	jobs?: OperationsJobsSubtree | null;
}

export interface OperationsAttentionCountsResponse {
	total: number;
	stale: number;
	review_pending: number;
	review_rejected: number;
	unpublished: number;
	translation_failed: number;
}

declare global {
	interface Window {
		aimlTranslatorWorkspace: TranslatorWorkspaceConfig;
	}
}
