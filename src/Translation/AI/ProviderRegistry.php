<?php
/**
 * Provider discovery and active resolution (F11 §4.5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Translation\AI;

use AIMultilingual\Settings;

/**
 * Registers AI providers and resolves the active implementation.
 */
final class ProviderRegistry {

	/**
	 * Registered providers keyed by id.
	 *
	 * @var array<string, AIProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Settings accessor.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fallback when unconfigured.
	 *
	 * @var AIProviderInterface
	 */
	private AIProviderInterface $fallback;

	/**
	 * Builds the registry.
	 *
	 * @param Settings                 $settings Settings.
	 * @param AIProviderInterface|null $fallback Fallback provider (Null).
	 */
	public function __construct( Settings $settings, ?AIProviderInterface $fallback = null ) {
		$this->settings = $settings;
		$this->fallback = $fallback ?? new NullAIProvider();
		$this->register( $this->fallback );
	}

	/**
	 * Registers or replaces a provider by id.
	 *
	 * @param AIProviderInterface $provider Provider instance.
	 */
	public function register( AIProviderInterface $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Returns all registered providers.
	 *
	 * @return array<string, AIProviderInterface>
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * Returns one provider by id.
	 *
	 * @param string $id Provider id.
	 */
	public function get( string $id ): ?AIProviderInterface {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Whether AI translation is configured enough to attempt (enabled, a
	 * provider selected, and that provider has a stored credential). A UI hint
	 * only — the create path still fails closed if the credential is invalid.
	 *
	 * @param Settings $settings Settings accessor.
	 */
	public static function is_ai_configured( Settings $settings ): bool {
		$data = $settings->get();

		if ( empty( $data['ai_enabled'] ) ) {
			return false;
		}

		$provider = (string) ( $data['ai_provider'] ?? '' );
		if ( '' === $provider || NullAIProvider::ID === $provider ) {
			return false;
		}

		$row = ( $data['ai_providers'] ?? array() )[ $provider ] ?? array();

		return is_array( $row ) && '' !== (string) ( $row['api_key_encrypted'] ?? '' );
	}

	/**
	 * Resolves the active provider from settings, or the fallback.
	 */
	public function active(): AIProviderInterface {
		$data     = $this->settings->get();
		$enabled  = ! empty( $data['ai_enabled'] );
		$provider = (string) ( $data['ai_provider'] ?? '' );

		if ( ! $enabled || '' === $provider ) {
			return $this->fallback;
		}

		$resolved = $this->get( $provider );
		if ( null === $resolved || NullAIProvider::ID === $resolved->get_id() ) {
			return $this->fallback;
		}

		return $resolved;
	}
}
