<?php
/**
 * Confirms the acceptance fake provider is gone and DEV resolves a real
 * provider again (or the null object) — never the fake.
 *
 * @package AIMultilingual\Acceptance
 */

$active = ( new \AIMultilingual\Translation\AI\ProviderRegistry( new \AIMultilingual\Settings() ) )->active()->get_id();
$filter = has_filter( 'aiml_ai_provider' );

if ( 'acceptance-fake' === $active || false !== $filter ) {
	echo "TEARDOWN FAIL: active={$active} filter=" . var_export( $filter, true ) . "\n";
	exit( 1 );
}

echo "TEARDOWN OK: fake provider removed, active provider = {$active}\n";

// Post-removal health smoke.
$home = wp_remote_get( home_url( '/' ), array( 'timeout' => 20 ) );
$code = is_wp_error( $home ) ? 0 : (int) wp_remote_retrieve_response_code( $home );
echo ( 200 === $code ? 'HEALTH OK' : "HEALTH FAIL http={$code}" ) . "\n";
