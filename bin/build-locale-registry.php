<?php
/**
 * Locale registry generator (dev tool — NOT shipped, NOT autoloaded).
 *
 * Reads a local copy of the GlotPress `locales/locales.php` file and emits
 * `src/Language/data/locales.php`, the plugin's offline locale registry.
 *
 * GlotPress (https://github.com/GlotPress/GlotPress) is GPL-2.0-or-later, the
 * same licence as this plugin. Only its locale *data* is used, as an offline
 * authoring input; the plugin takes no runtime dependency on GlotPress.
 *
 * Usage:
 *   php bin/build-locale-registry.php \
 *     --source=/path/to/glotpress-locales.php \
 *     --revision=<git sha> \
 *     [--url=<source url>] \
 *     [--date=YYYY-MM-DD] \
 *     [--out=src/Language/data/locales.php]
 *
 * Every entry that cannot be represented safely (no usable language code, a
 * WordPress locale that fails the production validator, or a URL code that
 * fails the routing grammar) is DROPPED with a warning printed to STDERR. No
 * upstream identifier is ever reshaped into an invented alternative.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

// Mirrors of the production contracts. Kept in sync by hand with:
// - Languages::is_valid_locale()  (src/Language/Languages.php)
// - Languages::is_valid_code()    (extended grammar, ADR-0029)
const AIML_GEN_LOCALE_RE = '/^[a-z]{2,3}(_[A-Z]{2}(_[A-Za-z0-9]+)?|_[a-z0-9]{3,})?$/';
const AIML_GEN_CODE_RE   = '/^[a-z]{2,3}(-[a-z]{2}(-[a-z0-9]+)?)?$/';

/**
 * Parses `--key=value` CLI arguments.
 *
 * @param string[] $argv Raw argv.
 * @return array<string, string>
 */
function aiml_gen_parse_args( array $argv ): array {
	$args = array();
	foreach ( array_slice( $argv, 1 ) as $token ) {
		if ( preg_match( '/^--([a-z-]+)=(.*)$/', $token, $m ) ) {
			$args[ $m[1] ] = $m[2];
		}
	}
	return $args;
}

/**
 * Writes a warning to STDERR.
 *
 * @param string $message Warning text.
 */
function aiml_gen_warn( string $message ): void {
	fwrite( STDERR, 'WARN  ' . $message . "\n" );
}

/**
 * Derives the 2-3 letter language code for a GlotPress locale.
 *
 * @param object $locale GP_Locale instance.
 * @return string Empty string when no usable code exists.
 */
function aiml_gen_language_code( object $locale ): string {
	$candidates = array(
		$locale->lang_code_iso_639_1 ?? '',
		$locale->lang_code_iso_639_3 ?? '',
		$locale->lang_code_iso_639_2 ?? '',
	);

	foreach ( $candidates as $candidate ) {
		$candidate = strtolower( trim( (string) $candidate ) );
		if ( 1 === preg_match( '/^[a-z]{2,3}$/', $candidate ) ) {
			return $candidate;
		}
	}

	// Fall back to the first segment of the GlotPress slug (e.g. "pt-br" -> "pt").
	$segment = strtolower( (string) preg_split( '/[-_]/', (string) ( $locale->slug ?? '' ) )[0] );
	if ( 1 === preg_match( '/^[a-z]{2,3}$/', $segment ) ) {
		return $segment;
	}

	return '';
}

/**
 * Splits a WordPress locale into region + variant.
 *
 * @param string $wp_locale     WordPress locale, e.g. `pt_PT_ao90`.
 * @param string $language_code The already-derived language code.
 * @return array{region: string, variant: string}
 */
function aiml_gen_split_locale( string $wp_locale, string $language_code ): array {
	$parts = explode( '_', $wp_locale );
	array_shift( $parts ); // Drop the language segment.

	$region = '';
	if ( array() !== $parts && 1 === preg_match( '/^[A-Z]{2}$/', $parts[0] ) ) {
		$region = strtolower( array_shift( $parts ) );
	}

	$variant = strtolower( preg_replace( '/[^a-z0-9]/i', '', implode( '', $parts ) ) );

	return array(
		'region'  => $region,
		'variant' => $variant,
	);
}

/**
 * Provisional URL code, ignoring collisions — used only to test representability.
 *
 * @param string $language_code Language code.
 * @param string $region        Region segment (may be empty).
 * @param string $variant       Variant segment (may be empty).
 */
