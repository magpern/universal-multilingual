<?php
/**
 * Trivial AIProviderInterface stub for the aiml_ai_provider filter-seam test.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Fixtures;

use AIMultilingual\Translation\AI\AIProviderInterface;
use AIMultilingual\Translation\AI\ProviderCapabilities;
use AIMultilingual\Translation\AI\ProviderResult;
use AIMultilingual\Translation\AI\TranslationBatch;

/**
 * Reports a distinctive id so a test can prove the filter override took effect.
 */
final class StubAIProvider implements AIProviderInterface {

	public function get_id(): string {
		return 'acceptance-fake';
	}

	public function get_capabilities(): ProviderCapabilities {
		return ProviderCapabilities::all();
	}

	public function test_connection() {
		return true;
	}

	public function list_models() {
		return array( 'fake-1' );
	}

	/**
	 * @param TranslationBatch $batch Domain batch.
	 * @return ProviderResult
	 */
	public function translate_batch( TranslationBatch $batch ) {
		unset( $batch );

		return new ProviderResult( array(), 0, 0, 'fake-1' );
	}
}
