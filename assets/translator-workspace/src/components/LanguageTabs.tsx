import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useRef } from '@wordpress/element';
import type { KeyboardEvent } from 'react';

import type { ObjectLanguageStatus } from '../types/view-models';
import { nextTabIndex } from '../utils/language-tabs-nav';
import {
	languageTabHint,
	stateBadgeVariant,
	stateLabel,
} from '../utils/object-language-status';

export interface LanguageTabDescriptor {
	code: string;
	name: string;
}

export interface LanguageTabsProps {
	/** Tabs to render, in display order. */
	tabs: LanguageTabDescriptor[];
	/** Active language code. */
	active: string;
	/** Called with the newly selected language code. */
	onSelect: ( code: string ) => void;
	/**
	 * Server-authoritative status per language code — drives the per-tab badge.
	 * The component never derives state from counts (ADR-0034 C2).
	 */
	statusByCode?: Record< string, ObjectLanguageStatus >;
	label?: string;
}

/**
 * MLW1a accessible language tab strip (WP2). Switching a tab changes the active
 * language only — the caller keeps the same object loaded. Tabs wrap; the strip
 * never relies on colour alone (icon dot + text via the shared badge).
 * @param root0
 * @param root0.tabs
 * @param root0.active
 * @param root0.onSelect
 * @param root0.statusByCode
 * @param root0.label
 */
export default function LanguageTabs( {
	tabs,
	active,
	onSelect,
	statusByCode,
	label,
}: LanguageTabsProps ) {
	const refs = useRef< Record< string, HTMLButtonElement | null > >( {} );

	const focusTab = useCallback( ( code: string ) => {
		refs.current[ code ]?.focus();
	}, [] );

	const onKeyDown = useCallback(
		( event: KeyboardEvent< HTMLButtonElement >, index: number ) => {
			const nextIndex = nextTabIndex( event.key, index, tabs.length );
			if ( null === nextIndex ) {
				return;
			}
			event.preventDefault();
			const target = tabs[ nextIndex ];
			if ( target ) {
				onSelect( target.code );
				focusTab( target.code );
			}
		},
		[ tabs, onSelect, focusTab ]
	);

	return (
		<div
			className="aiml-language-tabs"
			role="tablist"
			aria-label={
				label ?? __( 'Translation languages', 'ai-multilingual' )
			}
		>
			{ tabs.map( ( tab, index ) => {
				const isActive = tab.code === active;
				const status = statusByCode?.[ tab.code ];
				const badgeVariant = status
					? stateBadgeVariant( status.state )
					: '';
				const hint = status ? languageTabHint( status ) : '';

				return (
					<button
						key={ tab.code }
						type="button"
						role="tab"
						id={ `aiml-language-tab-${ tab.code }` }
						aria-selected={ isActive }
						aria-controls={ `aiml-language-panel-${ tab.code }` }
						tabIndex={ isActive ? 0 : -1 }
						ref={ ( el ) => {
							refs.current[ tab.code ] = el;
						} }
						className={ `aiml-language-tabs__tab${
							isActive ? ' is-active' : ''
						}` }
						onClick={ () => onSelect( tab.code ) }
						onKeyDown={ ( event ) => onKeyDown( event, index ) }
					>
						<span className="aiml-language-tabs__name">
							{ tab.name || tab.code }
						</span>
						{ status && (
							<span
								className={ `aiml-ui-badge aiml-ui-badge--${ badgeVariant }` }
							>
								<span
									className="aiml-ui-badge__dot"
									aria-hidden="true"
								/>
								<span className="screen-reader-text">
									{ sprintf(
										/* translators: 1: language, 2: state label */
										__( '%1$s: %2$s', 'ai-multilingual' ),
										tab.name || tab.code,
										stateLabel( status.state )
									) }
								</span>
								<span aria-hidden="true">
									{ hint || stateLabel( status.state ) }
								</span>
							</span>
						) }
					</button>
				);
			} ) }
		</div>
	);
}
