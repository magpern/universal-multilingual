import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import type {
	SiteTranslateAdmissionResponse,
	SiteTranslateCreateJobsResponse,
	SiteTranslateObjectsResponse,
	SiteTranslateRoutesResponse,
	SiteTranslateRunBatchResponse,
} from '../types/site-translate';
import { WorkspaceRequestError } from './workspace-api';

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
	const candidate = error as {
		code?: string;
		message?: string;
		data?: { blocking_objects?: unknown[] };
	};

	if ( candidate?.code === 'aiml_site_translate_strategy_f_required' ) {
		return __(
			'Selected Gutenberg content requires Strategy F to be fully configured. Open Settings → Strategy F diagnostics.',
			'ai-multilingual'
		);
	}

	if ( candidate?.message ) {
		return candidate.message;
	}

	return __(
		'The Site Translate request could not be completed.',
		'ai-multilingual'
	);
}

export interface FetchSiteTranslateObjectsParams {
	languageId: number;
	page?: number;
	perPage?: number;
	search?: string;
	postType?: string;
}

export async function fetchSiteTranslateObjects(
	params: FetchSiteTranslateObjectsParams
): Promise< SiteTranslateObjectsResponse > {
	const query = new URLSearchParams();
	query.set( 'language_id', String( params.languageId ) );
	query.set( 'page', String( params.page ?? 1 ) );
	query.set( 'per_page', String( params.perPage ?? 20 ) );
	if ( params.search ) {
		query.set( 'search', params.search );
	}
	if ( params.postType ) {
		query.set( 'post_type', params.postType );
	}

	try {
		return await apiFetch< SiteTranslateObjectsResponse >( {
			path: path( `site-translate/objects?${ query.toString() }` ),
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function checkSiteTranslateAdmission(
	postIds: number[]
): Promise< SiteTranslateAdmissionResponse > {
	try {
		return await apiFetch< SiteTranslateAdmissionResponse >( {
			path: path( 'site-translate/admission' ),
			method: 'POST',
			data: { post_ids: postIds },
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function createSiteTranslateJobs( payload: {
	postIds: number[];
	languageId: number;
	clientToken?: string;
	batchId?: string;
	providerId?: string;
	jobType?: string;
	autostart?: boolean;
} ): Promise< SiteTranslateCreateJobsResponse > {
	try {
		return await apiFetch< SiteTranslateCreateJobsResponse >( {
			path: path( 'site-translate/jobs' ),
			method: 'POST',
			data: {
				post_ids: payload.postIds,
				language_id: payload.languageId,
				client_token: payload.clientToken,
				batch_id: payload.batchId,
				provider_id: payload.providerId,
				job_type: payload.jobType,
				autostart: payload.autostart,
			},
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function runSiteTranslateBatch(
	batchId: string
): Promise< SiteTranslateRunBatchResponse > {
	try {
		return await apiFetch< SiteTranslateRunBatchResponse >( {
			path: path( 'site-translate/jobs/run' ),
			method: 'POST',
			data: { batch_id: batchId },
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}

export async function publishSiteTranslateRoutes( payload: {
	postIds: number[];
	languageId: number;
} ): Promise< SiteTranslateRoutesResponse > {
	try {
		return await apiFetch< SiteTranslateRoutesResponse >( {
			path: path( 'site-translate/routes' ),
			method: 'POST',
			data: {
				post_ids: payload.postIds,
				language_id: payload.languageId,
			},
		} );
	} catch ( error ) {
		throw new WorkspaceRequestError( userMessageFromError( error ) );
	}
}
