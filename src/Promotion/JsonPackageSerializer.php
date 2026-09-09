<?php
/**
 * JSON promotion package serializer — the M1 format (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * A single UTF-8 `.json` document, `{ manifest, payload }`.
 *
 * Decoding treats the bytes as untrusted: a hard size ceiling before parsing, a
 * bounded decode depth, associative arrays only (no object hydration), and a
 * structural shape check. It performs no checksum verification — that is
 * {@see TranslationPackageValidator}'s job — but it does reject anything that is
 * not a `{ manifest, payload }` map.
 *
 * Pure — no WordPress functions beyond the `wp_json_encode` shim.
 */
final class JsonPackageSerializer implements PackageSerializer {

	/**
	 * Maximum nesting depth accepted by json_decode.
	 */
	public const MAX_DEPTH = 64;

	/**
	 * Serializes a package to a pretty-printed JSON document.
	 *
	 * @param TranslationPackage $package Package.
	 */
	public function serialize( TranslationPackage $package ): string {
		return (string) wp_json_encode(
			$package->to_array(),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
	}

	/**
	 * Parses untrusted JSON bytes into a package.
	 *
	 * @param string $raw       Uploaded bytes.
	 * @param int    $max_bytes Hard ceiling.
	 * @throws PackageFormatException When the bytes are not a well-formed package.
	 */
	public function deserialize( string $raw, int $max_bytes ): TranslationPackage {
		if ( '' === trim( $raw ) ) {
			throw new PackageFormatException( 'package_empty', 'The package file is empty.' );
		}

		if ( strlen( $raw ) > $max_bytes ) {
			$too_large = sprintf( 'The package is larger than the %d byte limit.', $max_bytes );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output.
			throw new PackageFormatException( 'package_too_large', $too_large );
		}

		$decoded = json_decode( $raw, true, self::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$message = json_last_error_msg();
			$code    = false !== stripos( $message, 'depth' ) ? 'package_too_deep' : 'package_malformed_json';

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception message, not HTML output.
			throw new PackageFormatException( $code, 'The package is not valid JSON: ' . $message );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['manifest'], $decoded['payload'] )
			|| ! is_array( $decoded['manifest'] ) || ! is_array( $decoded['payload'] ) ) {
			throw new PackageFormatException(
				'package_shape_invalid',
				'The package must be a { manifest, payload } object.'
			);
		}

		if ( ! isset( $decoded['payload']['objects'] ) || ! is_array( $decoded['payload']['objects'] ) ) {
			throw new PackageFormatException(
				'package_shape_invalid',
				'The package payload must contain an objects list.'
			);
		}

		return TranslationPackage::from_array( $decoded );
	}

	/**
	 * The file extension this serializer produces.
	 */
	public function extension(): string {
		return 'json';
	}

	/**
	 * The MIME type this serializer produces.
	 */
	public function mime_type(): string {
		return 'application/json';
	}
}
