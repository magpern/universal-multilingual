<?php
/**
 * AIT1 — LIVE AI provider acceptance check.
 *
 * Runs one real "Translate with AI" job through the configured provider (no
 * fake, no MU-plugin) and asserts the output is a genuine translation: not
 * empty, not identical to the source, and not the `[locale] source` shape the
 * deterministic acceptance fake emits. This is the "use a real provider for at
 * least one check" gate — it costs a few provider calls and is NOT run in CI.
 *
 * Prerequisites: a configured, working AI provider on the target site
 * (Settings → provider + API key), and the acceptance fake-provider MU-plugin
 * removed/disabled so `has_filter('aiml_ai_provider')` is false.
 *
 * Run:
 *   dev-wp wp eval-file \
 *     wp-content/plugins/universal-multilingual/acceptance/ai-translation-browser/tools/live-provider-check.php
 *
 * @package AIMultilingual\Acceptance
 */

use AIMultilingual\Cache\Cache;
use AIMultilingual\Language\Languages;
use AIMultilingual\Translation\AI\ProviderRegistry;
use AIMultilingual\Translation\Store;

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

$fail = 0;
$ok   = static function ( string $label, bool $pass, string $detail = '' ) use ( &$fail ): void {
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . ( '' !== $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $pass ) {
		++$fail;
	}
};

// Run as an administrator so REST permission callbacks pass.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

$ok( 'acceptance fake provider is NOT active (real provider under test)', false === has_filter( 'aiml_ai_provider' ) );

$settings = new \AIMultilingual\Settings();
$ok( 'AI is configured (provider + credential)', ProviderRegistry::is_ai_configured( $settings ) );

$data = $settings->get();
$ok(
	'a real provider id is selected in settings',
	in_array( (string) ( $data['ai_provider'] ?? '' ), array( 'openai', 'deepseek' ), true ),
	(string) ( $data['ai_provider'] ?? '' )
);

$target_code = (string) ( $argv[1] ?? 'sv' );
$language    = ( new Languages( new Cache() ) )->find_by_code( $target_code );
if ( null === $language ) {
	echo "FAIL target language '{$target_code}' is not registered on this site\n";
	echo "\nLIVE PROVIDER CHECK: 1 FAILURE(S)\n";
	return;
}
$language_id = (int) $language->language_id;
$locale      = (string) $language->locale;

// --- Throwaway fixture with real prose ------------------------------------
$source_title   = 'AIT1 live check ' . gmdate( 'Ymd-His' );
$source_excerpt = 'A short research note about melanocortin receptor signalling in laboratory models.';
$source_p1      = 'The peptide is studied for its role in neuroendocrine pathways and receptor binding.';
$source_p2      = 'Researchers compare dose response across three cohorts before publishing results.';

$post_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $source_title,
		'post_excerpt' => $source_excerpt,
		'post_content' => '<p>' . $source_p1 . '</p><p>' . $source_p2 . '</p>',
	)
);
$ok( 'throwaway fixture created', is_int( $post_id ) && $post_id > 0 );

// --- Create + run one real translate_missing job -------------------------
$request = new WP_REST_Request( 'POST', '/aiml/v1/jobs' );
foreach (
	array(
		'job_type'     => 'translate_missing',
		'source_type'  => 'post',
		'source_id'    => (int) $post_id,
		'language_id'  => $language_id,
		'client_token' => 'live-check-' . time(),
		'autostart'    => true,
	) as $k => $v
) {
	$request->set_param( $k, $v );
}
$create = rest_do_request( $request );
$job_id = (int) ( $create->get_data()['job_id'] ?? 0 );
$ok( 'job created', ! $create->is_error() && $job_id > 0, 'HTTP ' . $create->get_status() );

// Drive it synchronously so the check does not depend on cron cadence.
if ( $job_id > 0 ) {
	for ( $i = 0; $i < 30; $i++ ) {
		$run = new WP_REST_Request( 'POST', '/aiml/v1/jobs/' . $job_id . '/run' );
		$run->set_param( 'id', $job_id );
		$run->set_param( 'sync', true );
		rest_do_request( $run );

		$detail = rest_do_request( new WP_REST_Request( 'GET', '/aiml/v1/jobs/' . $job_id ) )->get_data();
		$status = (string) ( $detail['status'] ?? '' );
		if ( in_array( $status, array( 'completed', 'failed', 'completed_with_errors', 'cancelled' ), true ) ) {
			break;
		}
	}
	$detail = rest_do_request( new WP_REST_Request( 'GET', '/aiml/v1/jobs/' . $job_id ) )->get_data();
	$ok(
		'job completed with no failures',
		'completed' === (string) ( $detail['status'] ?? '' ) && 0 === (int) ( $detail['failed_items'] ?? -1 ),
		wp_json_encode(
			array(
				'status'    => $detail['status'] ?? null,
				'completed' => $detail['completed_items'] ?? null,
				'total'     => $detail['total_items'] ?? null,
				'failed'    => $detail['failed_items'] ?? null,
			)
		)
	);
}

// --- Assert the output is a genuine translation ------------------------
$store = new Store( new Cache() );
$rows  = $store->load_object( Store::SOURCE_POST, (int) $post_id, $language_id );

$checked = 0;
foreach ( $rows as $key => $row ) {
	$source = trim( (string) ( $row->source_text ?? '' ) );
	$target = trim( (string) ( $row->translated_text ?? '' ) );

	// Skip pure identifiers / codes where "no change" is a valid translation.
	if ( '' === $source || preg_match( '/^[\p{L}]{0,2}[-\s]?\d/u', $source ) ) {
		continue;
	}

	++$checked;
	$ok( "segment {$key}: target is non-empty", '' !== $target, mb_substr( $target, 0, 60 ) );
	$ok( "segment {$key}: target differs from source", $target !== $source );
	$ok(
		"segment {$key}: target is not the fake `[locale] source` shape",
		'' === $target || 0 !== strpos( $target, '[' . $locale . '] ' )
	);
}
$ok( 'at least one prose segment was checked', $checked > 0, "checked={$checked}" );

wp_delete_post( (int) $post_id, true );

echo "\n" . ( 0 === $fail ? 'LIVE PROVIDER CHECK: ALL PASS' : 'LIVE PROVIDER CHECK: ' . $fail . ' FAILURE(S)' ) . "\n";
