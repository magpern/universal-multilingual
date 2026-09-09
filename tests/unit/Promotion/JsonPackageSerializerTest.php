<?php
/**
 * JSON package serializer — untrusted-input handling (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\JsonPackageSerializer;
use AIMultilingual\Promotion\PackageFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Size ceiling, depth limit, arrays-only, structural shape.
 */
final class JsonPackageSerializerTest extends TestCase {

	private JsonPackageSerializer $serializer;

	protected function setUp(): void {
		$this->serializer = new JsonPackageSerializer();
	}

	public function test_serialize_then_deserialize_round_trips(): void {
		$package = PackageFactory::package();
		$json    = $this->serializer->serialize( $package );

		$rebuilt = $this->serializer->deserialize( $json, 26214400 );

		$this->assertSame( $package->package_checksum(), $rebuilt->package_checksum() );
	}

	public function test_empty_input_is_rejected(): void {
		$this->assertRejectedWith( 'package_empty', "   \n", 1000 );
	}

	public function test_oversized_input_is_rejected_before_parsing(): void {
		$json = $this->serializer->serialize( PackageFactory::package() );
		$this->assertRejectedWith( 'package_too_large', $json, 10 );
	}

	public function test_malformed_json_is_rejected(): void {
		$this->assertRejectedWith( 'package_malformed_json', '{ "manifest": ', 1000 );
	}

	public function test_deeply_nested_json_is_rejected(): void {
		$deep = str_repeat( '[', 200 ) . str_repeat( ']', 200 );
		$this->assertRejectedWith( 'package_too_deep', $deep, 100000 );
	}

	public function test_non_manifest_payload_shape_is_rejected(): void {
		$this->assertRejectedWith( 'package_shape_invalid', '{"foo":"bar"}', 1000 );
	}

	public function test_payload_without_objects_list_is_rejected(): void {
		$this->assertRejectedWith( 'package_shape_invalid', '{"manifest":{},"payload":{}}', 1000 );
	}

	/**
	 * Asserts deserialize() throws a PackageFormatException with the given code.
	 *
	 * @param string $code      Expected machine code.
	 * @param string $raw       Input bytes.
	 * @param int    $max_bytes Ceiling.
	 */
	private function assertRejectedWith( string $code, string $raw, int $max_bytes ): void {
		try {
			$this->serializer->deserialize( $raw, $max_bytes );
			$this->fail( "Expected PackageFormatException [{$code}] but none was thrown." );
		} catch ( PackageFormatException $e ) {
			$this->assertSame( $code, $e->error_code() );
		}
	}
}
