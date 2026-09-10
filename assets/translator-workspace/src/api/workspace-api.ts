import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import type {
	BatchSaveResult,
	BatchTranslateResult,
	ReviewBatchResult,
} from '../types/segment-row';
import type {
	ApproveObjectResult,
	ObjectLanguagesResponse,
	ReviewErrorContext,
	ReviewQueueResponse,
	SegmentQA,
	WorkspacePostsResponse,
	WorkspaceSegment,
	WorkspaceSegmentsResponse,
} from '../types/view-models';

export class WorkspaceConflictError extends Error {
	public readonly segments: WorkspaceSegment[];
	public readonly kind: 'source' | 'translation';

	public constructor(
		message: string,
		segments: WorkspaceSegment[],
		kind: 'source' | 'translation' = 'source'
	) {
		super( message );
		this.name = 'WorkspaceConflictError';
		this.segments = segments;
		this.kind = kind;
	}
}

export class WorkspaceRequestError extends Error {
	public readonly userMessage: string;

	public constructor( userMessage: string ) {
		super( userMessage );
		this.name = 'WorkspaceRequestError';
		this.userMessage = userMessage;
	}
}

export class WorkspaceQABlockedError extends Error {
	public readonly qa: SegmentQA;

	public constructor( message: string, qa: SegmentQA ) {
		super( message );
		this.name = 'WorkspaceQABlockedError';
		this.qa = qa;
	}
}

/**
 * A review decision (approve/reject/submit) was rejected because the
 * segment's review state or submitted-translation hash drifted since it was
 * loaded (`aiml_review_conflict`, HTTP 409 — ADR-0015 §4.2/§22).
 */
export class WorkspaceReviewConflictError extends Error {
	public readonly code: string;
	public readonly context: ReviewErrorContext;

	public constructor(
		message: string,
		code: string,
		context: ReviewErrorContext
	) {
		super( message );
		this.name = 'WorkspaceReviewConflictError';
		this.code = code;
		this.context = context;
	}
}

/**
 * Any other stable Review Workflow domain error (illegal transition, missing
 * reason, permission denial, QA-unavailable, etc.) — see
 * REVIEW_WORKFLOW_IMPLEMENTATION_PLAN.md §4.2.
 */
export class WorkspaceReviewActionError extends Error {
	public readonly code: string;
	public readonly context: ReviewErrorContext;

	public constructor(
		message: string,
		code: string,
		context: ReviewErrorContext = {}
	) {
		super( message );
		this.name = 'WorkspaceReviewActionError';
		this.code = code;
		this.context = context;
	}
}

function namespace(): string {
	return window.aimlTranslatorWorkspace.restNamespace.replace(
		/^\/|\/$/g,
		''
	);
}

function path( route: string ): string {
	return `/${ namespace() }/${ route.replace( /^\//, '' ) }`;
}

function userMessageFromError( error: unknown ): string {
	if ( error instanceof WorkspaceConflictError ) {
		return error.message;
	}

	if ( error instanceof WorkspaceRequestError ) {
		return error.userMessage;
	}

	const candidate = error as {
		message?: string;
		code?: string;
	};

	if ( candidate?.code === 'rest_forbidden' ) {
		return 'You do not have permission to save this translation.';
	}

	if (
		candidate?.code === 'aiml_slug_route_collision' ||
		candidate?.code === 'aiml_slug_history_collision' ||
		candidate?.code === 'aiml_slug_collision_exhausted' ||
		( typeof candidate?.code === 'string' &&
			candidate.code.indexOf( 'collision' ) !== -1 )
	) {
		return __(
			'That localized path collides with another route or history entry. Edit the candidate, clear it, or choose a different slug, then publish again.',
			'ai-multilingual'
		);
	}

	if (
		typeof candidate?.code === 'string' &&
		candidate.code.indexOf( 'aiml_slug_' ) === 0 &&
		candidate?.message
	) {
		return candidate.message;
	}

	if ( candidate?.message ) {
		return 'The translation could not be saved. Please try again.';
	}

	return 'The translation could not be saved. Please try again.';
}

