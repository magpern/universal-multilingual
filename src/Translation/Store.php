<?php
/**
 * Segment store.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Translation;

use AIMultilingual\Cache\Cache;
use AIMultilingual\Database\Schema;
use WP_Error;

/**
 * Reads and writes translation segments.
 *
 * A segment is the unit of translation: one addressable piece of one field of
 * one object in one language. Identity is
 * (source_type, source_id, segment_hash, language_id), which makes every write
 * an upsert and therefore safe to replay.
 *
 * The normalization and hashing helpers are static and pure so the rules can be
 * unit-tested without a WordPress bootstrap. Normalization is dispatched on the
 * segment's text format because a single rule would be wrong: collapsing runs
 * of whitespace is harmless in a title and destructive inside a code block or a
 * JSON string. The algorithm is versioned (`norm_version`) so a future change
 * to these rules cannot silently mark an entire translated site stale
 * (ADR-0006).
 */
final class Store {

	/**
	 * Version of the normalization algorithm implemented here.
	 *
	 * Bump this when the rules change, keep the old branch reachable, and
	 * re-hash rows lazily on their next write.
	 */
	public const NORM_VERSION = 1;

	/**
	 * Text formats.
	 */
	public const FORMAT_PLAIN = 'plain';
	public const FORMAT_HTML  = 'html';
	public const FORMAT_JSON  = 'json';
	public const FORMAT_CODE  = 'code';
	public const FORMAT_SLUG  = 'slug';

	/**
	 * Segment provenance states. Freshness is a separate axis (`is_stale`).
	 */
	public const STATUS_MISSING            = 'missing';
	public const STATUS_MACHINE_TRANSLATED = 'machine_translated';
	public const STATUS_MANUALLY_EDITED    = 'manually_edited';
	public const STATUS_REVIEWED           = 'reviewed';
	public const STATUS_FAILED             = 'failed';
	public const STATUS_IGNORED            = 'ignored';

	/**
	 * Review Workflow axis (ADR-0015). Independent of provenance `status`.
	 */
	public const REVIEW_NOT_SUBMITTED = 'not_submitted';
	public const REVIEW_PENDING       = 'pending';
	public const REVIEW_APPROVED      = 'approved';
	public const REVIEW_REJECTED      = 'rejected';

	/**
	 * Publication axis (ADR-0020 / TI.7). Independent of provenance and review.
	 */
	public const PUBLISH_UNPUBLISHED = 'unpublished';
	public const PUBLISH_PUBLISHED   = 'published';

	/**
	 * Segment kinds.
	 */
	public const KIND_FIELD = 'field';

	/**
	 * Block-level segment kind (Strategy F).
	 */
	public const KIND_BLOCK = 'block';

	/**
	 * Source types.
	 */
	public const SOURCE_POST = 'post';

	/**
	 * Taxonomy-term identity (TSC.1 / ADR-0021). `source_id` is the term id and
	 * `source_subtype` the taxonomy slug.
	 */
	public const SOURCE_TERM = 'term';

	/**
	 * Object cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Builds the segment store.
	 *
	 * @param Cache $cache Object cache wrapper.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	// -- Pure helpers (safe to call without WordPress loaded) --

	/**
	 * Every known text format.
	 *
	 * @return string[]
	 */
	public static function formats(): array {
		return array( self::FORMAT_PLAIN, self::FORMAT_HTML, self::FORMAT_JSON, self::FORMAT_CODE, self::FORMAT_SLUG );
	}

	/**
	 * Every known provenance status.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_MISSING,
			self::STATUS_MACHINE_TRANSLATED,
			self::STATUS_MANUALLY_EDITED,
			self::STATUS_REVIEWED,
			self::STATUS_FAILED,
			self::STATUS_IGNORED,
		);
	}

	/**
	 * Every known review-status value (ADR-0015).
	 *
	 * @return string[]
	 */
	public static function review_statuses(): array {
		return array(
			self::REVIEW_NOT_SUBMITTED,
			self::REVIEW_PENDING,
			self::REVIEW_APPROVED,
			self::REVIEW_REJECTED,
		);
	}

	/**
	 * Every known publish-status value (ADR-0020).
	 *
	 * @return string[]
	 */
	public static function publish_statuses(): array {
		return array(
			self::PUBLISH_UNPUBLISHED,
			self::PUBLISH_PUBLISHED,
		);
	}

	/**
	 * Column map that clears active review decisions (invalidate-on-edit).
	 *
	 * @return array<string, mixed>
	 */
	public static function review_clear_fields(): array {
		return array(
			'review_status'              => self::REVIEW_NOT_SUBMITTED,
			'review_submitted_by'        => null,
			'review_submitted_at'        => null,
			'submitted_translation_hash' => '',
			'reviewed_by'                => null,
			'reviewed_at'                => null,
			'rejection_reason'           => '',
			'rejected_by'                => null,
			'rejected_at'                => null,
		);
	}

	/**
	 * Column map that clears publication (invalidate-on-edit / unpublish).
	 *
	 * @return array<string, mixed>
	 */
	public static function publish_clear_fields(): array {
		return array(
			'publish_status' => self::PUBLISH_UNPUBLISHED,
			'published_at'   => null,
			'published_by'   => null,
		);
	}

	/**
	 * Allowlisted review-metadata column names.
	 *
	 * @return string[]
	 */
	public static function review_metadata_columns(): array {
		return array_keys( self::review_clear_fields() );
	}

	/**
	 * Allowlisted publication-metadata column names.
	 *
	 * @return string[]
	 */
	public static function publish_metadata_columns(): array {
		return array_keys( self::publish_clear_fields() );
	}

	/**
	 * Normalizes source text for hashing, according to its format.
	 *
	 * The goal is to ignore differences that cannot change meaning, and only
	 * those. What counts as meaningless differs per format, which is why this
	 * dispatches rather than applying one rule:
	 *
	 * - plain: line endings, non-breaking spaces and whitespace runs are all
	 *   cosmetic in a title or label, so they collapse to a single space.
	 * - html: line endings and the three ways of writing a non-breaking space
	 *   are normalized, but whitespace is never collapsed — it is significant
	 *   between inline elements and inside `<pre>`. Non-breaking spaces are
	 *   canonicalized to the codepoint rather than converted to ordinary
	 *   spaces, because the non-breaking property is itself meaningful.
	 * - json: reordering keys does not change the document, so it is parsed and
	 *   re-encoded canonically. Invalid JSON is hashed byte-for-byte rather
	 *   than "repaired".
	 * - code: only line endings are normalized; indentation is meaning.
	 * - slug: trimmed and lowercased. Slug values are written already
	 *   canonicalized through sanitize_title(), so no further transformation is
	 *   needed here and this stays free of WordPress.
	 *
	 * @param string $text   Source text.
	 * @param string $format One of the FORMAT_* constants.
	 */
	public static function normalize( string $text, string $format = self::FORMAT_PLAIN ): string {
		switch ( $format ) {
			case self::FORMAT_HTML:
				$text = self::normalize_line_endings( $text );
				$text = self::canonicalize_nbsp( $text );

				return trim( $text );

			case self::FORMAT_JSON:
				return self::canonicalize_json( $text );

			case self::FORMAT_CODE:
				return self::normalize_line_endings( $text );

			case self::FORMAT_SLUG:
				return strtolower( trim( $text ) );

			case self::FORMAT_PLAIN:
			default:
				$text = self::normalize_line_endings( $text );
				$text = self::nbsp_to_space( $text );
				$text = (string) preg_replace( '/\s+/u', ' ', $text );

				return trim( $text );
		}
	}

	/**
	 * Hash of the normalized source text.
	 *
	 * Answers exactly one question: has the meaning of the source changed?
	 *
	 * @param string $text   Source text.
	 * @param string $format One of the FORMAT_* constants.
	 */
	public static function source_hash( string $text, string $format = self::FORMAT_PLAIN ): string {
		return sha1( self::normalize( $text, $format ) );
	}

	/**
	 * Hash of the stored translation, exactly as stored.
	 *
	 * This is an integrity marker, not an edit detector. The plugin owns the
	 * write path, so an editor saving through the UI updates the text and this
	 * hash together and they always agree afterwards. What it does catch is
	 * modification by something other than the plugin — direct SQL, a partial
	 * restore, replication damage. Comparison against remembered historical
	 * states (last machine output, last reviewed value) arrives with the
	 * revision-history migration in Milestone 3 (ADR-0007).
	 *
	 * @param string $text Translated text.
	 */
	public static function translation_hash( string $text ): string {
		return sha1( $text );
	}

	/**
	 * Stable identity of a segment within its object and language.
	 *
	 * @param string $field_key   Logical field.
	 * @param string $segment_key Segment identity within the field.
	 */
	public static function segment_hash( string $field_key, string $segment_key ): string {
		return sha1( $field_key . "\x1f" . $segment_key );
	}

	// -- Reads --

