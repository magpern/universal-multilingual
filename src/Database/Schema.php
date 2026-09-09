<?php
/**
 * Table names and DDL.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Database;

/**
 * Single source of truth for this plugin's table names and their DDL.
 *
 * Every name is built from `$wpdb->prefix`; no table name is ever hardcoded
 * with a `wp_` prefix (invariant I9). The DDL is written out explicitly rather
 * than handed to `dbDelta()`: dbDelta's parser silently drops composite
 * prefix indexes and misparses several forms this schema depends on
 * (ADR-0003).
 */
final class Schema {

	/**
	 * Unprefixed table names.
	 */
	public const LANGUAGES             = 'aiml_languages';
	public const TRANSLATIONS          = 'aiml_translations';
	public const TM                    = 'aiml_tm';
	public const METRICS_DAILY         = 'aiml_metrics_daily';
	public const GLOSSARY              = 'aiml_glossary';
	public const JOBS                  = 'aiml_jobs';
	public const JOB_ITEMS             = 'aiml_job_items';
	public const SLUG_ROUTES           = 'aiml_slug_routes';
	public const ROUTE_HISTORY         = 'aiml_route_history';
	public const SLUG_REINDEX_FRONTIER = 'aiml_slug_reindex_frontier';

	/**
	 * Option holding the monotonic glossary lexicon version (ADR-0014).
	 */
	public const GLOSSARY_VERSION_OPTION = 'aiml_glossary_version';

	/**
	 * Prefixes a table name with the current site's table prefix.
	 *
	 * @param string $name Unprefixed table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Fully qualified `aiml_languages` table name.
	 */
	public static function languages(): string {
		return self::table( self::LANGUAGES );
	}

	/**
	 * Fully qualified `aiml_translations` table name.
	 */
	public static function translations(): string {
		return self::table( self::TRANSLATIONS );
	}

	/**
	 * Fully qualified `aiml_tm` table name.
	 */
	public static function tm(): string {
		return self::table( self::TM );
	}

	/**
	 * Fully qualified `aiml_metrics_daily` table name.
	 */
	public static function metrics_daily(): string {
		return self::table( self::METRICS_DAILY );
	}

	/**
	 * Fully qualified `aiml_glossary` table name.
	 */
	public static function glossary(): string {
		return self::table( self::GLOSSARY );
	}

	/**
	 * Fully qualified `aiml_jobs` table name.
	 */
	public static function jobs(): string {
		return self::table( self::JOBS );
	}

	/**
	 * Fully qualified `aiml_job_items` table name.
	 */
	public static function job_items(): string {
		return self::table( self::JOB_ITEMS );
	}

	/**
	 * Fully qualified `aiml_slug_routes` table name.
	 */
	public static function slug_routes(): string {
		return self::table( self::SLUG_ROUTES );
	}

	/**
	 * Fully qualified `aiml_route_history` table name.
	 */
	public static function route_history(): string {
		return self::table( self::ROUTE_HISTORY );
	}

	/**
	 * Fully qualified `aiml_slug_reindex_frontier` table name.
	 */
	public static function slug_reindex_frontier(): string {
		return self::table( self::SLUG_REINDEX_FRONTIER );
	}

	/**
	 * Every table this plugin owns, in drop-safe order.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array(
			self::job_items(),
			self::jobs(),
			self::slug_reindex_frontier(),
			self::route_history(),
			self::slug_routes(),
			self::translations(),
			self::tm(),
			self::metrics_daily(),
			self::glossary(),
			self::languages(),
		);
	}

	/**
	 * Charset and collation clause matching the host WordPress install.
	 */
	private static function charset_collate(): string {
		global $wpdb;

		return $wpdb->get_charset_collate();
	}

