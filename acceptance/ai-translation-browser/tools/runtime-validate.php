<?php
/**
 * AIT1 DEV runtime validation via the real REST surface, with the acceptance
 * fake provider active. Run:
 *   dev-wp wp eval-file wp-content/plugins/universal-multilingual/acceptance/ai-translation-browser/tools/runtime-validate.php
 *
 * @package AIMultilingual\Acceptance
 */

use AIMultilingual\Cache\Cache;
use AIMultilingual\Language\Languages;
use AIMultilingual\Translation\AI\ProviderRegistry;
use AIMultilingual\Translation\Store;

$GLOBALS['__aiml_rt_fail'] = 0;
$ok = static function ( string $label, bool $pass ): void {
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $pass ) {
		++$GLOBALS['__aiml_rt_fail'];
	}
};

// Run as an administrator so REST permission callbacks pass.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) ( $admin[0] ?? 0 ) );

$ok( 'fake provider is active (no paid calls)', 'acceptance-fake' === ( new ProviderRegistry( new \AIMultilingual\Settings() ) )->active()->get_id() );

$sv = ( new Languages( new Cache() ) )->find_by_code( 'sv' );
if ( null === $sv ) {
	echo "FAIL sv language missing on DEV\n";
	return;
}
$sv_id = (int) $sv->language_id;
$store = new Store( new Cache() );

$rest = static function ( string $method, string $route, array $body = array() ) {
	$req = new WP_REST_Request( $method, $route );
	if ( array() !== $body ) {
		$req->set_body_params( $body );
	}
	foreach ( $body as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
};

$create_and_run = static function ( int $post_id, string $job_type, string $token ) use ( $rest, $sv_id ) {
	$res = $rest(
		'POST',
		'/aiml/v1/jobs',
		array(
			'job_type'     => $job_type,
			'source_type'  => 'post',
			'source_id'    => $post_id,
			'language_id'  => $sv_id,
			'client_token' => $token,
			'autostart'    => true,
		)
	);
	$data = $res->get_data();
	if ( $res->is_error() || empty( $data['job_id'] ) ) {
		return array( 'error' => $data['code'] ?? 'unknown', 'data' => $data );
	}
	$rest( 'POST', '/aiml/v1/jobs/' . (int) $data['job_id'] . '/run', array( 'id' => (int) $data['job_id'], 'sync' => true ) );

	return array( 'job_id' => (int) $data['job_id'] );
};

// --- Fixtures ----------------------------------------------------------------
$plain_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1 RT plain ' . time(),
		'post_content' => '<p>Runtime validation body paragraph.</p><p>Second paragraph here.</p>',
	)
);
$elem_data = wp_json_encode(
	array(
		array(
			'id'       => 'sec9',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'hd9',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Runtime Elementor Heading' ),
					'elements'   => array(),
				),
				array(
					'id'         => 'raw9',
					'elType'     => 'widget',
					'widgetType' => 'html',
					'settings'   => array( 'html' => '<b>[sc] raw</b>' ),
					'elements'   => array(),
				),
			),
		),
	)
);
$elem_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1 RT elementor ' . time(),
		'post_content' => '<!-- elementor -->',
	)
);
update_post_meta( $elem_id, '_elementor_data', $elem_data );
update_post_meta( $elem_id, '_elementor_edit_mode', 'builder' );
$elem_before = (string) get_post_meta( $elem_id, '_elementor_data', true );

// --- 1. plain page translate_missing --------------------------------------
$j1 = $create_and_run( (int) $plain_id, 'translate_missing', 'rt-plain-1' );
$ok( 'plain page job created + ran (' . wp_json_encode( $j1 ) . ')', isset( $j1['job_id'] ) );

$loaded = $store->load_object( Store::SOURCE_POST, (int) $plain_id, $sv_id );
$has_sv = false;
foreach ( $loaded as $row ) {
	if ( str_starts_with( (string) ( $row->translated_text ?? '' ), '[sv_SE] ' ) ) {
		$has_sv = true;
	}
}
$ok( 'plain page segments translated by fake provider', $has_sv );

