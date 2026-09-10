<?php
/**
 * Site Translate chunked job batch create and run orchestration.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\SiteTranslate;

use AIMultilingual\Jobs\BackgroundTranslationBatchCoordinator;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Jobs\JobBounds;
use AIMultilingual\Translation\Store;
use WP_Error;

/**
 * Chunks Site Translate selections into bulk_translate Jobs and enqueues batches.
 */
final class SiteTranslateBatchService {

	/**
	 * Batch coordinator.
	 *
	 * @var BackgroundTranslationBatchCoordinator
	 */
	private BackgroundTranslationBatchCoordinator $batches;

	/**
	 * Job repository.
	 *
	 * @var BackgroundTranslationJobRepository
	 */
	private BackgroundTranslationJobRepository $jobs;

	/**
	 * Strategy F admission gate.
	 *
	 * @var SiteTranslateAdmissionService
	 */
	private SiteTranslateAdmissionService $admission;

	/**
	 * Builds the batch service.
	 *
	 * @param BackgroundTranslationBatchCoordinator $batches   Batch coordinator.
	 * @param BackgroundTranslationJobRepository    $jobs      Job repository.
	 * @param SiteTranslateAdmissionService         $admission Strategy F admission.
	 */
	public function __construct(
		BackgroundTranslationBatchCoordinator $batches,
		BackgroundTranslationJobRepository $jobs,
		SiteTranslateAdmissionService $admission
	) {
		$this->batches   = $batches;
		$this->jobs      = $jobs;
		$this->admission = $admission;
	}

