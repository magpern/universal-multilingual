<?php
/**
 * Limited rollout operator admin screen.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Rollout\Metrics\RolloutDiagnosticsService;
use AIMultilingual\Rollout\Metrics\RolloutHotMetricsStore;
use AIMultilingual\Rollout\RolloutCapabilities;
use AIMultilingual\Rollout\RolloutConfigurationRepository;

/**
 * Read-only rollout status for operators (mutations via CLI/shared services).
 */
final class RolloutAdminPage {

	public const SLUG = 'aiml-rollout';

	/**
	 * Registers the admin submenu.
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				add_submenu_page(
					SettingsPage::MENU_SLUG,
					__( 'Limited Rollout', 'universal-multilingual' ),
					__( 'Limited Rollout', 'universal-multilingual' ),
					RolloutCapabilities::VIEW_ROLLOUT,
					self::SLUG,
					array( $this, 'render' )
				);
			}
		);
	}

	/**
	 * Renders rollout diagnostics summary.
	 */
	public function render(): void {
		if ( ! current_user_can( RolloutCapabilities::VIEW_ROLLOUT ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'universal-multilingual' ) );
		}

		$service = new RolloutDiagnosticsService(
			new RolloutConfigurationRepository(),
			RolloutHotMetricsStore::load()
		);

		$summary = $service->status_summary();

		echo '<div class="wrap aiml-ui">';
		echo '<h1>' . esc_html__( 'Limited Rollout', 'universal-multilingual' ) . '</h1>';
		AdminNavigation::render( self::SLUG );
		echo '<p>' . esc_html__( 'Mutations use shared CLI/services with capability checks. Reason codes are operator diagnostics only.', 'universal-multilingual' ) . '</p>';
		echo '<pre style="background:#fff;padding:1em;border:1px solid #ccd0d4;">';
		echo esc_html( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
		echo '</pre>';
		echo '</div>';
	}
}
