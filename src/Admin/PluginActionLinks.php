<?php
/**
 * Plugins-screen action and meta links for Universal Multilingual (AIT1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

/**
 * Adds "Settings" and "Overview" to the plugin row, and one "Documentation"
 * meta link. All links point at existing canonical admin screens — no new
 * menu, page or settings architecture.
 */
final class PluginActionLinks {

	private const DOCS_URL = 'https://github.com/magpern/universal-multilingual/tree/main/docs';

	/**
	 * Hooks the filters for this plugin's row only.
	 */
	public function register(): void {
		add_filter(
			'plugin_action_links_' . plugin_basename( AIML_PLUGIN_FILE ),
			array( $this, 'action_links' )
		);
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Prepends the capability-aware Settings and Overview links so the row
	 * reads "Settings | Overview | Deactivate".
	 *
	 * @param array<int|string, string> $links Existing action links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		return $this->build_action_links(
			$links,
			current_user_can( 'manage_options' ),
			current_user_can( TranslatorWorkspace::ACCESS_CAP ),
			admin_url( 'admin.php?page=' . SettingsPage::SETTINGS_SLUG ),
			admin_url( 'admin.php?page=' . TranslatorWorkspace::MENU_SLUG )
		);
	}

	/**
	 * Pure builder — no WordPress calls — so the ordering and capability gating
	 * are unit-testable.
	 *
	 * @param array<int|string, string> $existing     Existing action links.
	 * @param bool                      $can_settings Whether to show Settings.
	 * @param bool                      $can_overview Whether to show Overview.
	 * @param string                    $settings_url Settings screen URL.
	 * @param string                    $overview_url Workspace screen URL.
	 * @return array<int|string, string>
	 */
	public function build_action_links(
		array $existing,
		bool $can_settings,
		bool $can_overview,
		string $settings_url,
		string $overview_url
	): array {
		$prepend = array();

		if ( $can_settings ) {
			$prepend['aiml_settings'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Settings', 'universal-multilingual' )
			);
		}

		if ( $can_overview ) {
			$prepend['aiml_overview'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $overview_url ),
				esc_html__( 'Overview', 'universal-multilingual' )
			);
		}

		return array_merge( $prepend, $existing );
	}

	/**
	 * Adds a single Documentation meta link under this plugin's description.
	 *
	 * @param array<int, string> $meta Existing meta links.
	 * @param string             $file Plugin file the row is for.
	 * @return array<int, string>
	 */
	public function row_meta( array $meta, string $file ): array {
		if ( plugin_basename( AIML_PLUGIN_FILE ) !== $file ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::DOCS_URL ),
			esc_html__( 'Documentation', 'universal-multilingual' )
		);

		return $meta;
	}
}
