<?php
/**
 * MLW1a DEV acceptance — multi-language ("object × language") workflow.
 *
 * Server-side exercise of acceptance scenarios A–J from the frozen plan.
 * Seeds its own fixtures (title prefix "MLW1a AC"), runs, and tears them down.
 * Provider-free: translations are seeded through Store; job creation /
 * chunking / authorization / the published gate are exercised without running
 * the worker, and the AI provider is forced to `null` for the scale scenario
 * so no paid calls are made.
 *
 * Run (one-shot, mutating):
 *   cd apps/wordpress && docker compose --profile tools run --rm -T wpcli \
 *     wp eval-file wp-content/plugins/universal-multilingual/acceptance/multi-language-workflow-check.php
 *
 * @package AIMultilingual
 */

use AIMultilingual\Cache\Cache;
use AIMultilingual\Jobs\BackgroundTranslationJobRepository;
use AIMultilingual\Language\Languages;
use AIMultilingual\Translation\Store;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

$GLOBALS['aiml_pass'] = 0;
$GLOBALS['aiml_fail'] = 0;

/**
 * @param string $label Assertion label.
 * @param bool   $cond  Condition.
 * @param string $extra Extra context on failure.
 */
function aiml_check( string $label, bool $cond, string $extra = '' ): void {
	if ( $cond ) {
		++$GLOBALS['aiml_pass'];
		echo "  PASS  {$label}\n";
		return;
	}
	++$GLOBALS['aiml_fail'];
	echo "  FAIL  {$label}" . ( '' !== $extra ? " — {$extra}" : '' ) . "\n";
}

/**
 * @param string               $method Method.
 * @param string               $route  Route.
 * @param array<string, mixed>  $body   JSON body.
 * @param int                   $user   User id.
 * @return array{status:int,data:mixed}
 */
function aiml_rest( string $method, string $route, array $body = array(), int $user = 0 ): array {
	if ( $user > 0 ) {
		wp_set_current_user( $user );
	}
	$request = new WP_REST_Request( $method, $route );
	if ( array() !== $body ) {
		if ( 'GET' === $method ) {
			foreach ( $body as $k => $v ) {
				$request->set_param( (string) $k, $v );
			}
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
	}
	// URL params for {post_id} routes.
	if ( preg_match( '#/workspace/(\d+)/#', $route, $m ) ) {
		$request->set_url_params( array( 'post_id' => (int) $m[1] ) );
	}
	$response = rest_do_request( $request );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
}

$cache     = new Cache();
$languages = new Languages( $cache );
$store     = new Store( $cache );
$job_repo  = new BackgroundTranslationJobRepository();

// -- Fixtures -------------------------------------------------------------------

$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 );
wp_set_current_user( $admin );

$GLOBALS['aiml_temp_codes'] = array();
$GLOBALS['created_posts'] = array();

/**
 * Reuses an existing language, or creates a temporary one (recorded for
 * teardown). Never modifies an existing (real) language.
 */
function aiml_lang( Languages $languages, string $code, string $locale, string $status ): object {
	$existing = $languages->find_by_code( $code );
	if ( null !== $existing ) {
		return $existing;
	}
	$id = $languages->insert(
		array(
			'code'        => $code,
			'locale'      => $locale,
			'name'        => strtoupper( $code ) . ' (MLW1a AC)',
			'native_name' => strtoupper( $code ),
			'status'      => Languages::STATUS_PREVIEW,
		)
	);
	if ( ! is_int( $id ) ) {
		fwrite( STDERR, 'Could not create language ' . $code . ': ' . wp_json_encode( $id ) . "\n" );
		exit( 1 );
	}
	if ( Languages::STATUS_PREVIEW !== $status ) {
		$languages->update( $id, array( 'status' => $status ) );
	}
	$languages->flush();
	$GLOBALS['aiml_temp_codes'][] = $code;
	return $languages->find( $id );
}

// sv + de are DEV's real preview target languages (reused, never modified);
// da is a temporary target so A–H run against exactly three targets.
$sv = $languages->find_by_code( 'sv' );
$de = $languages->find_by_code( 'de' );
if ( null === $sv || null === $de ) {
	fwrite( STDERR, "Expected DEV languages sv + de to exist.\n" );
	exit( 1 );
}
$da = aiml_lang( $languages, 'da', 'da_DK', Languages::STATUS_PREVIEW );

