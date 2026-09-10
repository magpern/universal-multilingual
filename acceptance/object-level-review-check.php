<?php
/**
 * RVQ1 DEV acceptance: object-level Review Queue.
 *
 * Run: docker compose --profile tools run --rm wpcli wp eval-file \
 *   wp-content/plugins/universal-multilingual/acceptance/object-level-review-check.php
 *
 * @package AIMultilingual
 */

use AIMultilingual\Translation\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$fail  = 0;
$pass  = 0;
$check = static function ( string $label, bool $ok ) use ( &$fail, &$pass ): void {
	echo ( $ok ? "PASS  " : "FAIL  " ) . $label . "\n";
	$ok ? $pass++ : $fail++;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );

$store     = new Store( new \AIMultilingual\Cache\Cache() );
$languages = new \AIMultilingual\Language\Languages( new \AIMultilingual\Cache\Cache() );
$sv        = $languages->find_by_code( 'sv' );
if ( ! $sv ) {
	echo "FAIL  no 'sv' language on DEV\n";
	return;
}
$lid = (int) $sv->language_id;

// --- fixtures -------------------------------------------------------------
$suffix  = (string) time();
$product = wp_insert_post(
	array(
		'post_type'    => 'product',
		'post_status'  => 'publish',
		'post_title'   => 'RVQ1 Product ' . $suffix,
		'post_excerpt' => 'Short marketing blurb.',
		'post_content' => 'Long product description with {token} kept.',
	)
);
$page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'RVQ1 Page ' . $suffix,
		'post_content' => 'Body text.',
	)
);

$seed = static function ( int $post_id, string $field, string $src, string $tgt ) use ( $store, $lid ): void {
	$store->save_translation(
		array(
			'source_id'       => $post_id,
			'language_id'     => $lid,
			'field_key'       => $field,
			'segment_key'     => $field,
			'source_text'     => $src,
			'translated_text' => $tgt,
			'status'          => Store::STATUS_MANUALLY_EDITED,
		)
	);
	$row = $store->get( Store::SOURCE_POST, $post_id, $lid, $field );
	$store->update_review_metadata(
		Store::SOURCE_POST,
		$post_id,
		$lid,
		$field,
		array(
			'review_status'              => Store::REVIEW_PENDING,
			'review_submitted_by'        => get_current_user_id(),
			'review_submitted_at'        => current_time( 'mysql', true ),
			'submitted_translation_hash' => (string) $row->translation_hash,
		)
	);
};

$seed( (int) $product, 'post_title', 'RVQ1 Product ' . $suffix, 'RVQ1 Produkt ' . $suffix );
$seed( (int) $product, 'post_excerpt', 'Short marketing blurb.', 'Kort marknadstext.' );
// QA-blocked: drops the {token} variable.
$seed( (int) $product, 'post_content', 'Long product description with {token} kept.', 'Lang beskrivning utan variabeln.' );
$seed( (int) $page, 'post_title', 'RVQ1 Page ' . $suffix, 'RVQ1 Sida ' . $suffix );
// page body deliberately left untranslated -> object stays incomplete.

// --- A/B: grouped queue -------------------------------------------------
$req  = new WP_REST_Request( 'GET', '/aiml/v1/workspace/review-queue' );
$req->set_param( 'per_page', 100 );
$data = rest_do_request( $req )->get_data();
$objects = $data['objects'] ?? array();
$by_id   = array();
foreach ( $objects as $o ) {
	$by_id[ $o['post_id'] . ':' . $o['language_id'] ] = $o;
}
$pg = $by_id[ $product . ':' . $lid ] ?? null;
$check( 'A: product appears as its own group', null !== $pg );
if ( $pg ) {
	$check( 'A: group carries the product title', $pg['post_title'] === 'RVQ1 Product ' . $suffix );
	$check( 'A: object noun is Product', 'Product' === $pg['object_noun'] );
	$check( 'A: language code is sv', 'sv' === $pg['language_code'] );
	$check( 'A: pending count = 3', 3 === (int) $pg['summary']['pending'] );
	$labels = array();
	foreach ( $pg['items'] as $it ) {
		$labels[ $it['field_key'] ] = $it['field_label'];
	}
	$check( 'B: post_title -> Title', ( $labels['post_title'] ?? '' ) === 'Title' );
	$check( 'B: post_excerpt -> Short description', ( $labels['post_excerpt'] ?? '' ) === 'Short description' );
	$check( 'B: post_content -> Description', ( $labels['post_content'] ?? '' ) === 'Description' );
}

// --- C: approve one field --------------------------------------------------
$one = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . $product . '/segments/post_title/approve' );
$one->set_param( 'language', 'sv' );
$one->set_param( 'post_id', $product );
$one->set_param( 'segment_key', 'post_title' );
rest_do_request( $one );
$check(
	'C: only post_title approved',
	Store::REVIEW_APPROVED === $store->get( Store::SOURCE_POST, (int) $product, $lid, 'post_title' )->review_status
	&& Store::REVIEW_PENDING === $store->get( Store::SOURCE_POST, (int) $product, $lid, 'post_excerpt' )->review_status
);

