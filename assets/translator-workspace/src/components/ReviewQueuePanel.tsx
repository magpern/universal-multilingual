import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	approveObject,
	approveReview,
	batchReview,
	fetchReviewQueue,
	rejectReview,
} from '../api/workspace-api';
import type {
	ApproveObjectResult,
	LanguageOption,
	ReviewObjectGroup as ReviewObjectGroupModel,
	ReviewQueueItem,
} from '../types/view-models';
import { objectSummaryLine } from '../utils/object-review';
import {
	clearQueueSelection,
	groupQueueByObject,
	isQueueItemSelectable,
	languageCodeForId,
	queueItemKey,
	toggleQueueSelection,
} from '../utils/review-queue';
import type { ReviewQueueFilter } from '../utils/review-status';
import ReviewDecisionDialog from './ReviewDecisionDialog';
import ReviewObjectGroup from './ReviewObjectGroup';
import ReviewQueueFilterBar from './ReviewQueueFilterBar';

interface ReviewQueuePanelProps {
	languages: LanguageOption[];
	canTranslate: boolean;
	onOpenInEditor: ( postId: number, languageCode: string ) => void;
	onOpenInOperations?: (
		translationId: number,
		languageCode: string
	) => void;
	initialLanguageCode?: string;
	initialPostId?: string;
}

interface DialogState {
	action: 'approve' | 'reject';
	targets: ReviewQueueItem[];
	languageCode: string;
	reason: string;
	busy: boolean;
	error: string;
}

const PER_PAGE = 100;

