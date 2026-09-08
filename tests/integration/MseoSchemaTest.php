<?php
/**
 * MSEO.0 schema migration tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;

/**
 * TARGET 8 — localized URL foundation tables (M0AC2–M0AC7, M0AC19).
 */
final class MseoSchemaTest extends AimlTestCase {

	private function commit_schema_checkpoint(): void {
		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	public function test_schema_target_is_eight(): void {
		$this->assertSame( 9, Migrator::TARGET );
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
	}

	public function test_mseo_tables_exist_after_migration(): void {
		global $wpdb;

		foreach ( array(
			Schema::slug_routes(),
			Schema::route_history(),
			Schema::slug_reindex_frontier(),
		) as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	public function test_slug_origin_column_on_translations(): void {
		global $wpdb;

		$table = Schema::translations();
		$this->assertTrue( Schema::column_exists( $table, 'slug_origin' ) );

		$col = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'slug_origin'" ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $col );
		$this->assertSame( 'NO', (string) $col->Null ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( '', (string) $col->Default ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_slug_routes_nullability_and_defaults(): void {
		global $wpdb;

		$table = Schema::slug_routes();
		$cols  = $this->column_map( $table );

		$this->assertSame( 'NO', $cols['route_status']['Null'] );
		$this->assertSame( 'inactive', $cols['route_status']['Default'] );
		$this->assertSame( 'NO', $cols['slug_origin']['Null'] );
		$this->assertSame( 'generated', $cols['slug_origin']['Default'] );
		$this->assertSame( 'YES', $cols['activated_at']['Null'] );
		$this->assertNull( $cols['activated_at']['Default'] );
	}

	public function test_binary_hash_columns(): void {
		global $wpdb;

		$routes = $this->column_map( Schema::slug_routes() );
		$this->assertSame( 'binary(32)', strtolower( (string) $routes['source_path_hash']['Type'] ) );
		$this->assertSame( 'binary(32)', strtolower( (string) $routes['localized_path_hash']['Type'] ) );

		$history = $this->column_map( Schema::route_history() );
		$this->assertSame( 'binary(32)', strtolower( (string) $history['historical_path_hash']['Type'] ) );
	}

	public function test_unique_indexes_exist(): void {
		global $wpdb;

		$routes = $this->index_map( Schema::slug_routes() );
		$this->assertArrayHasKey( 'object_language', $routes );
		$this->assertSame(
			array( 'source_type', 'source_id', 'language_id' ),
			$routes['object_language']
		);
		$this->assertArrayHasKey( 'localized_identity', $routes );
		$this->assertArrayHasKey( 'source_identity', $routes );

		$history = $this->index_map( Schema::route_history() );
		$this->assertArrayHasKey( 'history_identity', $history );
		$this->assertArrayHasKey( 'source_lang_history', $history );

		$frontier = $this->index_map( Schema::slug_reindex_frontier() );
		$this->assertArrayHasKey( 'parent_frontier', $frontier );
	}

	public function test_all_tables_includes_mseo_in_drop_safe_order(): void {
		$tables = Schema::all_tables();
		$this->assertContains( Schema::slug_routes(), $tables );
		$this->assertContains( Schema::route_history(), $tables );
		$this->assertContains( Schema::slug_reindex_frontier(), $tables );
		$this->assertLessThan(
			array_search( Schema::translations(), $tables, true ),
			array_search( Schema::slug_routes(), $tables, true )
		);
	}

	public function test_upgrade_from_target_seven_is_idempotent(): void {
		global $wpdb;

		$this->strip_mseo_schema();
		update_option( Migrator::OPTION, 7 );

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( 9, Migrator::TARGET );
		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame(
			Schema::slug_routes(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::slug_routes() ) )
		);
		$this->assertTrue( Schema::column_exists( Schema::translations(), 'slug_origin' ) );

		$this->commit_schema_checkpoint();
	}

	/**
	 * @param string $table Table name.
	 * @return array<string, array{Type: string, Null: string, Default: string|null}>
	 */
	private function column_map( string $table ): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table ); // phpcs:ignore WordPress.DB
		$map  = array();
		foreach ( (array) $rows as $row ) {
			$name         = (string) $row->Field; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$map[ $name ] = array(
				'Type'    => (string) $row->Type, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'Null'    => (string) $row->Null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'Default' => $row->Default ?? null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			);
		}

		return $map;
	}

	/**
	 * @param string $table Table name.
	 * @return array<string, string[]>
	 */
	private function index_map( string $table ): array {
		global $wpdb;

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $table ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( (array) $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return $keys;
	}

	/**
	 * Drops MSEO step-8 artifacts to simulate TARGET 7 database.
	 */
	private function strip_mseo_schema(): void {
		global $wpdb;

		foreach ( array(
			Schema::slug_reindex_frontier(),
			Schema::route_history(),
			Schema::slug_routes(),
		) as $table ) {
			$escaped = str_replace( '`', '``', $table );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL against Schema table names only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"DROP TABLE IF EXISTS `{$escaped}`"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$translations = Schema::translations();
		if ( Schema::column_exists( $translations, 'slug_origin' ) ) {
			$escaped = str_replace( '`', '``', $translations );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL against Schema table names only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped}` DROP COLUMN `slug_origin`"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
