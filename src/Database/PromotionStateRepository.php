<?php
/**
 * Three-way promotion merge baseline persistence (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Database;

/**
 * Repository for `aiml_promotion_state` rows.
 *
 * Records, per promoted segment, the translation and source hash last written
 * into it by a promotion apply on this environment. A later import compares the
 * current local translation hash against this baseline to tell an ordinary
 * update from an independent local edit. Written only by apply.
 */
class PromotionStateRepository {

	/**
	 * The baseline for one promoted segment, or null when never promoted here.
	 *
	 * @param string $object_uuid  Cross-environment object UUID.
	 * @param string $language_code Stable language code.
	 * @param string $segment_hash  sha1(field_key\x1fsegment_key).
	 */
	public function find( string $object_uuid, string $language_code, string $segment_hash ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::promotion_state() . ' WHERE object_uuid = %s AND language_code = %s AND segment_hash = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				$object_uuid,
				$language_code,
				$segment_hash
			)
		);

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Inserts or updates the baseline for one promoted segment.
	 *
	 * @param array{object_uuid:string,language_code:string,segment_hash:string,last_promoted_translation_hash:string,last_promoted_source_hash:string,last_package_id:string} $data Row.
	 */
	public function upsert( array $data ): void {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$existing = $this->find(
			(string) $data['object_uuid'],
			(string) $data['language_code'],
			(string) $data['segment_hash']
		);

		if ( null !== $existing ) {
			$wpdb->update(
				Schema::promotion_state(),
				array(
					'last_promoted_translation_hash' => (string) $data['last_promoted_translation_hash'],
					'last_promoted_source_hash'      => (string) $data['last_promoted_source_hash'],
					'last_package_id'                => (string) $data['last_package_id'],
					'direction'                      => 'import',
					'last_promoted_at'               => $now,
				),
				array( 'state_id' => (int) $existing->state_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return;
		}

		$wpdb->insert(
			Schema::promotion_state(),
			array(
				'object_uuid'                    => (string) $data['object_uuid'],
				'language_code'                  => (string) $data['language_code'],
				'segment_hash'                   => (string) $data['segment_hash'],
				'last_promoted_translation_hash' => (string) $data['last_promoted_translation_hash'],
				'last_promoted_source_hash'      => (string) $data['last_promoted_source_hash'],
				'last_package_id'                => (string) $data['last_package_id'],
				'direction'                      => 'import',
				'last_promoted_at'               => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Removes every baseline row for an object UUID. Optional prune helper.
	 *
	 * @param string $object_uuid Object UUID.
	 */
	public function delete_for_uuid( string $object_uuid ): int {
		global $wpdb;

		$deleted = $wpdb->delete(
			Schema::promotion_state(),
			array( 'object_uuid' => $object_uuid ),
			array( '%s' )
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Total baseline rows. Used by tests and diagnostics.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::promotion_state() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