export default function ReviewQueuePanel( {
	languages,
	canTranslate,
	onOpenInEditor,
	onOpenInOperations,
	initialLanguageCode = '',
	initialPostId = '',
}: ReviewQueuePanelProps ) {
	const [ reviewStatus, setReviewStatus ] =
		useState< ReviewQueueFilter >( 'pending' );
	const [ languageCode, setLanguageCode ] = useState( initialLanguageCode );
	const [ postIdFilter, setPostIdFilter ] = useState( initialPostId );
	const [ page, setPage ] = useState( 1 );
	const [ groups, setGroups ] = useState< ReviewObjectGroupModel[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ objectResult, setObjectResult ] =
		useState< ApproveObjectResult | null >( null );
	const [ selectedKeys, setSelectedKeys ] = useState< Set< string > >(
		() => new Set()
	);
	const [ dialog, setDialog ] = useState< DialogState | null >( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( async () => {
		setLoading( true );
		setError( '' );

		const parsedPostId = Number( postIdFilter.trim() );
		const postId =
			postIdFilter.trim() && parsedPostId > 0 ? parsedPostId : undefined;

		try {
			const response = await fetchReviewQueue( {
				postId,
				languageCode: languageCode || undefined,
				reviewStatus,
				page,
				perPage: PER_PAGE,
			} );
			setGroups( groupQueueByObject( response ) );
			setTotal( response.total );
		} catch {
			setError(
				__( 'Could not load the review queue.', 'ai-multilingual' )
			);
			setGroups( [] );
			setTotal( 0 );
		} finally {
			setLoading( false );
		}
	}, [ postIdFilter, languageCode, reviewStatus, page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		setPage( 1 );
		setSelectedKeys( clearQueueSelection() );
	}, [ reviewStatus, languageCode, postIdFilter ] );

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	const groupLanguageCode = ( group: ReviewObjectGroupModel ): string =>
		group.language_code ||
		languageCodeForId( languages, group.language_id );

	const selectedItemsInGroup = (
		group: ReviewObjectGroupModel
	): ReviewQueueItem[] =>
		group.items.filter(
			( item ) =>
				isQueueItemSelectable( item ) &&
				selectedKeys.has( queueItemKey( item ) )
		);

	const openDialog = (
		targets: ReviewQueueItem[],
		action: 'approve' | 'reject',
		code: string
	) => {
		if ( 0 === targets.length || ! code ) {
			return;
		}
		setDialog( {
			action,
			targets,
			languageCode: code,
			reason: '',
			busy: false,
			error: '',
		} );
	};

	const confirmDialog = async () => {
		if ( ! dialog ) {
			return;
		}
		setDialog( ( current ) =>
			current ? { ...current, busy: true, error: '' } : current
		);

		try {
			if ( 1 === dialog.targets.length ) {
				const item = dialog.targets[ 0 ];
				if ( 'approve' === dialog.action ) {
					await approveReview(
						item.post_id,
						dialog.languageCode,
						item.segment_key,
						undefined,
						item.submitted_translation_hash
					);
				} else {
					await rejectReview(
						item.post_id,
						dialog.languageCode,
						item.segment_key,
						dialog.reason,
						undefined,
						item.submitted_translation_hash
					);
				}
			} else {
				await batchReview(
					dialog.targets[ 0 ].post_id,
					dialog.languageCode,
					dialog.action,
					dialog.targets.map( ( item ) => ( {
						segment_key: item.segment_key,
						submitted_translation_hash:
							item.submitted_translation_hash,
					} ) ),
					dialog.reason
				);
			}

			setDialog( null );
			setSelectedKeys( clearQueueSelection() );
			setObjectResult( null );
			setMessage(
				'approve' === dialog.action
					? __( 'Approved.', 'ai-multilingual' )
					: __( 'Rejected.', 'ai-multilingual' )
			);
			load();
		} catch ( unknownError ) {
			const errorMessage =
				unknownError instanceof Error
					? unknownError.message
					: __(
							'The review action could not be completed.',
							'ai-multilingual'
					  );
			setDialog( ( current ) =>
				current
					? { ...current, busy: false, error: errorMessage }
					: current
			);
		}
	};

	const handleApproveObject = async ( group: ReviewObjectGroupModel ) => {
		const code = groupLanguageCode( group );
		if ( ! code ) {
			return;
		}
		setBusy( true );
		setMessage( '' );
		setObjectResult( null );
		try {
			const result = await approveObject( group.post_id, code );
			setObjectResult( result );
			setSelectedKeys( clearQueueSelection() );
			load();
		} catch ( unknownError ) {
			setError(
				unknownError instanceof Error
					? unknownError.message
					: __( 'Could not approve this object.', 'ai-multilingual' )
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<div
			className="aiml-review-queue-panel"
			role="region"
			aria-label={ __( 'Review queue', 'ai-multilingual' ) }
		>
			<ReviewQueueFilterBar
				languages={ languages }
				languageCode={ languageCode }
				onLanguageChange={ setLanguageCode }
				reviewStatus={ reviewStatus }
				onReviewStatusChange={ setReviewStatus }
				postIdFilter={ postIdFilter }
				onPostIdFilterChange={ setPostIdFilter }
				onRefresh={ load }
				loading={ loading }
			/>

			{ message && (
				<Notice
					status="info"
					isDismissible={ true }
					onRemove={ () => setMessage( '' ) }
				>
					{ message }
				</Notice>
			) }

			{ objectResult && (
				<Notice
					status={
						objectResult.summary.is_fully_reviewed
							? 'success'
							: 'warning'
					}
					isDismissible={ true }
					onRemove={ () => setObjectResult( null ) }
				>
					<strong>{ objectResult.post_title }</strong>:{ ' ' }
					{ objectSummaryLine(
						objectResult.summary,
						objectResult.post_type === 'product'
							? __( 'product', 'ai-multilingual' )
							: __( 'page', 'ai-multilingual' )
					) }
					{ objectResult.skipped.length > 0 && (
						<ul className="aiml-review-object-group__skipped">
							{ objectResult.skipped.map( ( skip ) => (
								<li key={ skip.segment_key }>
									{ skip.field_label || skip.segment_key }
									{ skip.message
										? ` — ${ skip.message }`
										: '' }
								</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			{ loading && <Spinner /> }

			{ ! loading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && 0 === groups.length && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No translations match the current review queue filters.',
						'ai-multilingual'
					) }
				</Notice>
			) }

			{ ! loading &&
				! error &&
				groups.map( ( group ) => (
					<ReviewObjectGroup
						key={ `${ group.post_id }:${ group.language_id }` }
						group={ group }
						languages={ languages }
						canTranslate={ canTranslate }
						busy={ busy || null !== dialog }
						selectedKeys={ selectedKeys }
						onToggleSelect={ ( key, checked ) =>
							setSelectedKeys( ( current ) =>
								toggleQueueSelection( current, key, checked )
							)
						}
						onApproveRow={ ( item ) =>
							openDialog(
								[ item ],
								'approve',
								groupLanguageCode( group )
							)
						}
						onRejectRow={ ( item ) =>
							openDialog(
								[ item ],
								'reject',
								groupLanguageCode( group )
							)
						}
						onApproveSelected={ ( target ) =>
							openDialog(
								selectedItemsInGroup( target ),
								'approve',
								groupLanguageCode( target )
							)
						}
						onRejectSelected={ ( target ) =>
							openDialog(
								selectedItemsInGroup( target ),
								'reject',
								groupLanguageCode( target )
							)
						}
						onApproveObject={ handleApproveObject }
						onOpenInEditor={ onOpenInEditor }
						onOpenInOperations={ onOpenInOperations }
					/>
				) ) }

			{ ! loading && ! error && totalPages > 1 && (
				<div
					className="aiml-review-queue-pagination"
					role="navigation"
					aria-label={ __(
						'Review queue pagination',
						'ai-multilingual'
					) }
				>
					<Button
						variant="secondary"
						onClick={ () =>
							setPage( ( current ) => Math.max( 1, current - 1 ) )
						}
						disabled={ page <= 1 }
					>
						{ __( 'Previous', 'ai-multilingual' ) }
					</Button>
					<span aria-live="polite">
						{ sprintf(
							/* translators: 1: current page, 2: total pages */
							__( 'Page %1$d of %2$d', 'ai-multilingual' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="secondary"
						onClick={ () =>
							setPage( ( current ) =>
								Math.min( totalPages, current + 1 )
							)
						}
						disabled={ page >= totalPages }
					>
						{ __( 'Next', 'ai-multilingual' ) }
					</Button>
				</div>
			) }

			{ dialog && (
				<ReviewDecisionDialog
					action={ dialog.action }
					count={ dialog.targets.length }
					reason={ dialog.reason }
					onReasonChange={ ( value ) =>
						setDialog( ( current ) =>
							current ? { ...current, reason: value } : current
						)
					}
					onConfirm={ confirmDialog }
					onCancel={ () => setDialog( null ) }
					busy={ dialog.busy }
					errorMessage={ dialog.error }
				/>
			) }
		</div>
	);
}
