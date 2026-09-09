<?php
/**
 * Promotion audit channel (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Fires `do_action( 'aiml_promotion_audit', $event )` with a content-free,
 * secret-free payload — consistent with the plugin's other `aiml_*_audit`
 * channels. There is deliberately **no** `validate` / `dry_run` stage: a
 * read-only dry-run emits nothing at all, so a third-party listener cannot be
 * induced to persist anything during one.
 */
final class PromotionAudit {

	public const STAGE_EXPORT         = 'export';
	public const STAGE_APPLY_START    = 'apply_start';
	public const STAGE_APPLY_ROW      = 'apply_row';
	public const STAGE_APPLY_COMPLETE = 'apply_complete';

	/**
	 * Emits an audit event.
	 *
	 * @param string               $stage   One of STAGE_*.
	 * @param string               $direction export|import.
	 * @param string               $package_id Package id.
	 * @param array<string, mixed> $extra   Additional safe fields (counts, codes).
	 */
	public function emit( string $stage, string $direction, string $package_id, array $extra = array() ): void {
		$event = array_merge(
			array(
				'stage'      => $stage,
				'direction'  => $direction,
				'package_id' => $package_id,
				'actor_id'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			),
			$extra
		);

		/**
		 * Fires on a promotion export or apply milestone.
		 *
		 * @since 1.14.0
		 *
		 * @param array<string, mixed> $event Safe, content-free event payload.
		 */
		do_action( 'aiml_promotion_audit', $event );
	}
}
