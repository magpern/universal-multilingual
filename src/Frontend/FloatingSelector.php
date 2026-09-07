<?php
/**
 * Optional floating language selector.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Frontend;

use AIMultilingual\Language\LanguageContext;
use AIMultilingual\Language\Languages;
use AIMultilingual\Settings;
use WP_Error;

/**
 * Presentation consumer of the existing Switcher / SB11 language-link model.
 *
 * Does not construct localized URLs, resolve language, or write cookies.
 */
final class FloatingSelector {

	public const AJAX_ACTION   = 'aiml_floating_selector_prefer';
	public const SCRIPT_HANDLE = 'aiml-floating-selector';
	public const STYLE_HANDLE  = 'aiml-floating-selector';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Language configuration.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Request language state.
	 *
	 * @var LanguageContext
	 */
	private LanguageContext $context;

	/**
	 * Authoritative switcher link model.
	 *
	 * @var Switcher
	 */
	private Switcher $switcher;

	/**
	 * Whether {@see self::prepare_and_enqueue()} has run for this request.
	 *
	 * @var bool
	 */
	private bool $prepared = false;

	/**
	 * Request-local presentation model; null when ineligible.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $model = null;

	/**
	 * Builds the floating selector.
	 *
	 * @param Settings        $settings  Plugin settings.
	 * @param Languages       $languages Language configuration.
	 * @param LanguageContext $context   Request language state.
	 * @param Switcher        $switcher  Authoritative link model.
	 */
	public function __construct(
		Settings $settings,
		Languages $languages,
		LanguageContext $context,
		Switcher $switcher
	) {
		$this->settings  = $settings;
		$this->languages = $languages;
		$this->context   = $context;
		$this->switcher  = $switcher;
	}

	/**
	 * Registers enqueue, footer render, and authenticated preference AJAX.
	 */
	public function register(): void {
		/**
		 * Prepare the request-local selector model and enqueue assets when eligible.
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_and_enqueue' ), 20 );

		/**
		 * Print already-prepared selector markup; do not rebuild URLs.
		 */
		add_action( 'wp_footer', array( $this, 'render' ), 20 );

		/**
		 * Authenticated preferred-language persist. No nopriv counterpart.
		 */
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_prefer' ) );
	}

	/**
	 * Prepares the request-local model once and enqueues assets when eligible.
	 */
	public function prepare_and_enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$this->ensure_model();

		if ( null === $this->model ) {
			return;
		}

