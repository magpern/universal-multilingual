import { __, sprintf } from '@wordpress/i18n';

/**
 * The one badge implementation for the workspace (ADR-0031). Renders a
 * `.aiml-ui-badge` with a modifier from the shared admin design system.
 *
 * Execution status and review status are DIFFERENT axes — render two of these
 * side by side ("Completed" + "Needs review"), never one merged state.
 */
export interface StatusBadgeProps {
	/** Shared modifier, e.g. "translating", "complete", "failed", "needs-review". */
	variant: string;
	/** Visible label. */
	label: string;
	/** Screen-reader prefix, e.g. "Job status" or "Review". */
	kind?: string;
}

export default function StatusBadge( { variant, label, kind }: StatusBadgeProps ) {
	return (
		<span
			className={ `aiml-ui-badge aiml-ui-badge--${ variant }` }
			aria-label={
				kind
					? sprintf(
							/* translators: 1: axis name, 2: status label */
							__( '%1$s: %2$s', 'ai-multilingual' ),
							kind,
							label
					  )
					: label
			}
		>
			<span className="aiml-ui-badge__dot" aria-hidden="true" />
			{ label }
		</span>
	);
}
