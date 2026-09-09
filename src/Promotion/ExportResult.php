<?php
/**
 * Outcome of a promotion export (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable. Either carries a package, or an error code + message, or is empty
 * (nothing matched the filters).
 */
final class ExportResult {

	/**
	 * Builds a result.
	 *
	 * @param TranslationPackage|null $package    Built package, or null.
	 * @param string                  $error_code Machine code, or '' on success.
	 * @param string                  $message    Human message.
	 */
	private function __construct(
		private readonly ?TranslationPackage $package,
		private readonly string $error_code,
		private readonly string $message
	) {
	}

	/**
	 * A successful export.
	 *
	 * @param TranslationPackage $package Built package.
	 */
	public static function ok( TranslationPackage $package ): self {
		return new self( $package, '', '' );
	}

	/**
	 * A failed export.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Human message.
	 */
	public static function error( string $code, string $message ): self {
		return new self( null, $code, $message );
	}

	/**
	 * An export that matched nothing.
	 */
	public static function empty(): self {
		return new self( null, 'nothing_to_export', 'No translations matched the selected filters.' );
	}

	/**
	 * Whether a package was produced.
	 */
	public function is_ok(): bool {
		return null !== $this->package;
	}

	/**
	 * The built package.
	 */
	public function package(): ?TranslationPackage {
		return $this->package;
	}

	/**
	 * The machine error code, or '' on success.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * The human message.
	 */
	public function message(): string {
		return $this->message;
	}
}
