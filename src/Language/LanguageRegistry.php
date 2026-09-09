<?php
/**
 * Read access to the curated locale registry.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Language;

/**
 * The plugin-owned source of truth for language/locale metadata (ADR-0028).
 *
 * Loads `data/locales.php` once per request. Pure PHP: no database, no network,
 * no dependency on installed language packs or the PHP `intl` extension. The
 * data file is generated offline from GlotPress locale data by
 * `bin/build-locale-registry.php`; see its `provenance` block for the source
 * revision and date.
 */
final class LanguageRegistry {

	/**
	 * Absolute path to the registry data file, or null for the shipped default.
	 *
	 * @var string|null
	 */
	private ?string $path;

	/**
	 * Parsed data file, lazily loaded.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $data = null;

	/**
	 * Builds the registry reader.
	 *
	 * @param string|null $path Override data-file path (tests only).
	 */
	public function __construct( ?string $path = null ) {
		$this->path = $path;
	}

	/**
	 * Provenance block: source, revision, snapshot date, generator version.
	 *
	 * @return array<string, string>
	 */
	public function provenance(): array {
		$provenance = $this->load()['provenance'] ?? array();

		return is_array( $provenance ) ? array_map( 'strval', $provenance ) : array();
	}

	/**
	 * Every language group, ordered by group key.
	 *
	 * @return LanguageGroup[]
	 */
	public function groups(): array {
		$groups = array();
		foreach ( (array) ( $this->load()['groups'] ?? array() ) as $key => $row ) {
			$groups[] = LanguageGroup::from_row( (string) $key, (array) $row );
		}

		return $groups;
	}

	/**
	 * One language group, or null when unknown.
	 *
	 * @param string $key Group key (a `language_code`).
	 */
	public function group( string $key ): ?LanguageGroup {
		$row = $this->load()['groups'][ $key ] ?? null;

		return null === $row ? null : LanguageGroup::from_row( $key, (array) $row );
	}

	/**
	 * Whether a group key is known.
	 *
	 * @param string $key Group key.
	 */
	public function has_group( string $key ): bool {
		return isset( $this->load()['groups'][ $key ] );
	}

	/**
	 * The locales offered for a group, primary first.
	 *
	 * @param string $key Group key.
	 * @return LocaleMetadata[]
	 */
	public function locales_for( string $key ): array {
		$group = $this->group( $key );
		if ( null === $group ) {
			return array();
		}

		$locales = array();
		foreach ( $group->locales as $locale ) {
			$meta = $this->metadata_for_locale( $locale );
			if ( null !== $meta ) {
				$locales[] = $meta;
			}
		}

		return $locales;
	}

	/**
	 * Metadata for one exact WordPress runtime locale, or null when unknown.
	 *
	 * @param string $locale WordPress runtime locale string.
	 */
	public function metadata_for_locale( string $locale ): ?LocaleMetadata {
		$row = $this->load()['locales'][ $locale ] ?? null;

		return null === $row ? null : LocaleMetadata::from_row( $locale, (array) $row );
	}

	/**
	 * The group key that owns a locale, or null when the locale is unknown.
	 *
	 * @param string $locale WordPress runtime locale string.
	 */
	public function group_for_locale( string $locale ): ?string {
		$row = $this->load()['locales'][ $locale ] ?? null;

		return null === $row ? null : (string) ( $row['group'] ?? '' );
	}

	/**
	 * Whether a locale is a first-class registry entry.
	 *
	 * @param string $locale WordPress runtime locale string.
	 */
	public function has_locale( string $locale ): bool {
		return isset( $this->load()['locales'][ $locale ] );
	}

	/**
	 * Loads and memoises the data file.
	 *
	 * @return array<string, mixed>
	 */
	private function load(): array {
		if ( null === $this->data ) {
			$path       = $this->path ?? __DIR__ . '/data/locales.php';
			$data       = is_readable( $path ) ? require $path : array();
			$this->data = is_array( $data ) ? $data : array();
		}

		return $this->data;
	}
}
