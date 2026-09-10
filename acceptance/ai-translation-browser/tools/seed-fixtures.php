<?php
/**
 * Seeds AIT1 browser-acceptance fixtures and prints a JSON id map on stdout.
 * Run: dev-wp wp eval-file .../acceptance/ai-translation-browser/tools/seed-fixtures.php
 *
 * @package AIMultilingual\Acceptance
 */

// Clean any prior run.
foreach ( get_posts(
	array(
		'post_type'   => array( 'post', 'page' ),
		'post_status' => 'any',
		'numberposts' => 200,
		's'           => 'AIT1-ACCEPT',
	)
) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
delete_option( 'aiml_acceptance_fake_mode' );

$plain = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1-ACCEPT plain page',
		'post_content' => '<p>About Biopentra acceptance body.</p><p>A second paragraph for the acceptance run.</p>',
	)
);

$large_body = '';
for ( $i = 1; $i <= 120; $i++ ) {
	$large_body .= '<p>AIT1-ACCEPT large page paragraph number ' . $i . '.</p>';
}
$large = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1-ACCEPT large page',
		'post_content' => $large_body,
	)
);

$elem_data = wp_json_encode(
	array(
		array(
			'id'       => 'asec1',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'ahd1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'AIT1-ACCEPT Elementor heading' ),
					'elements'   => array(),
				),
				array(
					'id'         => 'abtn1',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array( 'text' => 'AIT1-ACCEPT button label' ),
					'elements'   => array(),
				),
				array(
					'id'         => 'araw1',
					'elType'     => 'widget',
					'widgetType' => 'html',
					'settings'   => array( 'html' => '<div>[acc_shortcode] raw</div>' ),
					'elements'   => array(),
				),
			),
		),
	)
);
$elem = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1-ACCEPT elementor page',
		'post_content' => '<!-- elementor -->',
	)
);
update_post_meta( $elem, '_elementor_data', $elem_data );
update_post_meta( $elem, '_elementor_edit_mode', 'builder' );

$fail_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'AIT1-ACCEPT failure page',
		'post_content' => '<p>AIT1-ACCEPT failure page body — used with the provider in a failure mode.</p>',
	)
);

$bulk = array();
foreach ( array( 1, 2, 3, 4, 5 ) as $n ) {
	$bulk[] = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'AIT1-ACCEPT bulk page ' . $n,
			'post_content' => '<p>AIT1-ACCEPT bulk page ' . $n . ' body.</p>',
		)
	);
}

echo wp_json_encode(
	array(
		'plain'   => (int) $plain,
		'large'   => (int) $large,
		'elem'    => (int) $elem,
		'fail'    => (int) $fail_page,
		'bulk'    => array_map( 'intval', $bulk ),
	)
) . "\n";
