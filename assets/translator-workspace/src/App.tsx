import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Notice, Panel, PanelBody } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	WorkspaceConflictError,
	WorkspaceQABlockedError,
	acceptTmSuggestions,
	approveReview,
	batchReview,
	configureWorkspaceApi,
	fetchPreviewUrl,
	fetchSegments,
	rejectReview,
	runQaBatch,
	saveBatch,
	saveSegment,
	submitReview,
	suggestSegment,
	translateBatch,
} from './api/workspace-api';
import BulkToolbar from './components/BulkToolbar';
import LanguageSelect from './components/LanguageSelect';
import PostSelect from './components/PostSelect';
import PageAiTranslate from './components/PageAiTranslate';
import PublishContext from './components/PublishContext';
import LocalizedSlugPanel from './components/LocalizedSlugPanel';
import ReviewDecisionDialog from './components/ReviewDecisionDialog';
import JobsPanel from './components/JobsPanel';
import OperationsPanel from './components/OperationsPanel';
import SiteTranslatePanel from './components/SiteTranslatePanel';
import ReviewQueuePanel from './components/ReviewQueuePanel';
import SegmentFilterBar from './components/SegmentFilterBar';
import SegmentTable from './components/SegmentTable';
import StatusFooter from './components/StatusFooter';
import type { SegmentRow } from './types/segment-row';
import type {
	LanguageOption,
	WorkspacePageSummary,
	WorkspaceTranslationStatus,
} from './types/view-models';
import {
	detailConflictMessage,
	detailConflictStatusMessage,
} from './utils/detail-conflict';
import { useConfirmDialog } from './hooks/useConfirmDialog';
import { dirtyLeaveConfirmMessage } from './utils/detail-dirty';
import {
	clearOperationsSession,
	stashOperationsSession,
	type OperationsNavSnapshot,
} from './utils/operations-session';
import {
	readOperationsUrlState,
	clearOperationsViewFromUrl,
	writeOperationsUrlState,
} from './utils/operations-url';
import {
	clearJobsViewFromUrl,
	readJobsUrlState,
	writeJobsUrlState,
} from './utils/jobs-url';
import {
	applyBatchSaveResults,
	applyConflict,
	applyReloadFromServer,
	applyReviewBatchResults,
	applySaveSuccess,
	countDirtyRows,
	createRowsFromSegments,
	dirtyRowsInOrder,
	mergeSegmentsIntoRows,
	updateDraftText,
} from './utils/segment-rows';
import {
	matchesSegmentFilter,
	type SegmentFilter,
} from './utils/segment-status';
import { aggregateQaSummary } from './utils/meta';
import {
	allVisibleSelected,
	clearSelection,
	deselectAllVisible,
	selectAllVisible,
	selectedDirtyRows,
	selectedEditableKeys,
	selectedPendingReviewRows,
	selectableRows,
	toggleSelection,
} from './utils/row-selection';

type WorkspaceViewMode = 'editor' | 'queue' | 'jobs' | 'operations' | 'site-translate';

interface ReviewDialogState {
	action: 'approve' | 'reject';
	targets: string[];
	reason: string;
	busy: boolean;
	error: string;
}

configureWorkspaceApi();

function readInitialLanguage( languages: LanguageOption[] ): string {
	const configured = window.aimlTranslatorWorkspace.initialLanguageCode;
	if (
		configured &&
		languages.some( ( language ) => language.code === configured )
	) {
		return configured;
	}

	return languages[ 0 ]?.code ?? '';
}

function readInitialPostId(): number | null {
	const configured = window.aimlTranslatorWorkspace.initialPostId ?? 0;
	return configured > 0 ? configured : null;
}