	/**
	 * Creates chunked Site Translate Jobs for a selection.
	 *
	 * @param int[]                $post_ids    Selected post ids.
	 * @param int                  $language_id Target language id.
	 * @param array<string, mixed> $shared_args Provider/prompt/created_by/client_token args.
	 * @param string|null          $batch_id    Existing batch id for partial retry.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_jobs( array $post_ids, int $language_id, array $shared_args = array(), ?string $batch_id = null ) {
		$post_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $post_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		if ( array() === $post_ids ) {
			return new WP_Error( 'empty_selection', __( 'Select at least one object for Site Translate.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		$admit = $this->admission->validate_selection( $post_ids );
		if ( is_wp_error( $admit ) ) {
			return $admit;
		}

		$existing_source_ids = array();
		if ( null !== $batch_id && '' !== $batch_id ) {
			foreach ( $this->jobs->list_by_batch_id( $batch_id ) as $job ) {
				$existing_source_ids[ (int) $job->source_id ] = true;
			}
		}

		$pending = array_values(
			array_filter(
				$post_ids,
				static fn( int $id ): bool => ! isset( $existing_source_ids[ $id ] )
			)
		);

		if ( array() === $pending && null !== $batch_id && '' !== $batch_id ) {
			return array(
				'batch_id'        => $batch_id,
				'jobs'            => array(),
				'failed'          => array(),
				'complete'        => true,
				'created_count'   => 0,
				'attempted_count' => 0,
				'skipped_count'   => count( $post_ids ),
				'chunk_count'     => 0,
			);
		}

		if ( array() === $pending ) {
			$pending = $post_ids;
		}

		$chunks      = array_chunk( $pending, JobBounds::MAX_POSTS_PER_BULK );
		$all_jobs    = array();
		$all_failed  = array();
		$resolved_id = $batch_id;

		foreach ( $chunks as $chunk ) {
			$scope_posts = array();
			foreach ( $chunk as $source_id ) {
				$scope_posts[] = array(
					'source_type'  => Store::SOURCE_POST,
					'source_id'    => $source_id,
					'segment_keys' => array(),
				);
			}

			$result = $this->batches->create_bulk_resilient(
				$scope_posts,
				$language_id,
				array_merge(
					$shared_args,
					array(
						'source_type' => Store::SOURCE_POST,
					)
				),
				$resolved_id,
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$resolved_id = $result['batch_id'];
			$all_jobs    = array_merge( $all_jobs, $result['jobs'] );
			$all_failed  = array_merge( $all_failed, $result['failed'] );
		}

		return array(
			'batch_id'        => (string) $resolved_id,
			'jobs'            => $all_jobs,
			'failed'          => $all_failed,
			'complete'        => array() === $all_failed,
			'created_count'   => count( $all_jobs ),
			'attempted_count' => count( $pending ),
			'skipped_count'   => count( $post_ids ) - count( $pending ),
			'chunk_count'     => count( $chunks ),
		);
	}

	/**
	 * MLW1a (ADR-0034 D7) — chunked Site Translate Jobs for an N objects × M
	 * languages selection. Dedup for partial retry keys on
	 * (source_id, language_id), so a page already done in language A is still
	 * created for language B. One shared batch_id across all chunks.
	 *
	 * @param int[]                $post_ids     Selected post ids.
	 * @param int[]                $language_ids Target language ids.
	 * @param array<string, mixed> $shared_args  Provider/prompt/created_by/client_token args.
	 * @param string|null          $batch_id     Existing batch id for partial retry.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_jobs_matrix( array $post_ids, array $language_ids, array $shared_args = array(), ?string $batch_id = null ) {
		$post_ids     = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $post_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		$language_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $language_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		if ( array() === $post_ids ) {
			return new WP_Error( 'empty_selection', __( 'Select at least one object for Site Translate.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}
		if ( array() === $language_ids ) {
			return new WP_Error( 'empty_languages', __( 'Select at least one target language for Site Translate.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		$admit = $this->admission->validate_selection( $post_ids );
		if ( is_wp_error( $admit ) ) {
			return $admit;
		}

		$existing_pairs = array();
		if ( null !== $batch_id && '' !== $batch_id ) {
			foreach ( $this->jobs->list_by_batch_id( $batch_id ) as $job ) {
				$existing_pairs[ (int) $job->source_id . ':' . (int) $job->language_id ] = true;
			}
		}

		$pairs = array();
		foreach ( $post_ids as $source_id ) {
			foreach ( $language_ids as $language_id ) {
				if ( ! isset( $existing_pairs[ $source_id . ':' . $language_id ] ) ) {
					$pairs[] = array(
						'source_id'   => $source_id,
						'language_id' => $language_id,
					);
				}
			}
		}

		$requested = count( $post_ids ) * count( $language_ids );

		if ( array() === $pairs ) {
			return array(
				'batch_id'        => (string) $batch_id,
				'jobs'            => array(),
				'failed'          => array(),
				'complete'        => true,
				'created_count'   => 0,
				'attempted_count' => 0,
				'skipped_count'   => $requested,
				'chunk_count'     => 0,
			);
		}

		$chunks      = array_chunk( $pairs, JobBounds::MAX_POSTS_PER_BULK );
		$all_jobs    = array();
		$all_failed  = array();
		$resolved_id = $batch_id;

		foreach ( $chunks as $chunk ) {
			$scope_posts = array();
			foreach ( $chunk as $pair ) {
				$scope_posts[] = array(
					'source_type'  => Store::SOURCE_POST,
					'source_id'    => $pair['source_id'],
					'language_id'  => $pair['language_id'],
					'segment_keys' => array(),
				);
			}

			$result = $this->batches->create_bulk_resilient(
				$scope_posts,
				0,
				array_merge( $shared_args, array( 'source_type' => Store::SOURCE_POST ) ),
				$resolved_id,
				true
			);

			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$resolved_id = $result['batch_id'];
			$all_jobs    = array_merge( $all_jobs, $result['jobs'] );
			$all_failed  = array_merge( $all_failed, $result['failed'] );
		}

		return array(
			'batch_id'        => (string) $resolved_id,
			'jobs'            => $all_jobs,
			'failed'          => $all_failed,
			'complete'        => array() === $all_failed,
			'created_count'   => count( $all_jobs ),
			'attempted_count' => count( $pairs ),
			'skipped_count'   => $requested - count( $pairs ),
			'chunk_count'     => count( $chunks ),
		);
	}

	/**
	 * Enqueues all waiting jobs in a Site Translate batch.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_batch_now( string $batch_id ) {
		if ( '' === trim( $batch_id ) ) {
			return new WP_Error( 'invalid_batch_id', __( 'Batch id is required.', 'universal-multilingual' ), array( 'status' => 422 ) );
		}

		return $this->batches->run_batch( $batch_id );
	}
}
