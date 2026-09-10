import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	checkSiteTranslateAdmission,
	createSiteTranslateJobs,
	fetchSiteTranslateObjects,
	publishSiteTranslateRoutes,
	runSiteTranslateBatch,
} from '../api/site-translate-api';
import LanguageChecklist from './LanguageChecklist';
import LanguageSelect from './LanguageSelect';
import { aiTranslateModeOptions } from '../utils/jobs';
import {
	defaultSelection,
	normalizeSelection,
	operationCount,
} from '../utils/multi-language-selection';
import type { AiTranslateMode } from '../types/jobs';
import type { LanguageOption } from '../types/view-models';
import type {
	SiteTranslateObjectRow,
	SiteTranslateRouteOutcome,
} from '../types/site-translate';
import {
	blockedReasonLabel,
	coverageFilterOptions,
	coverageSummaryLabel,
	matchesCoverageFilter,
	routeOutcomeLabel,
	siteTranslateChunkMessage,
	type SiteTranslateCoverageFilter,
} from '../utils/site-translate';

interface SiteTranslatePanelProps {
	languages: LanguageOption[];
	canManageJobs: boolean;
	canRunJobs: boolean;
	onOpenJobsBatch: ( batchId: string ) => void;
}

const PER_PAGE = 20;

function newClientToken(): string {
	if ( typeof crypto !== 'undefined' && 'randomUUID' in crypto ) {
		return crypto.randomUUID();
	}
	return `st-${ Date.now() }`;
}

