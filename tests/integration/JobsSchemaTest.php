<?php
/**
 * Background Translation Jobs schema migration tests (J1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;

/**
 * Jobs J1 — aiml_jobs and aiml_job_items tables.
 */
final class JobsSchemaTest extends AimlTestCase {

	public function test_jobs_tables_exist_after_migration(): void {
		global $wpdb;

		$this->assertSame(
			Schema::jobs(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::jobs() ) )
		);
		$this->assertSame(
			Schema::job_items(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::job_items() ) )
		);
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_jobs_table_columns_and_indexes(): void {
		global $wpdb;

		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::jobs() ); // phpcs:ignore WordPress.DB
		$names   = array();
		foreach ( $columns as $column ) {
			$names[ (string) $column->Field ] = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		foreach ( array(
			'job_id',
			'job_type',
			'status',
			'requested_action',
			'batch_id',
			'idempotency_key',
			'active_lock_key',
			'checkpoint',
			'created_at',
			'updated_at',
		) as $required ) {
			$this->assertArrayHasKey( $required, $names );
		}

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::jobs() ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'idempotency_key', $keys );
		$this->assertSame( array( 'idempotency_key' ), $keys['idempotency_key'] );
		$this->assertArrayHasKey( 'active_lock_key', $keys );
		$this->assertSame( array( 'active_lock_key' ), $keys['active_lock_key'] );
		$this->assertArrayHasKey( 'status_updated', $keys );
		$this->assertArrayHasKey( 'batch_id', $keys );
		$this->assertArrayHasKey( 'object_lang', $keys );
		$this->assertArrayHasKey( 'lease_expires', $keys );
	}

	public function test_job_items_table_columns_and_indexes(): void {
		global $wpdb;

		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::job_items() ); // phpcs:ignore WordPress.DB
		$names   = array();
		foreach ( $columns as $column ) {
			$names[ (string) $column->Field ] = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		foreach ( array(
			'item_id',
			'job_id',
			'segment_key',
			'status',
			'result_code',
			'created_at',
			'updated_at',
		) as $required ) {
			$this->assertArrayHasKey( $required, $names );
		}

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::job_items() ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'job_segment', $keys );
		$this->assertSame( array( 'job_id', 'segment_key' ), $keys['job_segment'] );
		$this->assertArrayHasKey( 'job_status', $keys );
		$this->assertArrayHasKey( 'status_updated', $keys );
	}

	public function test_migration_from_version_five_is_idempotent(): void {
		global $wpdb;

		update_option( Migrator::OPTION, 5 );
		$jobs_before  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::jobs() ); // phpcs:ignore WordPress.DB
		$items_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::job_items() ); // phpcs:ignore WordPress.DB

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame( $jobs_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::jobs() ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( $items_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::job_items() ) ); // phpcs:ignore WordPress.DB

		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	public function test_all_tables_includes_job_items_before_jobs(): void {
		$tables = Schema::all_tables();

		$this->assertContains( Schema::job_items(), $tables );
		$this->assertContains( Schema::jobs(), $tables );
		$this->assertLessThan(
			array_search( Schema::jobs(), $tables, true ),
			array_search( Schema::job_items(), $tables, true )
		);
		$this->assertLessThan(
			array_search( Schema::translations(), $tables, true ),
			array_search( Schema::jobs(), $tables, true )
		);
	}

	public function test_store_tm_glossary_review_columns_unchanged_by_jobs_step(): void {
		global $wpdb;

		$this->assertSame(
			Schema::translations(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::translations() ) )
		);
		$this->assertTrue( Schema::column_exists( Schema::translations(), 'review_status' ) );
		$this->assertSame(
			Schema::tm(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::tm() ) )
		);
		$this->assertSame(
			Schema::glossary(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::glossary() ) )
		);
	}
}
