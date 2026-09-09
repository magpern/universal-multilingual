import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	Notice,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { createJob, fetchJob } from '../api/jobs-api';
import {
	aiTranslateModeOptions,
	jobStatusBadgeVariant,
	jobStatusLabel,
} from '../utils/jobs';
import type { AiTranslateMode } from '../types/jobs';
import type { LanguageOption } from '../types/view-models';
import StatusBadge from './StatusBadge';

interface PageAiTranslateProps {
	postId: number | null;
	languageCode: string;
	languages: LanguageOption[];
	canManageJobs: boolean;
	/** Called when a translation job reaches a terminal state. */
	onComplete: () => void;
}

const ACTIVE = new Set( [ 'queued', 'running', 'retry_wait', 'paused' ] );

function newToken(): string {
	if ( typeof crypto !== 'undefined' && 'randomUUID' in crypto ) {
		return crypto.randomUUID();
	}
	return `ai-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;
}

export default function PageAiTranslate( {
	postId,
	languageCode,
	languages,
	canManageJobs,
	onComplete,
}: PageAiTranslateProps ) {
	const config = window.aimlTranslatorWorkspace;
	const aiConfigured = Boolean( config.aiConfigured );
	const aiSettingsUrl = config.aiSettingsUrl ?? '';

	const languageId =
		languages.find( ( entry ) => entry.code === languageCode )?.language_id ??
		0;

	const [ mode, setMode ] = useState< AiTranslateMode >( 'missing' );
	const [ busy, setBusy ] = useState( false );
	const [ jobStatus, setJobStatus ] = useState< string | null >( null );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ confirming, setConfirming ] = useState( false );

	// One opaque idempotency token per (post, language, mode) intent. Reset when
	// any of those change or when a job reaches a terminal state (ADR-0031 §5).
	const tokenRef = useRef< string >( newToken() );
	useEffect( () => {
		tokenRef.current = newToken();
		setJobStatus( null );
		setMessage( '' );
		setError( '' );
	}, [ postId, languageId, mode ] );

	const pollTimer = useRef< number | null >( null );
	useEffect(
		() => () => {
			if ( pollTimer.current ) {
				window.clearTimeout( pollTimer.current );
			}
		},
		[]
	);

	const finish = useCallback(
		( status: string, updated: number, skipped: number, failed: number ) => {
			setBusy( false );
			setJobStatus( status );
			tokenRef.current = newToken();
			if ( 'failed' === status ) {
				setError(
					__(
						'Automatic translation failed. Open the Jobs view for details, or retry.',
						'ai-multilingual'
					)
				);
			} else {
				setMessage(
					sprintf(
						/* translators: 1: translated count, 2: kept count, 3: failed count */
						__(
							'%1$d translated · %2$d kept · %3$d failed',
							'ai-multilingual'
						),
						updated,
						skipped,
						failed
					)
				);
			}
			onComplete();
		},
		[ onComplete ]
	);

	const poll = useCallback(
		( jobId: number ) => {
			fetchJob( jobId )
				.then( ( job ) => {
					setJobStatus( job.status );
					if ( ACTIVE.has( job.status ) ) {
						pollTimer.current = window.setTimeout(
							() => poll( jobId ),
							2500
						);
						return;
					}
					finish(
						job.status,
						Number( job.completed_items ?? 0 ),
						Number( job.skipped_items ?? 0 ),
						Number( job.failed_items ?? 0 )
					);
				} )
				.catch( ( err ) => {
					setBusy( false );
					setError(
						err instanceof Error
							? err.message
							: __(
									'Could not read translation progress.',
									'ai-multilingual'
							  )
					);
				} );
		},
		[ finish ]
	);

	const start = useCallback( async () => {
		if ( ! postId || ! languageId ) {
			return;
		}
		setBusy( true );
		setError( '' );
		setMessage( '' );
		setJobStatus( 'queued' );

		const jobType =
			aiTranslateModeOptions().find( ( option ) => option.value === mode )
				?.jobType ?? 'translate_missing';

		try {
			const job = await createJob( {
				source_type: 'post',
				source_id: postId,
				language_id: languageId,
				job_type: jobType,
				client_token: tokenRef.current,
				autostart: true,
			} );

			if ( ACTIVE.has( job.status ) ) {
				poll( job.job_id );
			} else {
				finish(
					job.status,
					Number( job.completed_items ?? 0 ),
					Number( job.skipped_items ?? 0 ),
					Number( job.failed_items ?? 0 )
				);
			}
		} catch ( err ) {
			setBusy( false );
			setJobStatus( null );
			const msg =
				err instanceof Error
					? err.message
					: __(
							'Automatic translation could not be started.',
							'ai-multilingual'
					  );
			// Empty workload is not an error the operator needs to worry about.
			if ( /empty_workload|No segments/i.test( msg ) ) {
				setMessage(
					__(
						'Nothing to translate for this page in that mode.',
						'ai-multilingual'
					)
				);
			} else {
				setError( msg );
			}
		}
	}, [ postId, languageId, mode, poll, finish ] );

	const onClick = useCallback( () => {
		if ( 'machine' === mode ) {
			setConfirming( true );
			return;
		}
		void start();
	}, [ mode, start ] );

	if ( ! postId || ! languageCode ) {
		return null;
	}

	return (
		<div className="aiml-ui-actionbar">
			<Button
				className="aiml-ui-actionbar__primary"
				variant="primary"
				onClick={ onClick }
				disabled={ busy || ! aiConfigured || ! canManageJobs }
				isBusy={ busy }
			>
				{ busy
					? __( 'Translating…', 'ai-multilingual' )
					: __( 'Translate with AI', 'ai-multilingual' ) }
			</Button>
			<div className="aiml-ui-actionbar__mode">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Mode', 'ai-multilingual' ) }
					hideLabelFromVision
					value={ mode }
					disabled={ busy }
					onChange={ ( value ) => setMode( value as AiTranslateMode ) }
					options={ aiTranslateModeOptions().map( ( option ) => ( {
						value: option.value,
						label: option.label,
					} ) ) }
				/>
			</div>
			{ jobStatus && (
				<span className="aiml-ui-actionbar__status">
					<StatusBadge
						variant={ jobStatusBadgeVariant( jobStatus ) }
						label={ jobStatusLabel( jobStatus ) }
						kind={ __( 'Job status', 'ai-multilingual' ) }
					/>
				</span>
			) }
			{ ! aiConfigured && (
				<p className="aiml-ui-actionbar__hint">
					{ aiSettingsUrl
						? __(
								'AI translation is not configured.',
								'ai-multilingual'
						  )
						: __(
								'AI translation must be configured by an administrator.',
								'ai-multilingual'
						  ) }
					{ aiSettingsUrl && (
						<>
							{ ' ' }
							<a href={ aiSettingsUrl }>
								{ __(
									'Configure AI settings',
									'ai-multilingual'
								) }
							</a>
						</>
					) }
				</p>
			) }
			{ message && (
				<p className="aiml-ui-actionbar__hint" role="status">
					{ message }
				</p>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<ConfirmDialog
				isOpen={ confirming }
				onCancel={ () => setConfirming( false ) }
				onConfirm={ () => {
					setConfirming( false );
					void start();
				} }
			>
				{ __(
					'Existing AI translations for this page will be replaced. Manually edited and reviewed translations are kept.',
					'ai-multilingual'
				) }
			</ConfirmDialog>
		</div>
	);
}
