<?php
/**
 * Shared Regional Preferences composition constants.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\User;

/**
 * Cross-plugin composition surface (string-stable; no shared package).
 *
 * Mirrored by Universal Multicurrency. Documented in docs/HOOKS.md.
 */
final class RegionalPreferencesComposition {

	public const ACTION_RENDER        = 'um_regional_preferences_render';
	public const ACTION_SAVE          = 'um_regional_preferences_save';
	public const ACTION_RENDERED      = 'um_regional_preferences_rendered';
	public const ACTION_SAVE_STARTED  = 'um_regional_preferences_save_started';
	public const FILTER_CLAIMED_SLOTS = 'um_regional_preferences_claimed_slots';

	public const SLOT_LANGUAGE = 'language';
	public const SLOT_CURRENCY = 'currency';

	public const SURFACE_PROFILE = 'profile';
	public const SURFACE_ACCOUNT = 'account';

	/**
	 * Builds the context array passed to composition actions.
	 *
	 * @param string $surface   profile|account.
	 * @param int    $user_id   Target user id.
	 * @param bool   $can_edit  Whether the viewer may edit the target.
	 * @return array{surface: string, user_id: int, can_edit: bool}
	 */
	public static function context( string $surface, int $user_id, bool $can_edit ): array {
		return array(
			'surface'  => $surface,
			'user_id'  => $user_id,
			'can_edit' => $can_edit,
		);
	}
}
