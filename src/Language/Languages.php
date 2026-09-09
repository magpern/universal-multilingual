<?php
/**
 * Language configuration store.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Language;

use AIMultilingual\Cache\Cache;
use AIMultilingual\Database\Schema;
use WP_Error;

/**
 * CRUD over `aiml_languages`, plus the validation rules that keep the table
 * coherent.
 *
 * Rows are returned as plain readonly-by-convention objects with their numeric
 * columns already cast, so callers never deal with the string-typed values
 * $wpdb hands back. The validators are static and WordPress-free so the rules
 * can be unit-tested without a bootstrap.
 *
 * Two invariants are enforced here rather than in the schema, because SQL
 * cannot express either: exactly one row is the default, and status changes
 * follow the allowed transitions.
 */
final class Languages {

	/**
	 * Language states.
	 */
	public const STATUS_DISABLED  = 'disabled';
	public const STATUS_PREVIEW   = 'preview';
	public const STATUS_PUBLISHED = 'published';

	/**
	 * Text directions.
	 */
	public const DIRECTIONS = array( 'ltr', 'rtl' );

	/**
	 * Cache key for the full language list.
	 */
	private const CACHE_KEY = 'languages';

	/**
	 * Object cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Per-request memo of the full list.
	 *
	 * @var object[]|null
	 */
	private ?array $memo = null;

	/**
	 * Global cache epoch observed when the memo was populated.
	 *
	 * @var int|null
	 */
	private ?int $memo_epoch = null;

	/**
	 * Builds the language store.
	 *
	 * @param Cache $cache Object cache wrapper.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	// -- Validation (pure; safe to call without WordPress loaded) --

	/**
	 * Every valid language state.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( self::STATUS_DISABLED, self::STATUS_PREVIEW, self::STATUS_PUBLISHED );
	}

	/**
	 * Whether a URL code is well formed.
	 *
	 * The grammar is `language` / `language-region` / `language-region-variant`
	 * (ADR-0029): a two- or three-letter language, an optional two-letter
	 * region, and — only with a region — an optional variant segment. Examples:
	 * `sv`, `pt-br`, `ceb`, `de-de-formal`, `pt-pt-ao90`. The code is used
	 * verbatim as the first URL path segment, so it is restricted to lowercase
	 * ASCII to keep routing unambiguous. It is derived once at creation and
	 * immutable afterward.
	 *
	 * @param string $code Candidate code.
	 */
	public static function is_valid_code( string $code ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$/', $code );
	}

	/**
	 * Whether a WordPress locale is well formed.
	 *
	 * Accepts a bare language (`en`, `ceb`), a language with an uppercase
	 * region (`sv_SE`), that plus a variant (`de_DE_formal`), and the
	 * variant-only form WordPress uses for a handful of locales
	 * (`art_xemoji`).
	 *
	 * A two-letter lowercase segment after the underscore is rejected: `sv_se`
	 * is a mistyped region, not a locale, and accepting it would silently
	 * produce a language whose translation files never load.
	 *
	 * @param string $locale Candidate locale.
	 */
	public static function is_valid_locale( string $locale ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(_[A-Z]{2}(_[A-Za-z0-9]+)?|_[a-z0-9]{3,})?$/', $locale );
	}

