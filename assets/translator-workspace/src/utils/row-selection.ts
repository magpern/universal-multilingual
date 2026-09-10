import type { SegmentRow } from '../types/segment-row';
import { canSubmitForReview } from './review-status';

export function isRowSelectable( row: SegmentRow ): boolean {
	return row.server.can_edit;
}

export function selectableRows( rows: SegmentRow[] ): SegmentRow[] {
	return rows.filter( isRowSelectable );
}

export function toggleSelection(
	selected: Set< string >,
	segmentKey: string,
	checked: boolean
): Set< string > {
	const next = new Set( selected );
	if ( checked ) {
		next.add( segmentKey );
	} else {
		next.delete( segmentKey );
	}
	return next;
}

export function selectAllVisible(
	selected: Set< string >,
	visibleRows: SegmentRow[]
): Set< string > {
	const next = new Set( selected );
	for ( const row of selectableRows( visibleRows ) ) {
		next.add( row.segmentKey );
	}
	return next;
}

export function clearSelection(): Set< string > {
	return new Set();
}

export function selectedDirtyRows(
	rows: SegmentRow[],
	selected: Set< string >
): SegmentRow[] {
	return rows.filter(
		( row ) =>
			selected.has( row.segmentKey ) &&
			row.draftText !== row.server.translated_text
	);
}

export function selectedEditableRows(
	rows: SegmentRow[],
	selected: Set< string >
): SegmentRow[] {
	return rows.filter(
		( row ) => selected.has( row.segmentKey ) && row.server.can_edit
	);
}

export function deselectAllVisible(
	selected: Set< string >,
	visibleRows: SegmentRow[]
): Set< string > {
	const next = new Set( selected );
	for ( const row of selectableRows( visibleRows ) ) {
		next.delete( row.segmentKey );
	}
	return next;
}

export function selectedEditableKeys(
	rows: SegmentRow[],
	selected: Set< string >
): string[] {
	return selectedEditableRows( rows, selected ).map(
		( row ) => row.segmentKey
	);
}

export function allVisibleSelected(
	visibleRows: SegmentRow[],
	selected: Set< string >
): boolean {
	const selectable = selectableRows( visibleRows );
	if ( selectable.length === 0 ) {
		return false;
	}

	return selectable.every( ( row ) => selected.has( row.segmentKey ) );
}

/**
 * Approve/reject are legal transitions only from `pending`
 * (ADR-0015 §4.1) — the batch review toolbar only ever acts on these rows.
 */
export function isRowPendingReview( row: SegmentRow ): boolean {
	return 'pending' === row.server.review_status;
}

export function selectedPendingReviewRows(
	rows: SegmentRow[],
	selected: Set< string >
): SegmentRow[] {
	return rows.filter(
		( row ) => selected.has( row.segmentKey ) && isRowPendingReview( row )
	);
}

/**
 * Selected rows a translator may submit for review: `not_submitted` or
 * `rejected`, editable, with eligible translated text (ADR-0015 §4.1). A
 * clean draft is required — an unsaved edit is submitted through Save first.
 */
export function selectedSubmittableRows(
	rows: SegmentRow[],
	selected: Set< string >
): SegmentRow[] {
	return rows.filter(
		( row ) =>
			selected.has( row.segmentKey ) &&
			row.draftText === row.server.translated_text &&
			canSubmitForReview( row.server )
	);
}
