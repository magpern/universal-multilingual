<?php
/**
 * Outcome of resolving one package object to a local WordPress object (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable. `local_source_id` is null when the object could not be matched
 * (`category` then says why). `identity_action` tells the apply engine whether
 * to adopt the incoming UUID onto the matched local object.
 */
final class ResolvedObject {

	public const MATCH_UUID        = 'uuid';
	public const MATCH_NATURAL_KEY = 'natural_key';
	public const MATCH_FINGERPRINT = 'fingerprint';
	public const MATCH_NONE        = 'none';

	public const ACTION_NONE           = 'none';
	public const ACTION_ADOPT_INCOMING = 'adopt_incoming_uuid_on_apply';

	public const CONFIDENCE_EXACT = 'exact';
	public const CONFIDENCE_LOW   = 'low';
	public const CONFIDENCE_NONE  = 'none';

	/**
	 * Builds a resolution outcome.
	 *
	 * @param int|null           $local_source_id Local WP object id, or null.
	 * @param string             $match_method    One of MATCH_*.
	 * @param string             $identity_action One of ACTION_*.
	 * @param string             $confidence      One of CONFIDENCE_*.
	 * @param string|null        $category        Blocking category, or null when matched cleanly.
	 * @param array<int, string> $notes           Advisory operator notes.
	 */
	public function __construct(
		public readonly ?int $local_source_id,
		public readonly string $match_method,
		public readonly string $identity_action,
		public readonly string $confidence,
		public readonly ?string $category = null,
		public readonly array $notes = array()
	) {
	}

	/**
	 * A clean, apply-eligible match.
	 *
	 * @param int                $local_source_id Local id.
	 * @param string             $match_method    MATCH_*.
	 * @param string             $identity_action ACTION_*.
	 * @param array<int, string> $notes           Advisory notes.
	 */
	public static function matched( int $local_source_id, string $match_method, string $identity_action, array $notes = array() ): self {
		return new self(
			$local_source_id,
			$match_method,
			$identity_action,
			self::MATCH_FINGERPRINT === $match_method ? self::CONFIDENCE_LOW : self::CONFIDENCE_EXACT,
			null,
			$notes
		);
	}

	/**
	 * A blocked resolution.
	 *
	 * @param string             $category Blocking category.
	 * @param array<int, string> $notes    Advisory notes.
	 */
	public static function blocked( string $category, array $notes = array() ): self {
		return new self( null, self::MATCH_NONE, self::ACTION_NONE, self::CONFIDENCE_NONE, $category, $notes );
	}

	/**
	 * Whether a local object was matched.
	 */
	public function is_matched(): bool {
		return null !== $this->local_source_id;
	}

	/**
	 * Array form for the import plan / REST.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'local_source_id' => $this->local_source_id,
			'match_method'    => $this->match_method,
			'identity_action' => $this->identity_action,
			'confidence'      => $this->confidence,
			'category'        => $this->category,
			'notes'           => array_values( $this->notes ),
		);
	}
}