	/**
	 * Whether a status string is one of the three known states.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_valid_status( string $status ): bool {
		return in_array( $status, self::statuses(), true );
	}

	/**
	 * Whether a status change is allowed.
	 *
	 * A disabled language cannot jump straight to published: it passes through
	 * preview first, so someone always looks at it before visitors do.
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( ! self::is_valid_status( $from ) || ! self::is_valid_status( $to ) ) {
			return false;
		}

		if ( $from === $to ) {
			return true;
		}

		$allowed = array(
			self::STATUS_PREVIEW   => array( self::STATUS_PUBLISHED, self::STATUS_DISABLED ),
			self::STATUS_PUBLISHED => array( self::STATUS_PREVIEW, self::STATUS_DISABLED ),
			self::STATUS_DISABLED  => array( self::STATUS_PREVIEW ),
		);

		return in_array( $to, $allowed[ $from ], true );
	}

	// -- Reads --

	/**
	 * All languages, ordered by sort order then code.
	 *
	 * @return object[]
	 */
	public function all(): array {
		$epoch = (int) get_option( Cache::VERSION_OPTION, 0 );
		if ( null !== $this->memo && $this->memo_epoch === $epoch ) {
			return $this->memo;
		}

		$this->memo = null;

		$cached = $this->cache->get( self::CACHE_KEY, 0 );
		if ( is_array( $cached ) ) {
			$this->memo       = $cached;
			$this->memo_epoch = $epoch;

			return $this->memo;
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . Schema::languages() . ' ORDER BY sort_order ASC, code ASC' // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$this->memo       = array_map( array( $this, 'hydrate' ), (array) $rows );
		$this->memo_epoch = $epoch;

		$this->cache->set( self::CACHE_KEY, 0, $this->memo );

		return $this->memo;
	}

	/**
	 * Languages that may appear in a switcher for this viewer.
	 *
	 * @param bool $viewer_can_preview Whether the viewer may see preview languages.
	 * @return object[]
	 */
	public function routable( bool $viewer_can_preview = false ): array {
		$resolver = new LanguageResolver();

		return array_values(
			array_filter(
				$this->all(),
				static function ( object $language ) use ( $resolver, $viewer_can_preview ): bool {
					return ! empty( $language->is_default )
						|| $resolver->is_routable( $language, $viewer_can_preview );
				}
			)
		);
	}

	/**
	 * Finds a language by primary key.
	 *
	 * @param int $language_id Language id.
	 */
	public function find( int $language_id ): ?object {
		foreach ( $this->all() as $language ) {
			if ( (int) $language->language_id === $language_id ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Finds a language by URL code.
	 *
	 * @param string $code Language code.
	 */
	public function find_by_code( string $code ): ?object {
		$code = strtolower( trim( $code ) );

		foreach ( $this->all() as $language ) {
			if ( (string) $language->code === $code ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Finds a language by WordPress locale.
	 *
	 * A locale identifies exactly one language: two rows sharing a locale would
	 * load the same translation files and is rejected on write (and, from
	 * schema 9, by a UNIQUE KEY).
	 *
	 * @param string $locale WordPress locale, e.g. `sv_SE`.
	 */
	public function find_by_locale( string $locale ): ?object {
		$locale = trim( $locale );

		foreach ( $this->all() as $language ) {
			if ( (string) $language->locale === $locale ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * The default (source) language, or null before one is seeded.
	 */
	public function default(): ?object {
		foreach ( $this->all() as $language ) {
			if ( ! empty( $language->is_default ) ) {
				return $language;
			}
		}

		return null;
	}

	// -- Writes --

	/**
	 * Creates a language.
	 *
	 * @param array<string, mixed> $data Language fields.
	 * @return int|WP_Error New language id, or an error.
	 */
	public function insert( array $data ) {
		$clean = $this->validate( $data );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		if ( null !== $this->find_by_code( $clean['code'] ) ) {
			return new WP_Error(
				'aiml_duplicate_code',
				__( 'A language with that code already exists.', 'universal-multilingual' )
			);
		}

		if ( null !== $this->find_by_locale( $clean['locale'] ) ) {
			return new WP_Error(
				'aiml_duplicate_locale',
				__( 'A language with that locale already exists.', 'universal-multilingual' )
			);
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::languages(),
			array(
				'code'        => $clean['code'],
				'locale'      => $clean['locale'],
				'name'        => $clean['name'],
				'native_name' => $clean['native_name'],
				'direction'   => $clean['direction'],
				'is_default'  => 0,
				'status'      => $clean['status'],
				'sort_order'  => $clean['sort_order'],
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'aiml_insert_failed', __( 'Could not save the language.', 'universal-multilingual' ) );
		}

		// Read the new id before flushing. Flushing writes a cache-version
		// option, and that write replaces $wpdb->insert_id with the options
		// row's id.
		$language_id = (int) $wpdb->insert_id;

		$this->flush();

		return $language_id;
	}

	/**
	 * Updates a language.
	 *
	 * @param int                  $language_id Language id.
	 * @param array<string, mixed> $data        Fields to change.
	 * @return true|WP_Error
	 */
	public function update( int $language_id, array $data ) {
		$existing = $this->find( $language_id );
		if ( null === $existing ) {
			return new WP_Error( 'aiml_unknown_language', __( 'That language does not exist.', 'universal-multilingual' ) );
		}

		$merged = array(
			'code'        => $data['code'] ?? $existing->code,
			'locale'      => $data['locale'] ?? $existing->locale,
			'name'        => $data['name'] ?? $existing->name,
			'native_name' => $data['native_name'] ?? $existing->native_name,
			'direction'   => $data['direction'] ?? $existing->direction,
			'status'      => $data['status'] ?? $existing->status,
			'sort_order'  => $data['sort_order'] ?? $existing->sort_order,
		);

		$clean = $this->validate( $merged );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$duplicate = $this->find_by_code( $clean['code'] );
		if ( null !== $duplicate && (int) $duplicate->language_id !== $language_id ) {
			return new WP_Error(
				'aiml_duplicate_code',
				__( 'A language with that code already exists.', 'universal-multilingual' )
			);
		}

		$locale_duplicate = $this->find_by_locale( $clean['locale'] );
		if ( null !== $locale_duplicate && (int) $locale_duplicate->language_id !== $language_id ) {
			return new WP_Error(
				'aiml_duplicate_locale',
				__( 'A language with that locale already exists.', 'universal-multilingual' )
			);
		}

		// The default language is always effectively published; it has no other
		// meaningful state because it is the source content itself.
		if ( ! empty( $existing->is_default ) ) {
			$clean['status'] = self::STATUS_PUBLISHED;
		} elseif ( ! self::can_transition( (string) $existing->status, $clean['status'] ) ) {
			return new WP_Error(
				'aiml_invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: requested status. */
					__( 'A language cannot move directly from %1$s to %2$s.', 'universal-multilingual' ),
					(string) $existing->status,
					$clean['status']
				)
			);
		}

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::languages(),
			array(
				'code'        => $clean['code'],
				'locale'      => $clean['locale'],
				'name'        => $clean['name'],
				'native_name' => $clean['native_name'],
				'direction'   => $clean['direction'],
				'status'      => $clean['status'],
				'sort_order'  => $clean['sort_order'],
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'language_id' => $language_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->flush();

		return true;
	}

	/**
	 * Deletes a language.
	 *
	 * Translations are deliberately retained: deleting a language must not
	 * destroy the work done in it (invariant I5). They become unreferenced rows
	 * that reappear if the language is recreated with the same id.
	 *
	 * @param int $language_id Language id.
	 * @return true|WP_Error
	 */
	public function delete( int $language_id ) {
		$existing = $this->find( $language_id );
		if ( null === $existing ) {
			return new WP_Error( 'aiml_unknown_language', __( 'That language does not exist.', 'universal-multilingual' ) );
		}

		if ( ! empty( $existing->is_default ) ) {
			return new WP_Error(
				'aiml_default_language',
				__( 'The default language cannot be deleted.', 'universal-multilingual' )
			);
		}

		global $wpdb;

		$wpdb->delete( Schema::languages(), array( 'language_id' => $language_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->flush();

		return true;
	}

	/**
	 * Creates the default language row if the table has none.
	 *
	 * Called on activation. The site's own locale becomes the source language,
	 * which is what makes the plugin inert on a single-language site until a
	 * second language is added.
	 *
	 * @param string $locale WordPress locale, e.g. `en_US`.
	 * @return int Language id of the default language.
	 */
	public function ensure_default( string $locale ): int {
		$existing = $this->default();
		if ( null !== $existing ) {
			return (int) $existing->language_id;
		}

		$locale = self::is_valid_locale( $locale ) ? $locale : 'en_US';
		$code   = strtolower( substr( $locale, 0, 2 ) );

		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::languages(),
			array(
				'code'        => $code,
				'locale'      => $locale,
				'name'        => $code,
				'native_name' => '',
				'direction'   => is_rtl() ? 'rtl' : 'ltr',
				'is_default'  => 1,
				'status'      => self::STATUS_PUBLISHED,
				'sort_order'  => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		// Captured before flushing, which writes an option and would otherwise
		// overwrite $wpdb->insert_id.
		$language_id = (int) $wpdb->insert_id;

		$this->flush();

		return $language_id;
	}

	/**
	 * Clears memoized and cached language data.
	 *
	 * Language configuration affects routing for every language, so this bumps
	 * the global cache epoch rather than one language counter.
	 */
	public function flush(): void {
		$this->memo       = null;
		$this->memo_epoch = null;

		$this->cache->flush_all();
	}

	// -- Internals --

	/**
	 * Validates and normalizes language input.
	 *
	 * @param array<string, mixed> $data Raw fields.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate( array $data ) {
		$code = strtolower( trim( (string) ( $data['code'] ?? '' ) ) );
		if ( ! self::is_valid_code( $code ) ) {
			return new WP_Error(
				'aiml_invalid_code',
				__( 'Language code must be two lowercase letters, optionally followed by a region (for example sv or pt-br).', 'universal-multilingual' )
			);
		}

		$locale = trim( (string) ( $data['locale'] ?? '' ) );
		if ( ! self::is_valid_locale( $locale ) ) {
			return new WP_Error(
				'aiml_invalid_locale',
				__( 'Locale must look like sv_SE.', 'universal-multilingual' )
			);
		}

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error( 'aiml_missing_name', __( 'Language name is required.', 'universal-multilingual' ) );
		}

		$status = (string) ( $data['status'] ?? self::STATUS_PREVIEW );
		if ( ! self::is_valid_status( $status ) ) {
			return new WP_Error( 'aiml_invalid_status', __( 'Unknown language state.', 'universal-multilingual' ) );
		}

		$direction = (string) ( $data['direction'] ?? 'ltr' );
		if ( ! in_array( $direction, self::DIRECTIONS, true ) ) {
			$direction = 'ltr';
		}

		return array(
			'code'        => $code,
			'locale'      => $locale,
			'name'        => sanitize_text_field( $name ),
			'native_name' => sanitize_text_field( trim( (string) ( $data['native_name'] ?? '' ) ) ),
			'direction'   => $direction,
			'status'      => $status,
			'sort_order'  => max( 0, (int) ( $data['sort_order'] ?? 0 ) ),
		);
	}

	/**
	 * Casts a raw database row into typed properties.
	 *
	 * @param object $row Raw row from $wpdb.
	 */
	private function hydrate( object $row ): object {
		$row->language_id = (int) $row->language_id;
		$row->is_default  = (bool) $row->is_default;
		$row->sort_order  = (int) $row->sort_order;
		$row->code        = (string) $row->code;
		$row->locale      = (string) $row->locale;
		$row->status      = (string) $row->status;
		$row->direction   = (string) $row->direction;

		return $row;
	}
}
