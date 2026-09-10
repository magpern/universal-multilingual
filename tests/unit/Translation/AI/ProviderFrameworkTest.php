<?php
/**
 * ProviderCapabilities and CredentialVault unit tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Translation\AI;

use AIMultilingual\Settings;
use AIMultilingual\Translation\AI\CredentialVault;
use AIMultilingual\Translation\AI\NullAIProvider;
use AIMultilingual\Translation\AI\PromptProfileRegistry;
use AIMultilingual\Translation\AI\ProviderCapabilities;
use AIMultilingual\Translation\AI\ProviderRegistry;
use AIMultilingual\Translation\AI\ProviderResult;
use AIMultilingual\Translation\AI\ProviderSegment;
use AIMultilingual\Translation\AI\Providers\DeepSeekProvider;
use AIMultilingual\Translation\AI\Providers\OpenAIProvider;
use AIMultilingual\Translation\AI\TranslationBatch;
use PHPUnit\Framework\TestCase;

/**
 * F11 WP5 — provider framework pure coverage.
 */
final class ProviderFrameworkTest extends TestCase {

	public function test_null_provider_reports_zero_capabilities(): void {
		$provider = new NullAIProvider();
		$caps     = $provider->get_capabilities();

		$this->assertSame( NullAIProvider::ID, $provider->get_id() );
		$this->assertFalse( $caps->translate );
		$this->assertFalse( $caps->supports_profile( PromptProfileRegistry::IMPROVE ) );
	}

	public function test_openai_without_key_has_no_capabilities(): void {
		$provider = new OpenAIProvider( '' );
		$this->assertSame( OpenAIProvider::ID, $provider->get_id() );
		$this->assertSame( ProviderCapabilities::none()->to_array(), $provider->get_capabilities()->to_array() );
	}

	public function test_openai_with_key_reports_all_capabilities(): void {
		$provider = new OpenAIProvider( 'sk-test' );
		$this->assertTrue( $provider->get_capabilities()->supports_profile( PromptProfileRegistry::FORMAL ) );
		$this->assertTrue( $provider->get_capabilities()->batch );
	}

	public function test_credential_vault_round_trips(): void {
		$vault = new CredentialVault( 'unit-test-salt-material' );
		$enc   = $vault->encrypt( 'sk-secret-key' );

		$this->assertTrue( $vault->is_encrypted( $enc ) );
		$this->assertSame( 'sk-secret-key', $vault->decrypt( $enc ) );
	}

	public function test_registry_falls_back_when_disabled(): void {
		$settings = new Settings(
			array(
				'ai_enabled'  => false,
				'ai_provider' => 'openai',
			)
		);
		$registry = new ProviderRegistry( $settings );
		$registry->register( new OpenAIProvider( 'sk-test' ) );

		$this->assertSame( NullAIProvider::ID, $registry->active()->get_id() );
	}

	public function test_is_ai_configured_requires_enabled_provider_and_key(): void {
		$this->assertFalse(
			ProviderRegistry::is_ai_configured(
				new Settings(
					array(
						'ai_enabled'  => false,
						'ai_provider' => 'openai',
					)
				)
			)
		);
		$this->assertFalse(
			ProviderRegistry::is_ai_configured(
				new Settings(
					array(
						'ai_enabled'  => true,
						'ai_provider' => '',
					)
				)
			),
			'a provider must be selected'
		);
		$this->assertFalse(
			ProviderRegistry::is_ai_configured(
				new Settings(
					array(
						'ai_enabled'  => true,
						'ai_provider' => 'openai',
					)
				)
			),
			'a stored credential is required'
		);
		$this->assertTrue(
			ProviderRegistry::is_ai_configured(
				new Settings(
					array(
						'ai_enabled'   => true,
						'ai_provider'  => 'openai',
						'ai_providers' => array(
							'openai' => array( 'api_key_encrypted' => 'aiml1:abc' ),
						),
					)
				)
			)
		);
	}

	public function test_registry_resolves_active_openai(): void {
		$settings = new Settings(
			array(
				'ai_enabled'  => true,
				'ai_provider' => 'openai',
			)
		);
		$registry = new ProviderRegistry( $settings );
		$registry->register( new OpenAIProvider( 'sk-test' ) );

		$this->assertSame( OpenAIProvider::ID, $registry->active()->get_id() );
	}

	public function test_aiml_ai_provider_filter_overrides_the_active_provider(): void {
		$settings = new Settings(
			array(
				'ai_enabled'  => false,
				'ai_provider' => '',
			)
		);
		$registry = new ProviderRegistry( $settings );
		$fake     = new \AIMultilingual\Tests\Fixtures\StubAIProvider();

		add_filter( 'aiml_ai_provider', static fn() => $fake );
		$this->assertSame( 'acceptance-fake', $registry->active()->get_id() );
		remove_all_filters( 'aiml_ai_provider' );

		$this->assertSame( NullAIProvider::ID, $registry->active()->get_id() );
	}