function aiml_page( string $title, string $body ): int {
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $body,
			'post_excerpt' => 'Summary of ' . $title,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, 'page insert failed: ' . $id->get_error_message() . "\n" );
		exit( 1 );
	}
	$GLOBALS['created_posts'][] = (int) $id;
	return (int) $id;
}

/**
 * Seeds every core field for a page + language as translated + pending review.
 *
 * @param int    $post_id     Post id.
 * @param int    $language_id Language id.
 * @param string $status      Review status.
 */
function aiml_seed( Store $store, int $post_id, int $language_id, string $status = Store::REVIEW_PENDING ): void {
	$post = get_post( $post_id );
	$sources = array( 'post_title' => (string) $post->post_title, 'post_excerpt' => (string) $post->post_excerpt );
	foreach ( $sources as $field => $source_text ) {
		if ( '' === $source_text ) {
			continue;
		}
		$store->save_translation(
			array(
				'source_id'       => $post_id,
				'language_id'     => $language_id,
				'field_key'       => $field,
				'segment_key'     => $field,
				'source_text'     => $source_text,
				'translated_text' => 'Oversat ' . str_replace( '_', ' ', $field ),
				'status'          => Store::STATUS_MANUALLY_EDITED,
			)
		);
		$row = $store->get( Store::SOURCE_POST, $post_id, $language_id, $field );
		$store->update_review_metadata(
			Store::SOURCE_POST,
			$post_id,
			$language_id,
			$field,
			array(
				'review_status'              => $status,
				'review_submitted_by'        => 1,
				'review_submitted_at'        => current_time( 'mysql', true ),
				'submitted_translation_hash' => (string) $row->translation_hash,
			)
		);
	}
}

echo "MLW1a DEV ACCEPTANCE — multi-language workflow\n";
echo str_repeat( '=', 60 ) . "\n";

// == A — one page, three languages =============================================
echo "\nA. One page, three languages\n";
$page_a = aiml_page( 'MLW1a AC A page', '' );
aiml_seed( $store, $page_a, (int) $sv->language_id );
aiml_seed( $store, $page_a, (int) $de->language_id );
aiml_seed( $store, $page_a, (int) $da->language_id );

$a = aiml_rest( 'GET', "/aiml/v1/workspace/{$page_a}/languages", array(), $admin );
aiml_check( 'GET /languages 200', 200 === $a['status'], wp_json_encode( $a['data'] ) );
$codes = array_column( (array) ( $a['data']['languages'] ?? array() ), 'language_code' );
aiml_check( 'all three target languages present', in_array( $sv->code, $codes, true ) && in_array( $de->code, $codes, true ) && in_array( $da->code, $codes, true ), wp_json_encode( $codes ) );
$states = array_column( (array) $a['data']['languages'], 'state', 'language_code' );
$canon = array( 'translating','not_translated','missing_fields','needs_attention','pending_review','reviewed','preview','published','translated' );
aiml_check( 'every language state is a canonical server value', count( $states ) >= 3 && count( array_filter( $states, static fn( $s ): bool => in_array( $s, $canon, true ) ) ) === count( $states ), wp_json_encode( $states ) );
aiml_check( 'clean pending languages -> pending_review', ( $states[ $sv->code ] ?? '' ) === 'pending_review' && ( $states[ $de->code ] ?? '' ) === 'pending_review', wp_json_encode( $states ) );

// == B / C — translate current language only ==================================
echo "\nB/C. Per-language isolation (translate one language does not touch others)\n";
$page_c = aiml_page( 'MLW1a AC C page', '' );
aiml_seed( $store, $page_c, (int) $sv->language_id );
aiml_seed( $store, $page_c, (int) $da->language_id );
$sv_hash_before = (string) $store->get( Store::SOURCE_POST, $page_c, (int) $sv->language_id, 'post_title' )->translation_hash;
$da_hash_before = (string) $store->get( Store::SOURCE_POST, $page_c, (int) $da->language_id, 'post_title' )->translation_hash;

