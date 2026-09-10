import { __, sprintf } from '@wordpress/i18n';

import type { ObjectLanguagesSummary } from '../types/view-models';
import { languagesReadyLabel } from '../utils/object-language-status';

export interface ObjectLanguagesBarProps {
	summary: ObjectLanguagesSummary;
}

/**
 * MLW1a "N / M languages ready" strip (WP2). Reused verbatim above the language
 * tabs, on Review Queue object cards and on Site Translate rows. Every number
 * comes straight from the server aggregate; a forgotten language is called out
 * explicitly so it can never hide behind a single-language view.
 * @param root0
 * @param root0.summary
 */
export default function ObjectLanguagesBar( {
	summary,
}: ObjectLanguagesBarProps ) {
	const parts: string[] = [];
	if ( summary.ready_count > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: ready language count */
				__( '%d ready', 'ai-multilingual' ),
				summary.ready_count
			)
		);
	}
	if ( summary.translating_count > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: translating language count */
				__( '%d translating', 'ai-multilingual' ),
				summary.translating_count
			)
		);
	}
	if ( summary.incomplete_count > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: incomplete language count */
				__( '%d incomplete', 'ai-multilingual' ),
				summary.incomplete_count
			)
		);
	}
	if ( summary.not_translated_count > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: not-translated language count */
				__( '%d not translated', 'ai-multilingual' ),
				summary.not_translated_count
			)
		);
	}

	return (
		<div
			className={ `aiml-object-languages-bar${
				summary.forgotten ? ' has-forgotten' : ''
			}` }
		>
			<strong className="aiml-object-languages-bar__ready">
				{ languagesReadyLabel( summary ) }
			</strong>
			{ parts.length > 0 && (
				<span className="aiml-object-languages-bar__breakdown">
					{ parts.join( ' · ' ) }
				</span>
			) }
			{ summary.forgotten && (
				<span
					className="aiml-object-languages-bar__forgotten"
					role="status"
				>
					{ sprintf(
						/* translators: %s: comma-separated language codes */
						__( 'Not translated yet: %s', 'ai-multilingual' ),
						summary.forgotten_languages.join( ', ' )
					) }
				</span>
			) }
		</div>
	);
}
