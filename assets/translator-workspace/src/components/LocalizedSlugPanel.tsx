import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Panel, PanelBody, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	WorkspaceRequestError,
	clearSlugCandidate,
	ensureSlugCandidate,
	fetchSlugRouteView,
	generateSlugCandidate,
	publishSlugRoute,
	saveSlugCandidate,
	type SlugRouteView,
} from '../api/workspace-api';

interface LocalizedSlugPanelProps {
	postId: number;
	languageCode: string;
	/** Bump to re-run auto-generation (e.g. after "Translate with AI"). */
	refreshToken?: number;
}

function statusLine( view: SlugRouteView ): { text: string; tone: 'info' | 'success' | 'warning' } {
	const language = view.language_name || __( 'this language', 'ai-multilingual' );
	switch ( view.state ) {
		case 'published':
			return { text: __( 'Published.', 'ai-multilingual' ), tone: 'success' };
		case 'ready_to_publish':
			return { text: __( 'Ready to publish.', 'ai-multilingual' ), tone: 'info' };
		case 'ready_when_language_published':
			return {
				text: sprintf(
					/* translators: %s: language name */
					__( 'Ready when %s is published.', 'ai-multilingual' ),
					language
				),
				tone: 'info',
			};
		case 'url_conflict':
			return {
				text:
					view.route_publication_blocked_reason ||
					__( 'URL conflict — another page already uses this path.', 'ai-multilingual' ),
				tone: 'warning',
			};
		case 'needs_attention':
			return {
				text:
					view.route_publication_blocked_reason ||
					__( 'This URL needs attention before it can be published.', 'ai-multilingual' ),
				tone: 'warning',
			};
		case 'no_translation':
			return {
				text: __( 'Translate the page to get a localized URL.', 'ai-multilingual' ),
				tone: 'info',
			};
		default:
			return { text: __( 'No localized URL yet.', 'ai-multilingual' ), tone: 'info' };
	}
}

