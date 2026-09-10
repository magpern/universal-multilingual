import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type {
	LanguageOption,
	ReviewObjectGroup as ReviewObjectGroupModel,
	ReviewQueueItem,
} from '../types/view-models';
import { objectStateChip, objectSummaryLine } from '../utils/object-review';
import { isQueueItemSelectable, queueItemKey } from '../utils/review-queue';
import ReviewQueueRow from './ReviewQueueRow';

interface ReviewObjectGroupProps {
	group: ReviewObjectGroupModel;
	languages: LanguageOption[];
	canTranslate: boolean;
	busy: boolean;
	selectedKeys: Set< string >;
	onToggleSelect: ( key: string, checked: boolean ) => void;
	onApproveRow: ( item: ReviewQueueItem ) => void;
	onRejectRow: ( item: ReviewQueueItem ) => void;
	onApproveSelected: ( group: ReviewObjectGroupModel ) => void;
	onRejectSelected: ( group: ReviewObjectGroupModel ) => void;
	onApproveObject: ( group: ReviewObjectGroupModel ) => void;
	onOpenInEditor: ( postId: number, languageCode: string ) => void;
	onOpenInOperations?: (
		translationId: number,
		languageCode: string
	) => void;
}

export default function ReviewObjectGroup( {
	group,
	languages,
	canTranslate,
	busy,
	selectedKeys,
	onToggleSelect,
	onApproveRow,
	onRejectRow,
	onApproveSelected,
	onRejectSelected,
	onApproveObject,
	onOpenInEditor,
	onOpenInOperations,
}: ReviewObjectGroupProps ) {
	const chip = objectStateChip( group.summary );
	const noun = group.object_noun || __( 'Post', 'ai-multilingual' );
	const nounLower = noun.toLowerCase();
	const selectedInGroup = group.items.filter(
		( item ) =>
			isQueueItemSelectable( item ) &&
			selectedKeys.has( queueItemKey( item ) )
	).length;
	const pendingInGroup = group.items.filter( isQueueItemSelectable ).length;

	return (
		<section
			className={ `aiml-review-object-group aiml-review-object-group--${ chip.tone }` }
			aria-label={ sprintf(
				/* translators: 1: object title, 2: language name */
				__( 'Review %1$s in %2$s', 'ai-multilingual' ),
				group.post_title,
				group.language_name || group.language_code
			) }
		>
			<header className="aiml-review-object-group__header">
				<div>
					<h3 className="aiml-review-object-group__title">
						{ group.post_title }
					</h3>
					<p className="aiml-review-object-group__meta">
						{ noun } ·{ ' ' }
						{ group.language_name || group.language_code }
						{ group.post_id ? ` · #${ group.post_id }` : '' }
					</p>
				</div>
				<span
					className={ `aiml-review-object-group__chip aiml-review-object-group__chip--${ chip.tone }` }
				>
					{ chip.label }
				</span>
			</header>

			<p className="aiml-review-object-group__summary" aria-live="polite">
				{ objectSummaryLine( group.summary, nounLower ) }
			</p>

			<div className="aiml-review-object-group__actions">
				<Button
					variant="primary"
					disabled={ busy || 0 === pendingInGroup }
					onClick={ () => onApproveObject( group ) }
				>
					{ sprintf(
						/* translators: 1: object noun, 2: pending count */
						__( 'Approve %1$s (%2$d)', 'ai-multilingual' ),
						noun,
						pendingInGroup
					) }
				</Button>
				{ selectedInGroup > 0 && (
					<>
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () => onApproveSelected( group ) }
						>
							{ sprintf(
								/* translators: %d: selected field count */
								__(
									'Approve selected (%d)',
									'ai-multilingual'
								),
								selectedInGroup
							) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							disabled={ busy }
							onClick={ () => onRejectSelected( group ) }
						>
							{ sprintf(
								/* translators: %d: selected field count */
								__( 'Reject selected (%d)', 'ai-multilingual' ),
								selectedInGroup
							) }
						</Button>
					</>
				) }
				{ canTranslate && (
					<Button
						variant="link"
						onClick={ () =>
							onOpenInEditor( group.post_id, group.language_code )
						}
					>
						{ __( 'Open in Workspace', 'ai-multilingual' ) }
					</Button>
				) }
				{ group.edit_link && (
					<a
						className="components-button is-link"
						href={ group.edit_link }
					>
						{ sprintf(
							/* translators: %s: object noun */
							__( 'Edit %s', 'ai-multilingual' ),
							nounLower
						) }
					</a>
				) }
			</div>

			<details className="aiml-review-object-group__fields" open>
				<summary>
					{ sprintf(
						/* translators: %d: field count */
						__( 'Fields (%d)', 'ai-multilingual' ),
						group.items.length
					) }
				</summary>
				<table className="aiml-review-queue-table widefat striped">
					<thead>
						<tr>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Select', 'ai-multilingual' ) }
								</span>
							</th>
							<th scope="col">
								{ __( 'Field', 'ai-multilingual' ) }
							</th>
							<th scope="col">
								{ __( 'Source', 'ai-multilingual' ) }
							</th>
							<th scope="col">
								{ __( 'Translation', 'ai-multilingual' ) }
							</th>
							<th scope="col">
								{ __( 'Status', 'ai-multilingual' ) }
							</th>
							<th scope="col">
								{ __( 'Actions', 'ai-multilingual' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ group.items.map( ( item ) => (
							<ReviewQueueRow
								key={ queueItemKey( item ) }
								item={ item }
								languages={ languages }
								selected={ selectedKeys.has(
									queueItemKey( item )
								) }
								selectable={ isQueueItemSelectable( item ) }
								canTranslate={ canTranslate }
								hideObjectColumn
								onToggleSelect={ onToggleSelect }
								onApprove={ onApproveRow }
								onReject={ onRejectRow }
								onOpenInEditor={ onOpenInEditor }
								onOpenInOperations={ onOpenInOperations }
							/>
						) ) }
					</tbody>
				</table>
			</details>
		</section>
	);
}
