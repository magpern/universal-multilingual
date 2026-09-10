import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	LanguageOption,
	ReviewObjectCard as ReviewObjectCardModel,
	ReviewQueueItem,
} from '../types/view-models';
import {
	isQueueItemSelectable,
	queueItemKey,
	readyLanguageCodes,
} from '../utils/review-queue';
import { stateLabel } from '../utils/object-language-status';
import LanguageTabs from './LanguageTabs';
import ObjectLanguagesBar from './ObjectLanguagesBar';
import ReviewQueueRow from './ReviewQueueRow';

interface ReviewObjectCardProps {
	card: ReviewObjectCardModel;
	languages: LanguageOption[];
	canTranslate: boolean;
	busy: boolean;
	selectedKeys: Set< string >;
	onToggleSelect: ( key: string, checked: boolean ) => void;
	onApproveRow: ( item: ReviewQueueItem, languageCode: string ) => void;
	onRejectRow: ( item: ReviewQueueItem, languageCode: string ) => void;
	onApproveReadyLanguages: (
		card: ReviewObjectCardModel,
		languageCodes: string[]
	) => void;
	onOpenInEditor: ( postId: number, languageCode: string ) => void;
	onOpenInOperations?: (
		translationId: number,
		languageCode: string
	) => void;
}

/**
 * MLW1a (WP6) — one Review Queue card per content object, with every target
 * language together. The object summary (ObjectLanguagesBar) always shows a
 * forgotten / incomplete / needs-attention / ready / complete breakdown, so a
 * single-language view can never hide a forgotten language. All per-language
 * state comes straight from the server.
 * @param root0
 * @param root0.card
 * @param root0.languages
 * @param root0.canTranslate
 * @param root0.busy
 * @param root0.selectedKeys
 * @param root0.onToggleSelect
 * @param root0.onApproveRow
 * @param root0.onRejectRow
 * @param root0.onApproveReadyLanguages
 * @param root0.onOpenInEditor
 * @param root0.onOpenInOperations
 */
export default function ReviewObjectCard( {
	card,
	languages,
	canTranslate,
	busy,
	selectedKeys,
	onToggleSelect,
	onApproveRow,
	onRejectRow,
	onApproveReadyLanguages,
	onOpenInEditor,
	onOpenInOperations,
}: ReviewObjectCardProps ) {
	const noun = card.object_noun || __( 'Post', 'ai-multilingual' );
	const nounLower = noun.toLowerCase();
	const tabs = card.languages.map( ( group ) => ( {
		code: group.language_code,
		name: group.language_name || group.language_code,
	} ) );
	const [ activeCode, setActiveCode ] = useState( tabs[ 0 ]?.code ?? '' );
	const activeGroup =
		card.languages.find(
			( group ) => group.language_code === activeCode
		) ?? card.languages[ 0 ];

	const statusByCode = Object.fromEntries(
		card.languages.map( ( group ) => [
			group.language_code,
			group.summary,
		] )
	);

	const readyCodes = readyLanguageCodes( card );

	return (
		<section
			className="aiml-review-object-card"
			aria-label={ sprintf(
				/* translators: %s: object title */
				__( 'Review %s', 'ai-multilingual' ),
				card.post_title
			) }
		>
			<header className="aiml-review-object-card__header">
				<h3 className="aiml-review-object-card__title">
					{ card.post_title }
				</h3>
				<p className="aiml-review-object-card__meta">
					{ noun }
					{ card.post_id ? ` · #${ card.post_id }` : '' }
				</p>
			</header>

			<ObjectLanguagesBar summary={ card.object_languages_summary } />

			<div className="aiml-review-object-card__actions">
				<Button
					variant="primary"
					disabled={ busy || 0 === readyCodes.length }
					onClick={ () =>
						onApproveReadyLanguages( card, readyCodes )
					}
				>
					{ sprintf(
						/* translators: %d: ready language count */
						__(
							'Approve all ready languages (%d)',
							'ai-multilingual'
						),
						readyCodes.length
					) }
				</Button>
				{ canTranslate && activeGroup && (
					<Button
						variant="link"
						onClick={ () =>
							onOpenInEditor(
								card.post_id,
								activeGroup.language_code
							)
						}
					>
						{ __( 'Open in Workspace', 'ai-multilingual' ) }
					</Button>
				) }
				{ card.edit_link && (
					<a
						className="components-button is-link"
						href={ card.edit_link }
					>
						{ sprintf(
							/* translators: %s: object noun */
							__( 'Edit %s', 'ai-multilingual' ),
							nounLower
						) }
					</a>
				) }
			</div>

			{ tabs.length > 0 && (
				<LanguageTabs
					tabs={ tabs }
					active={ activeGroup?.language_code ?? '' }
					onSelect={ setActiveCode }
					statusByCode={ statusByCode }
					label={ sprintf(
						/* translators: %s: object title */
						__( 'Languages for %s', 'ai-multilingual' ),
						card.post_title
					) }
				/>
			) }

			{ activeGroup && (
				<div
					id={ `aiml-language-panel-${ activeGroup.language_code }` }
					role="tabpanel"
					aria-labelledby={ `aiml-language-tab-${ activeGroup.language_code }` }
				>
					<p
						className="aiml-review-object-card__language-state"
						aria-live="polite"
					>
						{ sprintf(
							/* translators: 1: language, 2: state label */
							__( '%1$s: %2$s', 'ai-multilingual' ),
							activeGroup.language_name ||
								activeGroup.language_code,
							stateLabel( activeGroup.summary.state )
						) }
					</p>

					{ 0 === activeGroup.items.length ? (
						<p>
							{ __(
								'No fields awaiting review in this language.',
								'ai-multilingual'
							) }
						</p>
					) : (
						<table className="aiml-review-queue-table widefat striped">
							<thead>
								<tr>
									<th scope="col">
										<span className="screen-reader-text">
											{ __(
												'Select',
												'ai-multilingual'
											) }
										</span>
									</th>
									<th scope="col">
										{ __( 'Field', 'ai-multilingual' ) }
									</th>
									<th scope="col">
										{ __( 'Source', 'ai-multilingual' ) }
									</th>
									<th scope="col">
										{ __(
											'Translation',
											'ai-multilingual'
										) }
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
								{ activeGroup.items.map( ( item ) => (
									<ReviewQueueRow
										key={ queueItemKey( item ) }
										item={ item }
										languages={ languages }
										selected={ selectedKeys.has(
											queueItemKey( item )
										) }
										selectable={ isQueueItemSelectable(
											item
										) }
										canTranslate={ canTranslate }
										hideObjectColumn
										onToggleSelect={ onToggleSelect }
										onApprove={ ( row ) =>
											onApproveRow(
												row,
												activeGroup.language_code
											)
										}
										onReject={ ( row ) =>
											onRejectRow(
												row,
												activeGroup.language_code
											)
										}
										onOpenInEditor={ onOpenInEditor }
										onOpenInOperations={
											onOpenInOperations
										}
									/>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			) }
		</section>
	);
}
