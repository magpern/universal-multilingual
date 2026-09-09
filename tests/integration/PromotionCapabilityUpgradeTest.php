<?php
/**
 * Promotion capability provisioning tests (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Promotion\PromotionCapabilities;

/**
 * Idempotent, versioned, activation-independent capability provisioning.
 */
final class PromotionCapabilityUpgradeTest extends AimlTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Start each test from a clean, un-provisioned state.
		delete_option( PromotionCapabilities::VERSION_OPTION );
		PromotionCapabilities::revoke_all_roles();
	}

	protected function tearDown(): void {
		// Leave the suite as bootstrap left it — force a grant even when a test
		// left aiml_caps_version at the target (which would make provision() skip).
		delete_option( PromotionCapabilities::VERSION_OPTION );
		PromotionCapabilities::grant_default_roles();
		update_option( PromotionCapabilities::VERSION_OPTION, PromotionCapabilities::CAPS_TARGET, true );
		parent::tearDown();
	}

	public function test_capabilities_are_administrator_only_by_default(): void {
		$this->assertSame( array( 'administrator' ), PromotionCapabilities::default_roles() );
		$this->assertSame(
			array( 'aiml_promote_translations', 'aiml_trust_promoted_review_state' ),
			PromotionCapabilities::all()
		);
	}

	public function test_drift_path_grants_caps_without_activation(): void {
		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );

		$this->assertFalse( $admin->has_cap( PromotionCapabilities::PROMOTE ) );

		// The admin_init drift path, not Plugin::activate().
		PromotionCapabilities::provision();

		$this->assertTrue( $admin->has_cap( PromotionCapabilities::PROMOTE ) );
		$this->assertTrue( $admin->has_cap( PromotionCapabilities::TRUST_REVIEW_STATE ) );
		$this->assertFalse( $editor->has_cap( PromotionCapabilities::PROMOTE ) );
		$this->assertSame( PromotionCapabilities::CAPS_TARGET, (int) get_option( PromotionCapabilities::VERSION_OPTION ) );
	}

	public function test_provision_is_idempotent(): void {
		PromotionCapabilities::provision();
		PromotionCapabilities::provision();
		PromotionCapabilities::provision();

		$this->assertSame( PromotionCapabilities::CAPS_TARGET, (int) get_option( PromotionCapabilities::VERSION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( PromotionCapabilities::PROMOTE ) );
	}

	public function test_provision_skips_work_when_version_is_current(): void {
		update_option( PromotionCapabilities::VERSION_OPTION, PromotionCapabilities::CAPS_TARGET, true );

		PromotionCapabilities::provision();

		// Version already current — no grant happened.
		$this->assertFalse( get_role( 'administrator' )->has_cap( PromotionCapabilities::PROMOTE ) );
	}

	public function test_map_meta_cap_filter_widens_promote(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		( new PromotionCapabilities() )->register();

		$this->assertFalse( current_user_can( PromotionCapabilities::PROMOTE ) );

		$grant = static fn() => true;
		add_filter( 'aiml_promotion_can_promote', $grant );
		$this->assertTrue( current_user_can( PromotionCapabilities::PROMOTE ) );
		remove_filter( 'aiml_promotion_can_promote', $grant );

		wp_set_current_user( 0 );
	}

	public function test_revoke_removes_caps_from_every_role(): void {
		PromotionCapabilities::provision();
		$this->assertTrue( get_role( 'administrator' )->has_cap( PromotionCapabilities::PROMOTE ) );

		PromotionCapabilities::revoke_all_roles();

		$this->assertFalse( get_role( 'administrator' )->has_cap( PromotionCapabilities::PROMOTE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( PromotionCapabilities::TRUST_REVIEW_STATE ) );
	}
}
