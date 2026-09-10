import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { LanguageOption, ReviewQueueItem } from '../types/view-models';
import { fieldLabel } from '../utils/field-labels';
import { languageCodeForId, queueItemKey } from '../utils/review-queue';
import { segmentStatusLabel } from '../utils/segment-status';
import ReviewMetaSummary from './ReviewMetaSummary';
import ReviewStatusBadge from './ReviewStatusBadge';

interface ReviewQueueRowProps {
	item: ReviewQueueItem;
	languages: LanguageOption[];
	selected: boolean;
	selectable: boolean;
	canTranslate: boolean;
	/** Inside an object group the post identity lives in the card header. */
	hideObjectColumn?: boolean;
	onToggleSelect: ( key: string, checked: boolean ) => void;
	onApprove: ( item: ReviewQueueItem ) => void;
	onReject: ( item: ReviewQueueItem ) => void;
	onOpenInEditor: ( postId: number, languageCode: string ) => void;
	onOpenInOperations?: ( translationId: number, languageCode: string ) => void;
}

export default function ReviewQueueRow( {
	item,
	languages,
	selected,
	selectable,
	canTranslate,
	hideObjectColumn = false,
	onToggleSelect,
	onApprove,
	onReject,
	onOpenInEditor,
	onOpenInOperations,
}: ReviewQueueRowProps ) {
	const key = queueItemKey( item );
	const languageCode = languageCodeForId( languages, item.language_id );
	const isPending = 'pending' === item.review_status;
	const inputId = `aiml-queue-select-${ key.replace( /[^a-z0-9_-]/gi, '-' ) }`;

	return (
		<tr
			className={ `aiml-review-queue-row aiml-review-queue-row--${ item.review_status }${
				selected ? ' aiml-review-queue-row--selected' : ''
			}` }
		>
			<td>
				{ selectable ? (
					<>
						<label htmlFor={ inputId } className="screen-reader-text">
							{ sprintf(
								/* translators: %s: segment key */
								__( 'Select segment %s', 'ai-multilingual' ),
								item.segment_key
							) }
						</label>
						<input
							id={ inputId }
							type="checkbox"
							checked={ selected }
							onChange={ ( event ) =>
								onToggleSelect( key, event.target.checked )
							}
						/>
					</>
				) : (
					<span className="screen-reader-text">
						{ __(
							'This segment is not pending review and cannot be selected',
							'ai-multilingual'
						) }
					</span>
				) }
			</td>
			{ ! hideObjectColumn && (
				<td>
					{ item.post_id }
					{ canTranslate && (
						<Button
							variant="link"
							onClick={ () =>
								onOpenInEditor( item.post_id, languageCode )
							}
						>
							{ __( 'Open in editor', 'ai-multilingual' ) }
						</Button>
					) }
				</td>
			) }
			{ ! hideObjectColumn && (
				<td>{ languageCode || item.language_id }</td>
			) }
			<td>
				<span className="aiml-review-queue-row__field">
					{ fieldLabel( item ) }
				</span>
				<details className="aiml-review-queue-row__technical">
					<summary>
						{ __( 'Technical details', 'ai-multilingual' ) }
					</summary>
					<code>{ item.segment_key }</code>
					{ onOpenInOperations && item.translation_id > 0 && (
						<Button
							variant="link"
							onClick={ () =>
								onOpenInOperations(
									item.translation_id,
									languageCode
								)
							}
						>
							{ __( 'Open in Operations', 'ai-multilingual' ) }
						</Button>
					) }
				</details>
			</td>
			<td>
				<p className="aiml-workspace-readonly">{ item.source_text }</p>
			</td>
			<td>
				<p className="aiml-workspace-readonly">
					{ item.translated_text ||
						__( '(empty)', 'ai-multilingual' ) }
				</p>
			</td>
			<td>
				<span
					className="aiml-workspace-status-badge"
					aria-label={ sprintf(
						/* translators: %s: workflow status */
						__( 'Workflow status: %s', 'ai-multilingual' ),
						segmentStatusLabel( item.status )
					) }
				>
					{ segmentStatusLabel( item.status ) }
				</span>
				<ReviewStatusBadge status={ item.review_status } />
				<ReviewMetaSummary review={ item } />
			</td>
			<td>
				{ isPending && (
					<div className="aiml-workspace-row-actions">
						<Button
							variant="primary"
							onClick={ () => onApprove( item ) }
							aria-label={ sprintf(
								/* translators: %s: segment key */
								__( 'Approve translation for %s', 'ai-multilingual' ),
								item.segment_key
							) }
						>
							{ __( 'Approve', 'ai-multilingual' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							onClick={ () => onReject( item ) }
							aria-label={ sprintf(
								/* translators: %s: segment key */
								__( 'Reject translation for %s', 'ai-multilingual' ),
								item.segment_key
							) }
						>
							{ __( 'Reject', 'ai-multilingual' ) }
						</Button>
					</div>
				) }
			</td>
		</tr>
	);
}
