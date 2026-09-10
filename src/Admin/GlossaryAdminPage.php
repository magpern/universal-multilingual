<?php
/**
 * Glossary lexicon admin screen.
 *
 * @package AIMultilingual
 */

declare(strict_types=1);

namespace AIMultilingual\Admin;

use AIMultilingual\Glossary\GlossaryCapabilities;
use AIMultilingual\Language\Languages;

/**
 * Operator UI for glossary CRUD via REST.
 */
final class GlossaryAdminPage {

	public const MENU_SLUG = 'aiml-glossary';

	public const SCRIPT_HANDLE = 'aiml-glossary-admin';

	public const STYLE_HANDLE = 'aiml-glossary-admin';

	/**
	 * Language registry.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Builds the glossary admin page.
	 *
	 * @param Languages $languages Language registry.
	 */
	public function __construct( Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Registers menu and assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the glossary submenu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			SettingsPage::MENU_SLUG,
			__( 'Glossary', 'universal-multilingual' ),
			__( 'Glossary', 'universal-multilingual' ),
			GlossaryCapabilities::MANAGE_GLOSSARY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues admin assets on the glossary screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'multilingual_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$version = defined( 'AIML_VERSION' ) ? AIML_VERSION : '0.1.0';

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/glossary-admin/glossary-admin.css', AIML_PLUGIN_FILE ),
			array(),
			$version
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/glossary-admin/glossary-admin.js', AIML_PLUGIN_FILE ),
			array( 'wp-api-fetch', 'wp-dom-ready' ),
			$version,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'aimlGlossaryAdmin',
			array(
				'restNamespace' => 'aiml/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'languages'     => $this->language_bootstrap(),
				'i18n'          => array(
					'loadError'   => __( 'Failed to load glossary terms.', 'universal-multilingual' ),
					'saveError'   => __( 'Failed to save glossary term.', 'universal-multilingual' ),
					'deleteError' => __( 'Failed to delete glossary term.', 'universal-multilingual' ),
					'empty'       => __( 'No glossary terms yet.', 'universal-multilingual' ),
				),
			)
		);
	}

	/**
	 * Renders the glossary admin mount.
	 */
	public function render(): void {
		if ( ! current_user_can( GlossaryCapabilities::MANAGE_GLOSSARY ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'universal-multilingual' ) );
		}

		echo '<div class="wrap aiml-ui aiml-glossary-admin">';
		echo '<h1>' . esc_html__( 'Glossary', 'universal-multilingual' ) . '</h1>';
		AdminNavigation::render( self::MENU_SLUG );
		echo '<p>' . esc_html__( 'Curated platform terminology for translation suggestions, AI context, and QA warnings.', 'universal-multilingual' ) . '</p>';
		echo '<div id="aiml-glossary-admin-root"></div>';
		echo '</div>';
	}

	/**
	 * Language bootstrap for the admin UI.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function language_bootstrap(): array {
		$out = array();
		foreach ( $this->languages->all() as $lang ) {
			$out[] = array(
				'language_id' => (int) $lang->language_id,
				'code'        => (string) $lang->code,
				'locale'      => (string) $lang->locale,
				'name'        => (string) $lang->name,
				'is_default'  => ! empty( $lang->is_default ),
			);
		}

		return $out;
	}
}
