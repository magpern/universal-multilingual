<?php
/**
 * Migration step 9 — locale uniqueness + wider code column.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Database\Schema;

/**
 * Step 9 (ADR-0028 / ADR-0029) widens `aiml_languages.code` to VARCHAR(20) and
 * adds `UNIQUE KEY locale`. It must never advance the recorded schema version
 * unless the live schema is verified, and it must refuse — not force — the
 * upgrade when duplicate locales already exist.
 */
final class LanguageLocaleMigrationTest extends AimlTestCase {

	/**
	 * The bootstrap runs migrate(); the live schema must already be at 9.
	 */
	public function test_live_schema_is_at_version_nine(): void {
		global $wpdb;

		$this->assertSame( 9, Migrator::TARGET );
		$this->assertSame( 9, (int) get_option( Migrator::OPTION ) );

		$table = Schema::languages();

		$code = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'code'" ); // phpcs:ignore WordPress.DB
		$this->assertMatchesRegularExpression( '/^varchar\(20\)/i', (string) $code->Type ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$unique_locale = false;
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}" ) as $row ) { // phpcs:ignore WordPress.DB
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'locale' === (string) $row->Key_name && '0' === (string) $row->Non_unique ) {
				$unique_locale = true;
			}
		}
		$this->assertTrue( $unique_locale, 'aiml_languages must have a UNIQUE KEY on locale.' );
	}

	public function test_the_database_rejects_a_duplicate_locale(): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$this->languages->insert(
			array(
				'code'   => 'sv',
				'locale' => 'sv_SE',
				'name'   => 'Swedish',
			)
		);

		// phpcs:disable WordPress.DB
		$wpdb->hide_errors();
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::languages() . ' (code, locale, name, native_name, direction, is_default, status, sort_order, created_at, updated_at)'
				. " VALUES (%s, %s, %s, '', 'ltr', 0, 'preview', 0, %s, %s)",
				'zz',
				'sv_SE',
				'Bypass',
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB

		$this->assertFalse( $ok, 'A raw duplicate-locale INSERT must be rejected by the unique index.' );
	}

	public function test_migration_step_nine_is_idempotent(): void {
		update_option( Migrator::OPTION, 8, true );

		$migrator = new Migrator();
		$migrator->maybe_migrate();
		$migrator->maybe_migrate();

		$this->assertSame( 9, $migrator->current_version() );
		$this->assertFalse( (bool) get_option( Migrator::BLOCKED_OPTION ) );

		update_option( Migrator::OPTION, Migrator::TARGET, true );
		$this->commit_transaction();
	}

	/**
	 * The end-to-end blocked cycle: drop the index, seed a duplicate, watch the
	 * upgrade refuse to advance, resolve the duplicate, watch it complete.
	 *
	 * Every statement here auto-commits (DDL), so the table is fully restored at
	 * the end regardless of assertions.
	 */
	public function test_duplicate_locales_block_then_release_the_upgrade(): void {
		global $wpdb;

		$table = Schema::languages();
		$now   = current_time( 'mysql', true );

		try {
			// phpcs:disable WordPress.DB
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX locale" );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (code, locale, name, native_name, direction, is_default, status, sort_order, created_at, updated_at)"
					. " VALUES ('m1', %s, 'One', '', 'ltr', 0, 'preview', 0, %s, %s),"
					. " ('m2', %s, 'Two', '', 'ltr', 0, 'preview', 0, %s, %s)",
					'mig_TT',
					$now,
					$now,
					'mig_TT',
					$now,
					$now
				)
			);
			// phpcs:enable WordPress.DB

			update_option( Migrator::OPTION, 8, true );
			delete_option( Migrator::BLOCKED_OPTION );

			( new Migrator() )->maybe_migrate();

			$this->assertSame( 8, (int) get_option( Migrator::OPTION ), 'The upgrade must not advance while a locale is shared.' );
			$blocked = get_option( Migrator::BLOCKED_OPTION );
			$this->assertIsArray( $blocked );
			$this->assertContains( 'mig_TT', $blocked );

			// The operator gives one of the rows a distinct locale.
			// phpcs:disable WordPress.DB
			$wpdb->query( "UPDATE {$table} SET locale = 'mig_UU' WHERE code = 'm2'" );
			// phpcs:enable WordPress.DB

			( new Migrator() )->maybe_migrate();

			$this->assertSame( 9, (int) get_option( Migrator::OPTION ), 'The upgrade must complete once no locale is shared.' );
			$this->assertFalse( (bool) get_option( Migrator::BLOCKED_OPTION ) );
		} finally {
			// phpcs:disable WordPress.DB
			$wpdb->query( "DELETE FROM {$table} WHERE code IN ('m1','m2')" );
			if ( ! $this->has_unique_locale_index() ) {
				$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY locale (locale)" );
			}
			// phpcs:enable WordPress.DB

			update_option( Migrator::OPTION, Migrator::TARGET, true );
			delete_option( Migrator::BLOCKED_OPTION );
			$this->commit_transaction();
		}
	}

	public function test_blocked_notice_is_shown_only_to_admins_and_only_when_blocked(): void {
		update_option( Migrator::BLOCKED_OPTION, array( 'mig_TT' ), true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->capture_notice() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$notice = $this->capture_notice();
		$this->assertStringContainsString( 'paused', $notice );
		$this->assertStringContainsString( 'mig_TT', $notice );
		$this->assertStringNotContainsString( 'delete', strtolower( $notice ) );

		delete_option( Migrator::BLOCKED_OPTION );
		$this->assertSame( '', $this->capture_notice() );
	}

	private function capture_notice(): string {
		ob_start();
		Migrator::render_blocked_notice();
		return (string) ob_get_clean();
	}

	private function has_unique_locale_index(): bool {
		global $wpdb;

		foreach ( (array) $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::languages() ) as $row ) { // phpcs:ignore WordPress.DB
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'locale' === (string) $row->Key_name && '0' === (string) $row->Non_unique ) {
				return true;
			}
		}

		return false;
	}
}
