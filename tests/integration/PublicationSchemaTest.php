<?php
/**
 * Publication axis schema migration tests (TI.7 / ADR-0020).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;
use AIMultilingual\Translation\Store;

/**
 * Schema v7 — additive publication columns on aiml_translations.
 */
final class PublicationSchemaTest extends AimlTestCase {

	/**
	 * Publication columns introduced in schema version 7.
	 *
	 * @return string[]
	 */
	private function publication_columns(): array {
		return array(
			'publish_status',
			'published_at',
			'published_by',
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
		$this->assertSame( 9, Migrator::TARGET );
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
	}

	public function test_publication_columns_and_index_exist_after_migration(): void {
		global $wpdb;

		$table = Schema::translations();

		foreach ( $this->publication_columns() as $column ) {
			$this->assertTrue( Schema::column_exists( $table, $column ), "Missing column {$column}" );
		}

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $table ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'lang_publish_status', $keys );
		$this->assertSame(
			array( 'language_id', 'publish_status', 'published_at' ),
			$keys['lang_publish_status']
		);

		$publish_status = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'publish_status'" ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $publish_status );
		$this->assertSame( 'unpublished', (string) $publish_status->Default ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_new_rows_default_to_unpublished(): void {
		global $wpdb;

		$language = $this->languages->default();
		$this->assertNotNull( $language );

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => 9101,
				'source_subtype'  => 'post',
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'post_title',
				'segment_key'     => 'publication-schema-default',
				'segment_hash'    => Store::segment_hash( 'post_title', 'publication-schema-default' ),
				'segment_kind'    => Store::KIND_FIELD,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_PLAIN,
				'source_text'     => 'Hello',
				'source_hash'     => Store::source_hash( 'Hello', Store::FORMAT_PLAIN ),
				'norm_version'    => Store::NORM_VERSION,
				'translated_text' => 'Hej',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$this->assertNotFalse( $ok );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT publish_status, published_at, published_by, status FROM '
				. Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE segment_key = %s',
				'publication-schema-default'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( Store::STATUS_MACHINE_TRANSLATED, (string) $row->status );
		$this->assertSame( Store::PUBLISH_UNPUBLISHED, (string) $row->publish_status );
		$this->assertNull( $row->published_at );
		$this->assertNull( $row->published_by );
	}

	public function test_backfill_marks_overlayable_rows_published(): void {
		global $wpdb;

		$table = Schema::translations();
		$this->strip_publication_schema( $table );
		update_option( Migrator::OPTION, 6 );

		$language = $this->languages->default();
		$this->assertNotNull( $language );
		$now = current_time( 'mysql' );

		$wpdb->delete( $table, array( 'segment_key' => 'publication-backfill-visible' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'segment_key' => 'publication-backfill-missing' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => 9102,
				'source_subtype'  => 'post',
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'post_title',
				'segment_key'     => 'publication-backfill-visible',
				'segment_hash'    => Store::segment_hash( 'post_title', 'publication-backfill-visible' ),
				'segment_kind'    => Store::KIND_FIELD,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_PLAIN,
				'source_text'     => 'Visible',
				'source_hash'     => Store::source_hash( 'Visible', Store::FORMAT_PLAIN ),
				'norm_version'    => Store::NORM_VERSION,
				'translated_text' => 'Synlig',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => 9103,
				'source_subtype'  => 'post',
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'post_title',
				'segment_key'     => 'publication-backfill-missing',
				'segment_hash'    => Store::segment_hash( 'post_title', 'publication-backfill-missing' ),
				'segment_kind'    => Store::KIND_FIELD,
				'segment_order'   => 0,
				'text_format'     => Store::FORMAT_PLAIN,
				'source_text'     => 'Missing',
				'source_hash'     => Store::source_hash( 'Missing', Store::FORMAT_PLAIN ),
				'norm_version'    => Store::NORM_VERSION,
				'translated_text' => '',
				'status'          => Store::STATUS_MISSING,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		( new Migrator() )->maybe_migrate();

		$visible = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT publish_status FROM ' . $table . ' WHERE segment_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				'publication-backfill-visible'
			)
		);
		$missing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT publish_status FROM ' . $table . ' WHERE segment_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL
				'publication-backfill-missing'
			)
		);

		$this->assertNotNull( $visible );
		$this->assertNotNull( $missing );
		$this->assertSame( Store::PUBLISH_PUBLISHED, (string) $visible->publish_status );
		$this->assertSame( Store::PUBLISH_UNPUBLISHED, (string) $missing->publish_status );
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );

		$this->commit_schema_checkpoint();
	}

	public function test_migration_from_version_six_is_idempotent(): void {
		global $wpdb;

		$table = Schema::translations();
		$this->strip_publication_schema( $table );
		update_option( Migrator::OPTION, 6 );

		$store_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame( 9, Migrator::TARGET );

		foreach ( $this->publication_columns() as $column ) {
			$this->assertTrue( Schema::column_exists( $table, $column ) );
		}
		$this->assertTrue( Schema::index_exists( $table, 'lang_publish_status' ) );
		$this->assertSame( $store_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ) ); // phpcs:ignore WordPress.DB

		$this->commit_schema_checkpoint();
	}

	/**
	 * Drops publication columns/index to simulate a schema-v6 translations table.
	 *
	 * @param string $table Fully qualified translations table.
	 */
	private function strip_publication_schema( string $table ): void {
		global $wpdb;

		$escaped = str_replace( '`', '``', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL against Schema::translations() only.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"ALTER TABLE `{$escaped}` ROW_FORMAT=DYNAMIC"
		);

		if ( Schema::index_exists( $table, 'lang_publish_status' ) ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped}` DROP INDEX lang_publish_status"
			);
		}

		foreach ( array_reverse( $this->publication_columns() ) as $column ) {
			if ( ! Schema::column_exists( $table, $column ) ) {
				continue;
			}

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped}` DROP COLUMN `" . str_replace( '`', '``', $column ) . '`'
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
