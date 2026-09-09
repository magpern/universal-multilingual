<?php
/**
 * A versioned DEV → PROD translation package (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable two-part document.
 *
 * `payload` is deterministic: same logical translation data ⇒ byte-identical
 * canonical form (object keys sorted, every array in deterministic order).
 * `manifest` is the envelope (package id, timestamps, actor hash); it varies
 * between exports but is still integrity-protected — `package_checksum` covers
 * `{ manifest (minus package_checksum), payload }`.
 *
 * `payload_checksum` answers "is the translation payload identical?";
 * `package_checksum` answers "is this exact artifact intact?" and is what apply
 * and the review token bind to.
 *
 * Pure — no WordPress functions beyond the `wp_json_encode` shim.
 */
final class TranslationPackage {

	/**
	 * The only package format version this build reads or writes.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Builds an immutable package from its two arrays.
	 *
	 * @param array<string, mixed> $manifest Manifest array (with both checksums).
	 * @param array<string, mixed> $payload  Payload array.
	 */
	private function __construct(
		private readonly array $manifest,
		private readonly array $payload
	) {
	}

	/**
	 * Assembles a package, ordering every list and computing both checksums.
	 *
	 * @param string                            $package_id   Minted per export.
	 * @param array<string, mixed>              $generator    {plugin, plugin_version, db_version}.
	 * @param array<string, string>             $source_site  Advisory site metadata.
	 * @param int                               $norm_version Store::NORM_VERSION at export.
	 * @param array<int, array<string, mixed>>  $languages Language rows {code, locale, name, status}.
	 * @param array<string, array<int, string>> $capabilities field_keys / segment_key_namespaces / source_types.
	 * @param PackageObject[]                   $objects      Exported objects.
	 */
	public static function create(
		string $package_id,
		array $generator,
		array $source_site,
		int $norm_version,
		array $languages,
		array $capabilities,
		array $objects
	): self {
		$languages    = self::sorted_languages( $languages );
		$capabilities = self::sorted_capabilities( $capabilities );
		$object_rows  = self::sorted_objects( $objects );

		$segment_count = 0;
		foreach ( $object_rows as $object_row ) {
			$segment_count += count( $object_row['segments'] );
		}

		$payload = array(
			'norm_version' => $norm_version,
			'languages'    => $languages,
			'capabilities' => $capabilities,
			'counts'       => array(
				'objects'  => count( $object_rows ),
				'segments' => $segment_count,
			),
			'objects'      => $object_rows,
		);

		$manifest                     = array(
			'format_version'   => self::FORMAT_VERSION,
			'package_id'       => $package_id,
			'generator'        => $generator,
			'source_site'      => $source_site,
			'payload_checksum' => CanonicalJson::sha256( $payload ),
		);
		$manifest['package_checksum'] = CanonicalJson::sha256(
			array(
				'manifest' => $manifest,
				'payload'  => $payload,
			)
		);

		return new self( $manifest, $payload );
	}

	/**
	 * Rebuilds a package from a decoded `{ manifest, payload }` array.
	 *
	 * @param array<string, mixed> $data Decoded document.
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['manifest'] ) && is_array( $data['manifest'] ) ? $data['manifest'] : array(),
			isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array()
		);
	}

	/**
	 * The `{ manifest, payload }` structure, ready for JSON encoding.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'manifest' => $this->manifest,
			'payload'  => $this->payload,
		);
	}

	/**
	 * The manifest array.
	 *
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		return $this->manifest;
	}

	/**
	 * The payload array.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * The declared package format version.
	 */
	public function format_version(): int {
		return (int) ( $this->manifest['format_version'] ?? 0 );
	}

	/**
	 * The package id (a UUID minted at export).
	 */
	public function package_id(): string {
		return (string) ( $this->manifest['package_id'] ?? '' );
	}

	/**
	 * The declared payload checksum.
	 */
	public function payload_checksum(): string {
		return (string) ( $this->manifest['payload_checksum'] ?? '' );
	}

	/**
	 * The declared whole-artifact checksum.
	 */
	public function package_checksum(): string {
		return (string) ( $this->manifest['package_checksum'] ?? '' );
	}

	/**
	 * The normalization algorithm version in force when the package was built.
	 */
	public function norm_version(): int {
		return (int) ( $this->payload['norm_version'] ?? 1 );
	}

	/**
	 * The declared languages.
	 *
	 * @return array<int, array{code:string,locale:string,name:string,status:string}>
	 */
	public function languages(): array {
		$out = array();
		foreach ( (array) ( $this->payload['languages'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = array(
					'code'   => (string) ( $row['code'] ?? '' ),
					'locale' => (string) ( $row['locale'] ?? '' ),
					'name'   => (string) ( $row['name'] ?? '' ),
					'status' => (string) ( $row['status'] ?? '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * The declared capability sets.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function capabilities(): array {
		$out = array();
		foreach ( (array) ( $this->payload['capabilities'] ?? array() ) as $key => $values ) {
			$out[ (string) $key ] = is_array( $values ) ? array_values( array_map( 'strval', $values ) ) : array();
		}

		return $out;
	}

	/**
	 * The parsed objects.
	 *
	 * @return PackageObject[]
	 */
	public function objects(): array {
		$out = array();
		foreach ( (array) ( $this->payload['objects'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$out[] = PackageObject::from_array( $row );
			}
		}

		return $out;
	}

	/**
	 * Recomputes `payload_checksum` from the current payload.
	 */
	public function recompute_payload_checksum(): string {
		return CanonicalJson::sha256( $this->payload );
	}

	/**
	 * Recomputes `package_checksum` from manifest (minus own field) + payload.
	 */
	public function recompute_package_checksum(): string {
		$manifest = $this->manifest;
		unset( $manifest['package_checksum'] );

		return CanonicalJson::sha256(
			array(
				'manifest' => $manifest,
				'payload'  => $this->payload,
			)
		);
	}

	/**
	 * Normalizes and sorts language rows by code.
	 *
	 * @param array<int, array<string, mixed>> $languages Raw language rows.
	 * @return array<int, array{code:string,locale:string,name:string,status:string}>
	 */
	private static function sorted_languages( array $languages ): array {
		$rows = array();
		foreach ( $languages as $language ) {
			$rows[] = array(
				'code'   => (string) ( $language['code'] ?? '' ),
				'locale' => (string) ( $language['locale'] ?? '' ),
				'name'   => (string) ( $language['name'] ?? '' ),
				'status' => (string) ( $language['status'] ?? '' ),
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['code'], $b['code'] ) );

		return array_values( $rows );
	}

	/**
	 * Normalizes and sorts capability sets.
	 *
	 * @param array<string, array<int, string>> $capabilities Raw capabilities.
	 * @return array<string, array<int, string>>
	 */
	private static function sorted_capabilities( array $capabilities ): array {
		$out = array();
		foreach ( $capabilities as $key => $values ) {
			$values = array_values( array_unique( array_map( 'strval', (array) $values ) ) );
			sort( $values );
			$out[ (string) $key ] = $values;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Serializes objects and sorts them deterministically.
	 *
	 * @param PackageObject[] $objects Objects.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sorted_objects( array $objects ): array {
		$rows = array_map( static fn( PackageObject $package_object ): array => $package_object->to_array(), $objects );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['source_type'], $a['natural_key'], $a['object_uuid'] )
					<=> array( $b['source_type'], $b['natural_key'], $b['object_uuid'] );
			}
		);

		return array_values( $rows );
	}
}