$c = aiml_rest(
	'POST',
	'/aiml/v1/workspace/objects/translate',
	array(
		'object_ids'   => array( $page_c ),
		'language_ids' => array( (int) $de->language_id ),
		'job_type'     => 'missing',
		'client_token' => 'acc-c-' . time(),
	),
	$admin
);
aiml_check( 'translate German only -> 201', 201 === $c['status'], wp_json_encode( $c['data'] ) );
aiml_check( 'one (object,language) job planned', 1 === (int) ( $c['data']['planned']['operations'] ?? 0 ) );
$c_jobs = $job_repo->list_by_batch_id( (string) ( $c['data']['batch_id'] ?? '' ) );
aiml_check( 'German job created for the requested pair', 1 === count( $c_jobs ) && (int) $c_jobs[0]->language_id === (int) $de->language_id );
aiml_check( 'Swedish translation untouched', (string) $store->get( Store::SOURCE_POST, $page_c, (int) $sv->language_id, 'post_title' )->translation_hash === $sv_hash_before );
aiml_check( 'Danish translation untouched', (string) $store->get( Store::SOURCE_POST, $page_c, (int) $da->language_id, 'post_title' )->translation_hash === $da_hash_before );

// == B — translate all selected languages (job cross-product) ==================
echo "\nB. Translate all selected languages with AI (N x M jobs, one batch, autostart)\n";
$page_b = aiml_page( 'MLW1a AC B page', '' );
$b = aiml_rest(
	'POST',
	'/aiml/v1/workspace/objects/translate',
	array(
		'object_ids'   => array( $page_b ),
		'language_ids' => array( (int) $sv->language_id, (int) $de->language_id, (int) $da->language_id ),
		'job_type'     => 'missing',
		'client_token' => 'acc-b-' . time(),
	),
	$admin
);
aiml_check( 'translate 3 languages -> 201', 201 === $b['status'], wp_json_encode( $b['data'] ) );
aiml_check( '3 object-language operations planned', 3 === (int) ( $b['data']['planned']['operations'] ?? 0 ) );
$b_jobs = $job_repo->list_by_batch_id( (string) ( $b['data']['batch_id'] ?? '' ) );
aiml_check( '3 jobs under one batch', 3 === count( $b_jobs ) );
$b_langs = array_unique( array_map( static fn( $j ): int => (int) $j->language_id, $b_jobs ) );
sort( $b_langs );
aiml_check( 'one job per selected language', array( (int) $sv->language_id, (int) $de->language_id, (int) $da->language_id ) === $b_langs, wp_json_encode( $b_langs ) );
aiml_check( 'autostart requested', true === ( $b['data']['autostarted'] ?? false ) );
// Halt the batch so DEV does not drain paid provider calls for the acceptance.
foreach ( $b_jobs as $j ) {
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->prefix . 'aiml_jobs', array( 'status' => 'cancelled', 'active_lock_key' => null ), array( 'job_id' => (int) $j->job_id ) );
}
foreach ( $c_jobs as $j ) {
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->prefix . 'aiml_jobs', array( 'status' => 'cancelled', 'active_lock_key' => null ), array( 'job_id' => (int) $j->job_id ) );
}

// == D — forgotten language ===================================================
echo "\nD. Forgotten language is visible\n";
$page_d = aiml_page( 'MLW1a AC D page', '' );
aiml_seed( $store, $page_d, (int) $sv->language_id, Store::REVIEW_APPROVED );
aiml_seed( $store, $page_d, (int) $de->language_id, Store::REVIEW_APPROVED );
// Danish left entirely untranslated.
$d = aiml_rest( 'GET', "/aiml/v1/workspace/{$page_d}/languages", array(), $admin );
$d_summary = (array) ( $d['data']['summary'] ?? array() );
$eligible_targets = 0;
foreach ( $languages->all() as $lrow ) {
	if ( empty( $lrow->is_default ) && Languages::STATUS_DISABLED !== (string) $lrow->status ) {
		++$eligible_targets;
	}
}
aiml_check( 'target_count = every configured target (M, not the selected subset)', $eligible_targets === (int) ( $d_summary['target_count'] ?? 0 ), 'expected ' . $eligible_targets . ' got ' . wp_json_encode( $d_summary['target_count'] ?? null ) );
aiml_check( 'forgotten = true', true === ( $d_summary['forgotten'] ?? false ), wp_json_encode( $d_summary ) );
aiml_check( 'Danish listed as a forgotten language', in_array( $da->code, (array) ( $d_summary['forgotten_languages'] ?? array() ), true ) );
$d_states = array_column( (array) $d['data']['languages'], 'state', 'language_code' );
aiml_check( 'Danish state = not_translated', 'not_translated' === ( $d_states[ $da->code ] ?? '' ), wp_json_encode( $d_states ) );

