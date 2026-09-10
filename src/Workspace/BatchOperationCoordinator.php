<?php
/**
 * Bulk save and translate iteration for the workspace.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

use WP_Error;
use WP_Post;

/**
 * Owns bulk iteration, caps, and partial-result aggregation.
 */
final class BatchOperationCoordinator {

	public const BATCH_LIMIT = 50;

	/**
	 * Injected dependency.
	 *
	 * @var WorkspaceService
	 */
	private WorkspaceService $workspace;

	/**
	 * Injected dependency.
	 *
	 * @var TranslationService
	 */
	private TranslationService $translation;

	/**
	 * Segment assembly for the shared AI-write eligibility check.
	 *
	 * @var SegmentAssembler|null
	 */
	private ?SegmentAssembler $assembler;

	/**
	 * Builds the collaborator.
	 *
	 * @param WorkspaceService      $workspace   Workspace command facade.
	 * @param TranslationService    $translation Auto-translate service.
	 * @param SegmentAssembler|null $assembler  Segment assembly (manual-protection gate).
	 */
	public function __construct(
		WorkspaceService $workspace,
		TranslationService $translation,
		?SegmentAssembler $assembler = null
	) {
		$this->workspace   = $workspace;
		$this->translation = $translation;
		$this->assembler   = $assembler;
	}

	/**
	 * Saves multiple segments with per-item optimistic locking.
	 *
	 * @param WP_Post                          $post        Canonical post.
	 * @param int                              $language_id Target language id.
	 * @param array<int, array<string, mixed>> $items       Save payloads.
	 * @return array<string, mixed>|WP_Error
	 */
	public function save_batch( WP_Post $post, int $language_id, array $items ) {
		if ( count( $items ) > self::BATCH_LIMIT ) {
			return new WP_Error(
				'aiml_batch_too_large',
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Batch size exceeds the limit of %d segments.', 'universal-multilingual' ),
					self::BATCH_LIMIT
				),
				array( 'status' => 422 )
			);
		}

		$succeeded = array();
		$failed    = array();

		foreach ( $items as $item ) {
			$key = (string) ( $item['segment_key'] ?? '' );
			if ( '' === $key ) {
				$failed[] = array(
					'segment_key' => '',
					'code'        => 'aiml_invalid_segment',
					'message'     => __( 'Segment key is required.', 'universal-multilingual' ),
				);
				continue;
			}

			try {
				$expected_hash = array_key_exists( 'expected_translation_hash', $item )
					? (string) $item['expected_translation_hash']
					: null;
				$succeeded[]   = $this->workspace->save_segment(
					$post,
					$language_id,
					$key,
					(string) ( $item['translated_text'] ?? '' ),
					(string) ( $item['source_hash'] ?? '' ),
					(string) ( $item['status'] ?? '' ),
					(string) ( $item['save_origin'] ?? '' ),
					(int) ( $item['tm_id'] ?? 0 ),
					$expected_hash
				);
			} catch ( WorkspaceConflictException $conflict ) {
				$failed[] = array(
					'segment_key' => $key,
					'code'        => 'aiml_source_hash_mismatch',
					'message'     => $conflict->getMessage(),
					'segments'    => $conflict->segments(),
				);
			} catch ( WorkspaceTranslationConflictException $conflict ) {
				$failed[] = array(
					'segment_key' => $key,
					'code'        => 'aiml_translation_hash_mismatch',
					'message'     => $conflict->getMessage(),
					'segments'    => $conflict->segments(),
				);
			} catch ( WorkspaceQAException $qa_exception ) {
				$failed[] = array(
					'segment_key' => $key,
					'code'        => 'aiml_qa_blocked',
					'message'     => $qa_exception->getMessage(),
					'qa'          => $qa_exception->qa()->to_array(),
				);
			} catch ( \InvalidArgumentException $exception ) {
				$failed[] = array(
					'segment_key' => $key,
					'code'        => 'aiml_invalid_segment',
					'message'     => $exception->getMessage(),
				);
			}
		}

		return array(
			'status'   => array() === $failed ? 'completed' : 'partial',
			'segments' => $succeeded,
			'errors'   => $failed,
		);
	}

	/**
	 * Translates multiple segments via TranslationService.
	 *
	 * @param WP_Post               $post                       Canonical post.
	 * @param int                   $language_id                Target language id.
	 * @param array<int, string>    $segment_keys               Segment keys.
	 * @param array<string, string> $expected_translation_hashes Map of segment_key => translation_hash.
	 * @return array<string, mixed>|WP_Error
	 */
	public function translate_batch(
		WP_Post $post,
		int $language_id,
		array $segment_keys,
		array $expected_translation_hashes = array()
	) {
		if ( count( $segment_keys ) > self::BATCH_LIMIT ) {
			return new WP_Error(
				'aiml_batch_too_large',
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Batch size exceeds the limit of %d segments.', 'universal-multilingual' ),
					self::BATCH_LIMIT
				),
				array( 'status' => 422 )
			);
		}

		$succeeded = array();
		$failed    = array();
		$skipped   = array();

		foreach ( $segment_keys as $segment_key ) {
			$key = (string) $segment_key;
			if ( '' === $key ) {
				$failed[] = array(
					'segment_key' => '',
					'code'        => 'aiml_invalid_segment',
					'message'     => __( 'Segment key is required.', 'universal-multilingual' ),
				);
				continue;
			}

			if ( $this->is_manually_protected( $post, $language_id, $key ) ) {
				$skipped[] = array(
					'segment_key' => $key,
					'code'        => 'aiml_manual_translation_protected',
					'message'     => __(
						'Skipped: this translation was edited or reviewed by a person and is never replaced by AI.',
						'universal-multilingual'
					),
				);
				continue;
			}

			$hash   = $expected_translation_hashes[ $key ] ?? null;
			$result = $this->translation->translate_segment(
				$post,
				$language_id,
				$key,
				true,
				null !== $hash ? (string) $hash : null
			);
			if ( $result instanceof WP_Error ) {
				$failed[] = array(
					'segment_key' => $key,
					'code'        => $result->get_error_code(),
					'message'     => $result->get_error_message(),
				);
				continue;
			}

			$succeeded[] = $result;
		}

		if ( array() !== $failed && array() === $succeeded ) {
			$status = 'failed';
		} elseif ( array() === $failed ) {
			$status = 'completed';
		} else {
			$status = 'partial';
		}

		return array(
			'status'   => $status,
			'job_id'   => null,
			'segments' => $succeeded,
			'errors'   => $failed,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Whether a person owns this segment's translation, so an AI batch action
	 * must skip it (shared policy — ADR-0031). Fail open only when segment
	 * assembly is unavailable (unit-test wiring); the Jobs path still guards.
	 *
	 * @param WP_Post $post        Canonical post.
	 * @param int     $language_id Target language id.
	 * @param string  $segment_key Segment key.
	 */
	private function is_manually_protected( WP_Post $post, int $language_id, string $segment_key ): bool {
		if ( null === $this->assembler ) {
			return false;
		}

		$assembled = $this->assembler->assemble_one( $post, $language_id, $segment_key );
		if ( null === $assembled ) {
			return false;
		}

		return TranslatableSegmentEligibility::is_protected( $assembled );
	}
}
