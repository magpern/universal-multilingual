<?php
/**
 * DEV → PROD translation promotion admin screen (ADR-0030 §9).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Language\Languages;
use AIMultilingual\Promotion\PromotionCapabilities;
use AIMultilingual\Promotion\PromotionScope;

/**
 * Operator UI for export / import (dry-run → apply) via the aiml/v1 REST API.
 * Vanilla `wp-api-fetch` + `wp-dom-ready`, core admin styling — the same pattern
 * as the Glossary screen. No React, no cross-plugin CSS.
 */
final class PromotionAdminPage {

	public const MENU_SLUG     = 'aiml-promotion';
	public const SCRIPT_HANDLE = 'aiml-promotion-admin';
	public const STYLE_HANDLE  = 'aiml-promotion-admin';

	/**
	 * Builds the page.
	 *
	 * @param Languages $languages Language model.
	 */
	public function __construct(
		private readonly Languages $languages
	) {
	}

	/**
	 * Registers the menu and assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the Translation Promotion submenu under Universal Multilingual.
	 */
	public function add_menu(): void {
		add_submenu_page(
			SettingsPage::MENU_SLUG,
			__( 'Translation Promotion', 'universal-multilingual' ),
			__( 'Translation Promotion', 'universal-multilingual' ),
			PromotionCapabilities::PROMOTE,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues the screen's assets.
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
			plugins_url( 'assets/promotion-admin/promotion-admin.css', AIML_PLUGIN_FILE ),
			array(),
			$version
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/promotion-admin/promotion-admin.js', AIML_PLUGIN_FILE ),
			array( 'wp-api-fetch', 'wp-dom-ready' ),
			$version,
			true
		);

		$languages = array();
		foreach ( $this->languages->all() as $language ) {
			$languages[] = array(
				'code'   => (string) $language->code,
				'locale' => (string) $language->locale,
				'name'   => (string) $language->name,
			);
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'aimlPromotionAdmin',
			array(
				'restNamespace' => 'aiml/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'languages'     => $languages,
				'postTypes'     => PromotionScope::POST_SUBTYPES,
				'taxonomies'    => PromotionScope::TERM_SUBTYPES,
				'canTrust'      => current_user_can( PromotionCapabilities::TRUST_REVIEW_STATE ),
				'i18n'          => array(
					'exportError'   => __( 'Export failed.', 'universal-multilingual' ),
					'validateError' => __( 'The package could not be validated.', 'universal-multilingual' ),
					'applyError'    => __( 'Apply failed.', 'universal-multilingual' ),
					'reReview'      => __( 'This environment changed since the dry-run. Re-run the dry-run and review it again.', 'universal-multilingual' ),
				),
			)
		);
	}

	/**
	 * Renders the mount point.
	 */
	public function render(): void {
		if ( ! current_user_can( PromotionCapabilities::PROMOTE ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'universal-multilingual' ) );
		}

		echo '<div class="wrap aiml-ui aiml-promotion-admin">';
		echo '<h1>' . esc_html__( 'Translation Promotion', 'universal-multilingual' ) . '</h1>';
		AdminNavigation::render( self::MENU_SLUG );
		echo '<p>' . esc_html__( 'Move reviewed translations between environments: build a package here, then import it on the other site with a read-only dry-run before applying.', 'universal-multilingual' ) . '</p>';
		echo '<div id="aiml-promotion-admin-root"></div>';
		echo '</div>';
	}
}
