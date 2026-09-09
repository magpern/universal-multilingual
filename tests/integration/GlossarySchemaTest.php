<?php
/**
 * Glossary schema migration tests (G1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;

/**
 * Glossary MVP — aiml_glossary table and version option.
 */
final class GlossarySchemaTest extends AimlTestCase {

	public function test_glossary_table_exists_after_migration(): void {
		global $wpdb;

		$table = Schema::glossary();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found );
		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
		$this->assertSame( 9, Migrator::TARGET );
		$this->assertSame( 0, (int) get_option( Schema::GLOSSARY_VERSION_OPTION, -1 ) );
	}

	public function test_glossary_table_columns_and_indexes(): void {
		global $wpdb;

		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::glossary() ); // phpcs:ignore WordPress.DB
		$names   = array();
		foreach ( $columns as $column ) {
			$names[ (string) $column->Field ] = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		foreach ( array(
			'glossary_id',
			'source_lang_id',
			'target_lang_id',
			'source_term',
			'source_term_normalized',
			'target_term',
			'context',
			'description',
			'is_active',
			'created_at',
			'updated_at',
		) as $required ) {
			$this->assertArrayHasKey( $required, $names );
		}

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::glossary() ); // phpcs:ignore WordPress.DB
		$keys    = array();
		foreach ( $indexes as $index ) {
			$key            = (string) $index->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$keys[ $key ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$this->assertArrayHasKey( 'glossary_identity', $keys );
		$this->assertSame(
			array( 'source_lang_id', 'target_lang_id', 'source_term_normalized' ),
			$keys['glossary_identity']
		);
		$this->assertArrayHasKey( 'glossary_pair_active', $keys );
		$this->assertArrayHasKey( 'glossary_updated', $keys );
	}

	public function test_migration_from_version_three_is_idempotent(): void {
		global $wpdb;

		update_option( Migrator::OPTION, 3 );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::glossary() ); // phpcs:ignore WordPress.DB

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::glossary() ); // phpcs:ignore WordPress.DB

		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame( $before, $after );
		$this->assertSame(
			Schema::glossary(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::glossary() ) )
		);
		$this->assertSame( 0, (int) get_option( Schema::GLOSSARY_VERSION_OPTION, -1 ) );

		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	public function test_all_tables_includes_glossary_before_languages(): void {
		$tables = Schema::all_tables();

		$this->assertContains( Schema::glossary(), $tables );
		$this->assertLessThan(
			array_search( Schema::languages(), $tables, true ),
			array_search( Schema::glossary(), $tables, true )
		);
	}

	public function test_store_and_tm_tables_unchanged_by_glossary_step(): void {
		global $wpdb;

		$this->assertSame(
			Schema::translations(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::translations() ) )
		);
		$this->assertSame(
			Schema::tm(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::tm() ) )
		);
	}
}
