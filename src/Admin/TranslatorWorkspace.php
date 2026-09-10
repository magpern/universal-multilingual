<?php
/**
 * Translator workspace admin shell (F10.2).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Jobs\JobsCapabilities;
use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use AIMultilingual\Workspace\Review\ReviewCapabilities;

/**
 * Enqueues the React translator workspace under the Multilingual admin menu.
 */
final class TranslatorWorkspace {

	public const MENU_SLUG = 'aiml-translator';

	public const SCRIPT_HANDLE = 'aiml-translator-workspace';

	public const STYLE_HANDLE = 'aiml-translator-workspace';

	/**
	 * Virtual capability granting access to the Workspace admin screen to
	 * translators and reviewers alike (ADR-0015 §6). Mapped below to a
	 * capability the user already holds; never granted as a real role
	 * capability so it cannot drift from the two source capabilities.
	 */
	public const ACCESS_CAP = 'aiml_workspace_access';

	/**
	 * Language registry.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Builds the workspace admin screen.
	 *
	 * @param Languages $languages Language registry.
	 */
	public function __construct( Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Registers the workspace admin screen.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'map_meta_cap', array( $this, 'map_access_cap' ), 10, 2 );
	}

	/**
	 * Adds the workspace submenu for translators and reviewers.
	 */
	public function add_menu(): void {
		add_submenu_page(
			SettingsPage::MENU_SLUG,
			__( 'Translator workspace', 'universal-multilingual' ),
			__( 'Workspace', 'universal-multilingual' ),
			self::ACCESS_CAP,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Maps the virtual Workspace access capability to `aiml_translate` OR
	 * `aiml_review_translations` (ADR-0015 §6): the Workspace shell now
	 * serves both translators and review-only users, but neither capability
	 * is widened and no new real capability is granted to any role.
	 *
	 * @param array<int, string> $caps Required primitive capabilities.
	 * @param string             $cap  Capability being checked.
	 * @return array<int, string>
	 */
	public function map_access_cap( array $caps, string $cap ): array {
		if ( self::ACCESS_CAP !== $cap ) {
			return $caps;
		}

		if (
			current_user_can( Plugin::CAPABILITY )
			|| current_user_can( ReviewCapabilities::REVIEW_TRANSLATIONS )
			|| current_user_can( JobsCapabilities::VIEW_JOBS )
		) {
			return array( 'read' );
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Enqueues the compiled workspace bundle on the workspace screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'multilingual_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_dir = plugin_dir_path( AIML_PLUGIN_FILE );
		$asset_file = $plugin_dir . 'assets/translator-workspace/build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$version = is_array( $asset ) ? (string) ( $asset['version'] ?? AIML_VERSION ) : AIML_VERSION;
		$deps    = is_array( $asset ) ? (array) ( $asset['dependencies'] ?? array() ) : array();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/translator-workspace/build/index.js', AIML_PLUGIN_FILE ),
			array_merge( $deps, array( 'wp-api-fetch' ) ),
			$version,
			true
		);

		wp_enqueue_style(
			'aiml-admin-ui',
			plugins_url( 'assets/admin-ui/aiml-ui.css', AIML_PLUGIN_FILE ),
			array( 'wp-components' ),
			defined( 'AIML_VERSION' ) ? AIML_VERSION : $version
		);

		if ( is_readable( $plugin_dir . 'assets/translator-workspace/build/style-index.css' ) ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				plugins_url( 'assets/translator-workspace/build/style-index.css', AIML_PLUGIN_FILE ),
				array( 'wp-components', 'aiml-admin-ui' ),
				$version
			);
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'aimlTranslatorWorkspace',
			array(
				'restNamespace'       => 'aiml/v1',
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'languages'           => $this->language_bootstrap(),
				'initialPostId'       => isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'initialLanguageCode' => isset( $_GET['language'] ) ? sanitize_key( wp_unslash( (string) $_GET['language'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'aiConfigured'        => \AIMultilingual\Translation\AI\ProviderRegistry::is_ai_configured( new \AIMultilingual\Settings() ),
				'aiSettingsUrl'       => current_user_can( 'manage_options' )
					? admin_url( 'admin.php?page=' . SettingsPage::SETTINGS_SLUG )
					: '',
				'canTranslate'        => current_user_can( Plugin::CAPABILITY ),
				'canReview'           => current_user_can( ReviewCapabilities::REVIEW_TRANSLATIONS ),
				'canAccessOperations' => current_user_can( Plugin::CAPABILITY ) || current_user_can( ReviewCapabilities::REVIEW_TRANSLATIONS ),
				'canViewJobs'         => current_user_can( JobsCapabilities::VIEW_JOBS ),
				'canManageJobs'       => current_user_can( JobsCapabilities::MANAGE_JOBS ),
				'canRunJobs'          => current_user_can( JobsCapabilities::RUN_JOBS ),
				'canCancelJobs'       => current_user_can( JobsCapabilities::CANCEL_JOBS ),
			)
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'universal-multilingual' );
	}

	/**
	 * Renders the workspace mount point.
	 */
	public function render(): void {
		if ( ! current_user_can( self::ACCESS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access the translator workspace.', 'universal-multilingual' ) );
		}

		echo '<div class="wrap aiml-ui">';
		echo '<h1>' . esc_html__( 'Translator workspace', 'universal-multilingual' ) . '</h1>';
		AdminNavigation::render( self::MENU_SLUG );

		if ( ! is_readable( plugin_dir_path( AIML_PLUGIN_FILE ) . 'assets/translator-workspace/build/index.js' ) ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e(
				'The workspace bundle is not built. Run npm run build in assets/translator-workspace/.',
				'universal-multilingual'
			);
			echo '</p></div>';
		}

		echo '<div id="aiml-translator-workspace-root"></div>';
		echo '</div>';
	}

	/**
	 * Target languages exposed to the React shell.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function language_bootstrap(): array {
		$items = array();

		foreach ( $this->languages->all() as $language ) {
			if ( ! empty( $language->is_default ) ) {
				continue;
			}

			if ( Languages::STATUS_DISABLED === (string) ( $language->status ?? '' ) ) {
				continue;
			}

			$items[] = array(
				'language_id' => (int) $language->language_id,
				'code'        => (string) $language->code,
				'name'        => (string) $language->name,
				'native_name' => (string) $language->native_name,
				'status'      => (string) $language->status,
			);
		}

		return $items;
	}
}
