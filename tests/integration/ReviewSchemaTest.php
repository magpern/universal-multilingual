<?php
/**
 * Review Workflow schema migration tests (R1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Translation\Store;

/**
 * Schema v5 — additive review columns on aiml_translations (ADR-0015).
 */
final class ReviewSchemaTest extends AimlTestCase {

	/**
	 * Review columns introduced in schema version 5.
	 *
	 * @return string[]
	 */
	private function review_columns(): array {
		return array(
			'review_status',
			'review_submitted_by',
			'review_submitted_at',
			'submitted_translation_hash',
			'rejection_reason',
			'rejected_by',
			'rejected_at',
		);
	}

	/**
	 * DDL commits the PHPUnit transaction; pin schema version so later tests
	 * do not observe a rolled-back `aiml_db_version`.
	 */
	private function commit_schema_checkpoint(): void {
		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	public function test_schema_target_is_seven(): void {
		$this->assertSame( 10, Migrator::TARGET );
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
	}

	public function test_review_columns_and_index_exist_after_migration(): void {
		global $wpdb;

		$table = Schema::translations();

		foreach ( $this->review_columns() as $column ) {
			$this->assertTrue( Schema::column_exists( $table, $column ), "Missing column {$column}" );
		}

		$this->assertTrue( Schema::column_exists( $table, 'reviewed_by' ) );
		$this->assertTrue( Schema::column_exists( $table, 'reviewed_at' ) );

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $table ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'lang_review_queue', $keys );
		$this->assertSame(
			array( 'language_id', 'review_status', 'review_submitted_at' ),
			$keys['lang_review_queue']
		);

		$review_status = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'review_status'" ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $review_status );
		$this->assertSame( 'not_submitted', (string) $review_status->Default ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_existing_rows_default_to_not_submitted(): void {
		global $wpdb;

		$language = $this->languages->default();
		$this->assertNotNull( $language );

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => 9001,
				'source_subtype'  => 'post',
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'post_title',
				'segment_key'     => 'review-schema-default',
				'segment_hash'    => Store::segment_hash( 'post_title', 'review-schema-default' ),
				'segment_kind'    => Store::KIND_FIELD,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_PLAIN,
				'source_text'     => 'Hello',
				'source_hash'     => Store::source_hash( 'Hello', Store::FORMAT_PLAIN ),
				'norm_version'    => Store::NORM_VERSION,
				'translated_text' => 'Hej',
				'status'          => Store::STATUS_REVIEWED,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$this->assertNotFalse( $ok );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT review_status, status, submitted_translation_hash, rejection_reason FROM '
				. Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE segment_key = %s',
				'review-schema-default'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( Store::STATUS_REVIEWED, (string) $row->status );
		$this->assertSame( 'not_submitted', (string) $row->review_status );
		$this->assertSame( '', (string) $row->submitted_translation_hash );
		$this->assertSame( '', (string) $row->rejection_reason );
	}

	public function test_migration_from_version_four_is_idempotent(): void {
		global $wpdb;

		$table = Schema::translations();
		$this->strip_review_schema( $table );
		update_option( Migrator::OPTION, 4 );

		$tm_before       = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB
		$glossary_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::glossary() ); // phpcs:ignore WordPress.DB
		$store_before    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame( 10, Migrator::TARGET );

		foreach ( $this->review_columns() as $column ) {
			$this->assertTrue( Schema::column_exists( $table, $column ) );
		}
		$this->assertTrue( Schema::index_exists( $table, 'lang_review_queue' ) );

		$this->assertSame( $tm_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tm() ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( $glossary_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::glossary() ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( $store_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ) ); // phpcs:ignore WordPress.DB

		$this->commit_schema_checkpoint();
	}

	public function test_interrupted_migration_resumes_safely(): void {
		$table = Schema::translations();
		$this->strip_review_schema( $table );
		update_option( Migrator::OPTION, 4 );

		global $wpdb;

		// Simulate a partial step_5: only the first column applied before interrupt.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- DDL against Schema::translations() only.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'ALTER TABLE `' . str_replace( '`', '``', $table )
			. "` ADD COLUMN review_status VARCHAR(24) NOT NULL DEFAULT 'not_submitted'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		$this->assertTrue( Schema::column_exists( $table, 'review_status' ) );
		$this->assertFalse( Schema::column_exists( $table, 'rejected_at' ) );
		$this->assertSame( 4, (int) get_option( Migrator::OPTION ) );

		( new Migrator() )->maybe_migrate();

		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
		foreach ( $this->review_columns() as $column ) {
			$this->assertTrue( Schema::column_exists( $table, $column ) );
		}
		$this->assertTrue( Schema::index_exists( $table, 'lang_review_queue' ) );

		$this->commit_schema_checkpoint();
	}

	public function test_store_and_tm_tables_survive_review_migration(): void {
		global $wpdb;

		$this->assertSame(
			Schema::translations(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::translations() ) )
		);
		$this->assertSame(
			Schema::tm(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::tm() ) )
		);
		$this->assertSame(
			Schema::glossary(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::glossary() ) )
		);
	}

	/**
	 * Drops review columns/index to simulate a schema-v4 translations table.
	 *
	 * @param string $table Fully qualified translations table.
	 */
	private function strip_review_schema( string $table ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- DDL against Schema::translations() only.
		if ( Schema::index_exists( $table, 'lang_review_queue' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'ALTER TABLE `' . str_replace( '`', '``', $table ) . '` DROP INDEX lang_review_queue'
			);
		}

		foreach ( array_reverse( $this->review_columns() ) as $column ) {
			if ( ! Schema::column_exists( $table, $column ) ) {
				continue;
			}

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'ALTER TABLE `' . str_replace( '`', '``', $table ) . '` DROP COLUMN `'
				. str_replace( '`', '``', $column ) . '`'
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}
}
