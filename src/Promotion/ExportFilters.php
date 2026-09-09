<?php
/**
 * Scope filters for a promotion export (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable. `language_codes` is required; everything else narrows the object
 * set. Pure — no WordPress functions.
 */
final class ExportFilters {

	/**
	 * Builds the filter set.
	 *
	 * @param array<int, string> $language_codes Target language codes (required, non-empty).
	 * @param array<int, string> $post_types     Restrict to these post types (empty = all in scope).
	 * @param array<int, string> $taxonomies     Restrict to these taxonomies (empty = all in scope).
	 * @param array<int, int>    $object_ids     Restrict to these post/term ids (empty = no restriction).
	 * @param bool               $approved_only  Only segments with review_status = approved.
	 * @param bool               $published_only Only segments with publish_status = published.
	 * @param string|null        $changed_since  ISO-8601 timestamp; only objects touched at/after it.
	 */
	public function __construct(
		public readonly array $language_codes,
		public readonly array $post_types = array(),
		public readonly array $taxonomies = array(),
		public readonly array $object_ids = array(),
		public readonly bool $approved_only = false,
		public readonly bool $published_only = false,
		public readonly ?string $changed_since = null
	) {
	}

	/**
	 * Builds a filter set from a loose request array.
	 *
	 * @param array<string, mixed> $raw Request payload.
	 */
	public static function from_request( array $raw ): self {
		$codes = array();
		foreach ( (array) ( $raw['languages'] ?? $raw['language_codes'] ?? array() ) as $code ) {
			$code = strtolower( trim( (string) $code ) );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return new self(
			array_values( array_unique( $codes ) ),
			self::string_list( $raw['post_types'] ?? array() ),
			self::string_list( $raw['taxonomies'] ?? array() ),
			array_values( array_map( 'intval', (array) ( $raw['object_ids'] ?? array() ) ) ),
			! empty( $raw['approved_only'] ),
			! empty( $raw['published_only'] ),
			isset( $raw['changed_since'] ) && '' !== $raw['changed_since'] ? (string) $raw['changed_since'] : null
		);
	}

	/**
	 * Whether an id-list restriction is active.
	 */
	public function restricts_ids(): bool {
		return array() !== $this->object_ids;
	}

	/**
	 * Whether a specific id passes the id-list restriction.
	 *
	 * @param int $id Object id.
	 */
	public function allows_id( int $id ): bool {
		return ! $this->restricts_ids() || in_array( $id, $this->object_ids, true );
	}

	/**
	 * Post types to walk, intersected with the M1 scope.
	 *
	 * @return array<int, string>
	 */
	public function effective_post_types(): array {
		if ( array() === $this->post_types ) {
			return PromotionScope::POST_SUBTYPES;
		}

		return array_values( array_intersect( $this->post_types, PromotionScope::POST_SUBTYPES ) );
	}

	/**
	 * Taxonomies to walk, intersected with the M1 scope.
	 *
	 * @return array<int, string>
	 */
	public function effective_taxonomies(): array {
		if ( array() === $this->taxonomies ) {
			return PromotionScope::TERM_SUBTYPES;
		}

		return array_values( array_intersect( $this->taxonomies, PromotionScope::TERM_SUBTYPES ) );
	}

	/**
	 * Normalizes a loose value into a string list.
	 *
	 * @param mixed $value Value.
	 * @return array<int, string>
	 */
	private static function string_list( $value ): array {
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
