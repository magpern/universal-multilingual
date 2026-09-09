<?php
/**
 * Promotion package serialization contract (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Turns a {@see TranslationPackage} into transportable bytes and back.
 *
 * The JSON implementation is the M1 format; a ZIP implementation can be added
 * without touching callers.
 */
interface PackageSerializer {

	/**
	 * Serializes a package for download.
	 *
	 * @param TranslationPackage $package Package.
	 */
	public function serialize( TranslationPackage $package ): string;

	/**
	 * Parses transported bytes into a package.
	 *
	 * @param string $raw       Uploaded bytes (already read into memory).
	 * @param int    $max_bytes Hard ceiling; a larger input is rejected.
	 * @throws PackageFormatException When the bytes are not a well-formed package.
	 */
	public function deserialize( string $raw, int $max_bytes ): TranslationPackage;

	/**
	 * The file extension this serializer produces (without a dot).
	 */
	public function extension(): string;

	/**
	 * The MIME type this serializer produces.
	 */
	public function mime_type(): string;
}
