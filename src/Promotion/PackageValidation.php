<?php
/**
 * Result of validating a promotion package (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable list of typed validation errors. Empty ⇒ the package is structurally
 * sound and both checksums verify.
 */
final class PackageValidation {

	/**
	 * Builds a validation result.
	 *
	 * @param array<int, array{code:string,message:string}> $errors Typed errors.
	 */
	public function __construct(
		private readonly array $errors
	) {
	}

	/**
	 * A passing result.
	 */
	public static function ok(): self {
		return new self( array() );
	}

	/**
	 * Whether the package passed every check.
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * The typed errors.
	 *
	 * @return array<int, array{code:string,message:string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * The first error code, or '' when valid.
	 */
	public function first_code(): string {
		return $this->errors[0]['code'] ?? '';
	}

	/**
	 * The human messages.
	 *
	 * @return array<int, string>
	 */
	public function messages(): array {
		return array_map( static fn( array $error ): string => $error['message'], $this->errors );
	}
}