	public function test_openai_list_models_via_injected_http(): void {
		$http = static function ( string $method, string $url, array $args ) {
			unset( $method, $url, $args );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"data":[{"id":"gpt-4o-mini"},{"id":"gpt-4o"}]}',
			);
		};

		$provider = new OpenAIProvider( 'sk-test', 'gpt-4o-mini', null, $http );
		$models   = $provider->list_models();

		$this->assertIsArray( $models );
		$this->assertContains( 'gpt-4o-mini', $models );
		$this->assertTrue( true === $provider->test_connection() );
	}

	public function test_gpt5_omits_temperature_on_translate(): void {
		$seen = array();
		$http = static function ( string $method, string $url, array $args ) use ( &$seen ) {
			unset( $method, $url );
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$seen = is_array( $body ) ? $body : array();

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"choices":[{"message":{"content":"Hej"}}],"usage":{"prompt_tokens":3,"completion_tokens":1}}',
			);
		};

		$provider = new OpenAIProvider( 'sk-test', 'gpt-5-mini', null, $http );
		$result   = $provider->translate_batch(
			new TranslationBatch(
				'en',
				'sv',
				'default',
				'1',
				'',
				array( new ProviderSegment( 'title', 'Hello', 'plain' ) )
			)
		);

		$this->assertInstanceOf( ProviderResult::class, $result );
		$this->assertArrayNotHasKey( 'temperature', $seen );
		$this->assertSame( 'gpt-5-mini', $seen['model'] ?? '' );
	}

	public function test_gpt4o_includes_temperature_on_translate(): void {
		$seen = array();
		$http = static function ( string $method, string $url, array $args ) use ( &$seen ) {
			unset( $method, $url );
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$seen = is_array( $body ) ? $body : array();

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"choices":[{"message":{"content":"Hej"}}],"usage":{"prompt_tokens":3,"completion_tokens":1}}',
			);
		};

		$provider = new OpenAIProvider( 'sk-test', 'gpt-4o-mini', null, $http, 0.4, 512 );
		$result   = $provider->translate_batch(
			new TranslationBatch(
				'en',
				'sv',
				'default',
				'1',
				'',
				array( new ProviderSegment( 'title', 'Hello', 'plain' ) )
			)
		);

		$this->assertInstanceOf( ProviderResult::class, $result );
		$this->assertSame( 0.4, $seen['temperature'] ?? null );
		$this->assertSame( 512, $seen['max_tokens'] ?? null );
	}

	public function test_settings_sanitize_keeps_encrypted_key_shape(): void {
		$clean = Settings::sanitize(
			array(
				'ai_enabled'           => '1',
				'ai_provider'          => 'openai',
				'ai_model'             => 'gpt-4o-mini',
				'ai_api_key_encrypted' => 'aiml1:abc',
				'ai_api_key'           => 'sk-plaintext-must-drop',
			)
		);

		$this->assertTrue( $clean['ai_enabled'] );
		$this->assertSame( 'openai', $clean['ai_provider'] );
		$this->assertSame( 'gpt-4o-mini', $clean['ai_model'] );
		$this->assertSame( 'aiml1:abc', $clean['ai_api_key_encrypted'] );
		$this->assertArrayNotHasKey( 'ai_api_key', $clean );
		$this->assertSame( 'gpt-4o-mini', $clean['ai_providers']['openai']['model'] );
		$this->assertSame( 'aiml1:abc', $clean['ai_providers']['openai']['api_key_encrypted'] );
	}

	public function test_settings_sanitize_allows_deepseek_and_provider_params(): void {
		$clean = Settings::sanitize(
			array(
				'ai_enabled'   => true,
				'ai_provider'  => 'deepseek',
				'ai_providers' => array(
					'deepseek' => array(
						'model'             => 'deepseek-v4-flash',
						'api_key_encrypted' => 'aiml1:ds',
						'temperature'       => 1.3,
						'max_tokens'        => 2048,
					),
				),
			)
		);

		$this->assertSame( 'deepseek', $clean['ai_provider'] );
		$this->assertSame( 'deepseek-v4-flash', $clean['ai_providers']['deepseek']['model'] );
		$this->assertSame( 1.3, $clean['ai_providers']['deepseek']['temperature'] );
		$this->assertSame( 2048, $clean['ai_providers']['deepseek']['max_tokens'] );
	}

	public function test_registry_resolves_active_deepseek(): void {
		$settings = new Settings(
			array(
				'ai_enabled'  => true,
				'ai_provider' => 'deepseek',
			)
		);
		$registry = new ProviderRegistry( $settings );
		$registry->register( new DeepSeekProvider( 'sk-test' ) );

		$this->assertSame( DeepSeekProvider::ID, $registry->active()->get_id() );
	}

	public function test_deepseek_translate_disables_thinking_and_sends_params(): void {
		$seen = array();
		$http = static function ( string $method, string $url, array $args ) use ( &$seen ) {
			unset( $method );
			$body = json_decode( (string) ( $args['body'] ?? '' ), true );
			$seen = array(
				'url'  => $url,
				'body' => is_array( $body ) ? $body : array(),
			);

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"choices":[{"message":{"content":"Hej"}}],"usage":{"prompt_tokens":3,"completion_tokens":1}}',
			);
		};

		$provider = new DeepSeekProvider( 'sk-test', 'deepseek-chat', null, $http, 0.8, 256 );
		$result   = $provider->translate_batch(
			new TranslationBatch(
				'en',
				'sv',
				'default',
				'1',
				'',
				array( new ProviderSegment( 'title', 'Hello', 'plain' ) )
			)
		);

		$this->assertInstanceOf( ProviderResult::class, $result );
		$this->assertSame( 'https://api.deepseek.com/chat/completions', $seen['url'] ?? '' );
		$this->assertSame( 0.8, $seen['body']['temperature'] ?? null );
		$this->assertSame( 256, $seen['body']['max_tokens'] ?? null );
		$this->assertSame( 'disabled', $seen['body']['thinking']['type'] ?? null );
	}
}
