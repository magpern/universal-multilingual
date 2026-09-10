import { CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { LanguageOption } from '../types/view-models';
import {
	isAllSelected,
	isSomeSelected,
	toggleAll,
	toggleLanguage,
} from '../utils/multi-language-selection';

export interface LanguageChecklistProps {
	/** Eligible target languages — already server-filtered (no source/disabled). */
	languages: LanguageOption[];
	/** Currently selected language codes. */
	selected: string[];
	/** Called with the next selection whenever a box is toggled. */
	onChange: ( next: string[] ) => void;
	disabled?: boolean;
	/** Optional legend/label above the list. */
	legend?: string;
}

/**
 * MLW1a multi-language selector (WP2). "All languages" master checkbox plus one
 * checkbox per eligible target language. The default selection (all checked) is
 * the caller's responsibility via `defaultSelection()`.
 * @param root0
 * @param root0.languages
 * @param root0.selected
 * @param root0.onChange
 * @param root0.disabled
 * @param root0.legend
 */
export default function LanguageChecklist( {
	languages,
	selected,
	onChange,
	disabled = false,
	legend,
}: LanguageChecklistProps ) {
	const all = isAllSelected( selected, languages );
	const some = isSomeSelected( selected, languages );

	return (
		<fieldset className="aiml-language-checklist" disabled={ disabled }>
			<legend className="aiml-language-checklist__legend">
				{ legend ?? __( 'Languages', 'ai-multilingual' ) }
			</legend>

			<div className="aiml-language-checklist__all">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'All languages', 'ai-multilingual' ) }
					checked={ all }
					indeterminate={ some }
					onChange={ () =>
						onChange( toggleAll( selected, languages ) )
					}
				/>
			</div>

			<ul className="aiml-language-checklist__list">
				{ languages.map( ( language ) => (
					<li key={ language.code }>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={
								language.native_name ||
								language.name ||
								language.code
							}
							checked={ selected.includes( language.code ) }
							onChange={ () =>
								onChange(
									toggleLanguage(
										selected,
										language.code,
										languages
									)
								)
							}
						/>
					</li>
				) ) }
			</ul>

			<p className="aiml-language-checklist__count" aria-live="polite">
				{ sprintf(
					/* translators: 1: selected language count, 2: total target language count */
					__( '%1$d of %2$d languages selected', 'ai-multilingual' ),
					selected.length,
					languages.length
				) }
			</p>
		</fieldset>
	);
}
