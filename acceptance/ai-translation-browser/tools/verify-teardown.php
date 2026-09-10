<?php
/**
 * Confirms the acceptance fake provider is gone and DEV resolves a real
 * provider again (or the null object) — never the fake.
 *
 * @package AIMultilingual\Acceptance
 */

$active   = ( new \AIMultilingual\Translation\AI\ProviderRegistry( new \AIMultilingual\Settings() ) )->active()->get_id();
$filter   = has_filter( 'aiml_ai_provider' );
$fake_cls = class_exists( 'AIML_Acceptance_Fake_Provider', false );

if ( 'acceptance-fake' === $active || false !== $filter || $fake_cls ) {
	echo "TEARDOWN FAIL: active={$active} filter=" . var_export( $filter, true ) . " fake_class=" . var_export( $fake_cls, true ) . "\n";
	exit( 1 );
}

// The bare registry here does not run Plugin::init() registration, so `null`
// simply means "no override" — the real request lifecycle still resolves the
// settings-configured provider.
echo "TEARDOWN OK: fake provider MU-plugin + aiml_ai_provider filter removed (no override)\n";

// Post-removal health smoke.
$home = wp_remote_get( home_url( '/' ), array( 'timeout' => 20 ) );
$code = is_wp_error( $home ) ? 0 : (int) wp_remote_retrieve_response_code( $home );
echo ( 200 === $code ? 'HEALTH OK' : "HEALTH FAIL http={$code}" ) . "\n";