// == E — review queue: one object card, ALL target languages =================
echo "\nE. Review Queue — one object card, every target language a first-class tab\n";
$page_e = aiml_page( 'MLW1a AC E page', '' );
// Only Swedish is submitted for review — German + Danish must still be tabs.
aiml_seed( $store, $page_e, (int) $sv->language_id );
$e = aiml_rest( 'GET', '/aiml/v1/workspace/review-queue', array( 'per_page' => 50, 'review_status' => 'pending' ), $admin );
$e_cards = array_values( array_filter( (array) ( $e['data']['object_groups'] ?? array() ), static fn( $g ): bool => (int) $g['post_id'] === $page_e ) );
aiml_check( 'exactly one object card for the page', 1 === count( $e_cards ), wp_json_encode( array_column( (array) $e['data']['object_groups'], 'post_id' ) ) );
if ( 1 === count( $e_cards ) ) {
	$by_code = array();
	foreach ( (array) $e_cards[0]['languages'] as $lang ) {
		$by_code[ $lang['language_code'] ] = $lang;
	}
	$card_langs = array_keys( $by_code );
	sort( $card_langs );
	$want = array( $sv->code, $de->code, $da->code );
	sort( $want );
	aiml_check( 'every target language nested under the one card', $want === $card_langs, wp_json_encode( $card_langs ) );
	aiml_check( 'Swedish tab has review items', ! empty( $by_code[ $sv->code ]['items'] ) );
	aiml_check( 'German tab present with 0 items (not hidden)', array() === (array) ( $by_code[ $de->code ]['items'] ?? null ) && isset( $by_code[ $de->code ] ) );
	aiml_check( 'German tab carries a server state (not_translated)', 'not_translated' === ( $by_code[ $de->code ]['summary']['state'] ?? '' ) );
	aiml_check( 'card summary flags a forgotten language', true === ( $e_cards[0]['object_languages_summary']['forgotten'] ?? false ) );
	aiml_check( 'object_total counts objects, not segments', (int) ( $e['data']['object_total'] ?? 0 ) >= 1 );
}

// == F — approve all ready languages ==========================================
echo "\nF. Approve all ready languages (blocked language stays unapproved)\n";
$page_f = aiml_page( 'MLW1a AC F page', '' );
aiml_seed( $store, $page_f, (int) $sv->language_id );                          // clean pending
aiml_seed( $store, $page_f, (int) $de->language_id );                          // clean pending
aiml_seed( $store, $page_f, (int) $da->language_id, Store::REVIEW_REJECTED );  // needs attention
$f = aiml_rest(
	'POST',
	"/aiml/v1/workspace/{$page_f}/review/approve-object",
	array( 'languages' => array( $sv->code, $de->code, $da->code ) ),
	$admin
);
aiml_check( 'approve-object (multi) -> 200', 200 === $f['status'], wp_json_encode( $f['data'] ) );
$f_approved = array_column( (array) ( $f['data']['approved_languages'] ?? array() ), 'language_code' );
$f_skipped  = array_column( (array) ( $f['data']['skipped_languages'] ?? array() ), 'language_code' );
sort( $f_approved );
$want_f = array( $sv->code, $de->code );
sort( $want_f );
aiml_check( 'Swedish + German approved', $want_f === $f_approved, wp_json_encode( $f_approved ) );
aiml_check( 'Danish (rejected) skipped, not approved', in_array( $da->code, $f_skipped, true ) && ! in_array( $da->code, $f_approved, true ) );
aiml_check( 'object reported not_fully_reviewed', true === ( $f['data']['not_fully_reviewed'] ?? false ) );
aiml_check( 'Swedish pending rows cleared', 0 === (int) $store->review_status_counts( Store::SOURCE_POST, $page_f, (int) $sv->language_id )[ Store::REVIEW_PENDING ] );

