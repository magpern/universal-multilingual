<?php
/**
 * Promotion foundation schema tests — migration step 10 (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;

/**
 * `aiml_object_identity`, `aiml_promotion_state`, `aiml_promotion_log`.
 */
final class PromotionSchemaTest extends AimlTestCase {

	public function test_target_is_ten(): void {
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_all_three_tables_exist_after_migration(): void {
		global $wpdb;

		foreach ( array( Schema::object_identity(), Schema::promotion_state(), Schema::promotion_log() ) as $table ) {
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"Missing table {$table}"
			);
		}

		$this->assertSame( Migrator::TARGET, (int) get_option( Migrator::OPTION ) );
	}

	public function test_object_identity_columns_and_indexes(): void {
		global $wpdb;

		$names = array();
		foreach ( $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::object_identity() ) as $column ) { // phpcs:ignore WordPress.DB
			$names[ (string) $column->Field ] = (string) $column->Type; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		foreach ( array( 'identity_id', 'source_type', 'source_id', 'source_subtype', 'uuid', 'natural_key', 'natural_key_hash', 'natural_key_parts', 'first_seen_at', 'updated_at' ) as $required ) {
			$this->assertArrayHasKey( $required, $names );
		}

		// The full key is TEXT, never a length-capped VARCHAR — deep hierarchies
		// concatenate many ancestor slugs.
		$this->assertStringContainsStringIgnoringCase( 'text', $names['natural_key'] );
		$this->assertStringContainsStringIgnoringCase( 'binary(32)', $names['natural_key_hash'] );

		$keys = $this->index_map( Schema::object_identity() );
		$this->assertArrayHasKey( 'object_identity', $keys );
		$this->assertSame( array( 'source_type', 'source_id' ), $keys['object_identity'] );
		$this->assertArrayHasKey( 'uuid', $keys );
		$this->assertArrayHasKey( 'natural_lookup', $keys );
	}

	public function test_promotion_state_identity_key(): void {
		$keys = $this->index_map( Schema::promotion_state() );

		$this->assertArrayHasKey( 'promoted_identity', $keys );
		$this->assertSame( array( 'object_uuid', 'language_code', 'segment_hash' ), $keys['promoted_identity'] );
	}

	public function test_upgrade_from_version_nine(): void {
		global $wpdb;

		// Simulate a site that shipped v1.13.0 (schema 9) and dropped the new
		// tables, then received the v1.14.0 files without an activation hook.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::promotion_log() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::promotion_state() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::object_identity() ); // phpcs:ignore WordPress.DB
		update_option( Migrator::OPTION, 9, true );

		$migrator = new Migrator();
		$migrator->maybe_migrate();

		$this->assertSame( 10, $migrator->current_version() );
		foreach ( array( Schema::object_identity(), Schema::promotion_state(), Schema::promotion_log() ) as $table ) {
			$this->assertSame(
				$table,
				$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
			);
		}

		// Existing translation storage is untouched by step 10.
		$this->assertSame(
			Schema::translations(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::translations() ) )
		);

		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	public function test_step_ten_is_idempotent(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( Migrator::TARGET, $migrator->current_version() );
		$this->assertSame(
			Schema::object_identity(),
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::object_identity() ) )
		);
	}

	public function test_all_tables_lists_new_tables_before_translations(): void {
		$tables = Schema::all_tables();

		$translations = array_search( Schema::translations(), $tables, true );
		foreach ( array( Schema::object_identity(), Schema::promotion_state(), Schema::promotion_log() ) as $table ) {
			$pos = array_search( $table, $tables, true );
			$this->assertNotFalse( $pos );
			$this->assertLessThan( $translations, $pos );
		}
	}

	public function test_uninstall_handler_would_drop_the_new_tables(): void {
		// The retained-uninstall branch is exercised by LifecycleTest; the
		// destructive branch drops from Schema::all_tables(), so membership is
		// the guarantee that uninstall removes these three tables.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString( 'Schema::all_tables()', $source );
		$this->assertStringContainsString( 'PromotionCapabilities::revoke_all_roles', $source );
		$this->assertStringContainsString( 'PromotionCapabilities::VERSION_OPTION', $source );
		$this->assertContains( Schema::object_identity(), Schema::all_tables() );
		$this->assertContains( Schema::promotion_state(), Schema::all_tables() );
		$this->assertContains( Schema::promotion_log(), Schema::all_tables() );
	}

	/**
	 * Maps index name → ordered column list for a table.
	 *
	 * @param string $table Fully qualified table name.
	 * @return array<string, list<string>>
	 */
	private function index_map( string $table ): array {
		global $wpdb;

		$keys = array();
		foreach ( $wpdb->get_results( 'SHOW INDEX FROM ' . $table ) as $index ) { // phpcs:ignore WordPress.DB
			$keys[ (string) $index->Key_name ][] = (string) $index->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return $keys;
	}
}
