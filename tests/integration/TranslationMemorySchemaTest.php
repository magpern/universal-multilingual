<?php
/**
 * Translation memory schema migration tests.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;

/**
 * F11 WP1 — aiml_tm table creation and identity indexes.
 */
final class TranslationMemorySchemaTest extends AimlTestCase {

	public function test_tm_table_exists_after_migration(): void {
		global $wpdb;

		$table = Schema::tm();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found );
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_tm_table_has_origin_and_identity_index(): void {
		global $wpdb;

		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB
		$names   = array();
		foreach ( $columns as $column ) {
			$names[ (string) $column->Field ] = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'origin', $names );
		$this->assertArrayHasKey( 'quality', $names );
		$this->assertArrayHasKey( 'source_hash', $names );
		$this->assertArrayHasKey( 'context', $names );

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'tm_identity', $keys );
		$this->assertSame(
			array( 'source_hash', 'source_lang_id', 'target_lang_id', 'context' ),
			$keys['tm_identity']
		);
		$this->assertArrayHasKey( 'fuzzy_lookup', $keys );
		$this->assertArrayHasKey( 'origin_filter', $keys );
	}

	public function test_migration_from_version_one_is_idempotent(): void {
		global $wpdb;

		update_option( Migrator::OPTION, 1 );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tm() ); // phpcs:ignore WordPress.DB

		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame( $before, $after );
		$this->assertSame(
			Schema::tm(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::tm() ) )
		);

		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	public function test_all_tables_includes_tm_in_drop_safe_order(): void {
		$tables = Schema::all_tables();

		$this->assertContains( Schema::tm(), $tables );
		$this->assertLessThan(
			array_search( Schema::languages(), $tables, true ),
			array_search( Schema::tm(), $tables, true )
		);
	}
}