export interface SlugRouteView {
	slug_candidate: string;
	slug_origin: string;
	slug_candidate_publish_status: string;
	active_route_slug: string;
	active_route_status: string;
	localized_path?: string;
	route_prepared: boolean;
	route_sync_state: string;
	collision_adjusted: boolean;
	can_generate: boolean;
	can_edit_slug: boolean;
	can_publish_route: boolean;
	route_publication_blocked_reason: string;
	// Presentation facts added by WorkspaceService::enrich_slug_view().
	language_code?: string;
	language_name?: string;
	language_published?: boolean;
	translated_title?: string;
	title_slug_stale?: boolean;
	localized_url?: string;
	localized_url_absolute?: boolean;
	state?: string;
	status?: string;
	idempotent?: boolean;
}

function slugPath( postId: number, suffix = '' ): string {
	return path( `workspace/${ postId }/slug${ suffix }` );
}

export async function fetchSlugRouteView(
	postId: number,
	language: string
): Promise< SlugRouteView > {
	try {
		return await apiFetch< SlugRouteView >( {
			path:
				slugPath( postId ) +
				`?language=${ encodeURIComponent( language ) }`,
			method: 'GET',
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function generateSlugCandidate(
	postId: number,
	language: string
): Promise< SlugRouteView > {
	try {
		return await apiFetch< SlugRouteView >( {
			path:
				slugPath( postId, '/generate' ) +
				`?language=${ encodeURIComponent( language ) }`,
			method: 'POST',
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function ensureSlugCandidate(
	postId: number,
	language: string
): Promise< SlugRouteView > {
	try {
		return await apiFetch< SlugRouteView >( {
			path:
				slugPath( postId, '/ensure' ) +
				`?language=${ encodeURIComponent( language ) }`,
			method: 'POST',
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function saveSlugCandidate(
	postId: number,
	language: string,
	slugCandidate: string
): Promise< SlugRouteView > {
	try {
		return await apiFetch< SlugRouteView >( {
			path:
				slugPath( postId ) +
				`?language=${ encodeURIComponent( language ) }`,
			method: 'POST',
			data: { slug_candidate: slugCandidate },
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function clearSlugCandidate(
	postId: number,
	language: string
): Promise< SlugRouteView > {
	try {
		return await apiFetch< SlugRouteView >( {
			path:
				slugPath( postId ) +
				`?language=${ encodeURIComponent( language ) }`,
			method: 'DELETE',
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function publishSlugRoute(
	postId: number,
	language: string
): Promise< SlugRouteView > {
	try {
		return await apiFetch< SlugRouteView >( {
			path:
				slugPath( postId, '/publish-route' ) +
				`?language=${ encodeURIComponent( language ) }`,
			method: 'POST',
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

function parseConflict( error: unknown ): WorkspaceConflictError | null {
	const candidate = error as {
		code?: string;
		message?: string;
		data?: {
			status?: number;
			segments?: WorkspaceSegment[];
		};
		segments?: WorkspaceSegment[];
	};

	const segments = candidate?.data?.segments ?? candidate?.segments ?? [];

	if ( candidate?.code === 'aiml_source_hash_mismatch' ) {
		return new WorkspaceConflictError(
			candidate.message ||
				'The source text changed since this segment was loaded.',
			segments,
			'source'
		);
	}

	if ( candidate?.code === 'aiml_translation_hash_mismatch' ) {
		return new WorkspaceConflictError(
			candidate.message ||
				'The saved translation changed since this segment was loaded.',
			segments,
			'translation'
		);
	}

	return null;
}

export function configureWorkspaceApi(): void {
	apiFetch.use(
		apiFetch.createNonceMiddleware( window.aimlTranslatorWorkspace.nonce )
	);
}

export async function fetchPosts(
	languageCode: string,
	search = '',
	page = 1
): Promise< WorkspacePostsResponse > {
	return apiFetch< WorkspacePostsResponse >( {
		path: path(
			`workspace/posts?language=${ encodeURIComponent(
				languageCode
			) }&search=${ encodeURIComponent(
				search
			) }&page=${ page }&per_page=20`
		),
	} );
}

export async function fetchSegments(
	postId: number,
	languageCode: string
): Promise< WorkspaceSegmentsResponse > {
	return apiFetch< WorkspaceSegmentsResponse >( {
		path: path(
			`workspace/${ postId }/segments?language=${ encodeURIComponent(
				languageCode
			) }`
		),
	} );
}

/**
 * MLW1a — object × language completeness for one object across every eligible
 * target language (ADR-0034). Every `state` is server-computed.
 *
 * @param postId Canonical post id.
 */
export async function fetchObjectLanguages(
	postId: number
): Promise< ObjectLanguagesResponse > {
	return apiFetch< ObjectLanguagesResponse >( {
		path: path( `workspace/${ postId }/languages` ),
	} );
}

export async function deleteTranslation(
	postId: number,
	languageCode: string
): Promise< WorkspaceSegmentsResponse > {
	return apiFetch< WorkspaceSegmentsResponse >( {
		path: path(
			`workspace/${ postId }/translation?language=${ encodeURIComponent(
				languageCode
			) }`
		),
		method: 'DELETE',
	} );
}

export async function fetchPreviewUrl(
	postId: number,
	languageCode: string
): Promise< string > {
	const response = await apiFetch< { url: string } >( {
		path: path(
			`workspace/${ postId }/preview-url?language=${ encodeURIComponent(
				languageCode
			) }`
		),
	} );
	return response.url;
}

export async function saveSegment(
	postId: number,
	languageCode: string,
	segmentKey: string,
	translatedText: string,
	sourceHash: string,
	expectedTranslationHash: string
): Promise< WorkspaceSegment > {
	try {
		return await apiFetch< WorkspaceSegment >( {
			path: path(
				`workspace/${ postId }/segments/${ segmentKey }?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data: {
				translated_text: translatedText,
				source_hash: sourceHash,
				expected_translation_hash: expectedTranslationHash,
				status: 'manually_edited',
			},
		} );
	} catch ( error ) {
		const conflict = parseConflict( error );
		if ( conflict ) {
			throw conflict;
		}

		const qaBlocked = parseQaBlocked( error );
		if ( qaBlocked ) {
			throw qaBlocked;
		}

		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function suggestSegment(
	postId: number,
	languageCode: string,
	segmentKey: string,
	profile: string
): Promise< WorkspaceSegment > {
	try {
		return await apiFetch< WorkspaceSegment >( {
			path: path(
				`workspace/${ postId }/segments/${ encodeURIComponent(
					segmentKey
				) }/suggest?language=${ encodeURIComponent( languageCode ) }`
			),
			method: 'POST',
			data: {
				profile,
			},
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

function parseQaBlocked( error: unknown ): WorkspaceQABlockedError | null {
	const candidate = error as {
		code?: string;
		message?: string;
		qa?: SegmentQA;
		data?: {
			status?: number;
			qa?: SegmentQA;
		};
	};

	if ( candidate?.code === 'aiml_qa_blocked' ) {
		const qa = candidate.qa ?? candidate.data?.qa;
		if ( qa ) {
			return new WorkspaceQABlockedError(
				candidate.message || 'Translation failed quality checks.',
				{
					issues: Array.isArray( qa.issues ) ? qa.issues : [],
					summary: {
						errors: Number( qa.summary?.errors ?? 0 ),
						warnings: Number( qa.summary?.warnings ?? 0 ),
						info: Number( qa.summary?.info ?? 0 ),
					},
				}
			);
		}
	}

	return null;
}

/**
 * Parses the `{ code, message, context }` shape used by every stable Review
 * Workflow REST error (submit/approve/reject/batch-review), plus the shared
 * `aiml_qa_blocked` (approve QA gate) and `aiml_forbidden` transports.
 *
 * @param error Raw apiFetch rejection.
 */
function parseReviewError( error: unknown ): Error {
	const candidate = error as {
		code?: string;
		message?: string;
		context?: ReviewErrorContext;
		qa?: SegmentQA;
		data?: { status?: number; qa?: SegmentQA };
	};

	const code = candidate?.code ?? '';

	if ( 'aiml_qa_blocked' === code ) {
		const qa = candidate.qa ?? candidate.data?.qa;
		if ( qa ) {
			return new WorkspaceQABlockedError(
				candidate.message ||
					__(
						'Translation failed quality checks.',
						'ai-multilingual'
					),
				{
					issues: Array.isArray( qa.issues ) ? qa.issues : [],
					summary: {
						errors: Number( qa.summary?.errors ?? 0 ),
						warnings: Number( qa.summary?.warnings ?? 0 ),
						info: Number( qa.summary?.info ?? 0 ),
					},
				}
			);
		}
	}

	if ( 'aiml_review_conflict' === code ) {
		return new WorkspaceReviewConflictError(
			candidate.message ||
				__(
					'This review decision is out of date. Reload and try again.',
					'ai-multilingual'
				),
			code,
			candidate.context ?? {}
		);
	}

	if ( code.indexOf( 'aiml_review_' ) === 0 || 'aiml_forbidden' === code ) {
		return new WorkspaceReviewActionError(
			candidate.message ||
				__(
					'The review action could not be completed.',
					'ai-multilingual'
				),
			code,
			candidate.context ?? {}
		);
	}

	return new WorkspaceRequestError( userMessageFromError( error ) );
}

export async function saveBatch(
	postId: number,
	languageCode: string,
	items: Array< {
		segment_key: string;
		translated_text: string;
		source_hash: string;
		expected_translation_hash: string;
		status: string;
	} >
): Promise< BatchSaveResult > {
	try {
		const response = await apiFetch<
			BatchSaveResult & {
				segments: WorkspaceSegment[];
			}
		>( {
			path: path(
				`workspace/${ postId }/segments/batch?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data: {
				segments: items,
			},
		} );

		return {
			status: response.status,
			updated: response.segments,
			errors: response.errors ?? [],
		};
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function translateBatch(
	postId: number,
	languageCode: string,
	segmentKeys: string[],
	expectedTranslationHashes?: Record< string, string >
): Promise< BatchTranslateResult > {
	try {
		const data: {
			segment_keys: string[];
			mode: string;
			expected_translation_hashes?: Record< string, string >;
		} = {
			segment_keys: segmentKeys,
			mode: 'sync',
		};

		if (
			expectedTranslationHashes &&
			Object.keys( expectedTranslationHashes ).length > 0
		) {
			data.expected_translation_hashes = expectedTranslationHashes;
		}

		const response = await apiFetch<
			BatchTranslateResult & {
				segments: WorkspaceSegment[];
			}
		>( {
			path: path(
				`workspace/${ postId }/translate?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data,
		} );

		return {
			status: response.status,
			updated: response.segments ?? [],
			errors: response.errors ?? [],
			job_id: response.job_id ?? null,
		};
	} catch ( error ) {
		const conflict = parseConflict( error );
		if ( conflict ) {
			throw conflict;
		}

		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

/**
 * Publishes one post-backed segment (TI.7 manual path).
 *
 * @param postId                Post id.
 * @param languageCode          Target language code.
 * @param segmentKey            Segment key.
 * @param expectedPublishStatus Optimistic publish_status guard.
 */
export async function publishSegment(
	postId: number,
	languageCode: string,
	segmentKey: string,
	expectedPublishStatus: string
): Promise<
	WorkspaceSegment & { publication_result?: Record< string, unknown > }
> {
	try {
		return await apiFetch( {
			path: path(
				`workspace/${ postId }/segments/${ encodeURIComponent(
					segmentKey
				) }/publish?language=${ encodeURIComponent( languageCode ) }`
			),
			method: 'POST',
			data: {
				expected_publish_status: expectedPublishStatus,
			},
		} );
	} catch ( error ) {
		const conflict = parseConflict( error );
		if ( conflict ) {
			throw conflict;
		}

		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

/**
 * Unpublishes one post-backed segment (TI.7 manual path).
 *
 * @param postId       Post id.
 * @param languageCode Target language code.
 * @param segmentKey   Segment key.
 */
export async function unpublishSegment(
	postId: number,
	languageCode: string,
	segmentKey: string
): Promise<
	WorkspaceSegment & { publication_result?: Record< string, unknown > }
> {
	try {
		return await apiFetch( {
			path: path(
				`workspace/${ postId }/segments/${ encodeURIComponent(
					segmentKey
				) }/unpublish?language=${ encodeURIComponent( languageCode ) }`
			),
			method: 'POST',
			data: {},
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function acceptTmSuggestions(
	postId: number,
	languageCode: string,
	segmentKeys: string[]
): Promise< BatchSaveResult > {
	try {
		const response = await apiFetch<
			BatchSaveResult & {
				segments: WorkspaceSegment[];
			}
		>( {
			path: path(
				`workspace/${ postId }/suggestions/accept?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data: {
				segment_keys: segmentKeys,
			},
		} );

		return {
			status: response.status,
			updated: response.segments ?? [],
			errors: response.errors ?? [],
		};
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function runQaBatch(
	postId: number,
	languageCode: string,
	segmentKeys: string[]
): Promise< {
	segments: WorkspaceSegment[];
	summary: { errors: number; warnings: number; info: number };
} > {
	try {
		return await apiFetch( {
			path: path(
				`workspace/${ postId }/qa?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data: {
				segment_keys: segmentKeys,
			},
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

/**
 * Submits (or resubmits, from `rejected`) one segment for review.
 *
 * @param postId               Post id.
 * @param languageCode         Target language code.
 * @param segmentKey           Segment key.
 * @param expectedReviewStatus Optional optimistic `review_status` guard.
 */
export async function submitReview(
	postId: number,
	languageCode: string,
	segmentKey: string,
	expectedReviewStatus?: string | null
): Promise< WorkspaceSegment > {
	try {
		return await apiFetch< WorkspaceSegment >( {
			path: path(
				`workspace/${ postId }/segments/${ encodeURIComponent(
					segmentKey
				) }/submit-review?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data: expectedReviewStatus
				? { expected_review_status: expectedReviewStatus }
				: {},
		} );
	} catch ( error ) {
		throw parseReviewError( error );
	}
}

/**
 * Approves a pending review (QA freshness re-check happens server-side).
 *
 * @param postId                   Post id.
 * @param languageCode             Target language code.
 * @param segmentKey               Segment key.
 * @param expectedReviewStatus     Optional optimistic `review_status` guard.
 * @param submittedTranslationHash Optional client submitted-hash guard.
 */
export async function approveReview(
	postId: number,
	languageCode: string,
	segmentKey: string,
	expectedReviewStatus?: string | null,
	submittedTranslationHash?: string | null
): Promise< WorkspaceSegment > {
	try {
		return await apiFetch< WorkspaceSegment >( {
			path: path(
				`workspace/${ postId }/segments/${ encodeURIComponent(
					segmentKey
				) }/approve?language=${ encodeURIComponent( languageCode ) }`
			),
			method: 'POST',
			data: {
				...( expectedReviewStatus
					? { expected_review_status: expectedReviewStatus }
					: {} ),
				...( submittedTranslationHash
					? { submitted_translation_hash: submittedTranslationHash }
					: {} ),
			},
		} );
	} catch ( error ) {
		throw parseReviewError( error );
	}
}

/**
 * Rejects a pending review with a required reason (1–512 chars, trimmed).
 *
 * @param postId                   Post id.
 * @param languageCode             Target language code.
 * @param segmentKey               Segment key.
 * @param reason                   Rejection reason.
 * @param expectedReviewStatus     Optional optimistic `review_status` guard.
 * @param submittedTranslationHash Optional client submitted-hash guard.
 */
export async function rejectReview(
	postId: number,
	languageCode: string,
	segmentKey: string,
	reason: string,
	expectedReviewStatus?: string | null,
	submittedTranslationHash?: string | null
): Promise< WorkspaceSegment > {
	try {
		return await apiFetch< WorkspaceSegment >( {
			path: path(
				`workspace/${ postId }/segments/${ encodeURIComponent(
					segmentKey
				) }/reject?language=${ encodeURIComponent( languageCode ) }`
			),
			method: 'POST',
			data: {
				reason,
				...( expectedReviewStatus
					? { expected_review_status: expectedReviewStatus }
					: {} ),
				...( submittedTranslationHash
					? { submitted_translation_hash: submittedTranslationHash }
					: {} ),
			},
		} );
	} catch ( error ) {
		throw parseReviewError( error );
	}
}

export interface ReviewBatchItem {
	segment_key: string;
	expected_review_status?: string;
	submitted_translation_hash?: string;
	reason?: string;
}

/**
 * Applies one review action (submit/approve/reject) to multiple segments
 * within a single post/language, bounded and partial-success (ADR-0015 §11.1).
 *
 * @param postId       Post id.
 * @param languageCode Target language code.
 * @param action       One of 'submit' | 'approve' | 'reject'.
 * @param items        Per-segment payloads.
 * @param reason       Shared rejection reason applied when an item omits one.
 */
export async function batchReview(
	postId: number,
	languageCode: string,
	action: 'submit' | 'approve' | 'reject',
	items: ReviewBatchItem[],
	reason = ''
): Promise< ReviewBatchResult > {
	try {
		const response = await apiFetch<
			ReviewBatchResult & {
				segments: WorkspaceSegment[];
			}
		>( {
			path: path(
				`workspace/${ postId }/segments/batch-review?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
			data: {
				action,
				segments: items,
				reason,
			},
		} );

		return {
			status: response.status,
			updated: response.segments,
			errors: response.errors ?? [],
		};
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export interface ReviewQueueParams {
	postId?: number;
	languageCode?: string;
	reviewStatus?: string;
	page?: number;
	perPage?: number;
}

/**
 * Fetches a filtered, paginated review-queue page (Store view; never a
 * persisted queue — ADR-0015 §5, §11).
 *
 * @param params Optional post/language/status filters and pagination.
 */
export async function fetchReviewQueue(
	params: ReviewQueueParams = {}
): Promise< ReviewQueueResponse > {
	const query = new URLSearchParams();
	if ( params.postId ) {
		query.set( 'post_id', String( params.postId ) );
	}
	if ( params.languageCode ) {
		query.set( 'language', params.languageCode );
	}
	if ( params.reviewStatus ) {
		query.set( 'review_status', params.reviewStatus );
	}
	query.set( 'page', String( params.page ?? 1 ) );
	query.set( 'per_page', String( params.perPage ?? 20 ) );

	try {
		return await apiFetch< ReviewQueueResponse >( {
			path: path( `workspace/review-queue?${ query.toString() }` ),
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

/**
 * Approves every currently pending segment for one object + language (RVQ1).
 *
 * Safe-subset: QA-blocked / conflicted segments come back in `skipped`, and
 * `summary` reports true completeness so a page is never reported done when
 * fields were missed.
 *
 * @param postId       Object id.
 * @param languageCode Target language code.
 * @return Approved segments, skipped segments and the completeness summary.
 */
export async function approveObject(
	postId: number,
	languageCode: string
): Promise< ApproveObjectResult > {
	try {
		return await apiFetch< ApproveObjectResult >( {
			path: path(
				`workspace/${ postId }/review/approve-object?language=${ encodeURIComponent(
					languageCode
				) }`
			),
			method: 'POST',
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export interface OperationsListParams {
	languageCode: string;
	attention?: string;
	status?: string;
	reviewStatus?: string;
	publishStatus?: string;
	isStale?: string;
	sourceType?: string;
	sourceId?: number;
	page?: number;
	perPage?: number;
}

export async function fetchOperationsList(
	params: OperationsListParams
): Promise< import('../types/view-models').OperationsListResponse > {
	const query = new URLSearchParams();
	query.set( 'language', params.languageCode );
	if ( params.attention && 'all' !== params.attention ) {
		query.set( 'attention', params.attention );
	}
	if ( params.status ) {
		query.set( 'status', params.status );
	}
	if ( params.reviewStatus ) {
		query.set( 'review_status', params.reviewStatus );
	}
	if ( params.publishStatus ) {
		query.set( 'publish_status', params.publishStatus );
	}
	if ( params.isStale ) {
		query.set( 'is_stale', params.isStale );
	}
	if ( params.sourceType ) {
		query.set( 'source_type', params.sourceType );
	}
	if ( params.sourceId && params.sourceId > 0 ) {
		query.set( 'source_id', String( params.sourceId ) );
	}
	query.set( 'page', String( params.page ?? 1 ) );
	query.set( 'per_page', String( params.perPage ?? 20 ) );

	try {
		return await apiFetch( {
			path: path( `workspace/operations?${ query.toString() }` ),
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function fetchOperationsAttentionCounts(
	languageCode: string
): Promise< import('../types/view-models').OperationsAttentionCountsResponse > {
	const query = new URLSearchParams();
	query.set( 'language', languageCode );
	try {
		return await apiFetch( {
			path: path(
				`workspace/operations/attention-counts?${ query.toString() }`
			),
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function fetchOperationDetail(
	translationId: number
): Promise< import('../types/view-models').OperationsDetailResponse > {
	try {
		return await apiFetch( {
			path: path( `workspace/operations/${ translationId }` ),
		} );
	} catch ( error: unknown ) {
		const err = error as { code?: string; data?: { status?: number } };
		if (
			'aiml_forbidden' === err?.code ||
			403 === err?.data?.status ||
			'rest_forbidden' === err?.code
		) {
			throw new WorkspaceRequestError(
				__(
					'You do not have permission to inspect this translation.',
					'ai-multilingual'
				)
			);
		}
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export type OperationsBulkAction =
	| 'publish'
	| 'unpublish'
	| 'enqueue_retranslate';

export interface OperationsBulkItemRequest {
	translation_id: number;
	expected_publish_status?: string | null;
	source_hash?: string | null;
	translation_hash?: string | null;
}

export interface OperationsBulkItemResult {
	translation_id: number;
	outcome: string;
	code?: string | null;
	message?: string | null;
	job_id?: number;
	reason_codes?: string[];
}

export interface OperationsBulkOperationResult {
	operation_key: string;
	action: string;
	job_id?: number | null;
	outcome: string;
	code?: string | null;
	affected_items?: number[];
	message?: string | null;
}

export interface OperationsBulkResponse {
	status: 'completed' | 'partial' | 'failed' | string;
	items: OperationsBulkItemResult[];
	operations?: OperationsBulkOperationResult[];
	summary?: {
		total: number;
		ok: number;
		failed: number;
	};
}

/**
 * OTL.5 bounded Operations bulk (publish / unpublish / enqueue_retranslate).
 * @param action
 * @param items
 */
export async function operationsBulk(
	action: OperationsBulkAction,
	items: OperationsBulkItemRequest[]
): Promise< OperationsBulkResponse > {
	try {
		return await apiFetch< OperationsBulkResponse >( {
			path: path( 'workspace/operations/bulk' ),
			method: 'POST',
			data: {
				action,
				items,
			},
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export { userMessageFromError };