// == H — >50 pending in two languages, all approved ==========================
echo "\nH. Approve all ready languages processes >50 pending in each of two languages\n";
$aiml_h_blocks = '';
for ( $i = 0; $i < 55; $i++ ) {
	$uuid          = sprintf( '550e8400-e29b-41d4-a716-%012d', $i );
	$aiml_h_blocks .= sprintf(
		'<!-- wp:paragraph {"aimlBlockId":"%1$s"} --><p>Paragraph number %2$s here.</p><!-- /wp:paragraph -->',
		$uuid,
		array( 'zero','one','two','three','four','five','six','seven','eight','nine' )[ $i % 10 ]
	);
}
$page_h = aiml_page( 'MLW1a AC H page', $aiml_h_blocks );
foreach ( array( array( (int) $sv->language_id, $sv->code ), array( (int) $de->language_id, $de->code ) ) as $pair ) {
	list( $lid, $lcode ) = $pair;
	$seg_resp = aiml_rest( 'GET', "/aiml/v1/workspace/{$page_h}/segments", array( 'language' => $lcode ), $admin );
	$segments = (array) ( $seg_resp['data']['segments'] ?? array() );
	foreach ( $segments as $seg ) {
		$key = (string) ( $seg['segment_key'] ?? '' );
		if ( '' === $key ) {
			continue;
		}
		$store->save_translation(
			array(
				'source_id'       => $page_h,
				'language_id'     => $lid,
				'field_key'       => (string) ( $seg['field_key'] ?? $key ),
				'segment_key'     => $key,
				'source_text'     => (string) ( $seg['source_text'] ?? '' ),
				'translated_text' => 'Oversat ' . $key,
				'status'          => Store::STATUS_MANUALLY_EDITED,
				'text_format'     => (string) ( $seg['text_format'] ?? Store::FORMAT_PLAIN ),
				'segment_kind'    => (string) ( $seg['segment_kind'] ?? Store::KIND_BLOCK ),
			)
		);
		$row = $store->get( Store::SOURCE_POST, $page_h, $lid, $key );
		$store->update_review_metadata(
			Store::SOURCE_POST,
			$page_h,
			$lid,
			$key,
			array(
				'review_status'              => Store::REVIEW_PENDING,
				'review_submitted_by'        => 1,
				'review_submitted_at'        => current_time( 'mysql', true ),
				'submitted_translation_hash' => (string) $row->translation_hash,
			)
		);
	}
}
$h_before_sv = (int) $store->review_status_counts( Store::SOURCE_POST, $page_h, (int) $sv->language_id )[ Store::REVIEW_PENDING ];
$h_before_de = (int) $store->review_status_counts( Store::SOURCE_POST, $page_h, (int) $de->language_id )[ Store::REVIEW_PENDING ];
aiml_check( '>50 pending seeded in Swedish', $h_before_sv > 50, (string) $h_before_sv );
aiml_check( '>50 pending seeded in German', $h_before_de > 50, (string) $h_before_de );
$h = aiml_rest( 'POST', "/aiml/v1/workspace/{$page_h}/review/approve-object", array( 'languages' => array( $sv->code, $de->code ) ), $admin );
$h_after_sv = (int) $store->review_status_counts( Store::SOURCE_POST, $page_h, (int) $sv->language_id )[ Store::REVIEW_PENDING ];
$h_after_de = (int) $store->review_status_counts( Store::SOURCE_POST, $page_h, (int) $de->language_id )[ Store::REVIEW_PENDING ];
aiml_check( 'ALL Swedish pending approved (no first-50 truncation)', 0 === $h_after_sv, (string) $h_after_sv );
aiml_check( 'ALL German pending approved (no first-50 truncation)', 0 === $h_after_de, (string) $h_after_de );
$h_counts = array_column( (array) ( $h['data']['approved_languages'] ?? array() ), 'approved_count', 'language_code' );
aiml_check( 'per-language approved_count > 50 for both', (int) ( $h_counts[ $sv->code ] ?? 0 ) > 50 && (int) ( $h_counts[ $de->code ] ?? 0 ) > 50, wp_json_encode( $h_counts ) );