export default function LocalizedSlugPanel( {
	postId,
	languageCode,
	refreshToken = 0,
}: LocalizedSlugPanelProps ) {
	const [ view, setView ] = useState< SlugRouteView | null >( null );
	const [ draft, setDraft ] = useState( '' );
	const [ editing, setEditing ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const apply = useCallback( ( next: SlugRouteView ) => {
		setView( next );
		setDraft( next.slug_candidate || '' );
	}, [] );

	const load = useCallback(
		async ( ensure: boolean ) => {
			if ( ! postId || ! languageCode ) {
				return;
			}
			setBusy( true );
			setError( '' );
			try {
				apply(
					ensure
						? await ensureSlugCandidate( postId, languageCode )
						: await fetchSlugRouteView( postId, languageCode )
				);
			} catch ( err ) {
				// Auto-generate is best effort — fall back to a plain read.
				if ( ensure ) {
					try {
						apply( await fetchSlugRouteView( postId, languageCode ) );
					} catch ( inner ) {
						setError(
							inner instanceof WorkspaceRequestError
								? inner.userMessage
								: __( 'Could not load the localized URL.', 'ai-multilingual' )
						);
					}
				} else {
					setError(
						err instanceof WorkspaceRequestError
							? err.userMessage
							: __( 'Could not load the localized URL.', 'ai-multilingual' )
					);
				}
			} finally {
				setBusy( false );
			}
		},
		[ apply, languageCode, postId ]
	);

	useEffect( () => {
		void load( true );
	}, [ load, refreshToken ] );

	const run = async ( action: () => Promise< SlugRouteView >, okMessage: string ) => {
		setBusy( true );
		setError( '' );
		setMessage( '' );
		try {
			apply( await action() );
			setMessage( okMessage );
			setEditing( false );
		} catch ( err ) {
			setError(
				err instanceof WorkspaceRequestError
					? err.userMessage
					: __( 'The localized URL action failed.', 'ai-multilingual' )
			);
		} finally {
			setBusy( false );
		}
	};

	if ( ! postId || ! languageCode ) {
		return null;
	}

	const heading = view?.language_name
		? sprintf(
				/* translators: %s: language name */
				__( 'URL for %s', 'ai-multilingual' ),
				view.language_name
		  )
		: __( 'Localized URL', 'ai-multilingual' );

	const url = view?.localized_url || '';
	const line = view ? statusLine( view ) : null;
	const isManual = view?.slug_origin === 'manual';

	return (
		<Panel className="aiml-workspace-localized-slug">
			<PanelBody title={ heading } initialOpen={ true }>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ message && ! error && (
					<Notice status="success" isDismissible={ false }>
						{ message }
					</Notice>
				) }

				{ url && ! editing && (
					<p className="aiml-workspace-localized-slug-url">
						{ view?.localized_url_absolute ? (
							<a href={ url } target="_blank" rel="noreferrer">
								{ url }
							</a>
						) : (
							<code>{ url }</code>
						) }
						{ isManual && (
							<span className="aiml-workspace-localized-slug-custom">
								{ ' · ' }
								{ __( 'Custom', 'ai-multilingual' ) }
							</span>
						) }
					</p>
				) }

				{ editing && (
					<div className="aiml-workspace-localized-slug-edit">
						<TextControl
							label={ __( 'URL segment', 'ai-multilingual' ) }
							value={ draft }
							onChange={ setDraft }
							disabled={ busy }
						/>
						<div className="aiml-workspace-localized-slug-actions">
							<Button
								variant="primary"
								disabled={ busy || draft.trim() === '' }
								onClick={ () =>
									void run(
										() => saveSlugCandidate( postId, languageCode, draft ),
										__( 'URL saved.', 'ai-multilingual' )
									)
								}
							>
								{ __( 'Save', 'ai-multilingual' ) }
							</Button>
							<Button
								variant="tertiary"
								disabled={ busy }
								onClick={ () => {
									setDraft( view?.slug_candidate || '' );
									setEditing( false );
								} }
							>
								{ __( 'Cancel', 'ai-multilingual' ) }
							</Button>
						</div>
					</div>
				) }

				{ ! editing && line && (
					<Notice status={ line.tone } isDismissible={ false }>
						{ line.text }
					</Notice>
				) }

				{ ! editing && view?.title_slug_stale && ! isManual && (
					<p className="aiml-workspace-localized-slug-stale">
						{ __(
							'The translated title changed.',
							'ai-multilingual'
						) }{ ' ' }
						<Button
							variant="link"
							disabled={ busy }
							onClick={ () =>
								void run(
									() => generateSlugCandidate( postId, languageCode ),
									__( 'URL updated to match the title.', 'ai-multilingual' )
								)
							}
						>
							{ __( 'Update URL', 'ai-multilingual' ) }
						</Button>
					</p>
				) }

				{ ! editing && (
					<div className="aiml-workspace-localized-slug-actions">
						{ view?.can_edit_slug !== false && (
							<Button
								variant="secondary"
								disabled={ busy }
								onClick={ () => {
									setDraft( view?.slug_candidate || '' );
									setEditing( true );
								} }
							>
								{ __( 'Edit', 'ai-multilingual' ) }
							</Button>
						) }
						{ view?.can_publish_route && (
							<Button
								variant="primary"
								disabled={ busy }
								onClick={ () =>
									void run(
										() => publishSlugRoute( postId, languageCode ),
										__( 'URL published.', 'ai-multilingual' )
									)
								}
							>
								{ __( 'Publish URL', 'ai-multilingual' ) }
							</Button>
						) }
					</div>
				) }

				<details className="aiml-workspace-localized-slug-advanced aiml-ui-advanced">
					<summary>{ __( 'Advanced URL controls', 'ai-multilingual' ) }</summary>
					<p className="description">
						{ __( 'Origin:', 'ai-multilingual' ) } { view?.slug_origin || '—' }
						{ ' · ' }
						{ __( 'Effective path:', 'ai-multilingual' ) }{ ' ' }
						{ view?.localized_path || view?.active_route_slug || '—' }
						{ ' · ' }
						{ __( 'Sync:', 'ai-multilingual' ) } { view?.route_sync_state || '—' }
						{ ' · ' }
						{ __( 'Route:', 'ai-multilingual' ) } { view?.active_route_status || '—' }
					</p>
					<div className="aiml-workspace-localized-slug-actions">
						<Button
							variant="secondary"
							disabled={ busy || view?.can_generate === false }
							onClick={ () =>
								void run(
									() => generateSlugCandidate( postId, languageCode ),
									__( 'URL regenerated from the title.', 'ai-multilingual' )
								)
							}
						>
							{ __( 'Regenerate', 'ai-multilingual' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							disabled={ busy }
							onClick={ () =>
								void run(
									() => clearSlugCandidate( postId, languageCode ),
									__( 'URL cleared.', 'ai-multilingual' )
								)
							}
						>
							{ __( 'Clear', 'ai-multilingual' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ busy || view?.can_publish_route === false }
							onClick={ () =>
								void run(
									() => publishSlugRoute( postId, languageCode ),
									__( 'Route published.', 'ai-multilingual' )
								)
							}
						>
							{ __( 'Publish route', 'ai-multilingual' ) }
						</Button>
						<Button variant="link" disabled={ busy } onClick={ () => void load( false ) }>
							{ __( 'Refresh', 'ai-multilingual' ) }
						</Button>
					</div>
				</details>
			</PanelBody>
		</Panel>
	);
}