	/**
	 * DDL for the language configuration table.
	 *
	 * A single `status` column carries the three-state model
	 * (disabled / preview / published); it is VARCHAR rather than ENUM so a new
	 * state never requires an ALTER (ADR-0008). There is deliberately no
	 * `fallback_id`: the default language is the implicit fallback, and
	 * arbitrary language chains need a complete SEO policy first.
	 */
	public static function create_languages(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::languages() . " (
			language_id  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code         VARCHAR(20)       NOT NULL,
			locale       VARCHAR(20)       NOT NULL,
			name         VARCHAR(100)      NOT NULL,
			native_name  VARCHAR(100)      NOT NULL DEFAULT '',
			direction    VARCHAR(3)        NOT NULL DEFAULT 'ltr',
			is_default   TINYINT(1)        NOT NULL DEFAULT 0,
			status       VARCHAR(16)       NOT NULL DEFAULT 'preview',
			sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at   DATETIME          NOT NULL,
			updated_at   DATETIME          NOT NULL,
			PRIMARY KEY (language_id),
			UNIQUE KEY code (code),
			UNIQUE KEY locale (locale),
			KEY status_sort (status, sort_order)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for the segment store.
	 *
	 * `segment_identity` is the upsert key and keeps writes idempotent. It uses
	 * `segment_hash` rather than the raw (field_key, segment_key) pair because
	 * indexing those columns directly would cost roughly 1 KB per entry
	 * (ADR-0005). `object_lang` is the hot read path: one indexed query returns
	 * every segment for an object in a language, already in document order.
	 */
	public static function create_translations(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::translations() . " (
			translation_id   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			source_type      VARCHAR(20)       NOT NULL,
			source_id        BIGINT UNSIGNED   NOT NULL,
			source_subtype   VARCHAR(32)       NOT NULL DEFAULT '',
			language_id      SMALLINT UNSIGNED NOT NULL,
			field_key        VARCHAR(64)       NOT NULL,
			segment_key      VARCHAR(191)      NOT NULL,
			segment_hash     CHAR(40)          NOT NULL,
			segment_kind     VARCHAR(16)       NOT NULL DEFAULT 'field',
			segment_order    INT UNSIGNED      NOT NULL DEFAULT 0,
			text_format      VARCHAR(16)       NOT NULL DEFAULT 'plain',
			source_text      LONGTEXT          NULL,
			source_hash      CHAR(40)          NOT NULL DEFAULT '',
			norm_version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			translated_text  LONGTEXT          NULL,
			translation_hash CHAR(40)          NOT NULL DEFAULT '',
			status           VARCHAR(24)       NOT NULL DEFAULT 'missing',
			is_stale         TINYINT(1)        NOT NULL DEFAULT 0,
			provider         VARCHAR(32)       NOT NULL DEFAULT '',
			model            VARCHAR(64)       NOT NULL DEFAULT '',
			prompt_profile   VARCHAR(32)       NOT NULL DEFAULT '',
			prompt_version   VARCHAR(16)       NOT NULL DEFAULT '',
			glossary_version INT UNSIGNED      NOT NULL DEFAULT 0,
			tm_id            BIGINT UNSIGNED   NULL,
			translated_by    BIGINT UNSIGNED   NULL,
			reviewed_by      BIGINT UNSIGNED   NULL,
			reviewed_at      DATETIME          NULL,
			review_status    VARCHAR(24)       NOT NULL DEFAULT 'not_submitted',
			review_submitted_by BIGINT UNSIGNED NULL,
			review_submitted_at DATETIME       NULL,
			submitted_translation_hash CHAR(40) NOT NULL DEFAULT '',
			rejection_reason VARCHAR(512)      NOT NULL DEFAULT '',
			rejected_by      BIGINT UNSIGNED   NULL,
			rejected_at      DATETIME          NULL,
			publish_status   VARCHAR(24)       NOT NULL DEFAULT 'unpublished',
			published_at     DATETIME          NULL,
			published_by     BIGINT UNSIGNED   NULL,
			error_code       VARCHAR(32)       NOT NULL DEFAULT '',
			error_message    VARCHAR(500)      NOT NULL DEFAULT '',
			slug_origin      VARCHAR(16)       NOT NULL DEFAULT '',
			created_at       DATETIME          NOT NULL,
			updated_at       DATETIME          NOT NULL,
			PRIMARY KEY (translation_id),
			UNIQUE KEY segment_identity (source_type, source_id, segment_hash, language_id),
			KEY object_lang (source_type, source_id, language_id, segment_order),
			KEY lang_status (language_id, status, is_stale),
			KEY lang_subtype (language_id, source_type, source_subtype, status),
			KEY stale_sweep (language_id, is_stale, updated_at),
			KEY lang_review_queue (language_id, review_status, review_submitted_at),
			KEY lang_publish_status (language_id, publish_status, published_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * Whether a column exists on a plugin-owned table.
	 *
	 * Used by additive migrations so interrupted upgrades can resume without
	 * failing on duplicate column names.
	 *
	 * @param string $table  Fully qualified table name from Schema helpers.
	 * @param string $column Column name.
	 */
	public static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// Table names come only from Schema::*() helpers — never from request input.
		$escaped_table = str_replace( '`', '``', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted Schema table name.
		$found = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SHOW COLUMNS FROM `{$escaped_table}` LIKE %s",
				$column
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return ! empty( $found );
	}

	/**
	 * Whether a named index exists on a plugin-owned table.
	 *
	 * @param string $table Fully qualified table name from Schema helpers.
	 * @param string $index Index / key name.
	 */
	public static function index_exists( string $table, string $index ): bool {
		global $wpdb;

		$escaped_table = str_replace( '`', '``', $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted Schema table name.
		$indexes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SHOW INDEX FROM `{$escaped_table}`"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $indexes as $row ) {
			if ( isset( $row->Key_name ) && $index === (string) $row->Key_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				return true;
			}
		}

		return false;
	}

	/**
	 * DDL for the translation memory catalogue (ADR-0009 / F11).
	 *
	 * Identity is (source_hash, source_lang_id, target_lang_id, context) so
	 * write-back is an upsert. The `origin` column records provenance
	 * (human / ai / import / legacy) without conflating it with `quality`.
	 */
	public static function create_tm(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::tm() . " (
			tm_id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			source_lang_id   SMALLINT UNSIGNED NOT NULL,
			target_lang_id   SMALLINT UNSIGNED NOT NULL,
			source_hash      CHAR(40)          NOT NULL,
			source_text      LONGTEXT          NOT NULL,
			target_text      LONGTEXT          NOT NULL,
			text_format      VARCHAR(16)       NOT NULL DEFAULT 'plain',
			context          VARCHAR(64)       NOT NULL DEFAULT '',
			norm_version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			origin           VARCHAR(16)       NOT NULL DEFAULT 'human',
			quality          VARCHAR(24)       NOT NULL DEFAULT 'human_approved',
			use_count        INT UNSIGNED      NOT NULL DEFAULT 0,
			glossary_version INT UNSIGNED      NOT NULL DEFAULT 0,
			created_at       DATETIME          NOT NULL,
			updated_at       DATETIME          NOT NULL,
			last_used_at     DATETIME          NULL,
			PRIMARY KEY (tm_id),
			UNIQUE KEY tm_identity (source_hash, source_lang_id, target_lang_id, context),
			KEY fuzzy_lookup (source_lang_id, target_lang_id, text_format),
			KEY origin_filter (origin, source_lang_id, target_lang_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for F12 daily rollout metrics aggregates.
	 */
	public static function create_metrics_daily(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::metrics_daily() . " (
			metrics_id      BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			day             DATE              NOT NULL,
			metric_key      VARCHAR(64)       NOT NULL,
			dimension_hash  CHAR(40)          NOT NULL,
			stage           TINYINT UNSIGNED  NOT NULL DEFAULT 0,
			reason_code     VARCHAR(64)       NOT NULL DEFAULT '',
			post_type       VARCHAR(32)       NOT NULL DEFAULT '',
			language_code   VARCHAR(12)       NOT NULL DEFAULT '',
			result_class    VARCHAR(32)       NOT NULL DEFAULT '',
			cache_outcome   VARCHAR(32)       NOT NULL DEFAULT '',
			count_value     BIGINT UNSIGNED   NOT NULL DEFAULT 0,
			sum_value       BIGINT            NOT NULL DEFAULT 0,
			min_value       BIGINT            NOT NULL DEFAULT 0,
			max_value       BIGINT            NOT NULL DEFAULT 0,
			incomplete      TINYINT(1)        NOT NULL DEFAULT 0,
			registry_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			updated_at      DATETIME          NOT NULL,
			PRIMARY KEY (metrics_id),
			UNIQUE KEY daily_identity (day, metric_key, dimension_hash),
			KEY day_metric (day, metric_key),
			KEY retention (day)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for the platform glossary lexicon (ADR-0014 / Glossary MVP).
	 *
	 * Identity is (source_lang_id, target_lang_id, source_term_normalized).
	 * Semantic normalization is owned by PHP; collation alone is not trusted.
	 * Language IDs are validated in PHP — no SQL FOREIGN KEY (plugin convention).
	 */
	public static function create_glossary(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::glossary() . " (
			glossary_id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			source_lang_id          SMALLINT UNSIGNED NOT NULL,
			target_lang_id          SMALLINT UNSIGNED NOT NULL,
			source_term             VARCHAR(255)      NOT NULL,
			source_term_normalized  VARCHAR(191)      NOT NULL,
			target_term             VARCHAR(512)      NOT NULL,
			context                 VARCHAR(64)       NOT NULL DEFAULT '',
			description             VARCHAR(512)      NOT NULL DEFAULT '',
			is_active               TINYINT(1)        NOT NULL DEFAULT 1,
			created_at              DATETIME          NOT NULL,
			updated_at              DATETIME          NOT NULL,
			PRIMARY KEY (glossary_id),
			UNIQUE KEY glossary_identity (source_lang_id, target_lang_id, source_term_normalized),
			KEY glossary_pair_active (source_lang_id, target_lang_id, is_active),
			KEY glossary_updated (updated_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for background translation job aggregates (ADR-0011 / Jobs J1).
	 *
	 * Orchestration state only — no translation bodies or prompts. Language IDs
	 * are validated in PHP; no SQL FOREIGN KEY (plugin convention).
	 */
	public static function create_jobs(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::jobs() . " (
			job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_type VARCHAR(32) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'queued',
			requested_action VARCHAR(16) NOT NULL DEFAULT 'none',
			batch_id VARCHAR(36) NULL,
			idempotency_key VARCHAR(64) NOT NULL,
			source_type VARCHAR(20) NOT NULL DEFAULT '',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			language_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lock_key VARCHAR(191) NOT NULL DEFAULT '',
			active_lock_key VARCHAR(191) NULL,
			lease_owner VARCHAR(64) NOT NULL DEFAULT '',
			lease_expires_at DATETIME NULL,
			lease_heartbeat_at DATETIME NULL,
			stage VARCHAR(32) NOT NULL DEFAULT '',
			checkpoint TEXT NULL,
			provider_id VARCHAR(32) NOT NULL DEFAULT '',
			prompt_profile VARCHAR(32) NOT NULL DEFAULT '',
			prompt_version VARCHAR(16) NOT NULL DEFAULT '',
			provider_config_fp VARCHAR(64) NOT NULL DEFAULT '',
			glossary_version_intended INT UNSIGNED NOT NULL DEFAULT 0,
			glossary_version_actual INT UNSIGNED NOT NULL DEFAULT 0,
			total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			queued_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			running_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			completed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			failed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			skipped_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			stale_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cancelled_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
			budget_max_requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
			budget_max_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
			budget_used_requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
			budget_used_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
			budget_warning_pct TINYINT UNSIGNED NOT NULL DEFAULT 80,
			attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_error_code VARCHAR(32) NOT NULL DEFAULT '',
			last_error_class VARCHAR(24) NOT NULL DEFAULT '',
			last_error_message VARCHAR(500) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY (job_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			UNIQUE KEY active_lock_key (active_lock_key),
			KEY status_updated (status, updated_at),
			KEY batch_id (batch_id),
			KEY object_lang (source_type, source_id, language_id),
			KEY lease_expires (lease_expires_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for per-segment background translation job items (ADR-0011 / Jobs J1).
	 *
	 * Identity is (job_id, segment_key). No translation bodies in item rows.
	 */
	public static function create_job_items(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::job_items() . " (
			item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			segment_key VARCHAR(191) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'queued',
			result_code VARCHAR(32) NOT NULL DEFAULT '',
			attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
			source_hash_captured VARCHAR(64) NOT NULL DEFAULT '',
			translation_hash_captured VARCHAR(64) NOT NULL DEFAULT '',
			glossary_version_actual INT UNSIGNED NOT NULL DEFAULT 0,
			last_error_code VARCHAR(32) NOT NULL DEFAULT '',
			last_error_class VARCHAR(24) NOT NULL DEFAULT '',
			last_error_message VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY (item_id),
			UNIQUE KEY job_segment (job_id, segment_key),
			KEY job_status (job_id, status),
			KEY status_updated (status, updated_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for localized URL route registry (ADR-0023 / MSEO.0).
	 */
	public static function create_slug_routes(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::slug_routes() . " (
			route_id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			language_id         SMALLINT UNSIGNED NOT NULL,
			source_type         VARCHAR(20)       NOT NULL,
			source_id           BIGINT UNSIGNED   NOT NULL,
			source_subtype      VARCHAR(64)       NOT NULL DEFAULT '',
			source_path         VARCHAR(2048)     NOT NULL,
			source_path_hash    BINARY(32)        NOT NULL,
			localized_path      VARCHAR(2048)     NOT NULL,
			localized_path_hash BINARY(32)        NOT NULL,
			localized_slug      VARCHAR(191)      NOT NULL DEFAULT '',
			route_namespace     VARCHAR(64)       NOT NULL DEFAULT '',
			slug_origin         VARCHAR(16)       NOT NULL DEFAULT 'generated',
			route_status        VARCHAR(16)       NOT NULL DEFAULT 'inactive',
			activated_at        DATETIME          NULL,
			created_at          DATETIME          NOT NULL,
			updated_at          DATETIME          NOT NULL,
			PRIMARY KEY (route_id),
			UNIQUE KEY object_language (source_type, source_id, language_id),
			UNIQUE KEY localized_identity (language_id, localized_path_hash),
			UNIQUE KEY source_identity (language_id, source_path_hash),
			KEY route_status_lang (route_status, language_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for localized path history (source-identity only; ADR-0023).
	 */
	public static function create_route_history(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::route_history() . " (
			history_id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			language_id          SMALLINT UNSIGNED NOT NULL,
			historical_path      VARCHAR(2048)     NOT NULL,
			historical_path_hash BINARY(32)        NOT NULL,
			source_type          VARCHAR(20)       NOT NULL,
			source_id            BIGINT UNSIGNED   NOT NULL,
			source_subtype       VARCHAR(64)       NOT NULL DEFAULT '',
			created_at           DATETIME          NOT NULL,
			PRIMARY KEY (history_id),
			UNIQUE KEY history_identity (language_id, historical_path_hash),
			KEY source_lang_history (source_type, source_id, language_id)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}

	/**
	 * DDL for bounded hierarchy reindex frontier checkpoints (MSEO.3 contract).
	 */
	public static function create_slug_reindex_frontier(): string {
		return 'CREATE TABLE IF NOT EXISTS ' . self::slug_reindex_frontier() . " (
			frontier_id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			parent_source_type   VARCHAR(20)       NOT NULL,
			parent_source_id     BIGINT UNSIGNED   NOT NULL,
			checkpoint_json      LONGTEXT          NULL,
			generation           INT UNSIGNED      NOT NULL DEFAULT 1,
			status               VARCHAR(16)       NOT NULL DEFAULT 'pending',
			created_at           DATETIME          NOT NULL,
			updated_at           DATETIME          NOT NULL,
			PRIMARY KEY (frontier_id),
			UNIQUE KEY parent_frontier (parent_source_type, parent_source_id),
			KEY status_updated (status, updated_at)
		) ENGINE=InnoDB ROW_FORMAT=DYNAMIC " . self::charset_collate();
	}
}