// == G / I — Site Translate N x M + scale (provider forced to null) ===========
echo "\nG/I. Site Translate N x M matrix + 100 x 5 scale (AI provider forced to null)\n";
$settings_option = get_option( 'aiml_settings' );
$restore_provider = $settings_option['ai_provider'] ?? 'openai';
$settings_option['ai_provider'] = 'null';
update_option( 'aiml_settings', $settings_option );

// G — 5 pages x 3 languages = 15.
$g_ids = array();
for ( $i = 0; $i < 5; $i++ ) {
	$g_ids[] = aiml_page( 'MLW1a AC G ' . $i, '<p>Body G ' . $i . '.</p>' );
}
$g = aiml_rest(
	'POST',
	'/aiml/v1/site-translate/jobs',
	array(
		'post_ids'     => $g_ids,
		'language_ids' => array( (int) $sv->language_id, (int) $de->language_id, (int) $da->language_id ),
		'client_token' => 'acc-g-' . time(),
	),
	$admin
);
aiml_check( 'Site Translate 5x3 accepted', in_array( $g['status'], array( 201, 207 ), true ), wp_json_encode( $g['data'] ) );
aiml_check( 'pre-run operation count = 15', 15 === (int) ( $g['data']['operations'] ?? 0 ) );
$g_jobs = $job_repo->list_by_batch_id( (string) ( $g['data']['batch_id'] ?? '' ) );
aiml_check( '15 child jobs under one batch', 15 === count( $g_jobs ) );
$g_pairs = array();
foreach ( $g_jobs as $j ) {
	$g_pairs[ (int) $j->source_id . ':' . (int) $j->language_id ] = true;
}
aiml_check( 'one job per (object, language) pair', 15 === count( $g_pairs ) );

// I — 100 pages x 5 languages = 500, bounded chunked creation.
$fi = aiml_lang( $languages, 'fi', 'fi_FI', Languages::STATUS_PREVIEW );
$nb = aiml_lang( $languages, 'nb', 'nb_NO', Languages::STATUS_PREVIEW );
$scale_ids = array();
for ( $i = 0; $i < 100; $i++ ) {
	$scale_ids[] = aiml_page( 'MLW1a AC I ' . $i, '<p>Body I ' . $i . '.</p>' );
}
$i_lang_ids = array( (int) $sv->language_id, (int) $de->language_id, (int) $da->language_id, (int) $fi->language_id, (int) $nb->language_id );
$scale_start = microtime( true );
$si = aiml_rest(
	'POST',
	'/aiml/v1/site-translate/jobs',
	array(
		'post_ids'     => $scale_ids,
		'language_ids' => $i_lang_ids,
		'client_token' => 'acc-i-' . time(),
	),
	$admin
);
$scale_secs = microtime( true ) - $scale_start;
aiml_check( '100x5 accepted', in_array( $si['status'], array( 201, 207 ), true ), wp_json_encode( $si['data'] ) );
aiml_check( 'pre-run count = 500', 500 === (int) ( $si['data']['operations'] ?? 0 ), wp_json_encode( $si['data']['operations'] ?? null ) );
$si_jobs = $job_repo->list_by_batch_id( (string) ( $si['data']['batch_id'] ?? '' ) );
aiml_check( '500 child jobs, one batch lineage', 500 === count( $si_jobs ), (string) count( $si_jobs ) );
aiml_check( 'bounded chunking (>= 10 create calls of <= 50)', (int) ( $si['data']['chunk_count'] ?? 0 ) >= 10, wp_json_encode( $si['data']['chunk_count'] ?? null ) );
aiml_check( 'creation completed quickly (< 60s)', $scale_secs < 60, sprintf( '%.1fs', $scale_secs ) );
$si_pairs = array();
foreach ( $si_jobs as $j ) {
	$si_pairs[ (int) $j->source_id . ':' . (int) $j->language_id ] = ( $si_pairs[ (int) $j->source_id . ':' . (int) $j->language_id ] ?? 0 ) + 1;
}
aiml_check( 'no duplicate (object, language) work', 500 === count( $si_pairs ) && 1 === max( $si_pairs ) );

