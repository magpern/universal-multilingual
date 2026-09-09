<?php
/**
 * Structural + integrity validation for a promotion package (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Runs after {@see JsonPackageSerializer::deserialize()} and before any import
 * planning. Rejects: an unsupported format version, missing required keys,
 * an object without a UUID, an unknown text format, an invalid language code,
 * over-long fields, an unsafe slug value, a count mismatch, and — last — a
 * `payload_checksum` or `package_checksum` that does not verify.
 *
 * Pure — no WordPress functions beyond the `wp_json_encode` shim.
 */
final class TranslationPackageValidator {

	/**
	 * Accepted per-segment text formats (mirrors Store::formats()).
	 */
	private const TEXT_FORMATS = array( 'plain', 'html', 'json', 'code', 'slug' );

	/**
	 * Accepted source types.
	 */
	private const SOURCE_TYPES = array( 'post', 'term' );

	private const MAX_TEXT_BYTES        = 2_000_000;
	private const MAX_KEY_BYTES         = 512;
	private const MAX_LANGUAGE_CODE     = 20;
	private const MAX_NATURAL_KEY_BYTES = 8_192;

	/**
	 * Validates a deserialized package.
	 *
	 * @param TranslationPackage $package Deserialized package.
	 */
	public function validate( TranslationPackage $package ): PackageValidation {
		$errors = $this->check_version( $package );
		if ( array() !== $errors ) {
			return new PackageValidation( $errors );
		}

		$errors = array_merge(
			$errors,
			$this->check_manifest( $package ),
			$this->check_objects( $package ),
			$this->check_counts( $package )
		);

		if ( array() === $errors ) {
			$errors = $this->check_checksums( $package );
		}

		return new PackageValidation( $errors );
	}

	/**
	 * Checks the format version is exactly 1.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<int, array{code:string,message:string}>
	 */
	private function check_version( TranslationPackage $package ): array {
		$version = $package->format_version();

		if ( TranslationPackage::FORMAT_VERSION === $version ) {
			return array();
		}

		if ( $version < TranslationPackage::FORMAT_VERSION ) {
			return array( $this->error( 'unsupported_format', sprintf( 'Package format version %d is no longer supported.', $version ) ) );
		}

		return array( $this->error( 'unsupported_newer_format', sprintf( 'Package format version %d is newer than this site understands (%d).', $version, TranslationPackage::FORMAT_VERSION ) ) );
	}

	/**
	 * Checks the manifest carries a UUID id and both checksums.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<int, array{code:string,message:string}>
	 */
	private function check_manifest( TranslationPackage $package ): array {
		$errors   = array();
		$manifest = $package->manifest();

		foreach ( array( 'package_id', 'payload_checksum', 'package_checksum' ) as $key ) {
			if ( empty( $manifest[ $key ] ) || ! is_string( $manifest[ $key ] ) ) {
				$errors[] = $this->error( 'manifest_incomplete', sprintf( 'Package manifest is missing "%s".', $key ) );
			}
		}

		if ( ! $this->is_uuid( $package->package_id() ) ) {
			$errors[] = $this->error( 'manifest_incomplete', 'Package id is not a UUID.' );
		}

		return $errors;
	}

	/**
	 * Checks every object has a UUID, a known source type and a natural key.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<int, array{code:string,message:string}>
	 */
	private function check_objects( TranslationPackage $package ): array {
		$errors = array();
		$seen   = array();

		foreach ( $package->objects() as $index => $entry ) {
			$where = sprintf( 'object #%d (%s)', $index + 1, $entry->natural_key );

			if ( ! $this->is_uuid( $entry->object_uuid ) ) {
				$errors[] = $this->error( 'object_missing_uuid', "{$where} has no valid object_uuid." );
			}

			if ( ! in_array( $entry->source_type, self::SOURCE_TYPES, true ) ) {
				$errors[] = $this->error( 'object_bad_source_type', "{$where} has an unknown source_type." );
			}

			if ( '' === trim( $entry->natural_key ) ) {
				$errors[] = $this->error( 'object_missing_natural_key', "{$where} has no natural_key." );
			}

			if ( strlen( $entry->natural_key ) > self::MAX_NATURAL_KEY_BYTES ) {
				$errors[] = $this->error( 'object_natural_key_too_long', "{$where} natural_key exceeds the size limit." );
			}

			$key = $entry->source_type . '|' . $entry->object_uuid;
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = $this->error( 'object_duplicate', "{$where} repeats object_uuid {$entry->object_uuid}." );
			}
			$seen[ $key ] = true;

			$errors = array_merge( $errors, $this->check_segments( $entry, $where ) );
		}

