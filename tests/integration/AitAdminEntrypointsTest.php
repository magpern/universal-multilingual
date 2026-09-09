<?php
/**
 * AIT1 WP6/WP8 — Plugins-row links and the Pages/Posts bulk action.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Admin\PluginActionLinks;
use AIMultilingual\Admin\PostListAiTranslateBulkAction;
use AIMultilingual\Admin\TranslatorWorkspace;
use AIMultilingual\Jobs\JobsCapabilities;

/**
 * The new admin discoverability surfaces.
 */
final class AitAdminEntrypointsTest extends AimlTestCase {

	private string $basename;

	protected function setUp(): void {
		parent::setUp();
		$this->basename = plugin_basename( AIML_PLUGIN_FILE );
		JobsCapabilities::grant_default_roles();
		// Activates the aiml_workspace_access virtual capability mapping.
		( new TranslatorWorkspace( $this->languages ) )->register();
	}

	public function test_admin_gets_settings_and_overview_links_in_order(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$links = ( new PluginActionLinks() )->action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertSame(
			array( 'aiml_settings', 'aiml_overview', 'deactivate' ),
			array_keys( $links )
		);
		$this->assertStringContainsString( 'page=aiml-settings', $links['aiml_settings'] );
		$this->assertStringContainsString( 'page=aiml-translator', $links['aiml_overview'] );
	}

	public function test_subscriber_gets_no_plugin_links(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$existing = array( 'deactivate' => '<a>Deactivate</a>' );
		$this->assertSame(
			$existing,
			( new PluginActionLinks() )->action_links( $existing )
		);
	}

	public function test_documentation_meta_link_only_on_this_plugin_row(): void {
		$links = new PluginActionLinks();

		$mine = $links->row_meta( array( 'Version 1.0' ), $this->basename );
		$this->assertCount( 2, $mine );
		$this->assertStringContainsString( 'Documentation', $mine[1] );

		$other = $links->row_meta( array( 'Version 1.0' ), 'some-other-plugin/some-other-plugin.php' );
		$this->assertSame( array( 'Version 1.0' ), $other );
	}

	public function test_bulk_action_is_offered_to_translators_only(): void {
		$action = new PostListAiTranslateBulkAction();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertArrayHasKey( 'aiml_translate_with_ai', $action->add_bulk_action( array() ) );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertArrayNotHasKey( 'aiml_translate_with_ai', $action->add_bulk_action( array() ) );
	}

	public function test_bulk_action_redirects_selection_into_site_translate(): void {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$redirect = ( new PostListAiTranslateBulkAction() )->handle(
			'https://example.test/wp-admin/edit.php',
			'aiml_translate_with_ai',
			array( 5, 0, 9, -2, 12 )
		);

		$this->assertStringContainsString( 'page=aiml-translator', $redirect );
		$this->assertStringContainsString( 'view=site-translate', $redirect );
		$this->assertStringContainsString( 'aiml_st_ids=5,9,12', $redirect );
	}

	public function test_bulk_action_ignores_other_actions(): void {
		$this->assertSame(
			'https://example.test/wp-admin/edit.php',
			( new PostListAiTranslateBulkAction() )->handle(
				'https://example.test/wp-admin/edit.php',
				'trash',
				array( 1, 2 )
			)
		);
	}
}
