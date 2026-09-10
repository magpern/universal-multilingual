import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { createBulkJobs, createJob } from '../api/jobs-api';
import type { JobType } from '../types/jobs';
import type { LanguageOption } from '../types/view-models';
import {
	boundsHelpMessage,
	jobTypeOptions,
	parsePostIdsInput,
	parseSegmentKeysInput,
	validateCreateJobInput,
} from '../utils/jobs';
import LanguageSelect from './LanguageSelect';
import PostSelect from './PostSelect';

interface CreateJobDialogProps {
	languages: LanguageOption[];
	open: boolean;
	onClose: () => void;
	onCreated: () => void;
}

export default function CreateJobDialog( {
	languages,
	open,
	onClose,
	onCreated,
}: CreateJobDialogProps ) {
	const [ jobType, setJobType ] = useState< JobType >( 'translate_missing' );
	const [ languageCode, setLanguageCode ] = useState(
		languages[ 0 ]?.code ?? ''
	);
	const [ postId, setPostId ] = useState< number | null >( null );
	const [ segmentKeysRaw, setSegmentKeysRaw ] = useState( '' );
	const [ bulkPostIdsRaw, setBulkPostIdsRaw ] = useState( '' );
	const [ clientToken, setClientToken ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	if ( ! open ) {
		return null;
	}

	const language = languages.find(
		( candidate ) => candidate.code === languageCode
	);
	const languageId = language?.language_id ?? null;
	const segmentKeys = parseSegmentKeysInput( segmentKeysRaw );
	const bulkPostIds = parsePostIdsInput( bulkPostIdsRaw );
	const validationError = validateCreateJobInput( {
		jobType,
		postId,
		languageId,
		segmentKeys,
		bulkPostIds,
	} );

	const resetAndClose = () => {
		setError( '' );
		onClose();
	};

	const handleSubmit = async () => {
		if ( validationError || ! languageId ) {
			setError( validationError || __( 'Select a target language.', 'ai-multilingual' ) );
			return;
		}

		setBusy( true );
		setError( '' );

		try {
			const shared = {
				language_id: languageId,
				...( clientToken.trim()
					? { client_token: clientToken.trim() }
					: {} ),
			};

			if ( 'bulk_translate' === jobType ) {
				await createBulkJobs( {
					...shared,
					posts: bulkPostIds.map( ( source_id ) => ( { source_id } ) ),
				} );
			} else {
				await createJob( {
					...shared,
					job_type: jobType,
					source_id: postId as number,
					...( 'translate_selected' === jobType
						? { segment_keys: segmentKeys }
						: {} ),
				} );
			}

			onCreated();
			resetAndClose();
		} catch ( unknownError ) {
			setError(
				unknownError instanceof Error
					? unknownError.message
					: __(
							'Could not create the translation job.',
							'ai-multilingual'
					  )
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Create translation job', 'ai-multilingual' ) }
			onRequestClose={ resetAndClose }
			className="aiml-create-job-dialog aiml-ui"
		>
			<p className="aiml-ui-panel aiml-ui-panel--info aiml-ui-panel__message">
				{ __(
					'For a single page, use "Translate with AI" on the editor screen. This dialog is for operators who need explicit control over job type and workload.',
					'ai-multilingual'
				) }
			</p>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Job type', 'ai-multilingual' ) }
				value={ jobType }
				options={ jobTypeOptions() }
				onChange={ ( value ) => setJobType( value as JobType ) }
				disabled={ busy }
			/>
			<p className="aiml-create-job-bounds">{ boundsHelpMessage( jobType ) }</p>

			<LanguageSelect
				languages={ languages }
				value={ languageCode }
				onChange={ ( code ) => {
					setLanguageCode( code );
					setPostId( null );
				} }
			/>

			{ 'bulk_translate' === jobType ? (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Post IDs (one per line)', 'ai-multilingual' ) }
					value={ bulkPostIdsRaw }
					onChange={ setBulkPostIdsRaw }
					rows={ 5 }
					disabled={ busy }
				/>
			) : (
				<>
					<PostSelect
						languageCode={ languageCode }
						value={ postId }
						onChange={ ( id ) => setPostId( id ) }
					/>
					{ 'translate_selected' === jobType && (
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Segment keys (one per line)', 'ai-multilingual' ) }
							value={ segmentKeysRaw }
							onChange={ setSegmentKeysRaw }
							rows={ 5 }
							disabled={ busy }
						/>
					) }
				</>
			) }

			<details className="aiml-ui-advanced">
				<summary>{ __( 'Advanced', 'ai-multilingual' ) }</summary>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Idempotency token (optional)', 'ai-multilingual' ) }
					help={ __(
						'Reuse the same token to safely retry an identical create request.',
						'ai-multilingual'
					) }
					value={ clientToken }
					onChange={ setClientToken }
					disabled={ busy }
				/>
			</details>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div className="aiml-create-job-dialog-actions">
				<Button variant="tertiary" onClick={ resetAndClose } disabled={ busy }>
					{ __( 'Cancel', 'ai-multilingual' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleSubmit }
					disabled={ busy || Boolean( validationError ) }
				>
					{ busy
						? __( 'Creating…', 'ai-multilingual' )
						: __( 'Create job', 'ai-multilingual' ) }
				</Button>
			</div>
		</Modal>
	);
}
