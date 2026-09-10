import { __ } from '@wordpress/i18n';

import { jobStatusBadgeVariant, jobStatusLabel } from '../utils/jobs';
import StatusBadge from './StatusBadge';

interface JobStatusBadgeProps {
	status: string;
	requestedAction?: string;
}

export default function JobStatusBadge( {
	status,
	requestedAction = 'none',
}: JobStatusBadgeProps ) {
	if ( 'pause' === requestedAction ) {
		return (
			<StatusBadge
				variant="queued"
				label={ __( 'Pause requested', 'ai-multilingual' ) }
				kind={ __( 'Job status', 'ai-multilingual' ) }
			/>
		);
	}

	if ( 'cancel' === requestedAction ) {
		return (
			<StatusBadge
				variant="failed"
				label={ __( 'Cancel requested', 'ai-multilingual' ) }
				kind={ __( 'Job status', 'ai-multilingual' ) }
			/>
		);
	}

	return (
		<StatusBadge
			variant={ jobStatusBadgeVariant( status ) }
			label={ jobStatusLabel( status ) }
			kind={ __( 'Job status', 'ai-multilingual' ) }
		/>
	);
}
