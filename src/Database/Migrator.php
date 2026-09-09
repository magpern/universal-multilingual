<?php
/**
 * Versioned schema migrations.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Database;

/**
 * Runs ordered, explicit SQL migration steps and records the schema version.
 *
 * The version lives in its own option (`aiml_db_version`), separate from the
 * settings array, so a settings reset can never be mistaken for a schema
 * reset.
 *
 * Migrations run from two places: the activation hook, and an `admin_init`
 * drift check. The second is not redundant — the target environment deploys
 * this plugin as a bind mount, and updating files in place never fires an
 * activation hook. Without the drift check a schema upgrade would silently
 * never happen there.
 *
 * Every step is idempotent so a partially applied migration can be re-run
 * safely.
 */
final class Migrator {

	/**
	 * Option holding the applied schema version.
	 */
	public const OPTION = 'aiml_db_version';

	/**
	 * Option listing locales that block the step 9 unique-index upgrade.
	 */
	public const BLOCKED_OPTION = 'aiml_locale_unique_blocked';

	/**
	 * Schema version this build expects.
	 */
	public const TARGET = 9;

	/**
	 * Applies any migration steps newer than the recorded version.
	 *
	 * The version is written after each individual step, so an interrupted run
	 * resumes from the step that failed rather than replaying from zero.
	 */
	public function migrate(): void {
		$current = $this->current_version();

		foreach ( $this->steps() as $version => $step ) {
			if ( $version <= $current ) {
				continue;
			}

			// A step that returns false could not complete (for example step 9
			// blocked by pre-existing duplicate data). The recorded version
			// stays where it is; the next maybe_migrate() run retries.
			if ( false === $step() ) {
				break;
			}

			update_option( self::OPTION, $version, true );
		}
	}

	/**
	 * Runs migrations only when the recorded version is behind this build.
	 */
	public function maybe_migrate(): void {
		if ( $this->current_version() >= self::TARGET ) {
			return;
		}

		$this->migrate();
	}

