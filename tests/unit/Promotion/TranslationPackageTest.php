<?php
/**
 * Package determinism and dual-checksum integrity (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\CanonicalJson;
use AIMultilingual\Promotion\PackageSegment;
use AIMultilingual\Promotion\TranslationPackage;
use PHPUnit\Framework\TestCase;

/**
 * "Deterministic package" = same logical data ⇒ byte-identical payload; the
 * manifest may vary but is still integrity-protected.
 */
final class TranslationPackageTest extends TestCase {

	public function test_payload_is_byte_identical_for_same_logical_data(): void {
		$a = PackageFactory::package( array(), 'aaaaaaaa-1111-4111-8111-111111111111' );
		$b = PackageFactory::package( array(), 'bbbbbbbb-2222-4222-8222-222222222222' );

		// Different package ids give different manifests.
		$this->assertNotSame( $a->package_id(), $b->package_id() );
		$this->assertNotSame(
			CanonicalJson::encode( $a->manifest() ),
			CanonicalJson::encode( $b->manifest() )
		);

		// …but the payload is identical.
		$this->assertSame(
			CanonicalJson::encode( $a->payload() ),
			CanonicalJson::encode( $b->payload() )
		);
		$this->assertSame( $a->payload_checksum(), $b->payload_checksum() );
	}

	public function test_object_and_segment_order_does_not_affect_payload(): void {
		$seg_a = PackageFactory::segment(
			array(
				'segment_key'   => 'post_title',
				'language_code' => 'sv',
			)
		);
		$seg_b = PackageFactory::segment(
			array(
				'segment_key'   => 'post_excerpt',
				'field_key'     => 'post_excerpt',
				'language_code' => 'de',
			)
		);

		$ordered  = PackageFactory::object( '3f2c1a4b-5d6e-4f70-8901-234567890abc', array( $seg_a, $seg_b ) );
		$reversed = PackageFactory::object( '3f2c1a4b-5d6e-4f70-8901-234567890abc', array( $seg_b, $seg_a ) );

		$this->assertSame(
			PackageFactory::package( array( $ordered ) )->payload_checksum(),
			PackageFactory::package( array( $reversed ) )->payload_checksum()
		);
	}

	public function test_capability_array_order_does_not_change_checksum(): void {
		$forward = TranslationPackage::create(
			'11111111-2222-4333-8444-555555555555',
			array(),
			array(),
			1,
			array(),
			array( 'field_keys' => array( 'b', 'a' ) ),
			array( PackageFactory::object() )
		);
		$reverse = TranslationPackage::create(
			'11111111-2222-4333-8444-555555555555',
			array(),
			array(),
			1,
			array(),
			array( 'field_keys' => array( 'a', 'b' ) ),
			array( PackageFactory::object() )
		);

		$this->assertSame( $forward->payload_checksum(), $reverse->payload_checksum() );
		$this->assertSame( $forward->package_checksum(), $reverse->package_checksum() );
	}

	public function test_payload_checksum_covers_the_whole_payload_not_just_objects(): void {
		$base = PackageFactory::package();

		$mutated_payload                 = $base->payload();
		$mutated_payload['norm_version'] = 99;
		$mutated                         = TranslationPackage::from_array(
			array(
				'manifest' => $base->manifest(),
				'payload'  => $mutated_payload,
			)
		);

		$this->assertNotSame( $mutated->payload_checksum(), $mutated->recompute_payload_checksum() );
	}

	public function test_any_content_change_changes_the_payload_checksum(): void {
		$before = PackageFactory::package()->payload_checksum();

		$after = PackageFactory::package(
			array( PackageFactory::object( '3f2c1a4b-5d6e-4f70-8901-234567890abc', array( PackageFactory::segment( array( 'translated_text' => 'Ändrad' ) ) ) ) )
		)->payload_checksum();

		$this->assertNotSame( $before, $after );
	}

	/**
	 * @dataProvider manifest_fields
	 *
	 * @param string $path  Dotted manifest path to mutate.
	 * @param mixed  $value Replacement value.
	 */
	public function test_mutating_any_manifest_field_breaks_the_package_checksum( string $path, $value ): void {
		$package  = PackageFactory::package();
		$manifest = $package->manifest();

		$keys = explode( '.', $path );
		$last = count( $keys ) - 1;
		$ref  = &$manifest;
		foreach ( $keys as $i => $key ) {
			if ( $last === $i ) {
				$ref[ $key ] = $value;
			} else {
				$ref = &$ref[ $key ];
			}
		}
		unset( $ref );

		$tampered = TranslationPackage::from_array(
			array(
				'manifest' => $manifest,
				'payload'  => $package->payload(),
			)
		);

		$this->assertNotSame(
			$tampered->package_checksum(),
			$tampered->recompute_package_checksum(),
			"Mutating manifest.{$path} must break package_checksum"
		);
	}

	/**
	 * @return array<string, array{0:string,1:mixed}>
	 */
	public static function manifest_fields(): array {
		return array(
			'package_id'               => array( 'package_id', '99999999-9999-4999-8999-999999999999' ),
			'format_version'           => array( 'format_version', 2 ),
			'generator.plugin_version' => array( 'generator.plugin_version', '9.9.9' ),
			'source_site.exported_at'  => array( 'source_site.exported_at', '1999-01-01T00:00:00Z' ),
			'payload_checksum'         => array( 'payload_checksum', str_repeat( '0', 64 ) ),
		);
	}

	public function test_round_trip_preserves_payload(): void {
		$package = PackageFactory::package();
		$rebuilt = TranslationPackage::from_array( $package->to_array() );

		$this->assertSame( $package->payload_checksum(), $rebuilt->payload_checksum() );
		$this->assertSame( $package->package_checksum(), $rebuilt->package_checksum() );
		$this->assertCount( 1, $rebuilt->objects() );
		$this->assertInstanceOf( PackageSegment::class, $rebuilt->objects()[0]->segments[0] );
	}
}
