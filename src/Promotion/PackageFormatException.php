<?php
/**
 * Raised when transported bytes are not a well-formed promotion package.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Carries a stable machine code alongside the human message so callers can map
 * it to a REST error / dry-run `validation_failure` without string matching.
 */
final class PackageFormatException extends \RuntimeException {

	/**
	 * Builds the exception.
	 *
	 * @param string $error_code Stable machine code (e.g. `package_too_large`).
	 * @param string $message    Human message.
	 */
	public function __construct(
		private readonly string $error_code,
		string $message
	) {
		parent::__construct( $message );
	}

	/**
	 * The stable machine code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}
}
