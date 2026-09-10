<?php
/**
 * Thin SEO diagnostics admin UI (A.SEOf SF14).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Admin;

use AIMultilingual\Seo\Diagnostics\SeoDiagnosticsOptions;
use AIMultilingual\Seo\Diagnostics\SeoDiagnosticsService;
use AIMultilingual\Seo\Diagnostics\SeoDiagnosticsSnapshot;

/**
 * Presentation-only SEO health screen.
 *
 * Hard rule: this page must not evaluate SEO rules, crawl independently,
 * maintain health state, or invent thresholds. It only invokes the shared
 * diagnostics core and renders the SF13 snapshot.
 */
final class SeoDiagnosticsAdminPage {

	public const SLUG = 'aiml-seo-diagnostics';

	/**
	 * Builds the admin page.
	 *
	 * @param SeoDiagnosticsService $diagnostics Shared diagnostics core.
	 */
	public function __construct(
		private SeoDiagnosticsService $diagnostics
	) {
	}

	/**
	 * Registers the Multilingual submenu.
	 */
	public function register(): void {
		add_action(
			'admin_menu',
			function (): void {
				add_submenu_page(
					SettingsPage::MENU_SLUG,
					__( 'SEO Diagnostics', 'universal-multilingual' ),
					__( 'SEO Diagnostics', 'universal-multilingual' ),
					'manage_options',
					self::SLUG,
					array( $this, 'render' )
				);
			}
		);
	}

	/**
	 * Renders the SF13/SF1 snapshot from the shared core.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'universal-multilingual' ) );
		}

		$path         = '/';
		$url          = '';
		$include_http = true;
		$snapshot     = null;

		if ( isset( $_POST['aiml_seo_diagnostics_run'] ) ) {
			check_admin_referer( 'aiml_seo_diagnostics_run' );
			$path         = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['path'] ) ) : '/';
			$url          = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
			$include_http = ! empty( $_POST['include_http'] );
			$snapshot     = $this->diagnostics->scan(
				new SeoDiagnosticsOptions(
					url: $url,
					path: '' !== $path ? $path : '/',
					include_http: $include_http,
				)
			);
		}

		echo '<div class="wrap aiml-ui">';
		echo '<h1>' . esc_html__( 'SEO Diagnostics', 'universal-multilingual' ) . '</h1>';
		AdminNavigation::render( self::SLUG );
		echo '<p>' . esc_html__(
			'Read-only health checks over A.SEOa–e contracts. This screen does not change SEO output.',
			'universal-multilingual'
		) . '</p>';

		$this->render_form( $path, $url, $include_http );

		if ( $snapshot instanceof SeoDiagnosticsSnapshot ) {
			$this->render_snapshot( $snapshot );
		}

		echo '</div>';
	}

	/**
	 * Renders the invoke form (no SEO evaluation).
	 *
	 * @param string $path         Current path value.
	 * @param string $url          Current URL value.
	 * @param bool   $include_http Whether HTTP checks are enabled.
	 */
	private function render_form( string $path, string $url, bool $include_http ): void {
		echo '<form method="post">';
		wp_nonce_field( 'aiml_seo_diagnostics_run' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="aiml-seo-path">' . esc_html__( 'Unprefixed path', 'universal-multilingual' ) . '</label></th>';
		echo '<td><input class="regular-text" type="text" id="aiml-seo-path" name="path" value="' . esc_attr( $path ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="aiml-seo-url">' . esc_html__( 'Absolute URL (optional)', 'universal-multilingual' ) . '</label></th>';
		echo '<td><input class="regular-text" type="url" id="aiml-seo-url" name="url" value="' . esc_attr( $url ) . '" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Bounded HTTP checks', 'universal-multilingual' ) . '</th>';
		echo '<td><label><input type="checkbox" name="include_http" value="1"' . checked( $include_http, true, false ) . ' /> ';
		echo esc_html__( 'Include redirect / duplicate-title emission checks', 'universal-multilingual' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Run SEO diagnostics', 'universal-multilingual' ), 'primary', 'aiml_seo_diagnostics_run' );
		echo '</form>';
	}

	/**
	 * Renders structured SF13 results without re-evaluating SEO rules.
	 *
	 * @param SeoDiagnosticsSnapshot $snapshot Shared core snapshot.
	 */
	private function render_snapshot( SeoDiagnosticsSnapshot $snapshot ): void {
		$data = $snapshot->to_array();

		echo '<h2>' . esc_html__( 'Health summary', 'universal-multilingual' ) . '</h2>';
		echo '<ul>';
		foreach ( (array) ( $data['summary'] ?? array() ) as $status => $count ) {
			echo '<li><code>' . esc_html( (string) $status ) . '</code>: ' . esc_html( (string) $count ) . '</li>';
		}
		echo '</ul>';

		if ( ! empty( $data['limitations'] ) ) {
			echo '<h2>' . esc_html__( 'Limitations', 'universal-multilingual' ) . '</h2><ul>';
			foreach ( (array) $data['limitations'] as $limitation ) {
				echo '<li><code>' . esc_html( (string) $limitation ) . '</code></li>';
			}
			echo '</ul>';
		}

		echo '<h2>' . esc_html__( 'Checks', 'universal-multilingual' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Ownership', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Code', 'universal-multilingual' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'universal-multilingual' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( (array) ( $data['checks'] ?? array() ) as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) ( $check['id'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $check['status'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $check['ownership'] ?? '' ) ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) ( $check['code'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $check['message'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Machine-readable snapshot (SF13)', 'universal-multilingual' ) . '</h2>';
		echo '<pre style="background:#fff;padding:1em;border:1px solid #ccd0d4;overflow:auto;">';
		echo esc_html( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</pre>';
	}
}
