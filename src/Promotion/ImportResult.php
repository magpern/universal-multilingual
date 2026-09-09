<?php
/**
 * Outcome of an import apply (ADR-0030 §5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable. When `refused` is set, nothing was written and the operator must
 * run a fresh dry-run.
 */
final class ImportResult {

	/**
	 * Builds a result.
	 *
	 * @param string             $package_id           Package id.
	 * @param string             $mode                 Apply mode used.
	 * @param string             $refused_code         '' unless the whole apply was refused.
	 * @param string             $refused_message      Human message when refused.
	 * @param array<string, int> $applied_by_category  Applied row counts by category.
	 * @param array<string, int> $skipped_by_category  Skipped row counts by category.
	 * @param array<int, string> $changed_since_dry_run Row ids that changed at write time.
	 * @param array<int, string> $errors               Per-row error strings.
	 * @param string             $result               completed|partial|failed.
	 */
	public function __construct(
		public readonly string $package_id,
		public readonly string $mode,
		public readonly string $refused_code,
		public readonly string $refused_message,
		public readonly array $applied_by_category,
		public readonly array $skipped_by_category,
		public readonly array $changed_since_dry_run,
		public readonly array $errors,
		public readonly string $result
	) {
	}

	/**
	 * A refused apply.
	 *
	 * @param string $package_id Package id.
	 * @param string $mode       Mode.
	 * @param string $code       Refusal code.
	 * @param string $message    Human message.
	 */
	public static function refused( string $package_id, string $mode, string $code, string $message ): self {
		return new self( $package_id, $mode, $code, $message, array(), array(), array(), array(), 'failed' );
	}

	/**
	 * Whether the whole apply was refused.
	 */
	public function is_refused(): bool {
		return '' !== $this->refused_code;
	}

	/**
	 * Total rows written.
	 */
	public function applied_count(): int {
		return array_sum( $this->applied_by_category );
	}

	/**
	 * Array form for REST / the UI.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'package_id'            => $this->package_id,
			'mode'                  => $this->mode,
			'refused_code'          => $this->refused_code,
			'refused_message'       => $this->refused_message,
			'applied_by_category'   => $this->applied_by_category,
			'skipped_by_category'   => $this->skipped_by_category,
			'changed_since_dry_run' => array_values( $this->changed_since_dry_run ),
			'errors'                => array_values( $this->errors ),
			'result'                => $this->result,
			'applied_count'         => $this->applied_count(),
		);
	}
}