export default function SiteTranslatePanel( {
	languages,
	canManageJobs,
	canRunJobs,
	onOpenJobsBatch,
}: SiteTranslatePanelProps ) {
	const [ languageCode, setLanguageCode ] = useState(
		languages[ 0 ]?.code ?? ''
	);
	const [ search, setSearch ] = useState( '' );
	const [ postType, setPostType ] = useState( '' );
	const [ coverageFilter, setCoverageFilter ] =
		useState< SiteTranslateCoverageFilter >( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ rows, setRows ] = useState< SiteTranslateObjectRow[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ strategyFValid, setStrategyFValid ] = useState( true );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ selected, setSelected ] = useState< Set< number > >( () => {
		try {
			const raw = new URLSearchParams( window.location.search ).get(
				'aiml_st_ids'
			);
			if ( ! raw ) {
				return new Set();
			}
			return new Set(
				raw
					.split( ',' )
					.map( ( value ) => Number.parseInt( value, 10 ) )
					.filter(
						( value ) => Number.isInteger( value ) && value > 0
					)
			);
		} catch ( e ) {
			return new Set();
		}
	} );
	// MLW1a (WP7): the objects list still browses one language; the CREATE
	// action targets this checklist of languages (N objects × M languages).
	const [ selectedLanguageCodes, setSelectedLanguageCodes ] = useState<
		string[]
	>( () => {
		try {
			const raw = new URLSearchParams( window.location.search ).get(
				'aiml_st_langs'
			);
			if ( raw ) {
				return normalizeSelection( raw.split( ',' ), languages );
			}
		} catch ( e ) {
			// fall through to the default
		}
		return defaultSelection( languages );
	} );
	const [ acknowledgePublished, setAcknowledgePublished ] = useState( false );
	const [ mode, setMode ] = useState< AiTranslateMode >( 'missing' );
	const [ batchId, setBatchId ] = useState( '' );
	const [ clientToken, setClientToken ] = useState( () => newClientToken() );
	const [ batchIncomplete, setBatchIncomplete ] = useState( false );
	const [ createBusy, setCreateBusy ] = useState( false );
	const [ runBusy, setRunBusy ] = useState( false );
	const [ routesBusy, setRoutesBusy ] = useState( false );
	const [ routeOutcomes, setRouteOutcomes ] = useState<
		SiteTranslateRouteOutcome[]
	>( [] );

	const language = useMemo(
		() =>
			languages.find( ( candidate ) => candidate.code === languageCode ),
		[ languageCode, languages ]
	);
	const languageId = language?.language_id ?? 0;

	const filteredRows = useMemo(
		() =>
			rows.filter( ( row ) =>
				matchesCoverageFilter( row.coverage, coverageFilter )
			),
		[ coverageFilter, rows ]
	);

	const selectedIds = useMemo(
		() => Array.from( selected ).filter( ( id ) => id > 0 ),
		[ selected ]
	);

	const selectedLanguageIds = useMemo(
		() =>
			selectedLanguageCodes
				.map(
					( code ) =>
						languages.find( ( l ) => l.code === code )
							?.language_id ?? 0
				)
				.filter( ( id ) => id > 0 ),
		[ selectedLanguageCodes, languages ]
	);
	const publishedSelected = useMemo(
		() =>
			languages
				.filter(
					( l ) =>
						selectedLanguageCodes.includes( l.code ) &&
						l.status === 'published'
				)
				.map( ( l ) => l.native_name || l.name || l.code ),
		[ languages, selectedLanguageCodes ]
	);
	const operations = operationCount(
		selectedIds.length,
		selectedLanguageIds.length
	);

	useEffect( () => {
		try {
			const url = new URL( window.location.href );
			if ( selectedLanguageCodes.length > 0 ) {
				url.searchParams.set(
					'aiml_st_langs',
					selectedLanguageCodes.join( ',' )
				);
			} else {
				url.searchParams.delete( 'aiml_st_langs' );
			}
			window.history.replaceState( {}, '', url.toString() );
		} catch ( e ) {
			// URL persistence is best-effort.
		}
	}, [ selectedLanguageCodes ] );

	const allVisibleSelected = useMemo(
		() =>
			filteredRows.length > 0 &&
			filteredRows.every( ( row ) => selected.has( row.post_id ) ),
		[ filteredRows, selected ]
	);

	const loadObjects = useCallback( async () => {
		if ( languageId <= 0 ) {
			return;
		}

		setLoading( true );
		setError( '' );

		try {
			const response = await fetchSiteTranslateObjects( {
				languageId,
				page,
				perPage: PER_PAGE,
				search: search.trim(),
				postType: postType || undefined,
			} );
			setRows( response.items );
			setTotal( response.total );
			setStrategyFValid( response.strategy_f_valid );
		} catch ( unknownError ) {
			setRows( [] );
			setTotal( 0 );
			setError(
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Could not load Site Translate objects.',
							'ai-multilingual'
					  )
			);
		} finally {
			setLoading( false );
		}
	}, [ languageId, page, postType, search ] );

	useEffect( () => {
		void loadObjects();
	}, [ loadObjects ] );

	useEffect( () => {
		setPage( 1 );
	}, [ coverageFilter, languageCode, postType, search ] );

	const toggleRow = ( postId: number, checked: boolean ) => {
		setSelected( ( current ) => {
			const next = new Set( current );
			if ( checked ) {
				next.add( postId );
			} else {
				next.delete( postId );
			}
			return next;
		} );
	};

	const toggleAllVisible = ( checked: boolean ) => {
		setSelected( ( current ) => {
			const next = new Set( current );
			for ( const row of filteredRows ) {
				if ( checked ) {
					next.add( row.post_id );
				} else {
					next.delete( row.post_id );
				}
			}
			return next;
		} );
	};

	const handleCreateJobs = async () => {
		if (
			! canManageJobs ||
			0 === selectedIds.length ||
			0 === selectedLanguageIds.length
		) {
			return;
		}

		if ( publishedSelected.length > 0 && ! acknowledgePublished ) {
			setError(
				sprintf(
					/* translators: %s: comma-separated language names */
					__(
						'%s already published. Tick the acknowledgement to continue — new translations for these languages may become visible to visitors immediately.',
						'ai-multilingual'
					),
					publishedSelected.join( ', ' )
				)
			);
			return;
		}

		setCreateBusy( true );
		setError( '' );
		setMessage( '' );

		try {
			await checkSiteTranslateAdmission( selectedIds );
			const bulkJobType =
				aiTranslateModeOptions().find(
					( option ) => option.value === mode
				)?.bulkJobType ?? 'bulk_translate';
			const response = await createSiteTranslateJobs( {
				postIds: selectedIds,
				languageIds: selectedLanguageIds,
				acknowledgePublished:
					publishedSelected.length > 0 ? true : undefined,
				clientToken,
				jobType: bulkJobType,
				autostart: true,
				batchId: batchIncomplete ? batchId : undefined,
			} );

			setBatchId( response.batch_id );
			setBatchIncomplete( ! response.complete );
			setMessage(
				response.complete
					? sprintf(
							/* translators: 1: created jobs, 2: batch id */
							__(
								'Created %1$d jobs in batch %2$s.',
								'ai-multilingual'
							),
							response.created_count,
							response.batch_id
					  )
					: sprintf(
							/* translators: 1: created jobs, 2: failed count */
							__(
								'Batch incomplete: %1$d jobs created, %2$d failures. Retry preserves successful jobs.',
								'ai-multilingual'
							),
							response.created_count,
							response.failed.length
					  )
			);

			if ( response.complete ) {
				setClientToken( newClientToken() );
			}

			onOpenJobsBatch( response.batch_id );
			void loadObjects();
		} catch ( unknownError ) {
			setError(
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Could not create Site Translate jobs.',
							'ai-multilingual'
					  )
			);
		} finally {
			setCreateBusy( false );
		}
	};

	const handleRunBatch = async () => {
		if ( ! canRunJobs || ! batchId ) {
			return;
		}

		setRunBusy( true );
		setError( '' );
		setMessage( '' );

		try {
			const response = await runSiteTranslateBatch( batchId );
			setMessage(
				sprintf(
					/* translators: 1: enqueued count, 2: skipped count */
					__(
						'Enqueued %1$d waiting jobs (%2$d skipped — not waiting).',
						'ai-multilingual'
					),
					response.enqueued_job_ids.length,
					response.skipped_job_ids.length
				)
			);
			onOpenJobsBatch( batchId );
		} catch ( unknownError ) {
			setError(
				unknownError instanceof Error
					? unknownError.message
					: __( 'Could not run batch.', 'ai-multilingual' )
			);
		} finally {
			setRunBusy( false );
		}
	};

	const handlePublishRoutes = async () => {
		if ( languageId <= 0 || 0 === selectedIds.length ) {
			return;
		}

		setRoutesBusy( true );
		setError( '' );
		setMessage( '' );

		try {
			const response = await publishSiteTranslateRoutes( {
				postIds: selectedIds,
				languageId,
			} );
			setRouteOutcomes( response.outcomes );
			setMessage(
				sprintf(
					/* translators: 1: success count, 2: total count */
					__(
						'Localized routes: %1$d succeeded of %2$d objects.',
						'ai-multilingual'
					),
					response.success_count,
					response.total
				)
			);
		} catch ( unknownError ) {
			setError(
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Could not generate or publish localized routes.',
							'ai-multilingual'
					  )
			);
		} finally {
			setRoutesBusy( false );
		}
	};

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	return (
		<div className="aiml-site-translate-panel">
			<p className="description">
				{ __(
					'Select visitor-facing pages, posts, or products, review translation coverage, create chunked background jobs, then publish segments and localized routes in the recommended language order.',
					'ai-multilingual'
				) }
			</p>

			{ ! strategyFValid && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Strategy F is incomplete. Classic-only selections can still run; Gutenberg block bodies require full Strategy F configuration (Settings → Strategy F diagnostics).',
						'ai-multilingual'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" onDismiss={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ message && (
				<Notice status="success" onDismiss={ () => setMessage( '' ) }>
					{ message }
				</Notice>
			) }

			<LanguageChecklist
				languages={ languages }
				selected={ selectedLanguageCodes }
				onChange={ setSelectedLanguageCodes }
				legend={ __(
					'Target languages for this run',
					'ai-multilingual'
				) }
			/>

			<div className="aiml-site-translate-toolbar">
				<LanguageSelect
					languages={ languages }
					value={ languageCode }
					onChange={ setLanguageCode }
				/>
				<TextControl
					label={ __( 'Search', 'ai-multilingual' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					label={ __( 'Post type', 'ai-multilingual' ) }
					value={ postType }
					options={ [
						{
							label: __( 'All types', 'ai-multilingual' ),
							value: '',
						},
						{
							label: __( 'Pages', 'ai-multilingual' ),
							value: 'page',
						},
						{
							label: __( 'Posts', 'ai-multilingual' ),
							value: 'post',
						},
						{
							label: __( 'Products', 'ai-multilingual' ),
							value: 'product',
						},
					] }
					onChange={ setPostType }
				/>
				<SelectControl
					label={ __( 'Coverage filter', 'ai-multilingual' ) }
					value={ coverageFilter }
					options={ coverageFilterOptions() }
					onChange={ ( value ) =>
						setCoverageFilter(
							value as SiteTranslateCoverageFilter
						)
					}
				/>
			</div>

			<div className="aiml-site-translate-prerun">
				<p className="aiml-site-translate-prerun__count">
					{ sprintf(
						/* translators: 1: object count, 2: language count, 3: total operations */
						__(
							'%1$d pages × %2$d languages = %3$d translations',
							'ai-multilingual'
						),
						selectedIds.length,
						selectedLanguageIds.length,
						operations
					) }
				</p>
				{ publishedSelected.length > 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: comma-separated language names */
							__(
								"%s already published. New translations created for these languages may become visible to visitors immediately under the site's existing publication policy.",
								'ai-multilingual'
							),
							publishedSelected.join( ', ' )
						) }
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'I understand and want to continue',
								'ai-multilingual'
							) }
							checked={ acknowledgePublished }
							onChange={ setAcknowledgePublished }
						/>
					</Notice>
				) }
			</div>

			<div className="aiml-site-translate-actions aiml-ui-actionbar">
				<Button
					className="aiml-ui-actionbar__primary"
					variant="primary"
					disabled={
						! canManageJobs ||
						createBusy ||
						0 === selectedIds.length ||
						0 === selectedLanguageIds.length ||
						( publishedSelected.length > 0 &&
							! acknowledgePublished )
					}
					isBusy={ createBusy }
					onClick={ () => void handleCreateJobs() }
				>
					{ batchIncomplete
						? __( 'Retry failed items', 'ai-multilingual' )
						: __(
								'Translate selected with AI',
								'ai-multilingual'
						  ) }
				</Button>
				<div className="aiml-ui-actionbar__mode">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Mode', 'ai-multilingual' ) }
						hideLabelFromVision
						value={ mode }
						disabled={ createBusy }
						onChange={ ( value ) =>
							setMode( value as AiTranslateMode )
						}
						options={ aiTranslateModeOptions().map(
							( option ) => ( {
								value: option.value,
								label: option.label,
							} )
						) }
					/>
				</div>
				<p className="aiml-ui-actionbar__hint">
					{ siteTranslateChunkMessage( selectedIds.length ) }
				</p>
				<Button
					variant="tertiary"
					disabled={ ! canRunJobs || runBusy || ! batchId }
					isBusy={ runBusy }
					onClick={ () => void handleRunBatch() }
				>
					{ __( 'Run batch now', 'ai-multilingual' ) }
				</Button>
				<Button
					variant="secondary"
					disabled={ routesBusy || 0 === selectedIds.length }
					isBusy={ routesBusy }
					onClick={ () => void handlePublishRoutes() }
				>
					{ __( 'Generate & publish routes', 'ai-multilingual' ) }
				</Button>
				{ batchId && (
					<Button
						variant="link"
						onClick={ () => onOpenJobsBatch( batchId ) }
					>
						{ sprintf(
							/* translators: %s: batch id */
							__( 'View batch %s in Jobs', 'ai-multilingual' ),
							batchId
						) }
					</Button>
				) }
			</div>

			{ loading ? (
				<Spinner />
			) : (
				<table className="widefat striped aiml-site-translate-table">
					<thead>
						<tr>
							<th>
								<CheckboxControl
									label={ __(
										'Select all visible',
										'ai-multilingual'
									) }
									checked={ allVisibleSelected }
									onChange={ toggleAllVisible }
								/>
							</th>
							<th>{ __( 'Title', 'ai-multilingual' ) }</th>
							<th>{ __( 'Type', 'ai-multilingual' ) }</th>
							<th>{ __( 'Coverage', 'ai-multilingual' ) }</th>
							<th>{ __( 'Notes', 'ai-multilingual' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ 0 === filteredRows.length && (
							<tr>
								<td colSpan={ 5 }>
									{ __(
										'No objects match the current filters.',
										'ai-multilingual'
									) }
								</td>
							</tr>
						) }
						{ filteredRows.map( ( row ) => (
							<tr key={ row.post_id }>
								<td>
									<CheckboxControl
										label={ sprintf(
											/* translators: %s: post title */
											__(
												'Select %s',
												'ai-multilingual'
											),
											row.post_title
										) }
										checked={ selected.has( row.post_id ) }
										onChange={ ( checked ) =>
											toggleRow( row.post_id, checked )
										}
									/>
								</td>
								<td>{ row.post_title }</td>
								<td>{ row.post_type }</td>
								<td>
									{ coverageSummaryLabel( row.coverage ) }
								</td>
								<td>
									{ row.coverage.blocked_or_unsupported.map(
										( reason ) => (
											<span
												key={ `${ row.post_id }-${ reason }` }
												className="aiml-site-translate-note"
											>
												{ blockedReasonLabel( reason ) }
											</span>
										)
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="aiml-site-translate-pagination">
				<Button
					variant="secondary"
					disabled={ page <= 1 || loading }
					onClick={ () =>
						setPage( ( current ) => Math.max( 1, current - 1 ) )
					}
				>
					{ __( 'Previous', 'ai-multilingual' ) }
				</Button>
				<span>
					{ sprintf(
						/* translators: 1: current page, 2: total pages */
						__( 'Page %1$d of %2$d', 'ai-multilingual' ),
						page,
						totalPages
					) }
				</span>
				<Button
					variant="secondary"
					disabled={ page >= totalPages || loading }
					onClick={ () =>
						setPage( ( current ) =>
							Math.min( totalPages, current + 1 )
						)
					}
				>
					{ __( 'Next', 'ai-multilingual' ) }
				</Button>
			</div>

			{ routeOutcomes.length > 0 && (
				<div className="aiml-site-translate-routes">
					<h3>
						{ __( 'Localized URL outcomes', 'ai-multilingual' ) }
					</h3>
					<ul>
						{ routeOutcomes.map( ( outcome ) => (
							<li
								key={ `${ outcome.post_id }-${ outcome.outcome }` }
							>
								<strong>
									{ routeOutcomeLabel( outcome.outcome ) }
								</strong>
								{ ': ' }
								{ outcome.message }
								{ ' (#'.concat(
									String( outcome.post_id ),
									')'
								) }
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}