		$base = plugin_dir_url( AIML_PLUGIN_FILE ) . 'assets/frontend/';
		$ver  = AIML_VERSION;

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . 'floating-selector.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$base . 'floating-selector.js',
			array(),
			$ver,
			true
		);

		$persist = ! empty( $this->model['persist'] );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'aimlFloatingSelector',
			array(
				'ajaxUrl' => $persist ? admin_url( 'admin-ajax.php' ) : '',
				'action'  => self::AJAX_ACTION,
				'nonce'   => $persist ? wp_create_nonce( self::AJAX_ACTION ) : '',
				'persist' => $persist,
			)
		);
	}

	/**
	 * Prints markup for the already-prepared model. Does not rebuild URLs.
	 */
	public function render(): void {
		if ( ! $this->prepared || null === $this->model ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes.
		echo $this->markup( $this->model );
	}

	/**
	 * Request-local model, or null when the selector must not render.
	 *
	 * @return array<string, mixed>|null
	 */
	public function prepared_model(): ?array {
		$this->ensure_model();

		return $this->model;
	}

	/**
	 * Authenticated AJAX: best-effort preferred-language persist.
	 */
	public function ajax_prefer(): void {
		$result = $this->handle_prefer_request();
		if ( is_wp_error( $result ) ) {
			$status = (int) $result->get_error_data();
			wp_send_json_error(
				array( 'code' => $result->get_error_code() ),
				$status > 0 ? $status : 400
			);
		}

		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * Validates and persists preferred language for the current user only.
	 *
	 * @return true|WP_Error
	 */
	public function handle_prefer_request() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'aiml_forbidden', '', 403 );
		}

		if ( false === check_ajax_referer( self::AJAX_ACTION, '_ajax_nonce', false ) ) {
			return new WP_Error( 'aiml_invalid_nonce', '', 403 );
		}

		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
		if ( '' === $code ) {
			return new WP_Error( 'aiml_invalid_language', '', 400 );
		}

		if ( ! function_exists( 'aiml_set_preferred_language' ) ) {
			return new WP_Error( 'aiml_unavailable', '', 400 );
		}

		$result = aiml_set_preferred_language( get_current_user_id(), $code );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), '', 400 );
		}

		return true;
	}

	/**
	 * Builds or returns the request-local model.
	 */
	private function ensure_model(): void {
		if ( $this->prepared ) {
			return;
		}

		$this->prepared = true;
		$this->model    = $this->build_model();
	}

	/**
	 * Builds the presentation model, or null when ineligible.
	 *
	 * @return array<string, mixed>|null
	 */
	private function build_model(): ?array {
		if ( ! $this->settings->floating_selector_enabled() ) {
			return null;
		}

		$show_desktop = $this->settings->floating_selector_show_desktop();
		$show_mobile  = $this->settings->floating_selector_show_mobile();
		if ( ! $show_desktop && ! $show_mobile ) {
			return null;
		}

		$links = $this->switcher->all_language_links();
		if ( count( $links ) < 2 ) {
			return null;
		}

		$current = $this->current_link( $links );
		if ( null === $current ) {
			return null;
		}

		$persist = $this->settings->floating_selector_persist_preference() && is_user_logged_in();

		return array(
			'links'        => $links,
			'current'      => $current,
			'side'         => $this->settings->floating_selector_side(),
			'vertical'     => $this->settings->floating_selector_vertical(),
			'collapsed'    => $this->settings->floating_selector_collapsed(),
			'preset'       => $this->settings->floating_selector_preset(),
			'show_desktop' => $show_desktop,
			'show_mobile'  => $show_mobile,
			'persist'      => $persist,
		);
	}

	/**
	 * Current link from the request/URL relationship model.
	 *
	 * @param array<int, array{code: string, label: string, url: string, hreflang: string, current: bool}> $links Link model.
	 * @return array{code: string, label: string, url: string, hreflang: string, current: bool}|null
	 */
	private function current_link( array $links ): ?array {
		foreach ( $links as $link ) {
			if ( ! empty( $link['current'] ) ) {
				return $link;
			}
		}

		$current = $this->context->current();
		$code    = null !== $current ? (string) $current->code : '';
		if ( '' === $code ) {
			$default = $this->languages->default();
			$code    = null !== $default ? (string) $default->code : '';
		}

		foreach ( $links as $link ) {
			if ( $link['code'] === $code ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Semantic disclosure markup. URLs come from the cached Switcher model.
	 *
	 * @param array<string, mixed> $model Prepared model.
	 */
	private function markup( array $model ): string {
		$links   = $model['links'];
		$current = $model['current'];
		$side    = (string) $model['side'];
		$classes = array(
			'aiml-floating-selector',
			'aiml-floating-selector--side-' . $side,
			'aiml-floating-selector--vertical-' . (string) $model['vertical'],
			'aiml-floating-selector--preset-' . (string) $model['preset'],
			'aiml-floating-selector--collapsed-' . (string) $model['collapsed'],
		);
		if ( empty( $model['show_mobile'] ) ) {
			$classes[] = 'aiml-floating-selector--hide-mobile';
		}
		if ( empty( $model['show_desktop'] ) ) {
			$classes[] = 'aiml-floating-selector--hide-desktop';
		}

		$panel_id = 'aiml-floating-selector-panel';
		$out      = sprintf(
			'<nav class="%1$s" aria-label="%2$s" data-um-edge-control="language" data-um-edge="%3$s" data-um-edge-slot="1" data-um-edge-priority="10">',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr__( 'Language', 'universal-multilingual' ),
			esc_attr( $side )
		);

		$out .= sprintf(
			'<button type="button" class="aiml-floating-selector__toggle" hidden aria-expanded="false" aria-controls="%1$s">%2$s</button>',
			esc_attr( $panel_id ),
			$this->collapsed_inner( $current, (string) $model['collapsed'] )
		);

		$out .= sprintf( '<ul id="%s" class="aiml-floating-selector__panel">', esc_attr( $panel_id ) );
		foreach ( $links as $link ) {
			$is_current = ! empty( $link['current'] );
			$out       .= sprintf(
				'<li class="aiml-floating-selector__item%1$s"><a class="aiml-floating-selector__link" href="%2$s" hreflang="%3$s" lang="%3$s" data-aiml-code="%4$s"%5$s>%6$s%7$s</a></li>',
				$is_current ? ' is-current' : '',
				esc_url( (string) $link['url'] ),
				esc_attr( (string) $link['hreflang'] ),
				esc_attr( (string) $link['code'] ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( (string) $link['label'] ),
				$is_current ? '<span class="aiml-floating-selector__check" aria-hidden="true"></span>' : ''
			);
		}
		$out .= '</ul></nav>';

		return $out;
	}

	/**
	 * Collapsed control contents. Globe always includes accessible language text.
	 *
	 * @param array  $current Current link.
	 * @param string $mode    Collapsed mode.
	 */
	private function collapsed_inner( array $current, string $mode ): string {
		$code  = $this->presentation_code( (string) $current['code'] );
		$label = (string) $current['label'];

		if ( 'name' === $mode ) {
			return '<span class="aiml-floating-selector__label">' . esc_html( $label ) . '</span>';
		}

		if ( 'globe' === $mode ) {
			return $this->globe_svg()
				. '<span class="aiml-floating-selector__sr">' . esc_html( $label ) . '</span>';
		}

		return '<span class="aiml-floating-selector__code" aria-hidden="true">' . esc_html( $code ) . '</span>'
			. '<span class="aiml-floating-selector__sr">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Presentation-normalized language code (not a hard-coded language list).
	 *
	 * @param string $code Language code from configuration.
	 */
	private function presentation_code( string $code ): string {
		return strtoupper( str_replace( '_', '-', $code ) );
	}

	/**
	 * Decorative globe icon; accessible name is provided separately.
	 */
	private function globe_svg(): string {
		return '<svg class="aiml-floating-selector__globe" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
			. '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.75"/>'
			. '<path fill="none" stroke="currentColor" stroke-width="1.75" d="M3 12h18M12 3c2.5 3 3.5 6 3.5 9s-1 6-3.5 9c-2.5-3-3.5-6-3.5-9s1-6 3.5-9z"/>'
			. '</svg>';
	}
}
