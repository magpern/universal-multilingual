<?php
/**
 * Package structural + integrity validation (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\TranslationPackage;
use AIMultilingual\Promotion\TranslationPackageValidator;
use PHPUnit\Framework\TestCase;

/**
 * Every rejection reason plus the passing case.
 */
final class TranslationPackageValidatorTest extends TestCase {

	private TranslationPackageValidator $validator;

	protected function setUp(): void {
		$this->validator = new TranslationPackageValidator();
	}

	public function test_a_well_formed_package_passes(): void {
		$result = $this->validator->validate( PackageFactory::package() );

		$this->assertTrue( $result->is_valid(), implode( ' / ', $result->messages() ) );
	}

	public function test_newer_format_is_hard_rejected(): void {
		$package = $this->with_manifest( array( 'format_version' => 2 ) );

		$this->assertSame( 'unsupported_newer_format', $this->validator->validate( $package )->first_code() );
	}

	public function test_older_format_is_rejected(): void {
		$package = $this->with_manifest( array( 'format_version' => 0 ) );

		$this->assertSame( 'unsupported_format', $this->validator->validate( $package )->first_code() );
	}

	public function test_object_without_uuid_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['objects'][0]['object_uuid'] = '';

				return $payload;
			}
		);

		$this->assertContains( 'object_missing_uuid', $this->codes( $package ) );
	}

	public function test_unknown_source_type_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['objects'][0]['source_type'] = 'widget';

				return $payload;
			}
		);

		$this->assertContains( 'object_bad_source_type', $this->codes( $package ) );
	}

	public function test_unknown_text_format_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['objects'][0]['segments'][0]['text_format'] = 'markdown';

				return $payload;
			}
		);

		$this->assertContains( 'segment_bad_format', $this->codes( $package ) );
	}

	public function test_invalid_language_code_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['objects'][0]['segments'][0]['language_code'] = 'Svenska!';

				return $payload;
			}
		);

		$this->assertContains( 'segment_bad_language_code', $this->codes( $package ) );
	}

	public function test_unsafe_slug_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['objects'][0]['segments'][0]['text_format']     = 'slug';
				$payload['objects'][0]['segments'][0]['translated_text'] = 'foo%2Fbar';

				return $payload;
			}
		);

		$this->assertContains( 'segment_unsafe_slug', $this->codes( $package ) );
	}

	public function test_invalid_json_segment_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['objects'][0]['segments'][0]['text_format']     = 'json';
				$payload['objects'][0]['segments'][0]['translated_text'] = '{not json';

				return $payload;
			}
		);

		$this->assertContains( 'segment_invalid_json', $this->codes( $package ) );
	}

	public function test_count_mismatch_is_rejected(): void {
		$package = $this->mutate_payload(
			static function ( array $payload ): array {
				$payload['counts']['segments'] = 999;

				return $payload;
			}
		);

		$this->assertContains( 'count_mismatch', $this->codes( $package ) );
	}

	public function test_duplicate_object_uuid_is_rejected(): void {
		$package = PackageFactory::package(
			array(
				PackageFactory::object( '3f2c1a4b-5d6e-4f70-8901-234567890abc' ),
				PackageFactory::object( '3f2c1a4b-5d6e-4f70-8901-234567890abc' ),
			)
		);

		$this->assertContains( 'object_duplicate', $this->codes( $package ) );
	}

	public function test_tampered_payload_checksum_is_rejected(): void {
		$package = $this->with_manifest( array( 'payload_checksum' => str_repeat( 'a', 64 ) ) );

		$this->assertSame( 'payload_checksum_mismatch', $this->validator->validate( $package )->first_code() );
	}

	public function test_tampered_package_checksum_is_rejected(): void {
		$package = $this->with_manifest( array( 'package_checksum' => str_repeat( 'b', 64 ) ) );

		$this->assertSame( 'package_checksum_mismatch', $this->validator->validate( $package )->first_code() );
	}

	/**
	 * Error codes for a package.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<int, string>
	 */
	private function codes( TranslationPackage $package ): array {
		return array_map(
			static fn( array $error ): string => $error['code'],
			$this->validator->validate( $package )->errors()
		);
	}

	/**
	 * Rebuilds the fixture with manifest overrides (checksums left as-is).
	 *
	 * @param array<string, mixed> $overrides Manifest overrides.
	 */
	private function with_manifest( array $overrides ): TranslationPackage {
		$base = PackageFactory::package();

		return TranslationPackage::from_array(
			array(
				'manifest' => array_merge( $base->manifest(), $overrides ),
				'payload'  => $base->payload(),
			)
		);
	}

	/**
	 * Rebuilds the fixture after mutating the payload (checksums left stale).
	 *
	 * @param callable $mutator Payload mutator: fn(array): array.
	 */
	private function mutate_payload( callable $mutator ): TranslationPackage {
		$base = PackageFactory::package();

		return TranslationPackage::from_array(
			array(
				'manifest' => $base->manifest(),
				'payload'  => $mutator( $base->payload() ),
			)
		);
	}
}
