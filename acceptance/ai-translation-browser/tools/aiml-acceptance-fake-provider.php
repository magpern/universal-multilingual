<?php
/**
 * Plugin Name: AIML Acceptance — Fake AI Provider
 * Description: DEV-ONLY. Injects a deterministic AI provider through the
 *   aiml_ai_provider filter so the AI translation acceptance suite never calls
 *   a paid provider. Installed by acceptance/ai-translation-browser and removed
 *   on teardown. NOT part of the plugin. Do NOT deploy to production.
 *
 * @package AIMultilingual\Acceptance
 */

defined( 'ABSPATH' ) || exit;

/**
 * The provider class is defined lazily inside the filter callback: this MU-plugin
 * loads before the Universal Multilingual autoloader, so the interface does not
 * exist yet at file scope.
 *
 * @param mixed $provider The settings-resolved provider.
 * @return mixed
 */
function aiml_acceptance_fake_provider( $provider ) {
	if ( ! interface_exists( '\AIMultilingual\Translation\AI\AIProviderInterface' ) ) {
		return $provider;
	}

	if ( ! class_exists( 'AIML_Acceptance_Fake_Provider', false ) ) {
		/**
		 * Self-contained deterministic provider. Behaviour is switched by the
		 * `aiml_acceptance_fake_mode` option so the browser suite can drive
		 * failure scenarios through WP-CLI without redeploying.
		 */
		class AIML_Acceptance_Fake_Provider implements \AIMultilingual\Translation\AI\AIProviderInterface {

			public function get_id(): string {
				return 'acceptance-fake';
			}

			public function get_capabilities(): \AIMultilingual\Translation\AI\ProviderCapabilities {
				return \AIMultilingual\Translation\AI\ProviderCapabilities::all();
			}

			public function test_connection() {
				return true;
			}

			public function list_models() {
				return array( 'acceptance-fake-1' );
			}

			/**
			 * @param \AIMultilingual\Translation\AI\TranslationBatch $batch Domain batch.
			 * @return \AIMultilingual\Translation\AI\ProviderResult|\WP_Error
			 */
			public function translate_batch( \AIMultilingual\Translation\AI\TranslationBatch $batch ) {
				$mode = (string) get_option( 'aiml_acceptance_fake_mode', 'ok' );

				if ( 'rate_limit' === $mode ) {
					return new \WP_Error(
						'aiml_rate_limited',
						'Acceptance fake: rate limited.',
						array(
							'status'      => 429,
							'retry_after' => 1,
							'error_class' => \AIMultilingual\Translation\AI\ProviderResult::ERROR_RETRYABLE,
						)
					);
				}
				if ( 'timeout' === $mode ) {
					return new \WP_Error(
						'aiml_ai_http_error',
						'Acceptance fake: upstream timeout.',
						array(
							'status'      => 504,
							'error_class' => \AIMultilingual\Translation\AI\ProviderResult::ERROR_RETRYABLE,
						)
					);
				}

				$target   = $batch->target_locale;
				$segments = array();
				foreach ( $batch->segments as $segment ) {
					$segments[] = array(
						'segment_key'     => $segment->segment_key,
						'translated_text' => sprintf( '[%s] %s', $target, $segment->source_text ),
					);
				}

				return new \AIMultilingual\Translation\AI\ProviderResult( $segments, 4, 4, 'acceptance-fake-1' );
			}
		}
	}

	return new AIML_Acceptance_Fake_Provider();
}

add_filter( 'aiml_ai_provider', 'aiml_acceptance_fake_provider', 99 );