// --- 2. double-submit idempotency (two creates before any run) -----------
$create_only = static function ( int $post_id, string $job_type, string $token ) use ( $rest, $sv_id ) {
	$res  = $rest(
		'POST',
		'/aiml/v1/jobs',
		array(
			'job_type'     => $job_type,
			'source_type'  => 'post',
			'source_id'    => $post_id,
			'language_id'  => $sv_id,
			'client_token' => $token,
			'autostart'    => false,
		)
	);
	$data = $res->get_data();
	return isset( $data['job_id'] ) ? (int) $data['job_id'] : 0;
};
$idem_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1 RT idem ' . time(),
		'post_content' => '<p>Idempotency probe.</p>',
	)
);
$id_c = $create_only( (int) $idem_page, 'translate_missing', 'rt-idem-fixed' );
$id_d = $create_only( (int) $idem_page, 'translate_missing', 'rt-idem-fixed' );
$ok( 'double-submit with one client_token → one job', $id_c > 0 && $id_c === $id_d );
if ( $id_c > 0 ) {
	$rest( 'POST', '/aiml/v1/jobs/' . $id_c . '/cancel', array( 'id' => $id_c ) );
}
wp_delete_post( (int) $idem_page, true );

// --- 3. manual protection ----------------------------------------------
$keys = array_keys( $loaded );
if ( array() !== $keys ) {
	$mk = $keys[0];
	$store->save_translation(
		array(
			'source_type'     => Store::SOURCE_POST,
			'source_id'       => (int) $plain_id,
			'language_id'     => $sv_id,
			'field_key'       => (string) ( $loaded[ $mk ]->field_key ?? 'post_content' ),
			'segment_key'     => $mk,
			'source_text'     => (string) ( $loaded[ $mk ]->source_text ?? '' ),
			'translated_text' => 'MANSKLIG OVERSATTNING',
			'text_format'     => (string) ( $loaded[ $mk ]->text_format ?? 'plain' ),
			'status'          => Store::STATUS_MANUALLY_EDITED,
		)
	);
	$create_and_run( (int) $plain_id, 'retranslate_machine', 'rt-manual-1' );
	$after = $store->get( Store::SOURCE_POST, (int) $plain_id, $sv_id, $mk );
	$ok( 'manual translation not overwritten by retranslate_machine', 'MANSKLIG OVERSATTNING' === (string) ( $after->translated_text ?? '' ) );
}

// --- 4. Elementor ------------------------------------------------------
$je = $create_and_run( (int) $elem_id, 'translate_missing', 'rt-elem-1' );
$ok( 'elementor page job ran (' . wp_json_encode( $je ) . ')', isset( $je['job_id'] ) );
$ok( '_elementor_data byte-identical after AI job', $elem_before === (string) get_post_meta( $elem_id, '_elementor_data', true ) );

$eloaded    = $store->load_object( Store::SOURCE_POST, (int) $elem_id, $sv_id );
$heading_sv = false;
$raw_seg    = false;
foreach ( $eloaded as $k => $row ) {
	if ( str_contains( (string) $k, ':hd9:title' ) && str_starts_with( (string) ( $row->translated_text ?? '' ), '[sv_SE] ' ) ) {
		$heading_sv = true;
	}
	if ( str_contains( (string) $k, ':raw9:' ) ) {
		$raw_seg = true;
	}
}
$ok( 'elementor allowlisted heading control translated', $heading_sv );
$ok( 'elementor raw html widget never became a segment', ! $raw_seg );

// --- cleanup ---------------------------------------------------------
wp_delete_post( (int) $plain_id, true );
wp_delete_post( (int) $elem_id, true );

echo "\n" . ( 0 === (int) $GLOBALS['__aiml_rt_fail'] ? 'RUNTIME VALIDATION: ALL PASS' : 'RUNTIME VALIDATION: ' . (int) $GLOBALS['__aiml_rt_fail'] . ' FAILURE(S)' ) . "\n";
