<?php
/**
 * Shared internal navigation for every Universal Multilingual admin screen.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Glossary\GlossaryCapabilities;
use AIMultilingual\Plugin;
use AIMultilingual\Promotion\PromotionCapabilities;
use AIMultilingual\Rollout\RolloutCapabilities;

/**
 * One place that defines the plugin's operator-facing admin destinations and
 * renders a compact tab bar linking between them. No new menus, routes or
 * capabilities — every entry maps to an existing page slug constant and reuses
 * that page's own permission model.
 */
final class AdminNavigation {

	/**
	 * Shared stylesheet handle (registered by WP2b, reused here).
	 */
	public const STYLE_HANDLE = 'aiml-admin-ui';

	/**
	 * Canonical destinations, in display order. Each `capability` is the exact
	 * capability the target screen already gates itself on.
	 *
	 * @return list<array{slug: string, label: string, capability: string}>
	 */
	public static function destinations(): array {
		return array(
			array(
				'slug'       => SettingsPage::MENU_SLUG,
				'label'      => __( 'Languages', 'universal-multilingual' ),
				'capability' => 'manage_options',
			),
			array(
				'slug'       => SettingsPage::SETTINGS_SLUG,
				'label'      => __( 'Settings', 'universal-multilingual' ),
				'capability' => 'manage_options',
			),
			array(
				'slug'       => RolloutAdminPage::SLUG,
				'label'      => __( 'Limited Rollout', 'universal-multilingual' ),
				'capability' => RolloutCapabilities::VIEW_ROLLOUT,
			),
			array(
				'slug'       => SeoDiagnosticsAdminPage::SLUG,
				'label'      => __( 'SEO Diagnostics', 'universal-multilingual' ),
				'capability' => 'manage_options',
			),
			array(
				'slug'       => Editor::MENU_SLUG,
				'label'      => __( 'Translate', 'universal-multilingual' ),
				'capability' => Plugin::CAPABILITY,
			),
			array(
				'slug'       => TranslatorWorkspace::MENU_SLUG,
				'label'      => __( 'Workspace', 'universal-multilingual' ),
				'capability' => TranslatorWorkspace::ACCESS_CAP,
			),
			array(
				'slug'       => GlossaryAdminPage::MENU_SLUG,
				'label'      => __( 'Glossary', 'universal-multilingual' ),
				'capability' => GlossaryCapabilities::MANAGE_GLOSSARY,
			),
			array(
				'slug'       => PromotionAdminPage::MENU_SLUG,
				'label'      => __( 'Translation Promotion', 'universal-multilingual' ),
				'capability' => PromotionCapabilities::PROMOTE,
			),
		);
	}

	/**
	 * All destination slugs.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_map(
			static fn( array $entry ): string => (string) $entry['slug'],
			self::destinations()
		);
	}

	/**
	 * Registers the shared-stylesheet enqueue for the plugin's admin screens.
	 * Rendering is invoked by each screen so the bar sits directly under its
	 * heading.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueues the shared admin design-system stylesheet on a plugin screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function maybe_enqueue( string $hook_suffix ): void {
		unset( $hook_suffix );
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		self::enqueue();
	}

	/**
	 * Enqueues the shared stylesheet (idempotent).
	 */
	public static function enqueue(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/admin-ui/aiml-ui.css', AIML_PLUGIN_FILE ),
			array( 'wp-components' ),
			defined( 'AIML_VERSION' ) ? AIML_VERSION : '0.1.0'
		);
	}

	/**
	 * The `page` query var for the screen being rendered.
	 */
	public static function current_slug(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen identity.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/**
	 * Whether the screen being rendered belongs to the plugin's admin area.
	 */
	public static function is_plugin_screen(): bool {
		return in_array( self::current_slug(), self::slugs(), true );
	}

	/**
	 * Renders the navigation bar for the current (or given) screen.
	 *
	 * @param string|null $current Slug of the active screen; defaults to the
	 *                             `page` query var.
	 */
	public static function render( ?string $current = null ): void {
		$current = null !== $current ? sanitize_key( $current ) : self::current_slug();
		$items   = self::build_items( $current, 'current_user_can' );

		if ( array() === $items ) {
			return;
		}

		echo self::markup( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes every value.
	}

	/**
	 * Pure builder: the accessible destinations with resolved URL + active flag.
	 *
	 * @param string   $current   Active slug.
	 * @param callable $can_check fn(string $capability): bool.
	 * @return list<array{slug: string, label: string, url: string, active: bool}>
	 */
	public static function build_items( string $current, callable $can_check ): array {
		$items = array();

		foreach ( self::destinations() as $entry ) {
			if ( ! $can_check( $entry['capability'] ) ) {
				continue;
			}

			$items[] = array(
				'slug'   => (string) $entry['slug'],
				'label'  => (string) $entry['label'],
				'url'    => admin_url( 'admin.php?page=' . $entry['slug'] ),
				'active' => $current === (string) $entry['slug'],
			);
		}

		return $items;
	}

	/**
	 * Renders the escaped markup for a set of built items.
	 *
	 * @param list<array{slug: string, label: string, url: string, active: bool}> $items Items.
	 */
	public static function markup( array $items ): string {
		$links = '';

		foreach ( $items as $item ) {
			$class  = 'aiml-ui-subnav__link' . ( $item['active'] ? ' aiml-ui-subnav__link--active' : '' );
			$links .= sprintf(
				'<li class="aiml-ui-subnav__item"><a class="%1$s" href="%2$s"%3$s>%4$s</a></li>',
				esc_attr( $class ),
				esc_url( $item['url'] ),
				$item['active'] ? ' aria-current="page"' : '',
				esc_html( $item['label'] )
			);
		}

		return sprintf(
			'<nav class="aiml-ui-subnav" aria-label="%1$s"><ul class="aiml-ui-subnav__list">%2$s</ul></nav>',
			esc_attr__( 'Universal Multilingual sections', 'universal-multilingual' ),
			$links
		);
	}
}
