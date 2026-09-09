<?php
/**
 * Promotion REST API — auth + export / validate / apply / history (ADR-0030 §8).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Plugin;
use AIMultilingual\Promotion\PromotionCapabilities;
use WP_REST_Request;

/**
 * Every route requires aiml_promote_translations; the trust flag needs its own
 * capability; apply refuses a stale token.
 */
final class PromotionRestTest extends AimlTestCase {

	private int $sv_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		Plugin::activate();
		// Neutralise cross-test role/version state so the capability matrix is
		// deterministic regardless of suite order.
		PromotionCapabilities::revoke_all_roles();
		delete_option( PromotionCapabilities::VERSION_OPTION );
		PromotionCapabilities::provision();
		do_action( 'rest_api_init' );

		$sv            = $this->add_language( 'sv', 'sv_SE' );
		$this->sv_id   = (int) $sv->language_id;
		$this->post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'rest-widget',
				'post_title' => 'REST Widget',
			)
		);
		$this->store->save_translation(
			array(
				'source_type'     => 'post',
				'source_id'       => $this->post_id,
				'language_id'     => $this->sv_id,
				'field_key'       => 'post_title',
				'segment_key'     => 'post_title',
				'text_format'     => 'plain',
				'status'          => 'manually_edited',
				'source_text'     => 'REST Widget',
				'translated_text' => 'REST-pryl',
			)
		);
	}

	public function test_capabilities_are_admin_only(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( PromotionCapabilities::PROMOTE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( PromotionCapabilities::PROMOTE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( PromotionCapabilities::TRUST_REVIEW_STATE ) );
	}

	public function test_every_route_is_403_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach (
			array(
				array( 'POST', '/aiml/v1/promotion/export' ),
				array( 'POST', '/aiml/v1/promotion/import/validate' ),
				array( 'POST', '/aiml/v1/promotion/import/apply' ),
				array( 'GET', '/aiml/v1/promotion/history' ),
			) as [ $method, $route ]
		) {
			$this->assertSame( 403, rest_do_request( new WP_REST_Request( $method, $route ) )->get_status(), "{$route} must be 403" );
		}

		wp_set_current_user( 0 );
	}

	public function test_export_validate_apply_round_trip(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Export.
		$export = new WP_REST_Request( 'POST', '/aiml/v1/promotion/export' );
		$export->set_header( 'Content-Type', 'application/json' );
		$export->set_body( (string) wp_json_encode( array( 'languages' => array( 'sv' ) ) ) );
		$export_response = rest_do_request( $export );
		$this->assertSame( 200, $export_response->get_status() );
		$raw = (string) $export_response->get_data()['package'];
		$this->assertNotEmpty( $raw );

		// Remove the local translation so the dry-run reports "new".
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'aiml_translations' ); // phpcs:ignore WordPress.DB
		wp_cache_flush();

		// Validate / dry-run.
		$validate = new WP_REST_Request( 'POST', '/aiml/v1/promotion/import/validate' );
		$validate->set_header( 'Content-Type', 'application/json' );
		$validate->set_body( $raw );
		$validate_response = rest_do_request( $validate );
		$this->assertSame( 200, $validate_response->get_status(), wp_json_encode( $validate_response->get_data() ) );
		$data  = $validate_response->get_data();
		$token = (string) $data['review_token'];
		$plan  = $data['plan'];
		$this->assertNotEmpty( $token );
		$this->assertSame( array( 'new' => 1 ), $plan['counts'] );

		// Apply.
		$apply = new WP_REST_Request( 'POST', '/aiml/v1/promotion/import/apply' );
		$apply->set_header( 'Content-Type', 'application/json' );
		$apply->set_body( $raw );
		$apply->set_param( 'review_token', $token );
		$apply->set_param( 'plan', $plan );
		$apply->set_param( 'mode', 'safe_only' );
		$apply_response = rest_do_request( $apply );
		$this->assertSame( 200, $apply_response->get_status(), wp_json_encode( $apply_response->get_data() ) );
		$this->assertSame( 1, $apply_response->get_data()['applied_count'] );

		// History shows both.
		$history    = rest_do_request( new WP_REST_Request( 'GET', '/aiml/v1/promotion/history' ) );
		$directions = array_column( $history->get_data()['items'], 'direction' );
		$this->assertContains( 'export', $directions );
		$this->assertContains( 'import', $directions );

		wp_set_current_user( 0 );
	}

	public function test_apply_without_a_token_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$apply = new WP_REST_Request( 'POST', '/aiml/v1/promotion/import/apply' );
		$apply->set_header( 'Content-Type', 'application/json' );
		$apply->set_body( '{"manifest":{},"payload":{"objects":[]}}' );
		$response = rest_do_request( $apply );

		$this->assertSame( 422, $response->get_status() );

		wp_set_current_user( 0 );
	}

	public function test_trust_flag_requires_the_dedicated_capability(): void {
		// A user with PROMOTE but not TRUST_REVIEW_STATE.
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $user )->add_cap( PromotionCapabilities::PROMOTE );
		wp_set_current_user( $user );

		$export = new WP_REST_Request( 'POST', '/aiml/v1/promotion/export' );
		$export->set_header( 'Content-Type', 'application/json' );
		$export->set_body( (string) wp_json_encode( array( 'languages' => array( 'sv' ) ) ) );
		$raw = (string) rest_do_request( $export )->get_data()['package'];

		$apply = new WP_REST_Request( 'POST', '/aiml/v1/promotion/import/apply' );
		$apply->set_header( 'Content-Type', 'application/json' );
		$apply->set_body( $raw );
		$apply->set_param( 'review_token', 'anything' );
		$apply->set_param( 'trust_review', true );
		$response = rest_do_request( $apply );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aiml_forbidden_trust', $response->get_data()['code'] );

		wp_set_current_user( 0 );
	}
}
