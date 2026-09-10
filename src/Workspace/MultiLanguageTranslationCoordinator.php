<?php
/**
 * MLW1a — N objects × M languages translation coordinator (ADR-0034 D7).
 *
 * Builds the (object × language) cross-product, chunks it into
 * <= JobBounds::MAX_POSTS_PER_BULK calls to
 * BackgroundTranslationBatchCoordinator::create_bulk_resilient() under ONE
 * batch_id, and autostarts. Every child job carries its own language_id;
 * the jobs/lock/idempotency layer is already language-scoped so N distinct
 * (object, language) jobs never collide and a rapid duplicate submit with the
 * same client_token collapses per (object, language).
 *
 * MLW1a uses automatic=false only. This class never calls PublicationService
 * and never changes a language's publication status; a bulk operation
 * targeting an already-published language requires acknowledge_published
 * (ADR-0034 D8).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

use AIMultilingual\Jobs\BackgroundTranslationBatchCoordinator;
use AIMultilingual\Jobs\JobBounds;
use AIMultilingual\Jobs\JobTypes;
use AIMultilingual\Language\Languages;
use AIMultilingual\Translation\Store;
use WP_Error;

/**
 * Cross-product translation batch builder.
 */
final class MultiLanguageTranslationCoordinator {

	/**
	 * Accepted request job types mapped to the jobs layer's bulk job types.
	 */
	private const JOB_TYPE_MAP = array(
		'missing' => JobTypes::BULK_TRANSLATE,
		'stale'   => JobTypes::RETRANSLATE_STALE,
		'machine' => JobTypes::RETRANSLATE_MACHINE,
	);

	/**
	 * Wires the collaborators.
	 *
	 * @param BackgroundTranslationBatchCoordinator $batches   Bulk job coordinator.
	 * @param Languages                             $languages Language registry.
	 */
	public function __construct(
		private readonly BackgroundTranslationBatchCoordinator $batches,
		private readonly Languages $languages
	) {
	}

	/**
	 * Plans and creates the cross-product batch.
	 *
	 * @param array<int, int> $object_ids            Canonical post ids (already authorized by the caller).
	 * @param array<int, int> $language_ids          Target language ids.
	 * @param string          $job_type              'missing' | 'stale' | 'machine'.
	 * @param int             $created_by            Acting user id.
	 * @param string|null     $client_token          Idempotency token for this user action.
	 * @param bool            $acknowledge_published Operator acknowledged published-language visibility.
	 * @return array<string, mixed>|WP_Error
	 */
	public function plan_and_create(
		array $object_ids,
		array $language_ids,
		string $job_type,
		int $created_by,
		?string $client_token,
		bool $acknowledge_published
	) {
		$object_ids = $this->clean_ids( $object_ids );
		if ( array() === $object_ids ) {
			return new WP_Error(
				'aiml_no_objects',
				__( 'Select at least one object to translate.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		if ( ! isset( self::JOB_TYPE_MAP[ $job_type ] ) ) {
			return new WP_Error(
				'aiml_invalid_job_type',
				__( 'Unknown translation job type.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		$languages = $this->resolve_target_languages( $language_ids );
		if ( $languages instanceof WP_Error ) {
			return $languages;
		}

		$published = array();
		foreach ( $languages as $language ) {
			if ( Languages::STATUS_PUBLISHED === (string) ( $language->status ?? '' ) ) {
				$published[] = (string) ( $language->code ?? $language->language_id );
			}
		}
		if ( array() !== $published && ! $acknowledge_published ) {
			return new WP_Error(
				'aiml_acknowledge_published_required',
				sprintf(
					/* translators: %s: comma-separated language names/codes. */
					__(
						'%s already published. New translations created for these languages may become visible to visitors immediately under the site\'s existing publication policy. Re-submit with acknowledgement to continue.',
						'universal-multilingual'
					),
					implode( ', ', $published )
				),
				array(
					'status'              => 400,
					'published_languages' => $published,
				)
			);
		}

		$language_ids = array_map(
			static fn( object $language ): int => (int) $language->language_id,
			$languages
		);

		// Cross-product: one child job scope per (object × language), each
		// carrying its own language_id (the coordinator honours it).
		$scopes = array();
		foreach ( $object_ids as $object_id ) {
			foreach ( $language_ids as $language_id ) {
				$scopes[] = array(
					'source_type'  => Store::SOURCE_POST,
					'source_id'    => $object_id,
					'language_id'  => $language_id,
					'segment_keys' => array(),
				);
			}
		}

		$shared = array(
			'job_type'   => self::JOB_TYPE_MAP[ $job_type ],
			'created_by' => $created_by,
		);
		if ( null !== $client_token && '' !== $client_token ) {
			$shared['client_token'] = $client_token;
		}

		$chunks   = array_chunk( $scopes, JobBounds::MAX_POSTS_PER_BULK );
		$batch_id = null;
		$created  = 0;
		$failed   = array();

		foreach ( $chunks as $chunk ) {
			$result = $this->batches->create_bulk_resilient(
				$chunk,
				0,
				$shared,
				$batch_id,
				true
			);
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$batch_id = (string) $result['batch_id'];
			$created += count( $result['jobs'] );
			$failed   = array_merge( $failed, $result['failed'] );
		}

		$autostarted = false;
		if ( null !== $batch_id ) {
			$run         = $this->batches->run_batch( $batch_id );
			$autostarted = ! ( $run instanceof WP_Error );
		}

		return array(
			'batch_id'    => $batch_id,
			'planned'     => array(
				'objects'    => count( $object_ids ),
				'languages'  => count( $language_ids ),
				'operations' => count( $scopes ),
				'chunks'     => count( $chunks ),
			),
			'created'     => $created,
			'failed'      => array_values( $failed ),
			'autostarted' => $autostarted,
		);
	}

	/**
	 * Normalizes an id list: absint, positive, unique, re-indexed.
	 *
	 * @param array<int, mixed> $ids Raw ids.
	 * @return array<int, int>
	 */
	private function clean_ids( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Resolves and validates the requested target languages: each must exist,
	 * must not be the default/source language, and must not be disabled.
	 *
	 * @param array<int, int> $language_ids Requested ids.
	 * @return array<int, object>|WP_Error
	 */
	private function resolve_target_languages( array $language_ids ) {
		$language_ids = $this->clean_ids( $language_ids );
		if ( array() === $language_ids ) {
			return new WP_Error(
				'aiml_no_languages',
				__( 'Select at least one target language.', 'universal-multilingual' ),
				array( 'status' => 422 )
			);
		}

		$resolved = array();
		foreach ( $language_ids as $language_id ) {
			$language = $this->languages->find( $language_id );
			if ( null === $language ) {
				return new WP_Error(
					'aiml_invalid_language',
					sprintf(
						/* translators: %d: language id. */
						__( 'Unknown target language %d.', 'universal-multilingual' ),
						$language_id
					),
					array( 'status' => 422 )
				);
			}

			if ( ! empty( $language->is_default ) ) {
				return new WP_Error(
					'aiml_source_language',
					__( 'The source language cannot be a translation target.', 'universal-multilingual' ),
					array( 'status' => 422 )
				);
			}

			if ( Languages::STATUS_DISABLED === (string) ( $language->status ?? '' ) ) {
				return new WP_Error(
					'aiml_disabled_language',
					sprintf(
						/* translators: %s: language code. */
						__( 'Language %s is disabled.', 'universal-multilingual' ),
						(string) ( $language->code ?? $language_id )
					),
					array( 'status' => 422 )
				);
			}

			$resolved[] = $language;
		}

		return $resolved;
	}
}