		return $errors;
	}

	/**
	 * Checks the segments of one object.
	 *
	 * @param PackageObject $entry Object.
	 * @param string        $where Location label.
	 * @return array<int, array{code:string,message:string}>
	 */
	private function check_segments( PackageObject $entry, string $where ): array {
		$errors = array();

		foreach ( $entry->segments as $segment ) {
			$label = "{$where} segment {$segment->segment_key} [{$segment->language_code}]";

			if ( ! in_array( $segment->text_format, self::TEXT_FORMATS, true ) ) {
				$errors[] = $this->error( 'segment_bad_format', "{$label} has an unknown text_format." );
			}

			if ( ! $this->is_language_code( $segment->language_code ) ) {
				$errors[] = $this->error( 'segment_bad_language_code', "{$label} has an invalid language_code." );
			}

			if ( strlen( $segment->segment_key ) > self::MAX_KEY_BYTES || strlen( $segment->field_key ) > self::MAX_KEY_BYTES ) {
				$errors[] = $this->error( 'segment_key_too_long', "{$label} field/segment key exceeds the size limit." );
			}

			if ( strlen( $segment->source_text ) > self::MAX_TEXT_BYTES || strlen( $segment->translated_text ) > self::MAX_TEXT_BYTES ) {
				$errors[] = $this->error( 'segment_text_too_long', "{$label} text exceeds the size limit." );
			}

			if ( 'slug' === $segment->text_format && '' !== $segment->translated_text && ! $this->is_safe_slug( $segment->translated_text ) ) {
				$errors[] = $this->error( 'segment_unsafe_slug', "{$label} translated slug contains unsafe characters." );
			}

			if ( 'json' === $segment->text_format && '' !== $segment->translated_text && ! $this->is_valid_json( $segment->translated_text ) ) {
				$errors[] = $this->error( 'segment_invalid_json', "{$label} translated text is not valid JSON." );
			}
		}

		return $errors;
	}

	/**
	 * Checks the declared counts match the objects and segments present.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<int, array{code:string,message:string}>
	 */
	private function check_counts( TranslationPackage $package ): array {
		$payload  = $package->payload();
		$declared = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : array();

		$objects  = $package->objects();
		$segments = 0;
		foreach ( $objects as $entry ) {
			$segments += count( $entry->segments );
		}

		$errors      = array();
		$object_seen = count( $objects );
		if ( isset( $declared['objects'] ) && $object_seen !== (int) $declared['objects'] ) {
			$errors[] = $this->error( 'count_mismatch', 'Declared object count does not match the objects list.' );
		}
		if ( isset( $declared['segments'] ) && $segments !== (int) $declared['segments'] ) {
			$errors[] = $this->error( 'count_mismatch', 'Declared segment count does not match the segments in the package.' );
		}

		return $errors;
	}

	/**
	 * Verifies both checksums against the current contents.
	 *
	 * @param TranslationPackage $package Package.
	 * @return array<int, array{code:string,message:string}>
	 */
	private function check_checksums( TranslationPackage $package ): array {
		$errors = array();

		if ( ! hash_equals( $package->payload_checksum(), $package->recompute_payload_checksum() ) ) {
			$errors[] = $this->error( 'payload_checksum_mismatch', 'The payload checksum does not match the payload contents.' );
		}

		if ( ! hash_equals( $package->package_checksum(), $package->recompute_package_checksum() ) ) {
			$errors[] = $this->error( 'package_checksum_mismatch', 'The package checksum does not match the manifest and payload.' );
		}

		return $errors;
	}

	/**
	 * Builds a typed error entry.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 * @return array{code:string,message:string}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Whether a string is an RFC-4122-shaped UUID.
	 *
	 * @param string $value Candidate.
	 */
	private function is_uuid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Whether a string is a grammar-valid language URL code.
	 *
	 * @param string $value Candidate.
	 */
	private function is_language_code( string $value ): bool {
		if ( '' === $value || strlen( $value ) > self::MAX_LANGUAGE_CODE ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$/', $value );
	}

	/**
	 * Whether a translated slug value is safe to store as a candidate.
	 *
	 * @param string $value Candidate slug.
	 */
	private function is_safe_slug( string $value ): bool {
		if ( strlen( $value ) > 191 ) {
			return false;
		}

		// No slashes, encoded slashes, whitespace or control characters.
		return 0 === preg_match( '~[/\\\\\s]|%2f|%5c|[\x00-\x1f\x7f]~i', $value );
	}

	/**
	 * Whether a string parses as JSON.
	 *
	 * @param string $value Candidate.
	 */
	private function is_valid_json( string $value ): bool {
		json_decode( $value );

		return JSON_ERROR_NONE === json_last_error();
	}
}