	/**
	 * Loads every segment for one object in one language.
	 *
	 * This is the hot path: one indexed query, one cache entry, results keyed
	 * by segment key so the renderer can look up fields without further
	 * queries.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @param int    $language_id Language id.
	 * @return array<string, object> Segment rows keyed by segment key.
	 */
	public function load_object( string $source_type, int $source_id, int $language_id ): array {
		if ( $language_id <= 0 ) {
			return array();
		}

		$key    = sprintf( 'seg:%s:%d', $source_type, $source_id );
		$cached = $this->cache->get( $key, $language_id );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE source_type = %s AND source_id = %d AND language_id = %d'
				. ' ORDER BY segment_order ASC, translation_id ASC',
				$source_type,
				$source_id,
				$language_id
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->segment_key ] = $this->hydrate( $row );
		}

		$this->cache->set( $key, $language_id, $map );

		return $map;
	}

	/**
	 * Returns one segment, or null.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @param int    $language_id Language id.
	 * @param string $segment_key Segment key.
	 */
	public function get( string $source_type, int $source_id, int $language_id, string $segment_key ): ?object {
		$segments = $this->load_object( $source_type, $source_id, $language_id );

		return $segments[ $segment_key ] ?? null;
	}

	/**
	 * Returns one translation by primary key (OTL.0).
	 *
	 * @param int $translation_id Translation PK.
	 */
	public function get_by_translation_id( int $translation_id ): ?object {
		global $wpdb;

		if ( $translation_id <= 0 || ! $this->translations_table_exists() ) {
			return null;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::translations() . ' WHERE translation_id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$translation_id
			)
		);

		$row = is_array( $rows ) && isset( $rows[0] ) ? $rows[0] : null;

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Upper bound for OTL operations list page size (OTL.0).
	 */
	public const OPERATIONS_MAX_PER_PAGE = 50;

	/**
	 * Language-scoped paginated operations query (OTL.0).
	 *
	 * Axis filters only — no TI.5/TI.7 policy in SQL.
	 *
	 * @param array<string, mixed> $args {
	 *     Query arguments.
	 *
	 *     @type int         $language_id    Required language id.
	 *     @type string|null $status         Optional provenance status.
	 *     @type string|null $review_status  Optional review status.
	 *     @type string|null $publish_status Optional publish status.
	 *     @type bool|null   $is_stale       Optional stale filter.
	 *     @type string|null $source_type    Optional source type.
	 *     @type int         $source_id      Optional source id (0 = any).
	 *     @type int         $page           1-based page. Default 1.
	 *     @type int         $per_page       Page size up to OPERATIONS_MAX_PER_PAGE. Default 20.
	 * }
	 * @return array{items: list<object>, total: int, page: int, per_page: int}
	 */
	public function query_operations( array $args = array() ): array {
		global $wpdb;

		$language_id    = (int) ( $args['language_id'] ?? 0 );
		$page           = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page       = max( 1, min( self::OPERATIONS_MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );
		$status         = array_key_exists( 'status', $args ) ? $args['status'] : null;
		$review_status  = array_key_exists( 'review_status', $args ) ? $args['review_status'] : null;
		$publish_status = array_key_exists( 'publish_status', $args ) ? $args['publish_status'] : null;
		$is_stale       = array_key_exists( 'is_stale', $args ) ? $args['is_stale'] : null;
		$source_type    = array_key_exists( 'source_type', $args ) ? $args['source_type'] : null;
		$source_id      = (int) ( $args['source_id'] ?? 0 );

		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
		);

		if ( $language_id <= 0 || ! $this->translations_table_exists() ) {
			return $empty;
		}

		if ( null !== $status && '' !== (string) $status && ! in_array( (string) $status, self::statuses(), true ) ) {
			return $empty;
		}
		if ( null !== $review_status && '' !== (string) $review_status && ! in_array( (string) $review_status, self::review_statuses(), true ) ) {
			return $empty;
		}
		if ( null !== $publish_status && '' !== (string) $publish_status && ! in_array( (string) $publish_status, self::publish_statuses(), true ) ) {
			return $empty;
		}

		$where  = array( 'language_id = %d' );
		$params = array( $language_id );

		if ( null !== $status && '' !== (string) $status ) {
			$where[]  = 'status = %s';
			$params[] = (string) $status;
		}
		if ( null !== $review_status && '' !== (string) $review_status ) {
			$where[]  = 'review_status = %s';
			$params[] = (string) $review_status;
		}
		if ( null !== $publish_status && '' !== (string) $publish_status ) {
			$where[]  = 'publish_status = %s';
			$params[] = (string) $publish_status;
		}
		if ( null !== $is_stale ) {
			$where[]  = 'is_stale = %d';
			$params[] = $is_stale ? 1 : 0;
		}
		if ( null !== $source_type && '' !== (string) $source_type ) {
			$where[]  = 'source_type = %s';
			$params[] = (string) $source_type;
		}
		if ( $source_id > 0 ) {
			$where[]  = 'source_id = %d';
			$params[] = $source_id;
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::translations() . ' WHERE ' . $where_sql, // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$params
			)
		);

		if ( $total <= 0 ) {
			return $empty;
		}

		$offset      = ( $page - 1 ) * $per_page;
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::translations() . ' WHERE ' . $where_sql
				. ' ORDER BY updated_at DESC, translation_id DESC LIMIT %d OFFSET %d',
				$list_params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Language-scoped independent operational attention counts (OTL.1).
	 *
	 * Same visibility universe as unfiltered `query_operations` for the language
	 * (no per-row edit_post filter). Bucket counts may overlap; `total` is the
	 * unfiltered inventory count, not the sum of buckets.
	 *
	 * Single bounded aggregate — no AssessmentAssembler / publication explain.
	 *
	 * @param int $language_id Language id.
	 * @return array{
	 *     total: int,
	 *     stale: int,
	 *     review_pending: int,
	 *     review_rejected: int,
	 *     unpublished: int,
	 *     translation_failed: int
	 * }
	 */
	public function count_operations_attention( int $language_id ): array {
		global $wpdb;

		$empty = array(
			'total'              => 0,
			'stale'              => 0,
			'review_pending'     => 0,
			'review_rejected'    => 0,
			'unpublished'        => 0,
			'translation_failed' => 0,
		);

		if ( $language_id <= 0 || ! $this->translations_table_exists() ) {
			return $empty;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) AS total,
					SUM(CASE WHEN is_stale = 1 THEN 1 ELSE 0 END) AS stale,
					SUM(CASE WHEN review_status = %s THEN 1 ELSE 0 END) AS review_pending,
					SUM(CASE WHEN review_status = %s THEN 1 ELSE 0 END) AS review_rejected,
					SUM(CASE WHEN publish_status = %s THEN 1 ELSE 0 END) AS unpublished,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS translation_failed
				FROM ' . Schema::translations() . ' WHERE language_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				self::REVIEW_PENDING,
				self::REVIEW_REJECTED,
				self::PUBLISH_UNPUBLISHED,
				self::STATUS_FAILED,
				$language_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'total'              => (int) ( $row['total'] ?? 0 ),
			'stale'              => (int) ( $row['stale'] ?? 0 ),
			'review_pending'     => (int) ( $row['review_pending'] ?? 0 ),
			'review_rejected'    => (int) ( $row['review_rejected'] ?? 0 ),
			'unpublished'        => (int) ( $row['unpublished'] ?? 0 ),
			'translation_failed' => (int) ( $row['translation_failed'] ?? 0 ),
		);
	}

	/**
	 * Returns the renderable translation for a segment, or null.
	 *
	 * A stale translation is still returned. Dropping back to the source the
	 * moment an editor touches the English copy would splice languages together
	 * mid-page, which reads worse than slightly outdated but coherent text
	 * (invariant I7). Staleness is surfaced in the admin instead.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @param int    $language_id Language id.
	 * @param string $segment_key Segment key.
	 */
	public function translated_value( string $source_type, int $source_id, int $language_id, string $segment_key ): ?string {
		$segment = $this->get( $source_type, $source_id, $language_id, $segment_key );

		if ( null === $segment ) {
			return null;
		}

		if ( ! self::is_publicly_overlay_eligible( $segment ) ) {
			return null;
		}

		$value = (string) ( $segment->translated_text ?? '' );

		return '' === $value ? null : $value;
	}

	/**
	 * Whether a Store row may overlay public visitor content.
	 *
	 * Central frontend publication eligibility (ADR-0020). When the segment
	 * publication gate is enabled, `publish_status=published` is required.
	 * When the gate is off, pre-TI.7 semantics apply (non-empty text; status
	 * not ignored/missing).
	 *
	 * Path-specific prerequisites (e.g. block RENDERABLE_STATUSES / non-stale)
	 * remain the caller's responsibility on top of this check.
	 *
	 * @param object    $row        Hydrated translation row.
	 * @param bool|null $gate_enabled Optional override for tests; null reads Settings.
	 */
	public static function is_publicly_overlay_eligible( object $row, ?bool $gate_enabled = null ): bool {
		$status = (string) ( $row->status ?? '' );
		if ( self::STATUS_IGNORED === $status || self::STATUS_MISSING === $status ) {
			return false;
		}

		$text = (string) ( $row->translated_text ?? '' );
		if ( '' === $text ) {
			return false;
		}

		$enabled = $gate_enabled;
		if ( null === $enabled ) {
			$enabled = self::segment_publication_gate_enabled();
		}

		if ( ! $enabled ) {
			return true;
		}

		$publish = (string) ( $row->publish_status ?? self::PUBLISH_UNPUBLISHED );

		return self::PUBLISH_PUBLISHED === $publish;
	}

	/**
	 * Effective segment publication gate setting.
	 */
	public static function segment_publication_gate_enabled(): bool {
		if ( ! class_exists( \AIMultilingual\Settings::class ) || ! function_exists( 'get_option' ) ) {
			return false;
		}

		$all = ( new \AIMultilingual\Settings() )->get();

		return (bool) ( $all['segment_publication_gate_enabled'] ?? false );
	}

	/**
	 * Counts segments per status for an object across all languages.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @return array<int, array{total: int, stale: int}> Keyed by language id.
	 */
	public function summary_for_object( string $source_type, int $source_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT language_id, COUNT(*) AS total, SUM(is_stale) AS stale FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE source_type = %s AND source_id = %d AND status <> %s'
				. ' GROUP BY language_id',
				$source_type,
				$source_id,
				self::STATUS_MISSING
			)
		);

		$summary = array();
		foreach ( (array) $rows as $row ) {
			$summary[ (int) $row->language_id ] = array(
				'total' => (int) $row->total,
				'stale' => (int) $row->stale,
			);
		}

		return $summary;
	}

	/**
	 * Provenance states eligible for frontend block rendering.
	 *
	 * Keep aligned with {@see BlockTranslationLookup}.
	 *
	 * @var list<string>
	 */
	public const RENDERABLE_STATUSES = array(
		self::STATUS_MACHINE_TRANSLATED,
		self::STATUS_MANUALLY_EDITED,
		self::STATUS_REVIEWED,
	);

	/**
	 * Whether the translations table exists.
	 */
	public function translations_table_exists(): bool {
		global $wpdb;

		$table = Schema::translations();

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether duplicate segment identity rows can be detected reliably.
	 *
	 * The schema enforces UNIQUE segment_identity, so duplicate rows should not
	 * exist without bypassing the store write path.
	 */
	public function duplicate_segment_rows_detectable(): bool {
		return false;
	}

	/**
	 * Counts block-kind segments scoped by source type and optional ids.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 * @param int    $language_id Optional language id.
	 */
	public function count_block_segments( string $source_type, int $source_id = 0, int $language_id = 0 ): int {
		return $this->health_count_block_segments(
			$source_type,
			$source_id,
			$language_id,
			''
		);
	}

	/**
	 * Counts translated block segments with non-empty text.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 * @param int    $language_id Optional language id.
	 */
	public function count_translated_block_segments( string $source_type, int $source_id = 0, int $language_id = 0 ): int {
		return $this->health_count_block_segments(
			$source_type,
			$source_id,
			$language_id,
			' AND status NOT IN (%s, %s) AND TRIM(translated_text) <> %s',
			array(
				self::STATUS_MISSING,
				self::STATUS_IGNORED,
				'',
			)
		);
	}

	/**
	 * Counts renderable block segments aligned with frontend lookup rules.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 * @param int    $language_id Optional language id.
	 */
	public function count_renderable_block_segments( string $source_type, int $source_id = 0, int $language_id = 0 ): int {
		$status_placeholders = implode( ', ', array_fill( 0, count( self::RENDERABLE_STATUSES ), '%s' ) );

		return $this->health_count_block_segments(
			$source_type,
			$source_id,
			$language_id,
			' AND is_stale = 0 AND status IN (' . $status_placeholders . ') AND TRIM(translated_text) <> %s',
			array_merge( self::RENDERABLE_STATUSES, array( '' ) )
		);
	}

	/**
	 * Counts stale block-kind segments.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 * @param int    $language_id Optional language id.
	 */
	public function count_stale_block_segments( string $source_type, int $source_id = 0, int $language_id = 0 ): int {
		return $this->health_count_block_segments(
			$source_type,
			$source_id,
			$language_id,
			' AND is_stale = 1'
		);
	}

	/**
	 * Counts orphaned block segments reconciled by sync_source.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 * @param int    $language_id Optional language id.
	 */
	public function count_orphaned_block_segments( string $source_type, int $source_id = 0, int $language_id = 0 ): int {
		return $this->health_count_block_segments(
			$source_type,
			$source_id,
			$language_id,
			' AND status = %s AND error_code = %s',
			array(
				self::STATUS_IGNORED,
				'orphaned',
			)
		);
	}

	/**
	 * Counts duplicate segment identity rows when detectable.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 */
	public function count_duplicate_segment_rows( string $source_type, int $source_id = 0 ): int {
		if ( ! $this->duplicate_segment_rows_detectable() ) {
			return 0;
		}

		global $wpdb;

		if ( ! $this->translations_table_exists() ) {
			return 0;
		}

		$scope = $this->health_scope_sql( $source_type, $source_id, 0 );
		$sql   = 'SELECT COALESCE(SUM(dup_count - 1), 0) FROM ('
			. 'SELECT COUNT(*) AS dup_count FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
			. ' WHERE segment_kind = %s AND segment_key LIKE %s' . $scope['sql']
			. ' GROUP BY source_type, source_id, segment_hash, language_id HAVING COUNT(*) > 1'
			. ') AS duplicates';

		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...array_merge( array( self::KIND_BLOCK, 'b:%' ), $scope['args'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		return $this->normalize_health_count( $count );
	}

	/**
	 * Executes a scoped block-segment count query.
	 *
	 * @param string           $source_type Source type.
	 * @param int              $source_id   Optional source object id.
	 * @param int              $language_id Optional language id.
	 * @param string           $extra_where Additional WHERE clause with placeholders.
	 * @param list<string|int> $extra_args  Placeholder values for the extra clause.
	 */
	private function health_count_block_segments(
		string $source_type,
		int $source_id,
		int $language_id,
		string $extra_where,
		array $extra_args = array()
	): int {
		global $wpdb;

		if ( ! $this->translations_table_exists() ) {
			return 0;
		}

		$scope = $this->health_scope_sql( $source_type, $source_id, $language_id );
		$sql   = 'SELECT COUNT(*) FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
			. ' WHERE segment_kind = %s AND segment_key LIKE %s' . $scope['sql'] . $extra_where;

		$args  = array_merge( array( self::KIND_BLOCK, 'b:%' ), $scope['args'], $extra_args );
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		return $this->normalize_health_count( $count );
	}

	/**
	 * Builds optional source and language scope SQL fragments.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Optional source object id.
	 * @param int    $language_id Optional language id.
	 * @return array{sql: string, args: list<string|int>}
	 */
	private function health_scope_sql( string $source_type, int $source_id, int $language_id ): array {
		$sql  = ' AND source_type = %s';
		$args = array( $source_type );

		if ( $source_id > 0 ) {
			$sql   .= ' AND source_id = %d';
			$args[] = $source_id;
		}

		if ( $language_id > 0 ) {
			$sql   .= ' AND language_id = %d';
			$args[] = $language_id;
		}

		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * Normalizes count query results to non-negative integers.
	 *
	 * @param mixed $count Raw query result.
	 */
	private function normalize_health_count( $count ): int {
		if ( null === $count || false === $count ) {
			return 0;
		}

		return max( 0, (int) $count );
	}

	/**
	 * Maximum rows returned per review-queue page.
	 */
	public const REVIEW_QUEUE_MAX_PER_PAGE = 50;

	/**
	 * Filtered, paginated view over review-axis rows (ADR-0015 §5, §11).
	 *
	 * The review queue is a query over the existing translations table, never
	 * a separate persisted queue. Sort is stable (submission time, then row
	 * id) so pagination cannot skip or repeat rows between pages.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional query args.
	 *
	 *     @type string $source_type   Source type. Default SOURCE_POST.
	 *     @type int    $source_id     Optional object id filter (0 = any).
	 *     @type int    $language_id   Optional language filter (0 = any).
	 *     @type string $review_status One of review_statuses(), or 'all'. Default REVIEW_PENDING.
	 *     @type int    $page          1-based page number. Default 1.
	 *     @type int    $per_page      Page size, bounded by REVIEW_QUEUE_MAX_PER_PAGE. Default 20.
	 * }
	 * @return array{items: list<object>, total: int, page: int, per_page: int}
	 */
	public function query_review_queue( array $args = array() ): array {
		global $wpdb;

		$source_type   = (string) ( $args['source_type'] ?? self::SOURCE_POST );
		$source_id     = (int) ( $args['source_id'] ?? 0 );
		$language_id   = (int) ( $args['language_id'] ?? 0 );
		$review_status = (string) ( $args['review_status'] ?? self::REVIEW_PENDING );
		$page          = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page      = max( 1, min( self::REVIEW_QUEUE_MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );

		if ( 'all' !== $review_status && ! in_array( $review_status, self::review_statuses(), true ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		if ( ! $this->translations_table_exists() ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		$where  = array( 'source_type = %s' );
		$params = array( $source_type );

		if ( $language_id > 0 ) {
			$where[]  = 'language_id = %d';
			$params[] = $language_id;
		}

		if ( $source_id > 0 ) {
			$where[]  = 'source_id = %d';
			$params[] = $source_id;
		}

		if ( 'all' !== $review_status ) {
			$where[]  = 'review_status = %s';
			$params[] = $review_status;
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::translations() . ' WHERE ' . $where_sql, // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql is built from a fixed set of %s/%d placeholders above, matched 1:1 with $params.
				$params
			)
		);

		if ( $total <= 0 ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		$offset      = ( $page - 1 ) * $per_page;
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql is built from a fixed set of %s/%d placeholders above, matched 1:1 with $list_params.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::translations() . ' WHERE ' . $where_sql
				. ' ORDER BY review_submitted_at ASC, translation_id ASC LIMIT %d OFFSET %d',
				$list_params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * MLW1a (ADR-0034 C1) — object-level Review Queue pagination.
	 *
	 * Paginates distinct content objects that have review rows, via a grouped
	 * query, so one object's languages are NEVER split across pages:
	 *
	 *   SELECT source_id FROM {translations}
	 *    WHERE source_type = %s AND review_status = %s [AND language_id IN (…)]
	 *    GROUP BY source_id
	 *    ORDER BY MIN(review_submitted_at) ASC, source_id ASC
	 *    LIMIT %d OFFSET %d
	 *
	 * `total` is COUNT(DISTINCT source_id) — an object count, never a segment
	 * count. `source_id ASC` is the deterministic tie-break when the earliest
	 * submission times are equal.
	 *
	 * @param array<string, mixed> $args Query args: source_type, review_status
	 *                                   ('all' or a review_statuses() value),
	 *                                   language_ids (int[]), page, per_page.
	 * @return array{object_ids: array<int,int>, total: int, page: int, per_page: int}
	 */
	public function query_review_object_ids( array $args = array() ): array {
		global $wpdb;

		$source_type   = (string) ( $args['source_type'] ?? self::SOURCE_POST );
		$review_status = (string) ( $args['review_status'] ?? self::REVIEW_PENDING );
		$language_ids  = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) ( $args['language_ids'] ?? array() ) ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		$page          = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page      = max( 1, min( self::REVIEW_QUEUE_MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );

		$empty = array(
			'object_ids' => array(),
			'total'      => 0,
			'page'       => $page,
			'per_page'   => $per_page,
		);

		if ( 'all' !== $review_status && ! in_array( $review_status, self::review_statuses(), true ) ) {
			return $empty;
		}

		if ( ! $this->translations_table_exists() ) {
			return $empty;
		}

		$where  = array( 'source_type = %s' );
		$params = array( $source_type );

		if ( 'all' !== $review_status ) {
			$where[]  = 'review_status = %s';
			$params[] = $review_status;
		}

		if ( array() !== $language_ids ) {
			$where[] = 'language_id IN (' . implode( ',', array_fill( 0, count( $language_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $language_ids );
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT source_id) FROM ' . Schema::translations() . ' WHERE ' . $where_sql, // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql is a fixed set of %s/%d placeholders matched 1:1 with $params.
				$params
			)
		);

		if ( $total <= 0 ) {
			return $empty;
		}

		$offset      = ( $page - 1 ) * $per_page;
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql is a fixed set of %s/%d placeholders matched 1:1 with $list_params.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT source_id FROM ' . Schema::translations() . ' WHERE ' . $where_sql
				. ' GROUP BY source_id ORDER BY MIN(review_submitted_at) ASC, source_id ASC LIMIT %d OFFSET %d',
				$list_params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array(
			'object_ids' => array_values(
				array_map( static fn( object $row ): int => (int) $row->source_id, (array) $rows )
			),
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
		);
	}

	/**
	 * MLW1a (ADR-0034 C1) — every review row for the given objects across all
	 * requested languages, hydrated, with NO row-level pagination.
	 * Deterministic order: source_id, language_id, review_submitted_at,
	 * translation_id.
	 *
	 * @param array<int,int> $source_ids    Object ids (from query_review_object_ids()).
	 * @param array<int,int> $language_ids  Optional language filter (empty = all).
	 * @param string         $review_status One of review_statuses() or 'all'.
	 * @param string         $source_type   Source type. Default SOURCE_POST.
	 * @return array<int, object>
	 */
	public function review_rows_for_objects(
		array $source_ids,
		array $language_ids = array(),
		string $review_status = self::REVIEW_PENDING,
		string $source_type = self::SOURCE_POST
	): array {
		global $wpdb;

		$source_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $source_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		if ( array() === $source_ids ) {
			return array();
		}

		if ( 'all' !== $review_status && ! in_array( $review_status, self::review_statuses(), true ) ) {
			return array();
		}

		if ( ! $this->translations_table_exists() ) {
			return array();
		}

		$language_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $language_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		$where  = array( 'source_type = %s' );
		$params = array( $source_type );

		if ( 'all' !== $review_status ) {
			$where[]  = 'review_status = %s';
			$params[] = $review_status;
		}

		$where[] = 'source_id IN (' . implode( ',', array_fill( 0, count( $source_ids ), '%d' ) ) . ')';
		$params  = array_merge( $params, $source_ids );

		if ( array() !== $language_ids ) {
			$where[] = 'language_id IN (' . implode( ',', array_fill( 0, count( $language_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $language_ids );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql is a fixed set of %s/%d placeholders matched 1:1 with $params.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::translations() . ' WHERE ' . $where_sql
				. ' ORDER BY source_id ASC, language_id ASC, review_submitted_at ASC, translation_id ASC',
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Upper bound (seconds) reported for pending-review age (ADR-0015 §13).
	 *
	 * Diagnostics are query-time and bounded on purpose: a very old,
	 * forgotten pending row must not make the reported figure unbounded, and
	 * the plugin must never persist a high-cardinality metric to track it.
	 */
	public const REVIEW_PENDING_AGE_BOUND_SECONDS = 2592000; // 30 days.

	/**
	 * Counts review-axis rows by status, scoped by optional source/language
	 * (ADR-0015 §13). Query-time diagnostics only — never a persisted metric.
	 *
	 * @param string $source_type Source type. Default SOURCE_POST.
	 * @param int    $source_id   Optional source object id filter (0 = any).
	 * @param int    $language_id Optional language filter (0 = any).
	 * @return array<string, int> Map of every review_status catalog value to its count.
	 */
	public function review_status_counts( string $source_type = self::SOURCE_POST, int $source_id = 0, int $language_id = 0 ): array {
		$counts = array_fill_keys( self::review_statuses(), 0 );

		if ( ! $this->translations_table_exists() ) {
			return $counts;
		}

		global $wpdb;

		$scope = $this->health_scope_sql( $source_type, $source_id, $language_id );
		$sql   = 'SELECT review_status, COUNT(*) AS row_count FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
			. ' WHERE 1=1' . $scope['sql'] . ' GROUP BY review_status';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $scope['args'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		foreach ( (array) $rows as $row ) {
			$status = (string) ( $row->review_status ?? '' );
			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] = $this->normalize_health_count( $row->row_count );
			}
		}

		return $counts;
	}

	/**
	 * Returns every segment currently pending review for one object + language.
	 *
	 * Unpaginated on purpose: object-level "Approve page" must resolve the
	 * complete eligible set (a complex Elementor/product page can exceed any
	 * single batch page) and then process it in bounded chunks. Ordered oldest
	 * submission first to match the review queue.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @param int    $language_id Target language id.
	 * @return list<array{segment_key: string, submitted_translation_hash: string}>
	 */
	public function pending_segment_keys( string $source_type, int $source_id, int $language_id ): array {
		if ( $source_id <= 0 || $language_id <= 0 || ! $this->translations_table_exists() ) {
			return array();
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->prepare(
				'SELECT segment_key, submitted_translation_hash FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE source_type = %s AND source_id = %d AND language_id = %d AND review_status = %s'
				. ' ORDER BY review_submitted_at ASC, translation_id ASC',
				array( $source_type, $source_id, $language_id, self::REVIEW_PENDING )
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) ( $row->segment_key ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$out[] = array(
				'segment_key'                => $key,
				'submitted_translation_hash' => (string) ( $row->submitted_translation_hash ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Bounded pending-review age stats, scoped by optional source/language
	 * (ADR-0015 §13). Query-time diagnostics only.
	 *
	 * @param string $source_type Source type. Default SOURCE_POST.
	 * @param int    $source_id   Optional source object id filter (0 = any).
	 * @param int    $language_id Optional language filter (0 = any).
	 * @return array{count: int, avg_seconds: int, max_seconds: int}
	 */
	public function review_pending_age_stats( string $source_type = self::SOURCE_POST, int $source_id = 0, int $language_id = 0 ): array {
		$empty = array(
			'count'       => 0,
			'avg_seconds' => 0,
			'max_seconds' => 0,
		);

		if ( ! $this->translations_table_exists() ) {
			return $empty;
		}

		global $wpdb;

		$scope = $this->health_scope_sql( $source_type, $source_id, $language_id );
		$sql   = 'SELECT COUNT(*) AS row_count,'
			. ' COALESCE(AVG(TIMESTAMPDIFF(SECOND, review_submitted_at, UTC_TIMESTAMP())), 0) AS avg_seconds,'
			. ' COALESCE(MAX(TIMESTAMPDIFF(SECOND, review_submitted_at, UTC_TIMESTAMP())), 0) AS max_seconds'
			. ' FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
			. ' WHERE review_status = %s AND review_submitted_at IS NOT NULL' . $scope['sql'];

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( $sql, array_merge( array( self::REVIEW_PENDING ), $scope['args'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$row = ( is_array( $rows ) && isset( $rows[0] ) ) ? $rows[0] : null;
		if ( null === $row ) {
			return $empty;
		}

		$bound = self::REVIEW_PENDING_AGE_BOUND_SECONDS;

		return array(
			'count'       => $this->normalize_health_count( $row->row_count ),
			'avg_seconds' => min( $bound, max( 0, (int) round( (float) $row->avg_seconds ) ) ),
			'max_seconds' => min( $bound, max( 0, (int) $row->max_seconds ) ),
		);
	}

	// -- Writes --

	/**
	 * Saves a translated segment.
	 *
	 * @param array<string, mixed> $args Segment fields. Required: source_type,
	 *                                   source_id, language_id, field_key,
	 *                                   source_text, translated_text.
	 * @return true|WP_Error
	 */
	public function save_translation( array $args ) {
		$source_type = (string) ( $args['source_type'] ?? self::SOURCE_POST );
		$source_id   = (int) ( $args['source_id'] ?? 0 );
		$language_id = (int) ( $args['language_id'] ?? 0 );
		$field_key   = (string) ( $args['field_key'] ?? '' );
		$segment_key = (string) ( $args['segment_key'] ?? $field_key );

		if ( $source_id <= 0 || $language_id <= 0 || '' === $field_key ) {
			return new WP_Error( 'aiml_invalid_segment', __( 'Incomplete segment reference.', 'universal-multilingual' ) );
		}

		$format = (string) ( $args['text_format'] ?? self::FORMAT_PLAIN );
		if ( ! in_array( $format, self::formats(), true ) ) {
			$format = self::FORMAT_PLAIN;
		}

		$status = (string) ( $args['status'] ?? self::STATUS_MANUALLY_EDITED );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = self::STATUS_MANUALLY_EDITED;
		}

		$source_text     = (string) ( $args['source_text'] ?? '' );
		$translated_text = (string) ( $args['translated_text'] ?? '' );

		// An emptied translation reverts the segment to untranslated rather than
		// storing a blank string that would render as an empty title.
		if ( '' === trim( $translated_text ) ) {
			$status          = self::STATUS_MISSING;
			$translated_text = '';
		}

		$now = current_time( 'mysql', true );

		$translation_hash = self::translation_hash( $translated_text );

		$data = array(
			'source_type'      => $source_type,
			'source_id'        => $source_id,
			'source_subtype'   => (string) ( $args['source_subtype'] ?? '' ),
			'language_id'      => $language_id,
			'field_key'        => $field_key,
			'segment_key'      => $segment_key,
			'segment_hash'     => self::segment_hash( $field_key, $segment_key ),
			'segment_kind'     => (string) ( $args['segment_kind'] ?? self::KIND_FIELD ),
			'segment_order'    => (int) ( $args['segment_order'] ?? 0 ),
			'text_format'      => $format,
			'source_text'      => $source_text,
			'source_hash'      => self::source_hash( $source_text, $format ),
			'norm_version'     => self::NORM_VERSION,
			'translated_text'  => $translated_text,
			'translation_hash' => $translation_hash,
			'status'           => $status,
			'is_stale'         => 0,
			'translated_by'    => (int) ( $args['translated_by'] ?? get_current_user_id() ),
			'updated_at'       => $now,
		);

		$existing     = $this->get( $source_type, $source_id, $language_id, $segment_key );
		$prior_hash   = null === $existing ? '' : (string) ( $existing->translation_hash ?? '' );
		$text_changed = null === $existing || $prior_hash !== $translation_hash;

		if ( $text_changed ) {
			$data = array_merge( $data, self::review_clear_fields(), self::publish_clear_fields() );
		} elseif ( null !== $existing ) {
			// Preserve existing publication axis on no-op content saves.
			$data['publish_status'] = (string) ( $existing->publish_status ?? self::PUBLISH_UNPUBLISHED );
			$data['published_at']   = $existing->published_at ?? null;
			$data['published_by']   = null !== ( $existing->published_by ?? null ) && '' !== (string) $existing->published_by
				? (int) $existing->published_by
				: null;
		} else {
			$data = array_merge( $data, self::publish_clear_fields() );
		}

		// A1: generic saves never accept slug_origin from callers; preserve existing.
		if ( null !== $existing ) {
			$data['slug_origin'] = (string) ( $existing->slug_origin ?? '' );
		} else {
			$data['slug_origin'] = '';
		}

		$this->upsert( $data, $now );
		$this->invalidate( $source_type, $source_id, $language_id );

		if ( $text_changed && null !== $existing && function_exists( 'do_action' ) ) {
			/**
			 * Fires when a material translation edit clears prior review state.
			 *
			 * @since 0.1.0
			 *
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source object ID.
			 * @param int    $language_id Language ID.
			 * @param string $segment_key Segment key.
			 * @param string $old_review  Previous review_status.
			 */
			\do_action(
				'aiml_review_invalidated_by_edit',
				$source_type,
				$source_id,
				$language_id,
				$segment_key,
				(string) ( $existing->review_status ?? self::REVIEW_NOT_SUBMITTED )
			);

			/**
			 * Fires when a material translation edit clears prior publication.
			 *
			 * @since 0.1.0
			 *
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source object ID.
			 * @param int    $language_id Language ID.
			 * @param string $segment_key Segment key.
			 * @param string $old_publish Previous publish_status.
			 */
			\do_action(
				'aiml_publication_invalidated_by_edit',
				$source_type,
				$source_id,
				$language_id,
				$segment_key,
				(string) ( $existing->publish_status ?? self::PUBLISH_UNPUBLISHED )
			);
		}

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires after a translation segment is saved.
			 *
			 * @since 0.1.0
			 *
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source object ID.
			 * @param int    $language_id Language ID.
			 */
			\do_action( 'aiml_translation_saved', $source_type, $source_id, $language_id );
		}

		return true;
	}

	/**
	 * Persists a FORMAT_SLUG candidate and its slug_origin atomically (MSEO.1 A1).
	 *
	 * Sole Store writer for candidate slug_origin. Generic save_translation preserves origin.
	 *
	 * @param array<string, mixed> $args Same as save_translation plus required slug_origin.
	 * @return true|WP_Error
	 */
	public function save_slug_candidate( array $args ) {
		$origin = (string) ( $args['slug_origin'] ?? '' );
		if ( ! in_array( $origin, array( '', 'generated', 'manual' ), true ) ) {
			return new WP_Error( 'aiml_invalid_slug_origin', __( 'Invalid slug origin.', 'universal-multilingual' ) );
		}

		$args['text_format'] = self::FORMAT_SLUG;
		$args['field_key']   = (string) ( $args['field_key'] ?? 'post_name' );
		$args['segment_key'] = (string) ( $args['segment_key'] ?? $args['field_key'] );

		$source_type = (string) ( $args['source_type'] ?? self::SOURCE_POST );
		$source_id   = (int) ( $args['source_id'] ?? 0 );
		$language_id = (int) ( $args['language_id'] ?? 0 );
		$field_key   = (string) $args['field_key'];
		$segment_key = (string) $args['segment_key'];

		if ( $source_id <= 0 || $language_id <= 0 || '' === $field_key ) {
			return new WP_Error( 'aiml_invalid_segment', __( 'Incomplete segment reference.', 'universal-multilingual' ) );
		}

		$status = (string) ( $args['status'] ?? self::STATUS_MANUALLY_EDITED );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			$status = self::STATUS_MANUALLY_EDITED;
		}

		$source_text     = (string) ( $args['source_text'] ?? '' );
		$translated_text = (string) ( $args['translated_text'] ?? '' );
		if ( '' === trim( $translated_text ) ) {
			$status          = self::STATUS_MISSING;
			$translated_text = '';
			$origin          = '';
		}

		$now              = current_time( 'mysql', true );
		$translation_hash = self::translation_hash( $translated_text );
		$existing         = $this->get( $source_type, $source_id, $language_id, $segment_key );
		$prior_hash       = null === $existing ? '' : (string) ( $existing->translation_hash ?? '' );
		$text_changed     = null === $existing || $prior_hash !== $translation_hash;

		$data = array(
			'source_type'      => $source_type,
			'source_id'        => $source_id,
			'source_subtype'   => (string) ( $args['source_subtype'] ?? '' ),
			'language_id'      => $language_id,
			'field_key'        => $field_key,
			'segment_key'      => $segment_key,
			'segment_hash'     => self::segment_hash( $field_key, $segment_key ),
			'segment_kind'     => (string) ( $args['segment_kind'] ?? self::KIND_FIELD ),
			'segment_order'    => (int) ( $args['segment_order'] ?? 1 ),
			'text_format'      => self::FORMAT_SLUG,
			'source_text'      => $source_text,
			'source_hash'      => self::source_hash( $source_text, self::FORMAT_SLUG ),
			'norm_version'     => self::NORM_VERSION,
			'translated_text'  => $translated_text,
			'translation_hash' => $translation_hash,
			'status'           => $status,
			'is_stale'         => 0,
			'translated_by'    => (int) ( $args['translated_by'] ?? get_current_user_id() ),
			'updated_at'       => $now,
			'slug_origin'      => $origin,
		);

		if ( $text_changed ) {
			$data = array_merge( $data, self::review_clear_fields(), self::publish_clear_fields() );
		} elseif ( null !== $existing ) {
			$data['publish_status'] = (string) ( $existing->publish_status ?? self::PUBLISH_UNPUBLISHED );
			$data['published_at']   = $existing->published_at ?? null;
			$data['published_by']   = null !== ( $existing->published_by ?? null ) && '' !== (string) $existing->published_by
				? (int) $existing->published_by
				: null;
		} else {
			$data = array_merge( $data, self::publish_clear_fields() );
		}

		$this->upsert( $data, $now );
		$this->invalidate( $source_type, $source_id, $language_id );

		if ( $text_changed && null !== $existing && function_exists( 'do_action' ) ) {
			/**
			 * Fires when a material slug-candidate edit clears prior review state.
			 *
			 * @since 1.4.0
			 *
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source object ID.
			 * @param int    $language_id Language ID.
			 * @param string $segment_key Segment key.
			 * @param string $old_review  Previous review_status.
			 */
			\do_action(
				'aiml_review_invalidated_by_edit',
				$source_type,
				$source_id,
				$language_id,
				$segment_key,
				(string) ( $existing->review_status ?? self::REVIEW_NOT_SUBMITTED )
			);
			/**
			 * Fires when a material slug-candidate edit clears prior publication.
			 *
			 * @since 1.4.0
			 *
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source object ID.
			 * @param int    $language_id Language ID.
			 * @param string $segment_key Segment key.
			 * @param string $old_publish Previous publish_status.
			 */
			\do_action(
				'aiml_publication_invalidated_by_edit',
				$source_type,
				$source_id,
				$language_id,
				$segment_key,
				(string) ( $existing->publish_status ?? self::PUBLISH_UNPUBLISHED )
			);
		}

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires after a slug candidate segment is saved.
			 *
			 * @since 1.4.0
			 *
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source object ID.
			 * @param int    $language_id Language ID.
			 */
			\do_action( 'aiml_translation_saved', $source_type, $source_id, $language_id );
		}

		return true;
	}

	/**
	 * Runs a callback under a nested-safe Store transaction with a locked segment row.
	 *
	 * @param string   $source_type Source type.
	 * @param int      $source_id   Source id.
	 * @param int      $language_id Language id.
	 * @param string   $segment_key Segment key.
	 * @param callable $callback    Receives (?object $locked_row).
	 * @return mixed|WP_Error
	 */
	public function with_locked_segment( string $source_type, int $source_id, int $language_id, string $segment_key, callable $callback ) {
		$boundary  = $this->begin_term_compat_boundary();
		$committed = false;

		try {
			$row    = $this->lock_row_by_identity( $source_type, $source_id, $language_id, self::segment_hash( $segment_key, $segment_key ) );
			$result = $callback( $row );

			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$this->commit_term_compat_boundary( $boundary );
			$committed = true;

			return $result;
		} finally {
			if ( ! $committed ) {
				$this->rollback_term_compat_boundary( $boundary );
			}
		}
	}

	/**
	 * Opens a nested-safe transaction boundary for route publication (MSEO.1).
	 *
	 * @return array{mode: string, name: string}
	 */
	public function begin_route_boundary(): array {
		return $this->begin_term_compat_boundary();
	}

	/**
	 * Commits a route publication boundary.
	 *
	 * @param array{mode: string, name: string} $boundary Boundary.
	 */
	public function commit_route_boundary( array $boundary ): void {
		$this->commit_term_compat_boundary( $boundary );
	}

	/**
	 * Rolls back a route publication boundary.
	 *
	 * @param array{mode: string, name: string} $boundary Boundary.
	 */
	public function rollback_route_boundary( array $boundary ): void {
		$this->rollback_term_compat_boundary( $boundary );
	}

	/**
	 * Locks a translation row by identity for update.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @param int    $language_id Language id.
	 * @param string $field_key   Field key.
	 * @param string $segment_key Segment key.
	 */
	public function lock_segment_for_update( string $source_type, int $source_id, int $language_id, string $field_key, string $segment_key ): ?object {
		return $this->lock_row_by_identity( $source_type, $source_id, $language_id, self::segment_hash( $field_key, $segment_key ) );
	}

	/**
	 * Updates allowlisted review-metadata columns without changing translation content.
	 *
	 * @param string               $source_type Source type.
	 * @param int                  $source_id   Source object id.
	 * @param int                  $language_id Language id.
	 * @param string               $segment_key Segment key.
	 * @param array<string, mixed> $fields      Review columns only.
	 * @return true|WP_Error
	 */
	public function update_review_metadata(
		string $source_type,
		int $source_id,
		int $language_id,
		string $segment_key,
		array $fields
	) {
		$allowed = self::review_metadata_columns();
		$data    = array();

		foreach ( $fields as $column => $value ) {
			if ( ! in_array( (string) $column, $allowed, true ) ) {
				continue;
			}

			$data[ (string) $column ] = $value;
		}

		if ( array() === $data ) {
			return new WP_Error( 'aiml_invalid_review_fields', __( 'No review metadata fields provided.', 'universal-multilingual' ) );
		}

		if ( isset( $data['review_status'] ) && ! in_array( (string) $data['review_status'], self::review_statuses(), true ) ) {
			return new WP_Error( 'aiml_invalid_review_status', __( 'Unknown review status.', 'universal-multilingual' ) );
		}

		$existing = $this->get( $source_type, $source_id, $language_id, $segment_key );
		if ( null === $existing ) {
			return new WP_Error( 'aiml_segment_missing', __( 'Translation segment not found.', 'universal-multilingual' ) );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array();
		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$formats[] = null;
			} elseif ( is_int( $value ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			$data,
			array( 'translation_id' => (int) $existing->translation_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'aiml_review_update_failed', __( 'Could not update review metadata.', 'universal-multilingual' ) );
		}

		$this->invalidate( $source_type, $source_id, $language_id );

		return true;
	}

	/**
	 * Updates allowlisted publication-metadata columns without changing translation content.
	 *
	 * @param string               $source_type Source type.
	 * @param int                  $source_id   Source object id.
	 * @param int                  $language_id Language id.
	 * @param string               $segment_key Segment key.
	 * @param array<string, mixed> $fields      Publication columns only.
	 * @return true|WP_Error
	 */
	public function update_publish_metadata(
		string $source_type,
		int $source_id,
		int $language_id,
		string $segment_key,
		array $fields
	) {
		$allowed = self::publish_metadata_columns();
		$data    = array();

		foreach ( $fields as $column => $value ) {
			if ( ! in_array( (string) $column, $allowed, true ) ) {
				continue;
			}

			$data[ (string) $column ] = $value;
		}

		if ( array() === $data ) {
			return new WP_Error( 'aiml_invalid_publish_fields', __( 'No publication metadata fields provided.', 'universal-multilingual' ) );
		}

		if ( isset( $data['publish_status'] ) && ! in_array( (string) $data['publish_status'], self::publish_statuses(), true ) ) {
			return new WP_Error( 'aiml_invalid_publish_status', __( 'Unknown publish status.', 'universal-multilingual' ) );
		}

		$existing = $this->get( $source_type, $source_id, $language_id, $segment_key );
		if ( null === $existing ) {
			return new WP_Error( 'aiml_segment_missing', __( 'Translation segment not found.', 'universal-multilingual' ) );
		}

		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array();
		foreach ( $data as $value ) {
			if ( null === $value ) {
				$formats[] = null;
			} elseif ( is_int( $value ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			$data,
			array( 'translation_id' => (int) $existing->translation_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'aiml_publish_update_failed', __( 'Could not update publication metadata.', 'universal-multilingual' ) );
		}

		$this->invalidate( $source_type, $source_id, $language_id );

		return true;
	}

	/**
	 * Distinct segment keys currently stored for a source object.
	 *
	 * Used by TSC.2 CASE B retain computation for host-emitted external_p rows.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @return list<string>
	 */
	public function distinct_segment_keys_for_source( string $source_type, int $source_id ): array {
		global $wpdb;

		if ( $source_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT segment_key FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. ' WHERE source_type = %s AND source_id = %d',
				$source_type,
				$source_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$key = (string) ( $row->segment_key ?? '' );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Bounded rehost of matching segment rows from one Store host to another (TSC.3).
	 *
	 * Moves only rows whose segment_key satisfies $predicate. Does not change
	 * segment_key / language / lifecycle columns other than source_id (and
	 * updated_at). Destination conflicts keep the destination row and retire
	 * the source duplicate as ignored/orphaned — never two writable authorities.
	 *
	 * @param string                 $source_type Source type (typically post).
	 * @param int                    $from_id     Old host id.
	 * @param int                    $to_id       New host id.
	 * @param callable(string): bool $predicate   Segment key admission.
	 * @return array{moved:int, retired:int, skipped:int}
	 */
	public function rehost_segments( string $source_type, int $from_id, int $to_id, callable $predicate ): array {
		global $wpdb;

		$stats = array(
			'moved'   => 0,
			'retired' => 0,
			'skipped' => 0,
		);

		if ( '' === $source_type || $from_id <= 0 || $to_id <= 0 || $from_id === $to_id ) {
			return $stats;
		}

		$table = Schema::translations();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $table // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				. ' WHERE source_type = %s AND source_id = %d',
				$source_type,
				$from_id
			)
		);

		$now     = current_time( 'mysql', true );
		$touched = array();

		foreach ( (array) $rows as $row ) {
			$key = (string) ( $row->segment_key ?? '' );
			if ( '' === $key || ! $predicate( $key ) ) {
				++$stats['skipped'];
				continue;
			}

			$language_id  = (int) ( $row->language_id ?? 0 );
			$segment_hash = (string) ( $row->segment_hash ?? '' );
			if ( $language_id <= 0 || '' === $segment_hash ) {
				++$stats['skipped'];
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$dest = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT translation_id FROM ' . $table // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					. ' WHERE source_type = %s AND source_id = %d AND segment_hash = %s AND language_id = %d LIMIT 1',
					$source_type,
					$to_id,
					$segment_hash,
					$language_id
				)
			);

			if ( null !== $dest ) {
				// Destination already authoritative — retire source duplicate.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'status'     => self::STATUS_IGNORED,
						'error_code' => 'orphaned',
						'updated_at' => $now,
					),
					array( 'translation_id' => (int) $row->translation_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				++$stats['retired'];
				$touched[ $language_id ] = true;
				continue;
			}

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'source_id'  => $to_id,
					'updated_at' => $now,
				),
				array( 'translation_id' => (int) $row->translation_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				++$stats['skipped'];
				continue;
			}

			++$stats['moved'];
			$touched[ $language_id ] = true;
		}

		foreach ( array_keys( $touched ) as $language_id ) {
			$this->invalidate( $source_type, $from_id, (int) $language_id );
			$this->invalidate( $source_type, $to_id, (int) $language_id );
		}

		return $stats;
	}

	/**
	 * Reconciles stored segments against the current source content.
	 *
	 * Runs for every language when the canonical object changes. Translated
	 * text and workflow status are never touched here (invariant I6): a source
	 * edit flags work for review, it does not discard it.
	 *
	 * @param string                      $source_type         Source type.
	 * @param int                         $source_id           Source object id.
	 * @param string                      $source_subtype      Post type or taxonomy.
	 * @param array<string, array<mixed>> $segments            Extracted source segments keyed by segment key.
	 * @param array                       $retain_segment_keys CASE B: missing keys to leave genuinely untouched (list of segment_key strings).
	 */
	public function sync_source( string $source_type, int $source_id, string $source_subtype, array $segments, array $retain_segment_keys = array() ): void {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT translation_id, language_id, segment_key, source_hash, text_format, status FROM ' . Schema::translations() // phpcs:ignore WordPress.DB.PreparedSQL
				. ' WHERE source_type = %s AND source_id = %d',
				$source_type,
				$source_id
			)
		);

		if ( array() === (array) $rows ) {
			return;
		}

		$retain  = array_fill_keys( array_map( 'strval', $retain_segment_keys ), true );
		$now     = current_time( 'mysql', true );
		$touched = array();

		foreach ( (array) $rows as $row ) {
			$key = (string) $row->segment_key;

			if ( ! isset( $segments[ $key ] ) ) {
				// CASE B: inactive registered definition — leave the row genuinely
				// untouched (no status/error_code/updated_at/source mutation).
				if ( isset( $retain[ $key ] ) ) {
					continue;
				}

				// The segment no longer exists in the source. Mark it rather
				// than delete it, so reverting the source restores the work.
				if ( self::STATUS_IGNORED !== $row->status ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						Schema::translations(),
						array(
							'status'     => self::STATUS_IGNORED,
							'error_code' => 'orphaned',
							'updated_at' => $now,
						),
						array( 'translation_id' => (int) $row->translation_id ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);

					$touched[ (int) $row->language_id ] = true;
				}

				continue;
			}

			$segment = $segments[ $key ];
			$format  = (string) ( $segment['text_format'] ?? self::FORMAT_PLAIN );
			$text    = (string) ( $segment['source_text'] ?? '' );
			$hash    = self::source_hash( $text, $format );

			if ( $hash === (string) $row->source_hash ) {
				continue;
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::translations(),
				array(
					'source_text'    => $text,
					'source_hash'    => $hash,
					'norm_version'   => self::NORM_VERSION,
					'source_subtype' => $source_subtype,
					'is_stale'       => 1,
					'updated_at'     => $now,
				),
				array( 'translation_id' => (int) $row->translation_id ),
				array( '%s', '%s', '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);

			$touched[ (int) $row->language_id ] = true;
		}

		foreach ( array_keys( $touched ) as $language_id ) {
			$this->invalidate( $source_type, $source_id, (int) $language_id );
		}
	}

	/**
	 * Deletes every segment of an object in one language.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @param int    $language_id Language id.
	 */
	public function delete_object( string $source_type, int $source_id, int $language_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			array(
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'language_id' => $language_id,
			),
			array( '%s', '%d', '%d' )
		);

		$this->invalidate( $source_type, $source_id, $language_id );
	}

	// -- Term compatibility authority (TSC.1 / ADR-0021) --

	/**
	 * Runs a callback while holding the term compatibility authority lock.
	 *
	 * Serializes adoption against axis mutation so a review or publication
	 * write can never land on a hosted row that another request has just
	 * superseded. The lock order is frozen: native candidate key first, hosted
	 * key second, identically for both callers. The native row is usually
	 * absent, which is why the lock is taken through the unique key rather
	 * than a primary key — InnoDB then holds a gap lock on the missing row and
	 * a concurrent insert of the same identity blocks.
	 *
	 * Callers must not already hold a `translation_id` lock on the hosted row.
	 *
	 * @param array<string, mixed> $ref      Identity reference. Requires term_id,
	 *                                       language_id and native_segment_key; accepts
	 *                                       taxonomy, native_field_key, hosted_source_type,
	 *                                       hosted_source_id, hosted_field_key,
	 *                                       hosted_segment_key.
	 * @param callable             $callback Receives one array with native, hosted,
	 *                                       authoritative and ref.
	 * @return mixed|WP_Error Callback result. A WP_Error result rolls the transaction back;
	 *                        a thrown failure rolls back and keeps propagating.
	 */
	public function with_term_compat_authority( array $ref, callable $callback ) {
		global $wpdb;

		$normalized = $this->normalize_term_compat_ref( $ref );
		if ( $normalized instanceof WP_Error ) {
			return $normalized;
		}

		// Nesting under an outer transaction (PHPUnit, or a caller that already
		// opened one) must use SAVEPOINT. A nested START TRANSACTION / COMMIT
		// implicitly commits the outer unit in MySQL/MariaDB and would leak
		// writes across tests and caller scopes.
		$boundary = $this->begin_term_compat_boundary();

		$committed = false;

		try {
			$native = $this->lock_row_by_identity(
				self::SOURCE_TERM,
				$normalized['term_id'],
				$normalized['language_id'],
				self::segment_hash( $normalized['native_field_key'], $normalized['native_segment_key'] )
			);

			$hosted = null;
			if ( $normalized['hosted_source_id'] > 0 && '' !== $normalized['hosted_segment_key'] ) {
				$hosted = $this->lock_row_by_identity(
					$normalized['hosted_source_type'],
					$normalized['hosted_source_id'],
					$normalized['language_id'],
					self::segment_hash( $normalized['hosted_field_key'], $normalized['hosted_segment_key'] )
				);
			}

			$result = $callback(
				array(
					'native'        => $native,
					'hosted'        => $hosted,
					'authoritative' => $this->resolve_authority( $native, $hosted ),
					'ref'           => $normalized,
				)
			);

			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$this->commit_term_compat_boundary( $boundary );
			$committed = true;

			return $result;
		} finally {
			// Anything that did not reach the commit — a returned WP_Error or a
			// thrown failure on its way out — leaves the prior authority intact.
			// Exceptions keep propagating; a half-applied adoption would be a
			// worse outcome than a visible failure.
			if ( ! $committed ) {
				$this->rollback_term_compat_boundary( $boundary );
			}
		}
	}

	/**
	 * Opens a transaction or savepoint for term compatibility authority work.
	 *
	 * @return array{mode: string, name: string}
	 */
	private function begin_term_compat_boundary(): array {
		global $wpdb;

		$in_transaction = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT @@SESSION.in_transaction WHERE %d = %d', 1, 1 ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( $in_transaction > 0 ) {
			$name = 'aiml_tc_' . str_replace( '.', '', uniqid( '', true ) );
			$wpdb->query( 'SAVEPOINT `' . $name . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			return array(
				'mode' => 'savepoint',
				'name' => $name,
			);
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		return array(
			'mode' => 'transaction',
			'name' => '',
		);
	}

	/**
	 * Commits a term compatibility boundary opened by begin_term_compat_boundary.
	 *
	 * @param array{mode: string, name: string} $boundary Boundary descriptor.
	 */
	private function commit_term_compat_boundary( array $boundary ): void {
		global $wpdb;

		if ( 'savepoint' === $boundary['mode'] ) {
			$wpdb->query( 'RELEASE SAVEPOINT `' . $boundary['name'] . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			return;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Rolls back a term compatibility boundary opened by begin_term_compat_boundary.
	 *
	 * @param array{mode: string, name: string} $boundary Boundary descriptor.
	 */
	private function rollback_term_compat_boundary( array $boundary ): void {
		global $wpdb;

		if ( 'savepoint' === $boundary['mode'] ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT `' . $boundary['name'] . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

			return;
		}

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Mutates whichever row currently holds authority for a logical term field.
	 *
	 * Axis writes (review, publication) never adopt. They re-resolve authority
	 * under the lock and are remapped to the native row the moment one exists,
	 * so a successful mutation can never be recorded only on a retired hosted
	 * row.
	 *
	 * @param array<string, mixed>                              $ref     Identity reference (see with_term_compat_authority).
	 * @param callable(string, int, int, string, object): mixed $mutator Receives source_type, source_id, language_id, segment_key, row.
	 * @return mixed|WP_Error Mutator result, or WP_Error when no row holds authority.
	 */
	public function mutate_under_term_compat_authority( array $ref, callable $mutator ) {
		return $this->with_term_compat_authority(
			$ref,
			static function ( array $context ) use ( $mutator ) {
				$authority = (string) $context['authoritative'];

				if ( 'native' === $authority ) {
					$row = $context['native'];
				} elseif ( 'hosted' === $authority ) {
					$row = $context['hosted'];
				} else {
					return new WP_Error(
						'aiml_term_authority_missing',
						__( 'No translation row holds authority for this term field.', 'universal-multilingual' )
					);
				}

				return $mutator(
					(string) $row->source_type,
					(int) $row->source_id,
					(int) $row->language_id,
					(string) $row->segment_key,
					$row
				);
			}
		);
	}

	/**
	 * Moves a hosted compatibility row onto a first-class identity.
	 *
	 * Deliberately not `save_translation`: that recomputes the content hashes
	 * and clears the review and publication axes on any material change, which
	 * would silently destroy approval and publication evidence. Columns are
	 * copied only while they stay semantically valid under the new identity
	 * and hash contract; everything else is recomputed or honestly reset.
	 *
	 * The hosted row is retained and retired as `ignored` with an empty
	 * `error_code`. `orphaned` keeps meaning "the source no longer produces
	 * this segment", which is a different fact from "superseded".
	 *
	 * @param object                                                      $hosted_row           Row currently holding the translation.
	 * @param array<string, mixed>                                        $native_identity      Target identity: source_type, source_id,
	 *                                                                                          source_subtype, field_key, segment_key.
	 * @param callable(string, string): (array<string, string>|null)|null $source_text_resolver Optional
	 *                                                   current-extract lookup for source honesty.
	 * @return object|WP_Error Native row, or WP_Error.
	 */
	public function adopt_row_to_identity( object $hosted_row, array $native_identity, ?callable $source_text_resolver = null ) {
		$target_type = (string) ( $native_identity['source_type'] ?? self::SOURCE_TERM );
		$target_id   = (int) ( $native_identity['source_id'] ?? 0 );
		$segment_key = (string) ( $native_identity['segment_key'] ?? '' );
		$field_key   = (string) ( $native_identity['field_key'] ?? $segment_key );
		$language_id = (int) ( $hosted_row->language_id ?? 0 );

		if ( self::SOURCE_TERM !== $target_type ) {
			return new WP_Error( 'aiml_adopt_unsupported_identity', __( 'Only term identities can be adopted.', 'universal-multilingual' ) );
		}

		if ( $target_id <= 0 || $language_id <= 0 || '' === $segment_key ) {
			return new WP_Error( 'aiml_adopt_invalid_identity', __( 'Incomplete adoption identity.', 'universal-multilingual' ) );
		}

		$ref = array(
			'term_id'            => $target_id,
			'taxonomy'           => (string) ( $native_identity['source_subtype'] ?? '' ),
			'language_id'        => $language_id,
			'native_field_key'   => $field_key,
			'native_segment_key' => $segment_key,
			'hosted_source_type' => (string) ( $hosted_row->source_type ?? self::SOURCE_POST ),
			'hosted_source_id'   => (int) ( $hosted_row->source_id ?? 0 ),
			'hosted_field_key'   => (string) ( $hosted_row->field_key ?? '' ),
			'hosted_segment_key' => (string) ( $hosted_row->segment_key ?? '' ),
		);

		$adopted = $this->with_term_compat_authority(
			$ref,
			function ( array $context ) use ( $native_identity, $source_text_resolver ) {
				$native = $context['native'];
				$hosted = $context['hosted'];
				$locked = $context['ref'];

				// Native already exists: it stays authoritative and is never
				// overwritten by the older hosted copy.
				if ( null !== $native ) {
					$this->retire_hosted_compat_row( $hosted );

					return $native;
				}

				if ( null === $hosted ) {
					return new WP_Error( 'aiml_adopt_hosted_missing', __( 'The hosted translation row no longer exists.', 'universal-multilingual' ) );
				}

				$this->insert_raw( $this->adopt_column_map( $hosted, $native_identity, $source_text_resolver ) );

				// Re-read rather than trust the insert result: a concurrent
				// adoption that won the unique key is a success for us too.
				$adopted = $this->fetch_row_by_identity(
					self::SOURCE_TERM,
					$locked['term_id'],
					$locked['language_id'],
					self::segment_hash( $locked['native_field_key'], $locked['native_segment_key'] )
				);

				if ( null === $adopted ) {
					return new WP_Error( 'aiml_adopt_insert_failed', __( 'Could not create the term translation row.', 'universal-multilingual' ) );
				}

				$this->retire_hosted_compat_row( $hosted );

				return $adopted;
			}
		);

		// Caches are dropped only once the transaction is durable: dropping them
		// earlier would let a concurrent read repopulate them from uncommitted
		// state and outlive the rollback.
		if ( ! $adopted instanceof WP_Error ) {
			$this->invalidate_adopted_identities( $ref );
		}

		return $adopted;
	}

	/**
	 * Normalizes and validates a term compatibility reference.
	 *
	 * @param array<string, mixed> $ref Raw reference.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_term_compat_ref( array $ref ) {
		$term_id            = (int) ( $ref['term_id'] ?? 0 );
		$language_id        = (int) ( $ref['language_id'] ?? 0 );
		$native_segment_key = (string) ( $ref['native_segment_key'] ?? '' );

		if ( $term_id <= 0 || $language_id <= 0 || '' === $native_segment_key ) {
			return new WP_Error( 'aiml_invalid_term_ref', __( 'Incomplete term identity reference.', 'universal-multilingual' ) );
		}

		$hosted_segment_key = (string) ( $ref['hosted_segment_key'] ?? '' );

		return array(
			'term_id'            => $term_id,
			'taxonomy'           => (string) ( $ref['taxonomy'] ?? '' ),
			'language_id'        => $language_id,
			'native_field_key'   => (string) ( $ref['native_field_key'] ?? $native_segment_key ),
			'native_segment_key' => $native_segment_key,
			'hosted_source_type' => (string) ( $ref['hosted_source_type'] ?? self::SOURCE_POST ),
			'hosted_source_id'   => (int) ( $ref['hosted_source_id'] ?? 0 ),
			'hosted_field_key'   => (string) ( $ref['hosted_field_key'] ?? $hosted_segment_key ),
			'hosted_segment_key' => $hosted_segment_key,
		);
	}

	/**
	 * Which of the two candidate rows may currently be written as authoritative.
	 *
	 * @param object|null $native Native row.
	 * @param object|null $hosted Hosted row.
	 */
	private function resolve_authority( ?object $native, ?object $hosted ): string {
		if ( null !== $native ) {
			return 'native';
		}

		if ( null !== $hosted && self::STATUS_IGNORED !== (string) $hosted->status ) {
			return 'hosted';
		}

		return 'none';
	}

	/**
	 * Reads one row through the segment identity key, holding a write lock.
	 *
	 * @param string $source_type  Source type.
	 * @param int    $source_id    Source object id.
	 * @param int    $language_id  Language id.
	 * @param string $segment_hash Segment identity hash.
	 */
	private function lock_row_by_identity( string $source_type, int $source_id, int $language_id, string $segment_hash ): ?object {
		return $this->fetch_row_by_identity( $source_type, $source_id, $language_id, $segment_hash, true );
	}

	/**
	 * Reads one row through the segment identity key.
	 *
	 * @param string $source_type  Source type.
	 * @param int    $source_id    Source object id.
	 * @param int    $language_id  Language id.
	 * @param string $segment_hash Segment identity hash.
	 * @param bool   $for_update   Whether to take a write lock.
	 */
	private function fetch_row_by_identity(
		string $source_type,
		int $source_id,
		int $language_id,
		string $segment_hash,
		bool $for_update = false
	): ?object {
		global $wpdb;

		$sql = 'SELECT * FROM ' . Schema::translations()
			. ' WHERE source_type = %s AND source_id = %d AND segment_hash = %s AND language_id = %d'
			. ( $for_update ? ' FOR UPDATE' : '' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( $sql, $source_type, $source_id, $segment_hash, $language_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return is_object( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Retires a superseded hosted row without pretending its source vanished.
	 *
	 * @param object|null $row Hosted row, when one is still present.
	 */
	private function retire_hosted_compat_row( ?object $row ): void {
		global $wpdb;

		if ( null === $row || self::STATUS_IGNORED === (string) $row->status ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::translations(),
			array(
				'status'        => self::STATUS_IGNORED,
				'error_code'    => '',
				'error_message' => '',
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'translation_id' => (int) $row->translation_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Drops the cached segment maps of both identities touched by an adoption.
	 *
	 * @param array<string, mixed> $ref Normalized reference.
	 */
	private function invalidate_adopted_identities( array $ref ): void {
		$this->invalidate( self::SOURCE_TERM, (int) $ref['term_id'], (int) $ref['language_id'] );

		if ( (int) $ref['hosted_source_id'] > 0 ) {
			$this->invalidate(
				(string) $ref['hosted_source_type'],
				(int) $ref['hosted_source_id'],
				(int) $ref['language_id']
			);
		}
	}

	/**
	 * Builds the native row from a hosted row per the column validity matrix.
	 *
	 * @param object                                                      $hosted               Hosted row.
	 * @param array<string, mixed>                                        $native_identity      Target identity.
	 * @param callable(string, string): (array<string, string>|null)|null $source_text_resolver Current extract lookup.
	 * @return array<string, mixed>
	 */
	private function adopt_column_map( object $hosted, array $native_identity, ?callable $source_text_resolver ): array {
		$field_key   = (string) ( $native_identity['field_key'] ?? '' );
		$segment_key = (string) ( $native_identity['segment_key'] ?? '' );

		$format = (string) $hosted->text_format;
		if ( ! in_array( $format, self::formats(), true ) ) {
			$format = self::FORMAT_PLAIN;
		}

		$source_text = (string) ( $hosted->source_text ?? '' );
		$is_stale    = (bool) $hosted->is_stale;

		if ( null !== $source_text_resolver ) {
			$extract = $source_text_resolver( $field_key, $segment_key );

			if ( is_array( $extract ) ) {
				$extract_format = (string) ( $extract['text_format'] ?? $format );
				if ( in_array( $extract_format, self::formats(), true ) ) {
					$format = $extract_format;
				}

				$extract_text = (string) ( $extract['source_text'] ?? '' );
				if ( $extract_text !== $source_text ) {
					// The source moved on while the translation lived on the
					// hosted identity: carry the current text and say so.
					$source_text = $extract_text;
					$is_stale    = true;
				}
			}
		}

		$translation_hash = (string) ( $hosted->translation_hash ?? '' );
		$submitted_hash   = (string) ( $hosted->submitted_translation_hash ?? '' );
		$status           = (string) $hosted->status;

		$data = array(
			'source_type'      => self::SOURCE_TERM,
			'source_id'        => (int) ( $native_identity['source_id'] ?? 0 ),
			'source_subtype'   => (string) ( $native_identity['source_subtype'] ?? '' ),
			'language_id'      => (int) $hosted->language_id,
			'field_key'        => $field_key,
			'segment_key'      => $segment_key,
			'segment_hash'     => self::segment_hash( $field_key, $segment_key ),
			'segment_kind'     => (string) ( $hosted->segment_kind ?? self::KIND_FIELD ),
			'segment_order'    => (int) $hosted->segment_order,
			'text_format'      => $format,
			'source_text'      => $source_text,
			'source_hash'      => self::source_hash( $source_text, $format ),
			'norm_version'     => self::NORM_VERSION,
			'translated_text'  => (string) ( $hosted->translated_text ?? '' ),
			'translation_hash' => $translation_hash,
			'status'           => $status,
			'is_stale'         => $is_stale ? 1 : 0,
			'provider'         => (string) ( $hosted->provider ?? '' ),
			'model'            => (string) ( $hosted->model ?? '' ),
			'prompt_profile'   => (string) ( $hosted->prompt_profile ?? '' ),
			'prompt_version'   => (string) ( $hosted->prompt_version ?? '' ),
			'glossary_version' => (int) ( $hosted->glossary_version ?? 0 ),
			'tm_id'            => self::copy_nullable_int( $hosted, 'tm_id' ),
			'translated_by'    => self::copy_nullable_int( $hosted, 'translated_by' ),
			// Publication survives adoption: nothing about the translated text
			// changed, so a published segment stays published (AC14).
			'publish_status'   => (string) $hosted->publish_status,
			'published_at'     => self::copy_nullable_value( $hosted, 'published_at' ),
			'published_by'     => self::copy_nullable_int( $hosted, 'published_by' ),
			'error_code'       => self::STATUS_FAILED === $status ? (string) ( $hosted->error_code ?? '' ) : '',
			'error_message'    => self::STATUS_FAILED === $status ? (string) ( $hosted->error_message ?? '' ) : '',
			'created_at'       => (string) $hosted->created_at,
			'updated_at'       => current_time( 'mysql', true ),
		);

		// Review evidence only survives while it still describes the text that
		// is actually stored. A submitted hash that no longer matches is not
		// evidence, so it is reset rather than carried forward as a claim.
		if ( '' === $submitted_hash || $submitted_hash === $translation_hash ) {
			$data += array(
				'review_status'              => (string) $hosted->review_status,
				'review_submitted_by'        => self::copy_nullable_int( $hosted, 'review_submitted_by' ),
				'review_submitted_at'        => self::copy_nullable_value( $hosted, 'review_submitted_at' ),
				'submitted_translation_hash' => $submitted_hash,
				'reviewed_by'                => self::copy_nullable_int( $hosted, 'reviewed_by' ),
				'reviewed_at'                => self::copy_nullable_value( $hosted, 'reviewed_at' ),
				'rejection_reason'           => (string) ( $hosted->rejection_reason ?? '' ),
				'rejected_by'                => self::copy_nullable_int( $hosted, 'rejected_by' ),
				'rejected_at'                => self::copy_nullable_value( $hosted, 'rejected_at' ),
			);
		} else {
			$data += self::review_clear_fields();
		}

		return $data;
	}

	/**
	 * Copies a nullable integer column, treating empty strings as null.
	 *
	 * @param object $row    Source row.
	 * @param string $column Column name.
	 */
	private static function copy_nullable_int( object $row, string $column ): ?int {
		$value = $row->{$column} ?? null;

		if ( null === $value || '' === (string) $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Copies a nullable string column, treating empty strings as null.
	 *
	 * @param object $row    Source row.
	 * @param string $column Column name.
	 */
	private static function copy_nullable_value( object $row, string $column ): ?string {
		$value = $row->{$column} ?? null;

		if ( null === $value || '' === (string) $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Inserts a fully-formed row without the save_translation lifecycle rules.
	 *
	 * @param array<string, mixed> $data Column values.
	 */
	private function insert_raw( array $data ): bool {
		global $wpdb;

		$columns      = array_keys( $data );
		$placeholders = array();
		$values       = array();

		foreach ( $columns as $column ) {
			$value = $data[ $column ];

			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			$placeholders[] = is_int( $value ) ? '%d' : '%s';
			$values[]       = $value;
		}

		$sql = 'INSERT INTO ' . Schema::translations()
			. ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		if ( array() === $values ) {
			return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}

		return false !== $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	// -- Internals --

	/**
	 * Inserts or updates a row by segment identity.
	 *
	 * Uses ON DUPLICATE KEY UPDATE against `segment_identity` so a replayed
	 * write is a no-op rather than a duplicate row.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @param string               $now  Timestamp for created_at on insert.
	 */
	private function upsert( array $data, string $now ): void {
		global $wpdb;

		$data['created_at'] = $now;

		$columns      = array_keys( $data );
		$placeholders = array();
		$values       = array();

		foreach ( $columns as $column ) {
			$value = $data[ $column ];

			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			if ( is_int( $value ) ) {
				$placeholders[] = '%d';
			} else {
				$placeholders[] = '%s';
			}

			$values[] = $value;
		}

		$updatable = array_diff( $columns, array( 'created_at', 'source_type', 'source_id', 'language_id', 'segment_hash' ) );

		$assignments = array();
		foreach ( $updatable as $column ) {
			$assignments[] = sprintf( '%1$s = VALUES(%1$s)', $column );
		}

		$sql = 'INSERT INTO ' . Schema::translations()
			. ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')'
			. ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $assignments );

		if ( array() === $values ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			return;
		}

		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Drops the cached segment map for an object and language.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source object id.
	 * @param int    $language_id Language id.
	 */
	private function invalidate( string $source_type, int $source_id, int $language_id ): void {
		$this->cache->delete( sprintf( 'seg:%s:%d', $source_type, $source_id ), $language_id );
	}

	/**
	 * Casts a raw row's numeric columns.
	 *
	 * @param object $row Raw row from $wpdb.
	 */
	private function hydrate( object $row ): object {
		$row->translation_id = (int) $row->translation_id;
		$row->source_id      = (int) $row->source_id;
		$row->language_id    = (int) $row->language_id;
		$row->segment_order  = (int) $row->segment_order;
		$row->norm_version   = (int) $row->norm_version;
		$row->is_stale       = (bool) $row->is_stale;
		$row->status         = (string) $row->status;
		$row->segment_key    = (string) $row->segment_key;
		$row->field_key      = (string) $row->field_key;
		$row->text_format    = (string) $row->text_format;

		if ( isset( $row->review_status ) ) {
			$row->review_status = (string) $row->review_status;
		} else {
			$row->review_status = self::REVIEW_NOT_SUBMITTED;
		}

		if ( isset( $row->publish_status ) ) {
			$row->publish_status = (string) $row->publish_status;
		} else {
			$row->publish_status = self::PUBLISH_UNPUBLISHED;
		}

		$row->submitted_translation_hash = (string) ( $row->submitted_translation_hash ?? '' );
		$row->rejection_reason           = (string) ( $row->rejection_reason ?? '' );
		$row->review_submitted_by        = null !== ( $row->review_submitted_by ?? null ) && '' !== (string) $row->review_submitted_by
			? (int) $row->review_submitted_by
			: null;
		$row->reviewed_by                = null !== ( $row->reviewed_by ?? null ) && '' !== (string) $row->reviewed_by
			? (int) $row->reviewed_by
			: null;
		$row->rejected_by                = null !== ( $row->rejected_by ?? null ) && '' !== (string) $row->rejected_by
			? (int) $row->rejected_by
			: null;
		$row->published_by               = null !== ( $row->published_by ?? null ) && '' !== (string) $row->published_by
			? (int) $row->published_by
			: null;
		$row->slug_origin                = (string) ( $row->slug_origin ?? '' );

		return $row;
	}

	// -- Normalization primitives --

	/**
	 * Converts CRLF and lone CR to LF.
	 *
	 * @param string $text Input.
	 */
	private static function normalize_line_endings( string $text ): string {
		return (string) preg_replace( '/\r\n?/', "\n", $text );
	}

	/**
	 * Rewrites every representation of a non-breaking space to the codepoint.
	 *
	 * Keeps the non-breaking property intact, which matters in HTML where the
	 * difference is visible.
	 *
	 * @param string $text Input.
	 */
	private static function canonicalize_nbsp( string $text ): string {
		return (string) str_replace( array( '&nbsp;', '&#160;', '&#xA0;', '&#xa0;' ), "\xC2\xA0", $text );
	}

	/**
	 * Reduces every representation of a non-breaking space to a plain space.
	 *
	 * @param string $text Input.
	 */
	private static function nbsp_to_space( string $text ): string {
		return (string) str_replace(
			array( '&nbsp;', '&#160;', '&#xA0;', '&#xa0;', "\xC2\xA0" ),
			' ',
			$text
		);
	}

	/**
	 * Re-encodes JSON canonically, or returns the raw bytes when invalid.
	 *
	 * Invalid JSON is never "repaired": doing so would let two genuinely
	 * different broken documents hash the same.
	 *
	 * @param string $text Input.
	 */
	private static function canonicalize_json( string $text ): string {
		$decoded = json_decode( $text, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $text;
		}

		$sorted = self::sort_recursive( $decoded );

		// Deliberately json_encode() and not wp_json_encode(): this helper has
		// to run in the unit suite, which loads no WordPress.
		$encoded = json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		return false === $encoded ? $text : $encoded;
	}

	/**
	 * Sorts associative array keys recursively, leaving lists in order.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return mixed
	 */
	private static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		$value = array_map( array( self::class, 'sort_recursive' ), $value );

		if ( ! $is_list ) {
			ksort( $value );
		}

		return $value;
	}
}
