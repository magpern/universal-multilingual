<?php
/**
 * Promotion audit-log persistence (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Database;

/**
 * Repository for `aiml_promotion_log` rows.
 *
 * Infrequent, high-consequence, operator-facing history. Written only by export
 * and by import apply — never by a read-only dry-run. A retention sweep on
 * write keeps the table bounded.
 */
class PromotionLogRepository {

	/**
	 * Default number of rows to keep.
	 */
	public const DEFAULT_RETENTION = 200;

	/**
	 * Rows to retain after a sweep.
	 *
	 * @var int
	 */
	private int $retention;

	/**
	 * Builds the repository.
	 *
	 * @param int $retention Rows to keep (min 20).
	 */
	public function __construct( int $retention = self::DEFAULT_RETENTION ) {
		$this->retention = max( 20, $retention );
	}

	/**
	 * Records the start of an export or import and returns its row id.
	 *
	 * @param array{direction:string,package_id:string,package_checksum?:string,format_version?:int,mode?:string,language_codes?:string,actor_id?:int,source_site_uuid?:string} $data Row.
	 */
	public function start( array $data ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::promotion_log(),
			array(
				'direction'        => (string) $data['direction'],
				'package_id'       => (string) $data['package_id'],
				'package_checksum' => (string) ( $data['package_checksum'] ?? '' ),
				'format_version'   => (int) ( $data['format_version'] ?? 0 ),
				'mode'             => (string) ( $data['mode'] ?? '' ),
				'language_codes'   => (string) ( $data['language_codes'] ?? '' ),
				'result'           => 'started',
				'actor_id'         => (int) ( $data['actor_id'] ?? 0 ),
				'source_site_uuid' => (string) ( $data['source_site_uuid'] ?? '' ),
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		$this->sweep();

		return $id;
	}

	/**
	 * Marks a promotion-log row complete.
	 *
	 * @param int                       $promotion_id Row id from {@see start()}.
	 * @param string                    $result       completed|partial|failed.
	 * @param array<string, mixed>|null $counts       Structured counts payload.
	 */
	public function finish( int $promotion_id, string $result, ?array $counts = null ): void {
		global $wpdb;

		$wpdb->update(
			Schema::promotion_log(),
			array(
				'result'      => $result,
				'counts_json' => null === $counts ? null : (string) wp_json_encode( $counts ),
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'promotion_id' => $promotion_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Most recent promotion-log rows, newest first.
	 *
	 * @param int $limit Maximum rows (1..100).
	 * @return list<object>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::promotion_log() . ' ORDER BY promotion_id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			)
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Total log rows. Used by tests and diagnostics.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::promotion_log() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Deletes all but the newest `$retention` rows.
	 */
	public function sweep(): void {
		global $wpdb;

		$cutoff = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT promotion_id FROM ' . Schema::promotion_log() . ' ORDER BY promotion_id DESC LIMIT 1 OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$this->retention
			)
		);

		if ( $cutoff > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . Schema::promotion_log() . ' WHERE promotion_id <= %d', // phpcs:ignore WordPress.DB.PreparedSQL
					$cutoff
				)
			);
		}
	}
}