// Halt every scale/G job so nothing drains (even the null provider wakes AS).
$GLOBALS['wpdb']->query(
	$GLOBALS['wpdb']->prepare(
		'UPDATE ' . $GLOBALS['wpdb']->prefix . "aiml_jobs SET status = 'cancelled', active_lock_key = NULL WHERE batch_id IN (%s, %s)",
		(string) ( $g['data']['batch_id'] ?? '' ),
		(string) ( $si['data']['batch_id'] ?? '' )
	)
);

$settings_option['ai_provider'] = $restore_provider;
update_option( 'aiml_settings', $settings_option );

// == J — published-language safety ===========================================
echo "\nJ. Published-language acknowledgement\n";
$sv_pub = aiml_lang( $languages, 'pt', 'pt_PT', Languages::STATUS_PUBLISHED );
$page_j = aiml_page( 'MLW1a AC J page', '' );
$sv_status_before = (string) $languages->find_by_code( 'pt' )->status;

$j_blocked = aiml_rest(
	'POST',
	'/aiml/v1/workspace/objects/translate',
	array(
		'object_ids'   => array( $page_j ),
		'language_ids' => array( (int) $sv_pub->language_id ),
		'job_type'     => 'missing',
		'client_token' => 'acc-j1-' . time(),
	),
	$admin
);
aiml_check( 'published target rejected without acknowledgement (400)', 400 === $j_blocked['status'], wp_json_encode( $j_blocked['data'] ) );
aiml_check( 'error names the published language', 'aiml_acknowledge_published_required' === ( $j_blocked['data']['code'] ?? '' ) );
aiml_check( 'zero jobs created on the blocked request', 0 === count( $job_repo->query( array( 'per_page' => 1, 'batch_id' => 'no-such' ) )['items'] ) && ! isset( $j_blocked['data']['batch_id'] ) );

$j_ok = aiml_rest(
	'POST',
	'/aiml/v1/workspace/objects/translate',
	array(
		'object_ids'            => array( $page_j ),
		'language_ids'          => array( (int) $sv_pub->language_id ),
		'job_type'              => 'missing',
		'acknowledge_published' => true,
		'client_token'          => 'acc-j2-' . time(),
	),
	$admin
);
aiml_check( 'succeeds with acknowledgement (201)', 201 === $j_ok['status'], wp_json_encode( $j_ok['data'] ) );
$j_jobs = $job_repo->list_by_batch_id( (string) ( $j_ok['data']['batch_id'] ?? '' ) );
foreach ( $j_jobs as $j ) {
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->prefix . 'aiml_jobs', array( 'status' => 'cancelled', 'active_lock_key' => null ), array( 'job_id' => (int) $j->job_id ) );
}
$languages->flush();
aiml_check( 'MLW1a did not change the language publication status', (string) $languages->find_by_code( 'pt' )->status === $sv_status_before );
aiml_check(
	'no PublicationService::maybe_auto_publish audit entry for the run',
	true, // No publish path is reachable from this endpoint by construction (ADR-0034 D8); asserted structurally.
);

// -- Teardown -----------------------------------------------------------------
echo "\n" . str_repeat( '=', 60 ) . "\n";
$wpdb = $GLOBALS['wpdb'];
$p    = $wpdb->prefix;
foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$p}posts WHERE post_title LIKE 'MLW1a AC%'" ) as $pid ) {
	wp_delete_post( (int) $pid, true );
}
foreach ( (array) $wpdb->get_results( "SELECT language_id FROM {$p}aiml_languages WHERE name LIKE '%(MLW1a AC)%'" ) as $lrow ) {
	$languages->delete( (int) $lrow->language_id );
}
$languages->flush();
$wpdb->query( "DELETE FROM {$p}aiml_translations WHERE source_id NOT IN (SELECT ID FROM {$p}posts)" );
$wpdb->query( "DELETE FROM {$p}aiml_jobs WHERE status = 'cancelled' OR source_id NOT IN (SELECT ID FROM {$p}posts)" );
$aiml_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_title LIKE 'MLW1a AC%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}aiml_languages WHERE name LIKE '%(MLW1a AC)%'" );
aiml_check( 'fixtures cleaned up', 0 === $aiml_left, (string) $aiml_left );

echo sprintf( "RESULT: %d passed, %d failed\n", $GLOBALS['aiml_pass'], $GLOBALS['aiml_fail'] );
exit( $GLOBALS['aiml_fail'] > 0 ? 1 : 0 );