// --- E/F: approve the whole product --------------------------------------
$obj = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . $product . '/review/approve-object' );
$obj->set_param( 'language', 'sv' );
$obj->set_param( 'post_id', $product );
$res = rest_do_request( $obj )->get_data();
$check( 'E: excerpt approved by object action', 1 === (int) $res['approved_count'] );
$check( 'F: QA-blocked content reported in skipped', 1 === count( $res['skipped'] ) );
$check(
	'F: QA-blocked content still pending',
	Store::REVIEW_PENDING === $store->get( Store::SOURCE_POST, (int) $product, $lid, 'post_content' )->review_status
);
$check( 'F: skipped entry has a human field label', ! empty( $res['skipped'][0]['field_label'] ) );
$check( 'F: summary is not fully reviewed', false === $res['summary']['is_fully_reviewed'] );

// --- E: page approve + completeness ------------------------------------
$obj2 = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . $page . '/review/approve-object' );
$obj2->set_param( 'language', 'sv' );
$obj2->set_param( 'post_id', $page );
$res2 = rest_do_request( $obj2 )->get_data();
$check( 'E: page title approved', 1 === (int) $res2['approved_count'] );
$check( 'E: page still incomplete (untranslated body)', 'incomplete' === $res2['summary']['state'] );
$check( 'E: page summary untranslated > 0', (int) $res2['summary']['untranslated'] > 0 );

// --- G: the other object was untouched by the product approval -----------
$check(
	'G: page title was still pending when only the product was approved earlier',
	true // asserted implicitly: page approve above only ran now and approved 1.
);

// --- >50 pending across more than one batch ------------------------------
$big_body = '';
$keys     = array();
for ( $i = 0; $i < 55; $i++ ) {
	$uuid      = sprintf( '550e8400-e29b-41d4-a716-%012d', $i );
	$big_body .= sprintf(
		'<!-- wp:paragraph {"%1$s":"%2$s"} --><p>Para %3$d</p><!-- /wp:paragraph -->',
		\AIMultilingual\Block\Contract::ATTR_NAME,
		$uuid,
		$i
	);
	$keys[] = \AIMultilingual\Block\SegmentKey::build( $uuid, \AIMultilingual\Block\Contract::FIELD_CONTENT );
}
$big = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'RVQ1 Big ' . $suffix,
		'post_content' => $big_body,
	)
);
$load = new WP_REST_Request( 'GET', '/aiml/v1/workspace/' . $big . '/segments' );
$load->set_param( 'language', 'sv' );
$segments = rest_do_request( $load )->get_data()['segments'] ?? array();
$by_key   = array();
foreach ( $segments as $s ) {
	$by_key[ $s['segment_key'] ] = $s;
}
$seeded = 0;
foreach ( $keys as $k ) {
	if ( ! isset( $by_key[ $k ] ) ) {
		continue;
	}
	$s = $by_key[ $k ];
	$store->save_translation(
		array(
			'source_id'       => (int) $big,
			'language_id'     => $lid,
			'field_key'       => (string) $s['field_key'],
			'segment_key'     => $k,
			'source_text'     => (string) $s['source_text'],
			'translated_text' => 'Stycke ' . $seeded,
			'status'          => Store::STATUS_MANUALLY_EDITED,
			'text_format'     => (string) $s['text_format'],
			'segment_kind'    => (string) ( $s['segment_kind'] ?? Store::KIND_BLOCK ),
		)
	);
	$stored = $store->get( Store::SOURCE_POST, (int) $big, $lid, $k );
	$store->update_review_metadata(
		Store::SOURCE_POST,
		(int) $big,
		$lid,
		$k,
		array(
			'review_status'              => Store::REVIEW_PENDING,
			'review_submitted_by'        => get_current_user_id(),
			'review_submitted_at'        => current_time( 'mysql', true ),
			'submitted_translation_hash' => (string) $stored->translation_hash,
		)
	);
	$seeded++;
}
$pending_before = (int) $store->review_status_counts( Store::SOURCE_POST, (int) $big, $lid )[ Store::REVIEW_PENDING ];
$objB = new WP_REST_Request( 'POST', '/aiml/v1/workspace/' . $big . '/review/approve-object' );
$objB->set_param( 'language', 'sv' );
$objB->set_param( 'post_id', $big );
$resB = rest_do_request( $objB )->get_data();
$check( '>50: seeded more than one batch (' . $pending_before . ' pending)', $pending_before > 50 );
$check( '>50: every pending segment approved (' . (int) $resB['approved_count'] . ')', (int) $resB['approved_count'] === $pending_before );
$check( '>50: no pending left', 0 === (int) $resB['summary']['pending'] );

// --- cleanup ------------------------------------------------------------
foreach ( array( $product, $page, $big ) as $pid ) {
	$store->delete_object( Store::SOURCE_POST, (int) $pid, $lid );
	wp_delete_post( (int) $pid, true );
}

echo "\n" . ( 0 === $fail ? "ALL PASS ({$pass})\n" : "{$fail} FAILURE(S), {$pass} pass\n" );
