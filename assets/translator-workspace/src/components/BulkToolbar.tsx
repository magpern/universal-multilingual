import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

interface BulkToolbarProps {
	selectedCount: number;
	dirtySelectedCount: number;
	busy: boolean;
	onSaveSelected: () => void;
	onTranslateSelected: () => void;
	onAcceptTmExact?: () => void;
	onRunQa?: () => void;
	onClearSelection: () => void;
	canReview?: boolean;
	reviewSelectedCount?: number;
	submitSelectedCount?: number;
	onSubmitSelected?: () => void;
	onApproveSelected?: () => void;
	onRejectSelected?: () => void;
}

export default function BulkToolbar( {
	selectedCount,
	dirtySelectedCount,
	busy,
	onSaveSelected,
	onTranslateSelected,
	onAcceptTmExact,
	onRunQa,
	onClearSelection,
	canReview = false,
	reviewSelectedCount = 0,
	submitSelectedCount = 0,
	onSubmitSelected,
	onApproveSelected,
	onRejectSelected,
}: BulkToolbarProps ) {
	if ( selectedCount === 0 ) {
		return null;
	}

	return (
		<div
			className="aiml-workspace-bulk-toolbar"
			role="region"
			aria-label={ __( 'Bulk segment actions', 'ai-multilingual' ) }
		>
			<p className="aiml-workspace-bulk-summary">
				{ sprintf(
					/* translators: %d: selected segment count */
					__( '%d segment(s) selected', 'ai-multilingual' ),
					selectedCount
				) }
			</p>
			<div className="aiml-workspace-bulk-actions">
				<Button
					variant="secondary"
					onClick={ onSaveSelected }
					disabled={ busy || dirtySelectedCount === 0 }
					aria-label={ __( 'Save selected changed segments', 'ai-multilingual' ) }
				>
					{ busy
						? __( 'Saving…', 'ai-multilingual' )
						: sprintf(
								/* translators: %d: dirty selected count */
								__( 'Save selected (%d)', 'ai-multilingual' ),
								dirtySelectedCount
						  ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ onTranslateSelected }
					disabled={ busy }
					aria-label={ __(
						'Translate selected segments automatically',
						'ai-multilingual'
					) }
				>
					{ __( 'Translate selected', 'ai-multilingual' ) }
				</Button>
				{ onAcceptTmExact && (
					<Button
						variant="secondary"
						onClick={ onAcceptTmExact }
						disabled={ busy }
						aria-label={ __(
							'Apply the exact 100% translation-memory match to each selected segment that has one',
							'ai-multilingual'
						) }
						showTooltip
					>
						{ __( 'Apply exact memory matches', 'ai-multilingual' ) }
					</Button>
				) }
				{ onRunQa && (
					<Button
						variant="secondary"
						onClick={ onRunQa }
						disabled={ busy }
					>
						{ __( 'Run QA', 'ai-multilingual' ) }
					</Button>
				) }
				{ canReview && onSubmitSelected && (
					<Button
						variant="secondary"
						onClick={ onSubmitSelected }
						disabled={ busy || submitSelectedCount === 0 }
						aria-label={ __(
							'Submit selected translated segments for review',
							'ai-multilingual'
						) }
					>
						{ sprintf(
							/* translators: %d: submittable selected count */
							__( 'Submit selected for review (%d)', 'ai-multilingual' ),
							submitSelectedCount
						) }
					</Button>
				) }
				{ canReview && onApproveSelected && (
					<Button
						variant="primary"
						onClick={ onApproveSelected }
						disabled={ busy || reviewSelectedCount === 0 }
						aria-label={ __(
							'Approve selected pending segments',
							'ai-multilingual'
						) }
					>
						{ sprintf(
							/* translators: %d: pending selected count */
							__( 'Approve selected (%d)', 'ai-multilingual' ),
							reviewSelectedCount
						) }
					</Button>
				) }
				{ canReview && onRejectSelected && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ onRejectSelected }
						disabled={ busy || reviewSelectedCount === 0 }
						aria-label={ __(
							'Reject selected pending segments',
							'ai-multilingual'
						) }
					>
						{ sprintf(
							/* translators: %d: pending selected count */
							__( 'Reject selected (%d)', 'ai-multilingual' ),
							reviewSelectedCount
						) }
					</Button>
				) }
				<Button
					variant="tertiary"
					onClick={ onClearSelection }
					disabled={ busy }
				>
					{ __( 'Clear selection', 'ai-multilingual' ) }
				</Button>
			</div>
		</div>
	);
}