function aiml_gen_provisional_code( string $language_code, string $region, string $variant ): string {
	if ( '' !== $variant ) {
		return '' !== $region
			? $language_code . '-' . $region . '-' . $variant
			: $language_code . '-' . $variant;
	}

	return $language_code;
}

/**
 * Strips a trailing " (...)" qualifier from a display name.
 *
 * @param string $name Display name.
 */
function aiml_gen_base_name( string $name ): string {
	return trim( (string) preg_replace( '/\s*\([^()]*\)\s*$/', '', $name ) );
}

$args = aiml_gen_parse_args( $argv );

if ( ! isset( $args['source'] ) || ! is_readable( $args['source'] ) ) {
	fwrite( STDERR, "ERROR --source=<glotpress locales.php> is required and must be readable.\n" );
	exit( 1 );
}

$revision       = $args['revision'] ?? 'unknown';
$url            = $args['url'] ?? 'https://github.com/GlotPress/GlotPress/blob/develop/locales/locales.php';
$date           = $args['date'] ?? gmdate( 'Y-m-d' );
$out            = $args['out'] ?? dirname( __DIR__ ) . '/src/Language/data/locales.php';
$overrides_path = $args['overrides'] ?? __DIR__ . '/locale-registry-overrides.php';

require $args['source'];

if ( ! class_exists( 'GP_Locales' ) ) {
	fwrite( STDERR, "ERROR the source file did not define GP_Locales.\n" );
	exit( 1 );
}

$groups      = array();
$locale_rows = array();
$dropped     = 0;
$kept        = 0;

/**
 * Validates + normalises one candidate locale into a registry row.
 *
 * @param string $wp_locale     WordPress runtime locale string.
 * @param string $language_code Derived 2-3 letter language code ('' when unknown).
 * @param string $english_name  English display name.
 * @param string $native_name   Native display name.
 * @param string $direction     'ltr' or 'rtl'.
 * @param string $script        Lowercased script/alphabet hint ('' when unknown).
 * @param string $slug          GlotPress slug ('' for overrides).
 * @return array<string, mixed>|null Registry row, or null when the entry is unrepresentable.
 */
$build_row = static function ( string $wp_locale, string $language_code, string $english_name, string $native_name, string $direction, string $script, string $slug ): ?array {
	$wp_locale = trim( $wp_locale );
	if ( '' === $wp_locale ) {
		return null;
	}

	if ( '' === $language_code ) {
		aiml_gen_warn( "dropped {$wp_locale}: no usable 2-3 letter language code." );
		return null;
	}

	if ( 1 !== preg_match( AIML_GEN_LOCALE_RE, $wp_locale ) ) {
		aiml_gen_warn( "dropped {$wp_locale}: fails the production locale validator." );
		return null;
	}

	$split       = aiml_gen_split_locale( $wp_locale, $language_code );
	$provisional = aiml_gen_provisional_code( $language_code, $split['region'], $split['variant'] );

	if ( 1 !== preg_match( AIML_GEN_CODE_RE, $provisional ) ) {
		aiml_gen_warn( "dropped {$wp_locale}: URL code \"{$provisional}\" fails the routing grammar (variant without a region)." );
		return null;
	}

	$direction = 'rtl' === strtolower( $direction ) ? 'rtl' : 'ltr';

	$english_name = trim( $english_name );
	$native_name  = trim( $native_name );
	if ( '' === $native_name ) {
		$native_name = $english_name;
	}
	if ( '' === $english_name ) {
		aiml_gen_warn( "dropped {$wp_locale}: no English name." );
		return null;
	}

	return array(
		'group'         => $language_code,
		'language_code' => $language_code,
		'wp_locale'     => $wp_locale,
		'slug'          => $slug,
		'english_name'  => $english_name,
		'native_name'   => $native_name,
		'direction'     => $direction,
		'script'        => strtolower( trim( $script ) ),
		'region'        => $split['region'],
		'variant'       => $split['variant'],
		'is_variant'    => '' !== $split['variant'],
	);
};

