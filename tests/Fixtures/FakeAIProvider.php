<?php
/**
 * Deterministic fake AI provider for automated tests (AIT1 / WP9).
 *
 * Test-autoload scope only. It is NOT shipped and NOT referenced by any
 * production code path. Browser/DEV acceptance uses a separate, self-contained
 * MU-plugin (it does not load this class).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Fixtures;

use AIMultilingual\Translation\AI\AIProviderInterface;
use AIMultilingual\Translation\AI\ProviderCapabilities;
use AIMultilingual\Translation\AI\ProviderResult;
use AIMultilingual\Translation\AI\TranslationBatch;
use WP_Error;

/**
 * Deterministic translations plus opt-in failure simulation.
 */
final class FakeAIProvider implements AIProviderInterface {

	public const MODE_OK         = 'ok';
	public const MODE_TIMEOUT    = 'timeout';
	public const MODE_RATE_LIMIT = 'rate_limit';
	public const MODE_MALFORMED  = 'malformed';
	public const MODE_MISSING_ID = 'missing_id';
	public const MODE_PARTIAL    = 'partial';

	/**
	 * @var string
	 */
	private string $mode;

	/**
	 * How many calls have been made (so a mode can fail once then succeed).
	 *
	 * @var int
	 */
	private int $calls = 0;

	/**
	 * When > 0, the first N calls fail per $mode and later calls succeed.
	 *
	 * @var int
	 */
	private int $fail_first;

	/**
	 * @param string $mode      One of the MODE_* constants.
	 * @param int    $fail_first Fail only the first N calls, then behave as OK.
	 */
	public function __construct( string $mode = self::MODE_OK, int $fail_first = 0 ) {
		$this->mode      = $mode;
		$this->fail_first = $fail_first;
	}

	public function get_id(): string {
		return 'fake';
	}

	public function get_capabilities(): ProviderCapabilities {
		return ProviderCapabilities::all();
	}

	public function test_connection() {
		return self::MODE_OK === $this->mode
			? true
			: new WP_Error( 'fake_unavailable', 'Fake provider is in a failure mode.' );
	}

	public function list_models() {
		return array( 'fake-1' );
	}

	/**
	 * Deterministic translation: "[<target>] <source>", preserving placeholder
	 * tokens and absolute URLs verbatim.
	 */
	public function translate_batch( TranslationBatch $batch ) {
		++$this->calls;

		$active = self::MODE_OK !== $this->mode
			&& ( 0 === $this->fail_first || $this->calls <= $this->fail_first );

		if ( $active ) {
			switch ( $this->mode ) {
				case self::MODE_TIMEOUT:
					return new WP_Error(
						'fake_timeout',
						'Request timed out.',
						array(
							'status'      => 504,
							'error_class' => ProviderResult::ERROR_RETRYABLE,
						)
					);
				case self::MODE_RATE_LIMIT:
					return new WP_Error(
						'fake_rate_limit',
						'Rate limit exceeded.',
						array(
							'status'      => 429,
							'retry_after' => 1,
							'error_class' => ProviderResult::ERROR_RETRYABLE,
						)
					);
				case self::MODE_MALFORMED:
					return new WP_Error(
						'fake_malformed',
						'Provider returned a non-JSON body.',
						array(
							'status'      => 502,
							'error_class' => ProviderResult::ERROR_RETRYABLE,
						)
					);
			}
		}

		$target   = $batch->target_locale;
		$segments = array();
		$index    = 0;

		foreach ( $batch->segments as $segment ) {
			++$index;

			if ( self::MODE_MISSING_ID === $this->mode && $active ) {
				// Drop this segment's id entirely — validation must reject.
				continue;
			}
			if ( self::MODE_PARTIAL === $this->mode && $active && 1 !== $index ) {
				// Translate only the first segment.
				continue;
			}

			$segments[] = array(
				'segment_key'     => $segment->segment_key,
				'translated_text' => sprintf( '[%s] %s', $target, $segment->source_text ),
			);
		}

		return new ProviderResult( $segments, 4, 4, 'fake-1' );
	}
}