export default function App() {
	const languages: LanguageOption[] =
		window.aimlTranslatorWorkspace.languages ?? [];
	const canTranslate = Boolean( window.aimlTranslatorWorkspace.canTranslate );
	const canReview = Boolean( window.aimlTranslatorWorkspace.canReview );
	const canAccessOperations = Boolean(
		window.aimlTranslatorWorkspace.canAccessOperations ??
			( canTranslate || canReview )
	);
	const canViewJobs = Boolean( window.aimlTranslatorWorkspace.canViewJobs );
	const canManageJobs = Boolean(
		window.aimlTranslatorWorkspace.canManageJobs
	);
	const canRunJobs = Boolean( window.aimlTranslatorWorkspace.canRunJobs );
	const canCancelJobs = Boolean(
		window.aimlTranslatorWorkspace.canCancelJobs
	);

	const initialJobsUrl = readJobsUrlState();
	const [ viewMode, setViewMode ] = useState< WorkspaceViewMode >( () => {
		const ops =
			window.aimlTranslatorWorkspace.canAccessOperations ??
			( window.aimlTranslatorWorkspace.canTranslate ||
				window.aimlTranslatorWorkspace.canReview );
		let urlView = '';
		try {
			urlView =
				new URLSearchParams( window.location.search ).get( 'view' ) ??
				'';
		} catch ( e ) {
			urlView = '';
		}
		if ( 'site-translate' === urlView && canManageJobs ) {
			return 'site-translate';
		}
		if ( 'operations' === readOperationsUrlState().view && ops ) {
			return 'operations';
		}
		if (
			( 'jobs' === initialJobsUrl.view || null !== initialJobsUrl.jobId ) &&
			canViewJobs
		) {
			return 'jobs';
		}
		if ( canTranslate ) {
			return 'editor';
		}
		if ( canReview ) {
			return 'queue';
		}
		if ( canViewJobs ) {
			return 'jobs';
		}
		return 'editor';
	} );
	const [ focusedJobId, setFocusedJobId ] = useState< number | null >(
		() => initialJobsUrl.jobId
	);
	const [ focusedJobItemId, setFocusedJobItemId ] = useState< number | null >(
		() => initialJobsUrl.itemId
	);
	const [ focusedBatchId, setFocusedBatchId ] = useState< string | null >(
		() => initialJobsUrl.batchId
	);
	const [ reviewJump, setReviewJump ] = useState( {
		language: '',
		postId: '',
	} );
	const [ reviewDialog, setReviewDialog ] =
		useState< ReviewDialogState | null >( null );

	const [ languageCode, setLanguageCode ] = useState( () =>
		readInitialLanguage( languages )
	);
	const [ postId, setPostId ] = useState< number | null >( readInitialPostId );
	const [ postSummary, setPostSummary ] =
		useState< WorkspacePageSummary | null >( null );
	const [ rows, setRows ] = useState< SegmentRow[] >( [] );
	const [ status, setStatus ] =
		useState< WorkspaceTranslationStatus | null >( null );
	const [ loading, setLoading ] = useState( false );
	const [ batchSaving, setBatchSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ previewError, setPreviewError ] = useState( '' );
	const [ batchMessage, setBatchMessage ] = useState( '' );
	const [ segmentFilter, setSegmentFilter ] = useState< SegmentFilter >( 'all' );
	const [ selectedKeys, setSelectedKeys ] = useState< Set< string > >(
		() => new Set()
	);
	const [ suggestingKey, setSuggestingKey ] = useState< string | null >( null );

	const dirtyCount = useMemo( () => countDirtyRows( rows ), [ rows ] );
	const filteredRows = useMemo(
		() =>
			rows.filter( ( row ) =>
				matchesSegmentFilter( row.server, segmentFilter )
			),
		[ rows, segmentFilter ]
	);
	const dirtySelectedCount = useMemo(
		() => selectedDirtyRows( rows, selectedKeys ).length,
		[ rows, selectedKeys ]
	);
	const visibleAllSelected = useMemo(
		() => allVisibleSelected( filteredRows, selectedKeys ),
		[ filteredRows, selectedKeys ]
	);
	const hasSelectableVisible = useMemo(
		() => selectableRows( filteredRows ).length > 0,
		[ filteredRows ]
	);

	const runBatchSave = async (
		dirtyRows: SegmentRow[],
		inFlightMessage: string,
		successMessage: string,
		partialMessage: string
	) => {
		if ( ! postId || ! languageCode || dirtyRows.length === 0 ) {
			return;
		}

		setBatchSaving( true );
		setBatchMessage( inFlightMessage );

		try {
			const result = await saveBatch(
				postId,
				languageCode,
				dirtyRows.map( ( row ) => ( {
					segment_key: row.segmentKey,
					translated_text: row.draftText,
					source_hash: row.server.source_hash,
					expected_translation_hash: row.server.translation_hash ?? '',
					status: 'manually_edited',
				} ) )
			);

			setRows( ( current ) =>
				applyBatchSaveResults( current, result, dirtyRows )
			);

			if ( result.status === 'partial' ) {
				setBatchMessage(
					sprintf( partialMessage, result.errors.length )
				);
			} else {
				setBatchMessage( successMessage );
			}
		} catch ( unknownError ) {
			setBatchMessage(
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Batch save failed. Please try again.',
							'ai-multilingual'
					  )
			);
		} finally {
			setBatchSaving( false );
		}
	};

	const loadSegments = useCallback( async () => {
		if ( ! postId || ! languageCode ) {
			setRows( [] );
			setStatus( null );
			return;
		}

		setLoading( true );
		setError( '' );
		setBatchMessage( '' );

		try {
			const response = await fetchSegments( postId, languageCode );
			setRows( createRowsFromSegments( response.segments ) );
			setStatus( response.status );
			setSegmentFilter( 'all' );
			setSelectedKeys( clearSelection() );
		} catch {
			setError(
				__(
					'Could not load workspace segments for this post.',
					'ai-multilingual'
				)
			);
			setRows( [] );
			setStatus( null );
		} finally {
			setLoading( false );
		}
	}, [ languageCode, postId ] );

	useEffect( () => {
		loadSegments();
	}, [ loadSegments ] );

	const dirtyGuardRef = useRef< ( () => boolean ) | null >( null );
	const navSnapshotRef = useRef< ( () => OperationsNavSnapshot ) | null >(
		null
	);
	const { requestConfirm, confirmDialog } = useConfirmDialog();

	const requestLeaveOperations = useCallback( async (): Promise< boolean > => {
		if ( 'operations' !== viewMode ) {
			return true;
		}
		const isDirty = dirtyGuardRef.current?.() ?? false;
		if ( isDirty ) {
			const confirmed = await requestConfirm( {
				title: __( 'Unsaved changes', 'ai-multilingual' ),
				message: dirtyLeaveConfirmMessage(),
				confirmLabel: __( 'Discard and continue', 'ai-multilingual' ),
				isDestructive: true,
			} );
			if ( ! confirmed ) {
				return false;
			}
		}
		const snapshot = navSnapshotRef.current?.() ?? null;
		if ( snapshot ) {
			stashOperationsSession( snapshot );
		}
		clearOperationsViewFromUrl();
		return true;
	}, [ requestConfirm, viewMode ] );

	const requestViewChange = useCallback(
		async ( next: WorkspaceViewMode ) => {
			if ( next === viewMode ) {
				return;
			}
			if ( 'operations' === viewMode && 'operations' !== next ) {
				if ( ! ( await requestLeaveOperations() ) ) {
					return;
				}
			}
			setViewMode( next );
		},
		[ requestLeaveOperations, viewMode ]
	);

	useEffect( () => {
		if ( 'operations' !== viewMode ) {
			clearOperationsViewFromUrl();
		}
		if ( 'jobs' !== viewMode ) {
			clearJobsViewFromUrl();
		} else {
			writeJobsUrlState( {
				jobId: focusedJobId,
				itemId: focusedJobItemId,
				batchId: focusedBatchId,
			} );
		}
	}, [ viewMode, focusedJobId, focusedJobItemId, focusedBatchId ] );

	const handleDraftChange = ( segmentKey: string, value: string ) => {
		setRows( ( current ) => updateDraftText( current, segmentKey, value ) );
		setBatchMessage( '' );
	};

	const handleSaveRow = async ( segmentKey: string ) => {
		if ( ! postId || ! languageCode ) {
			return;
		}

		const row = rows.find( ( candidate ) => candidate.segmentKey === segmentKey );
		if ( ! row || ! row.server.can_edit ) {
			return;
		}

		setRows( ( current ) =>
			current.map( ( candidate ) =>
				candidate.segmentKey === segmentKey
					? { ...candidate, rowState: 'saving', errorMessage: undefined }
					: candidate
			)
		);

		try {
			const saved = await saveSegment(
				postId,
				languageCode,
				segmentKey,
				row.draftText,
				row.server.source_hash,
				row.server.translation_hash ?? ''
			);
			setRows( ( current ) => applySaveSuccess( current, segmentKey, saved ) );
			setBatchMessage( '' );
		} catch ( unknownError ) {
			if ( unknownError instanceof WorkspaceConflictError ) {
				const refreshed = unknownError.segments.find(
					( segment ) => segment.segment_key === segmentKey
				);
				setRows( ( current ) =>
					applyConflict(
						current,
						segmentKey,
						refreshed,
						row.draftText,
						detailConflictMessage( unknownError.kind )
					)
				);
				setBatchMessage( detailConflictStatusMessage( unknownError.kind ) );
				return;
			}

			if ( unknownError instanceof WorkspaceQABlockedError ) {
				const message = sprintf(
					/* translators: %d: error count */
					__(
						'Save blocked by %d quality error(s). Fix QA issues and try again.',
						'ai-multilingual'
					),
					unknownError.qa.summary.errors
				);
				setRows( ( current ) =>
					current.map( ( candidate ) =>
						candidate.segmentKey === segmentKey
							? {
									...candidate,
									rowState: 'error',
									errorMessage: message,
									server: {
										...candidate.server,
										meta: {
											...candidate.server.meta,
											qa: unknownError.qa,
										},
									},
							  }
							: candidate
					)
				);
				return;
			}

			const message =
				unknownError instanceof Error
					? unknownError.message
					: __(
							'The translation could not be saved. Please try again.',
							'ai-multilingual'
					  );

			setRows( ( current ) =>
				current.map( ( candidate ) =>
					candidate.segmentKey === segmentKey
						? {
								...candidate,
								rowState: 'error',
								errorMessage: message,
						  }
						: candidate
				)
			);
		}
	};

	const handleSubmitReview = async ( segmentKey: string ) => {
		if ( ! postId || ! languageCode ) {
			return;
		}

		try {
			const dto = await submitReview( postId, languageCode, segmentKey );
			setRows( ( current ) => mergeSegmentsIntoRows( current, [ dto ] ) );
			setBatchMessage(
				__( 'Segment submitted for review.', 'ai-multilingual' )
			);
		} catch ( unknownError ) {
			const message =
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Could not submit this segment for review.',
							'ai-multilingual'
					  );
			setRows( ( current ) =>
				current.map( ( candidate ) =>
					candidate.segmentKey === segmentKey
						? { ...candidate, rowState: 'error', errorMessage: message }
						: candidate
				)
			);
		}
	};

	const openReviewDialog = (
		segmentKeys: string[],
		action: 'approve' | 'reject'
	) => {
		if ( 0 === segmentKeys.length ) {
			return;
		}
		setReviewDialog( { action, targets: segmentKeys, reason: '', busy: false, error: '' } );
	};

	const closeReviewDialog = () => setReviewDialog( null );

	const confirmReviewDialog = async () => {
		if ( ! reviewDialog || ! postId || ! languageCode ) {
			return;
		}

		const { action, targets, reason } = reviewDialog;
		setReviewDialog( ( current ) =>
			current ? { ...current, busy: true, error: '' } : current
		);

		if ( 1 === targets.length ) {
			const segmentKey = targets[ 0 ];
			const row = rows.find(
				( candidate ) => candidate.segmentKey === segmentKey
			);

			try {
				const dto =
					'approve' === action
						? await approveReview(
								postId,
								languageCode,
								segmentKey,
								undefined,
								row?.server.submitted_translation_hash
						  )
						: await rejectReview(
								postId,
								languageCode,
								segmentKey,
								reason,
								undefined,
								row?.server.submitted_translation_hash
						  );

				setRows( ( current ) => mergeSegmentsIntoRows( current, [ dto ] ) );
				setBatchMessage(
					'approve' === action
						? __( 'Segment approved.', 'ai-multilingual' )
						: __( 'Segment rejected.', 'ai-multilingual' )
				);
				setReviewDialog( null );
			} catch ( unknownError ) {
				if ( unknownError instanceof WorkspaceQABlockedError ) {
					setRows( ( current ) =>
						current.map( ( candidate ) =>
							candidate.segmentKey === segmentKey
								? {
										...candidate,
										server: {
											...candidate.server,
											meta: {
												...candidate.server.meta,
												qa: unknownError.qa,
											},
										},
								  }
								: candidate
						)
					);
				}

				const message =
					unknownError instanceof Error
						? unknownError.message
						: __(
								'The review action could not be completed.',
								'ai-multilingual'
						  );
				setReviewDialog( ( current ) =>
					current ? { ...current, busy: false, error: message } : current
				);
			}
			return;
		}

		try {
			const items = targets.map( ( segmentKey ) => {
				const row = rows.find(
					( candidate ) => candidate.segmentKey === segmentKey
				);
				return {
					segment_key: segmentKey,
					submitted_translation_hash:
						row?.server.submitted_translation_hash,
				};
			} );

			const result = await batchReview(
				postId,
				languageCode,
				action,
				items,
				reason
			);

			setRows( ( current ) => applyReviewBatchResults( current, result ) );
			setSelectedKeys( clearSelection() );
			setBatchMessage(
				0 === result.errors.length
					? sprintf(
							/* translators: %d: succeeded count */
							'approve' === action
								? __( '%d segment(s) approved.', 'ai-multilingual' )
								: __( '%d segment(s) rejected.', 'ai-multilingual' ),
							result.updated.length
					  )
					: sprintf(
							/* translators: 1: succeeded count, 2: failed count */
							__( '%1$d succeeded, %2$d failed.', 'ai-multilingual' ),
							result.updated.length,
							result.errors.length
					  )
			);
			setReviewDialog( null );
		} catch ( unknownError ) {
			const message =
				unknownError instanceof Error
					? unknownError.message
					: __(
							'The batch review action could not be completed.',
							'ai-multilingual'
					  );
			setReviewDialog( ( current ) =>
				current ? { ...current, busy: false, error: message } : current
			);
		}
	};

	const handleSuggestProfile = async (
		segmentKey: string,
		profile: string
	) => {
		if ( ! postId || ! languageCode ) {
			return;
		}

		setSuggestingKey( segmentKey );
		setBatchMessage( '' );
		try {
			const updated = await suggestSegment(
				postId,
				languageCode,
				segmentKey,
				profile
			);
			setRows( ( current ) =>
				mergeSegmentsIntoRows( current, [ updated ] )
			);
		} catch ( unknownError ) {
			const message =
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Could not request AI suggestions.',
							'ai-multilingual'
					  );
			setBatchMessage( message );
		} finally {
			setSuggestingKey( null );
		}
	};

	const handleReloadRow = ( segmentKey: string ) => {
		const row = rows.find( ( candidate ) => candidate.segmentKey === segmentKey );
		if ( ! row ) {
			return;
		}

		const server = row.conflictServer ?? row.server;
		setRows( ( current ) =>
			applyReloadFromServer( current, segmentKey, server, true )
		);
	};

	const handleSaveAllDirty = async () => {
		await runBatchSave(
			dirtyRowsInOrder(
				rows.filter( ( row ) => row.rowState !== 'conflict' )
			),
			__( 'Saving all changed segments…', 'ai-multilingual' ),
			__( 'All changed segments were saved.', 'ai-multilingual' ),
			/* translators: %d: number of failed segments */
			__(
				'Some segments could not be saved. %d segment(s) still need attention.',
				'ai-multilingual'
			)
		);
	};

	const handleSaveSelected = async () => {
		await runBatchSave(
			dirtyRowsInOrder(
				selectedDirtyRows(
					rows.filter( ( row ) => row.rowState !== 'conflict' ),
					selectedKeys
				)
			),
			__( 'Saving selected segments…', 'ai-multilingual' ),
			__( 'Selected segments were saved.', 'ai-multilingual' ),
			/* translators: %d: number of failed segments */
			__(
				'Some selected segments could not be saved. %d segment(s) still need attention.',
				'ai-multilingual'
			)
		);
	};

	const handleTranslateSelected = async () => {
		if ( ! postId || ! languageCode || selectedKeys.size === 0 ) {
			return;
		}

		const uniqueKeys = selectedEditableKeys( rows, selectedKeys );
		if ( uniqueKeys.length === 0 ) {
			setBatchMessage(
				__(
					'Select at least one editable segment to translate.',
					'ai-multilingual'
				)
			);
			return;
		}

		setBatchSaving( true );
		setBatchMessage(
			__( 'Requesting automatic translation…', 'ai-multilingual' )
		);

		try {
			const result = await translateBatch(
				postId,
				languageCode,
				uniqueKeys
			);

			if ( result.updated.length > 0 ) {
				setRows( ( current ) =>
					mergeSegmentsIntoRows( current, result.updated )
				);
			}

			if ( result.status === 'failed' ) {
				setBatchMessage(
					result.errors[ 0 ]?.message ||
						__(
							'Automatic translation is not configured.',
							'ai-multilingual'
						)
				);
			} else if ( result.status === 'partial' ) {
				setBatchMessage(
					__(
						'Some selected segments could not be translated automatically.',
						'ai-multilingual'
					)
				);
			} else {
				setBatchMessage(
					__( 'Selected segments were translated.', 'ai-multilingual' )
				);
			}
		} catch ( unknownError ) {
			setBatchMessage(
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Automatic translation is not configured.',
							'ai-multilingual'
					  )
			);
		} finally {
			setBatchSaving( false );
		}
	};

	const handleAcceptTmExact = async () => {
		if ( ! postId || ! languageCode || selectedKeys.size === 0 ) {
			return;
		}

		const uniqueKeys = selectedEditableKeys( rows, selectedKeys );
		if ( uniqueKeys.length === 0 ) {
			setBatchMessage(
				__(
					'Select at least one editable segment to accept TM matches.',
					'ai-multilingual'
				)
			);
			return;
		}

		setBatchSaving( true );
		setBatchMessage( __( 'Accepting exact TM suggestions…', 'ai-multilingual' ) );

		try {
			const result = await acceptTmSuggestions(
				postId,
				languageCode,
				uniqueKeys
			);
			if ( result.updated.length > 0 ) {
				setRows( ( current ) =>
					mergeSegmentsIntoRows( current, result.updated )
				);
			}
			setBatchMessage(
				sprintf(
					/* translators: %d: accepted count */
					__( 'Accepted %d exact TM suggestion(s).', 'ai-multilingual' ),
					result.updated.length
				)
			);
		} catch ( unknownError ) {
			setBatchMessage(
				unknownError instanceof Error
					? unknownError.message
					: __( 'Could not accept TM suggestions.', 'ai-multilingual' )
			);
		} finally {
			setBatchSaving( false );
		}
	};

	const handleRunQa = async () => {
		if ( ! postId || ! languageCode || selectedKeys.size === 0 ) {
			return;
		}

		const uniqueKeys = selectedEditableKeys( rows, selectedKeys );
		setBatchSaving( true );
		setBatchMessage( __( 'Running quality checks…', 'ai-multilingual' ) );

		try {
			const result = await runQaBatch( postId, languageCode, uniqueKeys );
			if ( result.segments.length > 0 ) {
				setRows( ( current ) =>
					mergeSegmentsIntoRows( current, result.segments )
				);
			}
			setBatchMessage(
				sprintf(
					/* translators: 1: errors, 2: warnings */
					__(
						'QA complete — errors: %1$d, warnings: %2$d.',
						'ai-multilingual'
					),
					result.summary.errors,
					result.summary.warnings
				)
			);
		} catch ( unknownError ) {
			setBatchMessage(
				unknownError instanceof Error
					? unknownError.message
					: __( 'Could not run QA.', 'ai-multilingual' )
			);
		} finally {
			setBatchSaving( false );
		}
	};

	const handleToggleSelect = ( segmentKey: string, checked: boolean ) => {
		setSelectedKeys( ( current ) =>
			toggleSelection( current, segmentKey, checked )
		);
	};

	const handleToggleSelectAll = ( checked: boolean ) => {
		setSelectedKeys( ( current ) =>
			checked
				? selectAllVisible( current, filteredRows )
				: deselectAllVisible( current, filteredRows )
		);
	};

	const handlePreview = async () => {
		if ( ! postId || ! languageCode ) {
			return;
		}

		setPreviewError( '' );

		try {
			const url = await fetchPreviewUrl( postId, languageCode );
			window.open( url, '_blank', 'noopener,noreferrer' );
		} catch {
			setPreviewError(
				__(
					'Preview URL is unavailable for this post.',
					'ai-multilingual'
				)
			);
		}
	};

	const reviewSelectedRows = selectedPendingReviewRows( rows, selectedKeys );

	return (
		<div className="aiml-translator-workspace">
			{ ( canTranslate || canReview || canViewJobs || canAccessOperations ) && (
				<div
					className="aiml-workspace-view-tabs"
					role="tablist"
					aria-label={ __( 'Workspace views', 'ai-multilingual' ) }
				>
					{ canAccessOperations && (
						<Button
							variant={
								viewMode === 'operations' ? 'primary' : 'secondary'
							}
							role="tab"
							aria-selected={ viewMode === 'operations' }
							onClick={ () => {
								void requestViewChange( 'operations' );
							} }
						>
							{ __( 'Operations', 'ai-multilingual' ) }
						</Button>
					) }
					{ canTranslate && (
						<Button
							variant={
								viewMode === 'site-translate' ? 'primary' : 'secondary'
							}
							role="tab"
							aria-selected={ viewMode === 'site-translate' }
							onClick={ () => {
								void requestViewChange( 'site-translate' );
							} }
						>
							{ __( 'Site Translate', 'ai-multilingual' ) }
						</Button>
					) }
					{ canTranslate && (
						<Button
							variant={ viewMode === 'editor' ? 'primary' : 'secondary' }
							role="tab"
							aria-selected={ viewMode === 'editor' }
							onClick={ () => {
								void requestViewChange( 'editor' );
							} }
						>
							{ __( 'Translate', 'ai-multilingual' ) }
						</Button>
					) }
					{ canReview && (
						<Button
							variant={ viewMode === 'queue' ? 'primary' : 'secondary' }
							role="tab"
							aria-selected={ viewMode === 'queue' }
							onClick={ () => {
								void requestViewChange( 'queue' );
							} }
						>
							{ __( 'Review queue', 'ai-multilingual' ) }
						</Button>
					) }
					{ canViewJobs && (
						<Button
							variant={ viewMode === 'jobs' ? 'primary' : 'secondary' }
							role="tab"
							aria-selected={ viewMode === 'jobs' }
							onClick={ () => {
								void requestViewChange( 'jobs' );
							} }
						>
							{ __( 'Jobs', 'ai-multilingual' ) }
						</Button>
					) }
				</div>
			) }

			{ ! canTranslate && ! canReview && ! canViewJobs && ! canAccessOperations && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'You do not have permission to use the translator workspace.',
						'ai-multilingual'
					) }
				</Notice>
			) }

			{ canTranslate && 'editor' === viewMode && (
				<>
					<Panel>
						<PanelBody
							title={ __( 'Workspace', 'ai-multilingual' ) }
							initialOpen={ true }
						>
							<div className="aiml-workspace-toolbar">
								<LanguageSelect
									languages={ languages }
									value={ languageCode }
									onChange={ ( code ) => {
										setLanguageCode( code );
										setPostId( null );
										setPostSummary( null );
										setSegmentFilter( 'all' );
									} }
								/>
								<PostSelect
									languageCode={ languageCode }
									value={ postId }
									onChange={ ( id, summary ) => {
										setPostId( id );
										setPostSummary( summary );
									} }
								/>
								<div className="aiml-workspace-toolbar-actions">
									<Button
										variant="secondary"
										onClick={ loadSegments }
										disabled={ loading || ! postId }
									>
										{ __( 'Refresh', 'ai-multilingual' ) }
									</Button>
									<Button
										variant="secondary"
										onClick={ handleSaveAllDirty }
										disabled={
											batchSaving ||
											loading ||
											! postId ||
											dirtyCount === 0
										}
									>
										{ batchSaving
											? __( 'Saving…', 'ai-multilingual' )
											: sprintf(
													/* translators: %d: dirty segment count */
													__(
														'Save all changed (%d)',
														'ai-multilingual'
													),
													dirtyCount
											  ) }
									</Button>
									<Button
										variant="primary"
										onClick={ handlePreview }
										disabled={ ! postId || ! languageCode }
									>
										{ __( 'Preview', 'ai-multilingual' ) }
									</Button>
								</div>
							</div>
							<PageAiTranslate
								postId={ postId }
								languageCode={ languageCode }
								languages={ languages }
								canManageJobs={ canManageJobs }
								onComplete={ loadSegments }
							/>
							{ previewError && (
								<Notice status="error" isDismissible={ false }>
									{ previewError }
								</Notice>
							) }
							{ postSummary && (
								<p className="aiml-workspace-summary">
									{ postSummary.post_title } · { postSummary.total_segments }{ ' ' }
									{ __( 'segments', 'ai-multilingual' ) }
									{ postSummary.stale_count > 0 &&
										` · ${ postSummary.stale_count } ${ __(
											'stale',
											'ai-multilingual'
										) }` }
								</p>
							) }
						</PanelBody>
					</Panel>

					{ status && postId && (
						<PublishContext
							status={ status }
							onPreview={ handlePreview }
							previewDisabled={ ! languageCode }
						/>
					) }

					{ postId && languageCode && (
						<LocalizedSlugPanel
							postId={ postId }
							languageCode={ languageCode }
						/>
					) }

					{ rows.length > 0 && (
						<SegmentFilterBar
							value={ segmentFilter }
							visibleCount={ filteredRows.length }
							totalCount={ rows.length }
							onChange={ setSegmentFilter }
						/>
					) }

					<BulkToolbar
						selectedCount={ selectedKeys.size }
						dirtySelectedCount={ dirtySelectedCount }
						busy={ batchSaving }
						onSaveSelected={ handleSaveSelected }
						onTranslateSelected={ handleTranslateSelected }
						onAcceptTmExact={ handleAcceptTmExact }
						onRunQa={ handleRunQa }
						onClearSelection={ () => setSelectedKeys( clearSelection() ) }
						canReview={ canReview }
						reviewSelectedCount={ reviewSelectedRows.length }
						onApproveSelected={ () =>
							openReviewDialog(
								reviewSelectedRows.map( ( row ) => row.segmentKey ),
								'approve'
							)
						}
						onRejectSelected={ () =>
							openReviewDialog(
								reviewSelectedRows.map( ( row ) => row.segmentKey ),
								'reject'
							)
						}
					/>

					<SegmentTable
						rows={ filteredRows }
						loading={ loading }
						error={ error }
						batchMessage={ batchMessage }
						filterActive={ segmentFilter !== 'all' }
						selectedKeys={ selectedKeys }
						allVisibleSelected={ visibleAllSelected }
						hasSelectableVisible={ hasSelectableVisible }
						canReview={ canReview }
						onToggleSelect={ handleToggleSelect }
						onToggleSelectAll={ handleToggleSelectAll }
						onDraftChange={ handleDraftChange }
						onSave={ handleSaveRow }
						onReload={ handleReloadRow }
						onSuggestProfile={ handleSuggestProfile }
						suggestingKey={ suggestingKey }
						onSubmitReview={ handleSubmitReview }
						onRequestReviewDecision={ openReviewDialog }
					/>

					{ status && (
						<StatusFooter
							status={ status }
							qaSummary={ aggregateQaSummary(
								rows.map( ( row ) => row.server )
							) }
						/>
					) }
				</>
			) }

			{ canAccessOperations && 'operations' === viewMode && (
				<OperationsPanel
					languages={ languages }
					canTranslate={ canTranslate }
					canReview={ canReview }
					requestConfirm={ requestConfirm }
					onRegisterDirtyGuard={ ( guard ) => {
						dirtyGuardRef.current = guard;
					} }
					onRegisterNavSnapshot={ ( getter ) => {
						navSnapshotRef.current = getter;
					} }
					onRequestLeaveOperations={ requestLeaveOperations }
					onOpenInTranslate={ ( openPostId, openLanguageCode ) => {
						if ( canTranslate ) {
							setViewMode( 'editor' );
						}
						if ( openLanguageCode ) {
							setLanguageCode( openLanguageCode );
						}
						setPostId( openPostId );
					} }
					onOpenInReview={ ( openLanguageCode, openPostId ) => {
						setReviewJump( {
							language: openLanguageCode,
							postId: openPostId ? String( openPostId ) : '',
						} );
						if ( canReview ) {
							setViewMode( 'queue' );
						}
					} }
					onOpenJobs={ ( jobId, itemId ) => {
						if ( ! canViewJobs ) {
							return;
						}
						setFocusedJobId( jobId ?? null );
						setFocusedJobItemId( itemId ?? null );
						writeJobsUrlState( {
							jobId: jobId ?? null,
							itemId: itemId ?? null,
						} );
						setViewMode( 'jobs' );
					} }
				/>
			) }

			{ canReview && 'queue' === viewMode && (
				<ReviewQueuePanel
					key={ `queue-${ reviewJump.language }-${ reviewJump.postId }` }
					languages={ languages }
					canTranslate={ canTranslate }
					initialLanguageCode={ reviewJump.language }
					initialPostId={ reviewJump.postId }
					onOpenInEditor={ ( openPostId, openLanguageCode ) => {
						if ( canTranslate ) {
							setViewMode( 'editor' );
						}
						if ( openLanguageCode ) {
							setLanguageCode( openLanguageCode );
						}
						setPostId( openPostId );
					} }
					onOpenInOperations={
						canAccessOperations
							? ( translationId, languageCode ) => {
									clearOperationsSession();
									writeOperationsUrlState( {
										language: languageCode || '',
										attention: 'all',
										pageNum: 1,
										translationId,
									} );
									setViewMode( 'operations' );
							  }
							: undefined
					}
				/>
			) }

			{ canTranslate && 'site-translate' === viewMode && (
				<SiteTranslatePanel
					languages={ languages }
					canManageJobs={ canManageJobs }
					canRunJobs={ canRunJobs }
					onOpenJobsBatch={ ( openBatchId ) => {
						if ( ! canViewJobs ) {
							return;
						}
						setFocusedJobId( null );
						setFocusedJobItemId( null );
						setFocusedBatchId( openBatchId );
						setViewMode( 'jobs' );
						writeJobsUrlState( { batchId: openBatchId } );
					} }
				/>
			) }

			{ canViewJobs && 'jobs' === viewMode && (
				<JobsPanel
					languages={ languages }
					canManage={ canManageJobs }
					canCancel={ canCancelJobs }
					canRun={ canRunJobs }
					focusedJobId={ focusedJobId }
					focusedItemId={ focusedJobItemId }
					focusedBatchId={ focusedBatchId }
					onOpenOperations={
						canAccessOperations
							? ( { sourceType, sourceId, languageId } ) => {
									const language =
										languages.find(
											( candidate ) =>
												candidate.language_id === languageId
										)?.code ?? '';
									clearOperationsSession();
									writeOperationsUrlState( {
										language,
										attention: 'all',
										pageNum: 1,
										sourceType,
										sourceId: String( sourceId ),
										translationId: null,
									} );
									setViewMode( 'operations' );
							  }
							: undefined
					}
				/>
			) }

			{ confirmDialog }
			{ reviewDialog && (
				<ReviewDecisionDialog
					action={ reviewDialog.action }
					count={ reviewDialog.targets.length }
					reason={ reviewDialog.reason }
					onReasonChange={ ( value ) =>
						setReviewDialog( ( current ) =>
							current ? { ...current, reason: value } : current
						)
					}
					onConfirm={ confirmReviewDialog }
					onCancel={ closeReviewDialog }
					busy={ reviewDialog.busy }
					errorMessage={ reviewDialog.error }
				/>
			) }
		</div>
	);
}
