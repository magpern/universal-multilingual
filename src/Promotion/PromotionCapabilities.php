<?php
/**
 * Translation promotion capabilities (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Two explicit capabilities, both administrator-only by default.
 *
 * `PROMOTE` gates export, dry-run, apply and history. `TRUST_REVIEW_STATE`
 * additionally gates carrying an approved / published review state across
 * environments.
 *
 * Provisioning is idempotent and versioned through the `aiml_caps_version`
 * option so a bind-mount upgrade (which never fires the activation hook) still
 * grants the caps on the next `admin_init`. Sites widen the promoter set through
 * the `aiml_promotion_can_promote` filter, evaluated per request — there is no
 * one-time role scan that could drift.
 */
final class PromotionCapabilities {

	public const PROMOTE            = 'aiml_promote_translations';
	public const TRUST_REVIEW_STATE = 'aiml_trust_promoted_review_state';

	/**
	 * Option holding the applied capability-set version.
	 */
	public const VERSION_OPTION = 'aiml_caps_version';

	/**
	 * Capability-set version this build expects.
	 */
	public const CAPS_TARGET = 1;

	/**
	 * All promotion capabilities.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::PROMOTE, self::TRUST_REVIEW_STATE );
	}

	/**
	 * Roles that receive promotion capabilities.
	 *
	 * @return list<string>
	 */
	public static function default_roles(): array {
		return array( 'administrator' );
	}

	/**
	 * Grants the capabilities when the recorded version is behind this build.
	 *
	 * Safe to call from both the activation hook and an `admin_init` drift check.
	 */
	public static function provision(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::CAPS_TARGET ) {
			return;
		}

		self::grant_default_roles();

		update_option( self::VERSION_OPTION, self::CAPS_TARGET, true );
	}

	/**
	 * Adds every promotion capability to each default role that lacks it.
	 */
	public static function grant_default_roles(): void {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		foreach ( self::default_roles() as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes every promotion capability from every role. Uninstall path.
	 */
	public static function revoke_all_roles(): void {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}

		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = $roles->get_role( (string) $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Registers the `map_meta_cap` widening filter.
	 *
	 * A site can grant promotion to a bespoke role or user by returning true
	 * from `aiml_promotion_can_promote` — evaluated on every permission check, so
	 * a role that gains the underlying cap later is honoured immediately.
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Lets `aiml_promotion_can_promote` satisfy the promote capability.
	 *
	 * @param array<int, string> $caps    Required primitive caps.
	 * @param string             $cap     Capability being checked.
	 * @param int                $user_id User ID.
	 * @param array<int, mixed>  $args    Extra args.
	 * @return array<int, string>
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( self::PROMOTE !== $cap ) {
			return $caps;
		}

		/**
		 * Filters whether a user may promote translations.
		 *
		 * Return true to grant regardless of role capabilities.
		 *
		 * @since 1.14.0
		 *
		 * @param bool              $allowed Default false.
		 * @param int               $user_id User being checked.
		 * @param array<int, mixed> $args    Extra args passed to current_user_can().
		 */
		if ( (bool) apply_filters( 'aiml_promotion_can_promote', false, $user_id, $args ) ) {
			return array( 'exist' );
		}

		return $caps;
	}
}
