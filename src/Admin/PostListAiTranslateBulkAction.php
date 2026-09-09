<?php
/**
 * "Translate with AI" bulk action on the Pages / Posts list tables (AIT1 / WP6).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Plugin;

/**
 * Discoverability only: the action performs no translation. It hands the
 * selected IDs to the Site Translate screen, where the operator picks a target
 * language and mode and confirms.
 */
final class PostListAiTranslateBulkAction {

	private const ACTION = 'aiml_translate_with_ai';

	/**
	 * Post types that carry the bulk action (the plugin's supported admin-edit
	 * scope — pages and posts).
	 *
	 * @var list<string>
	 */
	private const POST_TYPES = array( 'post', 'page' );

	/**
	 * Registers the bulk-action filters and handlers.
	 */
	public function register(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle' ), 10, 3 );
		}
	}

	/**
	 * Adds the action to a list table's dropdown when the current user may
	 * translate.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( array $actions ): array {
		if ( current_user_can( Plugin::CAPABILITY ) ) {
			$actions[ self::ACTION ] = __( 'Translate with AI', 'universal-multilingual' );
		}

		return $actions;
	}

	/**
	 * Redirects the selection into the Site Translate screen with the IDs
	 * pre-filled. Does not create any job.
	 *
	 * @param string     $redirect_to Redirect URL WordPress would use.
	 * @param string     $action      Bulk action being handled.
	 * @param array<int> $post_ids   Selected post IDs.
	 * @return string
	 */
	public function handle( string $redirect_to, string $action, array $post_ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return $redirect_to;
		}

		$ids = array_values(
			array_filter(
				array_map( 'intval', $post_ids ),
				static fn( int $id ): bool => $id > 0
			)
		);

		if ( array() === $ids ) {
			return $redirect_to;
		}

		return add_query_arg(
			array(
				'page'        => TranslatorWorkspace::MENU_SLUG,
				'view'        => 'site-translate',
				'aiml_st_ids' => implode( ',', $ids ),
			),
			admin_url( 'admin.php' )
		);
	}
}