foreach ( GP_Locales::instance()->locales as $gp ) {
	$wp_locale = trim( (string) ( $gp->wp_locale ?? '' ) );
	if ( '' === $wp_locale ) {
		continue; // Not a WordPress runtime locale — never first-class.
	}

	$row = $build_row(
		$wp_locale,
		aiml_gen_language_code( $gp ),
		(string) ( $gp->english_name ?? '' ),
		(string) ( $gp->native_name ?? '' ),
		(string) ( $gp->text_direction ?? 'ltr' ),
		(string) ( $gp->alphabet ?? '' ),
		(string) ( $gp->slug ?? '' )
	);

	if ( null === $row ) {
		++$dropped;
		continue;
	}

	$locale_rows[ $wp_locale ] = $row;
	$groups[ $row['group'] ][] = $wp_locale;
	++$kept;
}

// Hand-maintained additions (WordPress formal/informal locales absent from GlotPress).
$overrides_applied = 0;
if ( is_readable( $overrides_path ) ) {
	foreach ( (array) require $overrides_path as $wp_locale => $spec ) {
		if ( isset( $locale_rows[ $wp_locale ] ) ) {
			aiml_gen_warn( "override {$wp_locale}: already present from GlotPress; override ignored." );
			continue;
		}

		$script = (string) ( $spec['script'] ?? '' );
		if ( '' === $script && isset( $groups[ $spec['language_code'] ][0] ) ) {
			$script = (string) ( $locale_rows[ $groups[ $spec['language_code'] ][0] ]['script'] ?? '' );
		}

		$row = $build_row(
			(string) $wp_locale,
			(string) ( $spec['language_code'] ?? '' ),
			(string) ( $spec['english_name'] ?? '' ),
			(string) ( $spec['native_name'] ?? '' ),
			(string) ( $spec['direction'] ?? 'ltr' ),
			$script,
			''
		);

		if ( null === $row ) {
			++$dropped;
			continue;
		}

		$locale_rows[ $wp_locale ] = $row;
		$groups[ $row['group'] ][] = $wp_locale;
		++$kept;
		++$overrides_applied;
	}
}

// Resolve one representative ("primary") locale per group and derive the
// group-level display names from it.
$group_rows = array();
foreach ( $groups as $code => $wp_locales ) {
	usort(
		$wp_locales,
		static function ( string $a, string $b ) use ( $locale_rows, $code ): int {
			$score = static function ( string $wp ) use ( $locale_rows, $code ): array {
				$row = $locale_rows[ $wp ];
				return array(
					$row['slug'] === $code ? 0 : 1,          // Slug equal to the group code wins.
					substr_count( $wp, '_' ),                 // Fewer underscores wins.
					$row['is_variant'] ? 1 : 0,               // Non-variant wins.
					strlen( $wp ),                            // Shorter wins.
					$wp,                                      // Stable alphabetical tie-break.
				);
			};
			return $score( $a ) <=> $score( $b );
		}
	);

	$primary_wp = $wp_locales[0];
	$primary    = $locale_rows[ $primary_wp ];

	$group_rows[ $code ] = array(
		'language_code' => $code,
		'english_name'  => aiml_gen_base_name( $primary['english_name'] ),
		'native_name'   => aiml_gen_base_name( $primary['native_name'] ),
		'direction'     => $primary['direction'],
		'script'        => $primary['script'],
		'locales'       => $wp_locales,
	);
}

// Per-locale region label for the "regional variant" selector.
foreach ( $locale_rows as $wp => &$row ) {
	$group_english = $group_rows[ $row['group'] ]['english_name'];
	if ( 1 === preg_match( '/\(([^()]+)\)\s*$/', $row['english_name'], $m ) ) {
		$row['region_label'] = trim( $m[1] );
	} elseif ( $row['english_name'] === $group_english ) {
		$row['region_label'] = '';
	} else {
		$row['region_label'] = $row['english_name'];
	}
}
unset( $row );

ksort( $group_rows );
ksort( $locale_rows );

// -- Emit the PHP data file --------------------------------------------------

/**
 * Single-quoted PHP string literal.
 *
 * @param string $value Raw value.
 */
function aiml_gen_q( string $value ): string {
	return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";
}

