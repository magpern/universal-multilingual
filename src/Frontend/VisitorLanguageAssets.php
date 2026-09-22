<?php
/**
 * Client-side visitor language persistence/detection assets.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Frontend;

use AIMultilingual\Language\LanguageContext;
use AIMultilingual\Language\Languages;
use AIMultilingual\Settings;
use AIMultilingual\User\VisitorLanguageCookie;

/**
 * Enqueues a small, static, equally-cacheable JS bundle that owns every
 * "automatic" anonymous behaviour this milestone adds: writing/reading the
 * `aiml_visitor_lang` cookie, the cookie-driven redirect from an unprefixed
 * URL, and the browser/geo suggestion banner (ADR-0035).
 *
 * This class never reads the visitor-language cookie and never issues one
 * from PHP, and never makes the response depend on visitor state — it only
 * decides, from request-local, URL-derived data that is already identical for every
 * anonymous visitor of a given URL, whether to print a static bundle plus a
 * localized JSON model. That keeps the anonymous HTML response byte-for-byte
 * unchanged for the same URL (ADR-0024) — all visitor-specific branching
 * happens client-side, after the (cached) response has already been served.
 */
final class VisitorLanguageAssets {

	public const SCRIPT_HANDLE = 'aiml-visitor-language';
	public const STYLE_HANDLE  = 'aiml-visitor-language';

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
	 * Authoritative switcher link model (same URLs as the shortcode/nav/floating selector).
	 *
	 * @var Switcher
	 */
	private Switcher $switcher;

	/**
	 * Builds the asset provider.
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
	 * Registers the enqueue hook.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'prepare_and_enqueue' ), 20 );
	}

	/**
	 * Enqueues the bundle and its localized model when eligible.
	 */
	public function prepare_and_enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->settings->visitor_cookie_persist_enabled() && ! $this->settings->visitor_autodetect_enabled() ) {
			return;
		}

		$links = $this->switcher->all_language_links();
		if ( count( $links ) < 2 ) {
			return;
		}

		$base = plugin_dir_url( AIML_PLUGIN_FILE ) . 'assets/frontend/';
		$ver  = AIML_VERSION;

		wp_enqueue_style( self::STYLE_HANDLE, $base . 'visitor-language.css', array(), $ver );
		wp_enqueue_script( self::SCRIPT_HANDLE, $base . 'visitor-language.js', array(), $ver, true );

		$browser_enabled = $this->settings->visitor_autodetect_enabled() && $this->settings->visitor_autodetect_browser_enabled();
		$geo_enabled     = $this->settings->visitor_autodetect_enabled() && $this->settings->visitor_autodetect_geo_enabled();

		$model = array(
			'cookieName'     => VisitorLanguageCookie::COOKIE_NAME,
			'persistEnabled' => $this->settings->visitor_cookie_persist_enabled(),
			'browserEnabled' => $browser_enabled,
			'geoEnabled'     => $geo_enabled,
			'geoRestUrl'     => $geo_enabled ? rest_url( 'universal-geo-context/v1/context' ) : '',
			'geoLanguageMap' => $geo_enabled ? $this->settings->geo_language_map() : array(),
			'isDefaultUrl'   => $this->context->is_default(),
			'currentCode'    => null !== $this->context->current() ? (string) $this->context->current()->code : '',
			'links'          => array_map(
				static function ( array $link ): array {
					return array(
						'code'    => $link['code'],
						'url'     => $link['url'],
						'label'   => $link['label'],
						'current' => $link['current'],
					);
				},
				$links
			),
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'aimlVisitorLanguage', $model );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'aimlVisitorLanguageStrings',
			array(
				'accept'      => __( 'Switch', 'universal-multilingual' ),
				'dismiss'     => __( 'Dismiss', 'universal-multilingual' ),
				'switchTo'    => __( 'Switch to {language}?', 'universal-multilingual' ),
				'regionLabel' => __( 'Language suggestion', 'universal-multilingual' ),
			)
		);
	}
}