	/**
	 * Schema version currently applied to the database.
	 */
	public function current_version(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Ordered migration steps, keyed by the version they produce.
	 *
	 * A step returns void (or true) on success, or false when it could not
	 * complete and the run should stop without advancing.
	 *
	 * @return array<int, callable():(void|bool)>
	 */
	private function steps(): array {
		return array(
			1 => array( $this, 'step_1_initial_tables' ),
			2 => array( $this, 'step_2_translation_memory' ),
			3 => array( $this, 'step_3_rollout_metrics_daily' ),
			4 => array( $this, 'step_4_glossary' ),
			5 => array( $this, 'step_5_review_workflow' ),
			6 => array( $this, 'step_6_background_jobs' ),
			7 => array( $this, 'step_7_publication_axis' ),
			8 => array( $this, 'step_8_mseo_localized_url_foundation' ),
			9 => array( $this, 'step_9_language_metadata' ),
		);
	}

	/**
	 * Step 1 — the Milestone 1 tables.
	 *
	 * Only `aiml_languages` and `aiml_translations` are created. The slug
	 * projection, jobs, glossary, translation memory, usage and string registry
	 * tables arrive with the milestones that use them, each as its own step.
	 */
	private function step_1_initial_tables(): void {
		global $wpdb;

		$wpdb->query( Schema::create_languages() );    // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( Schema::create_translations() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Step 2 — F11 translation memory catalogue (`aiml_tm`).
	 */
	private function step_2_translation_memory(): void {
		global $wpdb;

		$wpdb->query( Schema::create_tm() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Step 3 — F12 daily rollout metrics aggregates.
	 */
	private function step_3_rollout_metrics_daily(): void {
		global $wpdb;

		$wpdb->query( Schema::create_metrics_daily() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Step 4 — Glossary MVP lexicon table and version option (ADR-0014).
	 */
	private function step_4_glossary(): void {
		global $wpdb;

		$wpdb->query( Schema::create_glossary() ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( false === get_option( Schema::GLOSSARY_VERSION_OPTION, false ) ) {
			add_option( Schema::GLOSSARY_VERSION_OPTION, 0, '', true );
		}
	}

	/**
	 * Step 5 — Review Workflow additive columns on the Store (ADR-0015).
	 *
	 * Existing rows keep translation content and TM links. Review defaults to
	 * `not_submitted` (fail closed for legacy `status=reviewed`). Columns are
	 * added one at a time so an interrupted upgrade can resume safely.
	 */
	private function step_5_review_workflow(): void {
		global $wpdb;

		$table         = Schema::translations();
		$escaped_table = str_replace( '`', '``', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema table identifier only.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"ALTER TABLE `{$escaped_table}` ROW_FORMAT=DYNAMIC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$columns = array(
			'review_status'              => "VARCHAR(24) NOT NULL DEFAULT 'not_submitted'",
			'review_submitted_by'        => 'BIGINT UNSIGNED NULL',
			'review_submitted_at'        => 'DATETIME NULL',
			'submitted_translation_hash' => "CHAR(40) NOT NULL DEFAULT ''",
			'rejection_reason'           => "VARCHAR(512) NOT NULL DEFAULT ''",
			'rejected_by'                => 'BIGINT UNSIGNED NULL',
			'rejected_at'                => 'DATETIME NULL',
		);

		foreach ( $columns as $name => $definition ) {
			if ( Schema::column_exists( $table, $name ) ) {
				continue;
			}

			$escaped_table = str_replace( '`', '``', $table );
			$escaped_name  = str_replace( '`', '``', $name );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- additive DDL; identifiers from Schema only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped_table}` ADD COLUMN `{$escaped_name}` {$definition}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! Schema::index_exists( $table, 'lang_review_queue' ) ) {
			$escaped_table = str_replace( '`', '``', $table );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- additive DDL; identifiers from Schema only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped_table}` ADD KEY lang_review_queue (language_id, review_status, review_submitted_at)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Step 6 — Background Translation Jobs tables (ADR-0011 / J1).
	 *
	 * Creates `aiml_jobs` and `aiml_job_items` only. Action Scheduler hooks
	 * (`aiml_run_job`, `aiml_jobs_sweep`) are registered in J4 — not here.
	 */
	private function step_6_background_jobs(): void {
		global $wpdb;

		$wpdb->query( Schema::create_jobs() );      // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( Schema::create_job_items() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Step 7 — Segment publication axis on the Store (ADR-0020 / TI.7).
	 *
	 * Additive columns default to `unpublished` for new writes. Existing rows
	 * that were publicly overlayable under the most permissive pre-TI.7 path
	 * (non-empty translated_text; status not ignored/missing) are backfilled
	 * to `published` so upgrades do not hide currently-visible translations.
	 */
	private function step_7_publication_axis(): void {
		global $wpdb;

		$table         = Schema::translations();
		$escaped_table = str_replace( '`', '``', $table );

		// Ensure DYNAMIC row format before additive ALTERs (InnoDB row-size headroom).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema table identifier only.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"ALTER TABLE `{$escaped_table}` ROW_FORMAT=DYNAMIC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$columns = array(
			'publish_status' => "VARCHAR(24) NOT NULL DEFAULT 'unpublished'",
			'published_at'   => 'DATETIME NULL',
			'published_by'   => 'BIGINT UNSIGNED NULL',
		);

		foreach ( $columns as $name => $definition ) {
			if ( Schema::column_exists( $table, $name ) ) {
				continue;
			}

			$escaped_name = str_replace( '`', '``', $name );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- additive DDL; identifiers from Schema only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped_table}` ADD COLUMN `{$escaped_name}` {$definition}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! Schema::index_exists( $table, 'lang_publish_status' ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- additive DDL; identifiers from Schema only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped_table}` ADD KEY lang_publish_status (language_id, publish_status, published_at)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- backfill uses Schema table name only.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE `{$escaped_table}`
			SET publish_status = 'published',
				published_at = COALESCE(published_at, updated_at, UTC_TIMESTAMP())
			WHERE publish_status = 'unpublished'
			  AND translated_text IS NOT NULL
			  AND TRIM(translated_text) <> ''
			  AND status NOT IN ('ignored', 'missing')"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Step 8 — Localized URL foundation tables (ADR-0023 / MSEO.0).
	 *
	 * Creates route, history, and reindex frontier tables. Adds slug_origin to
	 * the Store. No data backfill; foundation only — zero public URL change.
	 */
	private function step_8_mseo_localized_url_foundation(): void {
		global $wpdb;

		$wpdb->query( Schema::create_slug_routes() );           // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( Schema::create_route_history() );         // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( Schema::create_slug_reindex_frontier() ); // phpcs:ignore WordPress.DB.PreparedSQL

		$table         = Schema::translations();
		$escaped_table = str_replace( '`', '``', $table );

		if ( ! Schema::column_exists( $table, 'slug_origin' ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- additive DDL; identifiers from Schema only.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"ALTER TABLE `{$escaped_table}` ADD COLUMN `slug_origin` VARCHAR(16) NOT NULL DEFAULT ''"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Step 9 — language identity integrity (ADR-0028 / ADR-0029).
	 *
	 * Widens `aiml_languages.code` to VARCHAR(20) for three-segment URL codes
	 * and adds `UNIQUE KEY locale (locale)` so two languages can never share a
	 * WordPress locale.
	 *
	 * The unique index cannot be created while duplicate locales exist, and the
	 * plugin must not merge language rows on an operator's behalf. So this step
	 * first checks for duplicates: if any are found it records them, leaves the
	 * schema (and the recorded version) untouched, and returns false — the
	 * admin notice from {@see render_blocked_notice()} then asks an operator to
	 * resolve the conflict, and the next migration run retries. The ALTER is
	 * verified against the live schema before the version is allowed to advance.
	 *
	 * @return bool True when the schema is at version 9; false when blocked.
	 */
	private function step_9_language_metadata(): bool {
		global $wpdb;

		$table         = Schema::languages();
		$escaped_table = str_replace( '`', '``', $table );

		$has_unique_locale = $this->has_unique_index( $table, 'locale' );
		$code_is_wide      = 20 === $this->column_char_length( $table, 'code' );

		if ( $has_unique_locale && $code_is_wide ) {
			delete_option( self::BLOCKED_OPTION );

			return true;
		}

		$clauses = array();

		if ( ! $code_is_wide ) {
			$clauses[] = 'MODIFY code VARCHAR(20) NOT NULL';
		}

		if ( ! $has_unique_locale ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema table identifier only.
			$duplicates = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT locale FROM `{$escaped_table}` GROUP BY locale HAVING COUNT(*) > 1"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! empty( $duplicates ) ) {
				update_option(
					self::BLOCKED_OPTION,
					array_values( array_map( 'strval', $duplicates ) ),
					true
				);

				return false;
			}

			$clauses[] = 'ADD UNIQUE KEY locale (locale)';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema identifiers only; clause list is a fixed literal set.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"ALTER TABLE `{$escaped_table}` " . implode( ', ', $clauses )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$verified = $this->has_unique_index( $table, 'locale' )
			&& 20 === $this->column_char_length( $table, 'code' );

		if ( ! $verified ) {
			// The ALTER did not take. Stay at version 8 and retry next run.
			error_log( 'AIML: migration step 9 could not verify the aiml_languages schema; staying at the previous version.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return false;
		}

		delete_option( self::BLOCKED_OPTION );

		return true;
	}

	/**
	 * Whether a UNIQUE index of the given name exists on a plugin table.
	 *
	 * @param string $table Fully qualified table name.
	 * @param string $index Index name.
	 */
	private function has_unique_index( string $table, string $index ): bool {
		global $wpdb;

		$escaped = str_replace( '`', '``', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema table identifier only.
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$escaped}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( isset( $row->Key_name, $row->Non_unique )
				&& $index === (string) $row->Key_name // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				&& '0' === (string) $row->Non_unique ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return true;
			}
		}

		return false;
	}

	/**
	 * The declared VARCHAR length of a column, or 0 when it is not a VARCHAR.
	 *
	 * @param string $table  Fully qualified table name.
	 * @param string $column Column name.
	 */
	private function column_char_length( string $table, string $column ): int {
		global $wpdb;

		$escaped = str_replace( '`', '``', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted schema table name.
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SHOW COLUMNS FROM `{$escaped}` LIKE %s", $column )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row || ! isset( $row->Type ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return 0;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( 1 === preg_match( '/^varchar\((\d+)\)/i', (string) $row->Type, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	/**
	 * Admin notice shown while step 9 is blocked by duplicate locales.
	 *
	 * Registered on `admin_notices` by the plugin bootstrap. The wording is
	 * deliberately neutral: it asks the operator to give each language a
	 * distinct locale, not to delete anything.
	 */
	public static function render_blocked_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$blocked = get_option( self::BLOCKED_OPTION );

		if ( empty( $blocked ) || ! is_array( $blocked ) ) {
			return;
		}

		$locales = implode( ', ', array_map( 'sanitize_text_field', $blocked ) );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Universal Multilingual: a database upgrade is paused.', 'universal-multilingual' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of shared locales. */
					__( 'More than one language is registered with the same locale (%s). Until each language has a distinct locale, the upgrade that enforces this cannot run. Review the affected languages under Multilingual → Languages; the upgrade finishes on its own once no locale is shared.', 'universal-multilingual' ),
					$locales
				)
			)
		);
	}
}