$lines   = array();
$lines[] = '<?php';
$lines[] = '/**';
$lines[] = ' * Canonical locale registry — the plugin\'s offline source of truth for';
$lines[] = ' * language/locale metadata (ADR-0028). GENERATED FILE — do not hand-edit.';
$lines[] = ' *';
$lines[] = ' * Regenerate with: php bin/build-locale-registry.php --source=<glotpress locales.php>';
$lines[] = ' *';
$lines[] = ' * @package AIMultilingual';
$lines[] = ' */';
$lines[] = '';
$lines[] = 'return array(';
$lines[] = "\t'provenance' => array(";
$lines[] = "\t\t'source'            => 'GlotPress/GlotPress locales/locales.php',";
$lines[] = "\t\t'source_url'        => " . aiml_gen_q( $url ) . ',';
$lines[] = "\t\t'source_revision'   => " . aiml_gen_q( $revision ) . ',';
$lines[] = "\t\t'snapshot_date'     => " . aiml_gen_q( $date ) . ',';
$lines[] = "\t\t'generator_version' => '1',";
$lines[] = "\t\t'overrides_applied' => " . $overrides_applied . ',';
$lines[] = "\t\t'licence'           => 'GPL-2.0-or-later (GlotPress locale data)',";
$lines[] = "\t),";
$lines[] = '';
$lines[] = "\t'groups' => array(";

foreach ( $group_rows as $code => $group ) {
	$lines[] = "\t\t" . aiml_gen_q( $code ) . ' => array(';
	$lines[] = "\t\t\t'language_code' => " . aiml_gen_q( $group['language_code'] ) . ',';
	$lines[] = "\t\t\t'english_name'  => " . aiml_gen_q( $group['english_name'] ) . ',';
	$lines[] = "\t\t\t'native_name'   => " . aiml_gen_q( $group['native_name'] ) . ',';
	$lines[] = "\t\t\t'direction'     => " . aiml_gen_q( $group['direction'] ) . ',';
	$lines[] = "\t\t\t'script'        => " . aiml_gen_q( $group['script'] ) . ',';
	$lines[] = "\t\t\t'locales'       => array(";
	foreach ( $group['locales'] as $wp ) {
		$lines[] = "\t\t\t\t" . aiml_gen_q( $wp ) . ',';
	}
	$lines[] = "\t\t\t),";
	$lines[] = "\t\t),";
}

$lines[] = "\t),";
$lines[] = '';
$lines[] = "\t'locales' => array(";

foreach ( $locale_rows as $wp => $row ) {
	$lines[] = "\t\t" . aiml_gen_q( $wp ) . ' => array(';
	$lines[] = "\t\t\t'group'         => " . aiml_gen_q( $row['group'] ) . ',';
	$lines[] = "\t\t\t'language_code' => " . aiml_gen_q( $row['language_code'] ) . ',';
	$lines[] = "\t\t\t'english_name'  => " . aiml_gen_q( $row['english_name'] ) . ',';
	$lines[] = "\t\t\t'native_name'   => " . aiml_gen_q( $row['native_name'] ) . ',';
	$lines[] = "\t\t\t'direction'     => " . aiml_gen_q( $row['direction'] ) . ',';
	$lines[] = "\t\t\t'script'        => " . aiml_gen_q( $row['script'] ) . ',';
	$lines[] = "\t\t\t'region'        => " . aiml_gen_q( $row['region'] ) . ',';
	$lines[] = "\t\t\t'variant'       => " . aiml_gen_q( $row['variant'] ) . ',';
	$lines[] = "\t\t\t'region_label'  => " . aiml_gen_q( $row['region_label'] ) . ',';
	$lines[] = "\t\t),";
}

$lines[] = "\t),";
$lines[] = ');';
$lines[] = '';

file_put_contents( $out, implode( "\n", $lines ) );

// Normalise array alignment to the project coding standard so the committed
// file is phpcs-clean without a manual pass.
$phpcbf = dirname( __DIR__ ) . '/vendor/bin/phpcbf';
if ( is_executable( $phpcbf ) ) {
	exec( escapeshellarg( $phpcbf ) . ' -q ' . escapeshellarg( $out ) . ' 2>&1', $ignored, $phpcbf_status );
	fwrite( STDERR, "INFO  phpcbf normalised the output (status {$phpcbf_status}).\n" );
} else {
	fwrite( STDERR, "INFO  phpcbf not found; run `vendor/bin/phpcbf {$out}` before committing.\n" );
}

fwrite(
	STDERR,
	sprintf(
		"OK    %d locales kept in %d groups (%d hand overrides), %d dropped. Written to %s\n",
		$kept,
		count( $group_rows ),
		$overrides_applied,
		$dropped,
		$out
	)
);
