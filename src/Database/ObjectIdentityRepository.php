<?php
/**
 * Cross-environment object identity persistence (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Database;

/**
 * Repository for `aiml_object_identity` rows.
 *
 * Holds no matching policy — {@see \AIMultilingual\Promotion\ObjectIdentityResolver}
 * owns that. This class is the only place raw `$wpdb` touches the identity table
 * (invariant 8). It never creates a WordPress object (invariant 1).
 */
class ObjectIdentityRepository {

	/**
	 * Hex sha256 of a natural key, as stored in `natural_key_hash`.
	 *
	 * @param string $natural_key Deterministic natural key string.
	 */
	public static function hash( string $natural_key ): string {
		return hash( 'sha256', $natural_key );
	}

	/**
	 * Finds the identity row for a local source object.
	 *
	 * @param string $source_type Source type (`post` / `term`).
	 * @param int    $source_id   Local WordPress object ID.
	 */
	public function find_by_source( string $source_type, int $source_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::object_identity() . ' WHERE source_type = %s AND source_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$source_type,
				$source_id
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Finds the identity row that owns a UUID.
	 *
	 * @param string $uuid UUIDv4.
	 */
	public function find_by_uuid( string $uuid ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::object_identity() . ' WHERE uuid = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				$uuid
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Identity rows whose natural key hash matches (advisory; usually 0 or 1).
	 *
	 * @param string $source_type   Source type.
	 * @param string $natural_key   Deterministic natural key string.
	 * @return list<object>
	 */
	public function find_by_natural_key( string $source_type, string $natural_key ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::object_identity() . ' WHERE source_type = %s AND natural_key_hash = UNHEX(%s)', // phpcs:ignore WordPress.DB.PreparedSQL
				$source_type,
				self::hash( $natural_key )
			)
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Ensures an identity row exists for a source object, minting a UUID if not.
	 *
	 * Used on the export side: the environment owns its own objects, so it may
	 * write here. Returns the row (existing or freshly created). The natural key
	 * is refreshed when it drifts (a rename) so bootstrap on the far side keeps
	 * working until the UUID relationship is established.
	 *
	 * @param string      $source_type    Source type.
	 * @param int         $source_id      Local object ID.
	 * @param string      $source_subtype Post type or taxonomy.
	 * @param string      $natural_key    Deterministic natural key.
	 * @param string|null $natural_parts  JSON of the key parts, for audit.
	 */
	public function ensure( string $source_type, int $source_id, string $source_subtype, string $natural_key, ?string $natural_parts = null ): object {
		global $wpdb;

		$existing = $this->find_by_source( $source_type, $source_id );
		$now      = current_time( 'mysql', true );

		if ( null !== $existing ) {
			if ( (string) $existing->natural_key !== $natural_key || (string) $existing->source_subtype !== $source_subtype ) {
				$wpdb->update(
					Schema::object_identity(),
					array(
						'source_subtype'    => $source_subtype,
						'natural_key'       => $natural_key,
						'natural_key_hash'  => self::hash( $natural_key ),
						'natural_key_parts' => $natural_parts,
						'updated_at'        => $now,
					),
					array( 'identity_id' => (int) $existing->identity_id ),
					array( '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);

				$refetched = $this->find_by_source( $source_type, $source_id );
				if ( null !== $refetched ) {
					return $refetched;
				}
			}

			return $existing;
		}

		$wpdb->insert(
			Schema::object_identity(),
			array(
				'source_type'       => $source_type,
				'source_id'         => $source_id,
				'source_subtype'    => $source_subtype,
				'uuid'              => wp_generate_uuid4(),
				'natural_key'       => $natural_key,
				'natural_key_hash'  => self::hash( $natural_key ),
				'natural_key_parts' => $natural_parts,
				'first_seen_at'     => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$row = $this->find_by_source( $source_type, $source_id );
		if ( null === $row ) {
			// Extremely unlikely race; re-read once more.
			$row = $this->find_by_source( $source_type, $source_id );
		}

		return $row ?? (object) array(
			'identity_id' => (int) $wpdb->insert_id,
			'source_type' => $source_type,
			'source_id'   => $source_id,
			'uuid'        => '',
			'natural_key' => $natural_key,
		);
	}

	/**
	 * Adopts an incoming UUID for a local object during import apply.
	 *
	 * Bootstrap only — the caller has already established there is no local
	 * identity row for this object. Returns true on success, false when the
	 * incoming UUID is already taken locally by a different object (the caller
	 * then classifies `identity_conflict`).
	 *
	 * @param string      $source_type    Source type.
	 * @param int         $source_id      Local object ID.
	 * @param string      $source_subtype Post type or taxonomy.
	 * @param string      $uuid           Incoming UUID to adopt.
	 * @param string      $natural_key    Local deterministic natural key.
	 * @param string|null $natural_parts  JSON of key parts.
	 */
	public function adopt_uuid( string $source_type, int $source_id, string $source_subtype, string $uuid, string $natural_key, ?string $natural_parts = null ): bool {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$ok = $wpdb->insert(
			Schema::object_identity(),
			array(
				'source_type'       => $source_type,
				'source_id'         => $source_id,
				'source_subtype'    => $source_subtype,
				'uuid'              => $uuid,
				'natural_key'       => $natural_key,
				'natural_key_hash'  => self::hash( $natural_key ),
				'natural_key_parts' => $natural_parts,
				'first_seen_at'     => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $ok;
	}

	/**
	 * Total identity rows. Used by tests and diagnostics.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::object_identity() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
