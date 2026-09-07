<?php
/**
 * Language switcher.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Frontend;

use AIMultilingual\Language\LanguageContext;
use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use AIMultilingual\Seo\LanguageRelationshipService;
use AIMultilingual\Settings;

/**
 * Renders links to the current page in each available language.
 *
 * URLs come from the SB11 LanguageRelationshipService so Switcher UX and SEO
 * head stay aligned. Preview languages appear only for capable viewers.
 */
final class Switcher {

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
	 * SB11 relationship contract.
	 *
	 * @var LanguageRelationshipService
	 */
	private LanguageRelationshipService $relationships;

	/**
	 * Builds the switcher renderer.
	 *
	 * @param Settings                    $settings       Plugin settings.
	 * @param Languages                   $languages      Language configuration.
	 * @param LanguageContext             $context        Request language state.
	 * @param LanguageRelationshipService $relationships  SB11 contract.
	 */
	public function __construct(
		Settings $settings,
		Languages $languages,
		LanguageContext $context,
		LanguageRelationshipService $relationships
	) {
		$this->settings      = $settings;
		$this->languages     = $languages;
		$this->context       = $context;
		$this->relationships = $relationships;
	}

	/**
	 * Registers the shortcode and the optional menu integration.
	 */
	public function register(): void {
		add_shortcode( 'aiml_switcher', array( $this, 'shortcode' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'filter_nav_menu_items' ), 10, 2 );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'show' => 'auto' ),
			is_array( $atts ) ? $atts : array(),
			'aiml_switcher'
		);

		return $this->render( 'auto' === $atts['show'] ? null : 'native' === $atts['show'] );
	}

	/**
	 * Appends the switcher to a nav menu when the theme location opts in.
	 *
	 * @param string $items Menu item markup.
	 * @param object $args  Menu arguments.
	 */
	public function filter_nav_menu_items( $items, $args = null ) {
		if ( ! is_string( $items ) || ! is_object( $args ) ) {
			return $items;
		}

		/**
		 * Filters whether the language switcher is appended to a nav menu.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $show Whether to append the switcher. Default false.
		 * @param object $args wp_nav_menu() arguments.
		 */
		if ( ! apply_filters( 'aiml_switcher_in_menu', false, $args ) ) {
			return $items;
		}

		foreach ( $this->links() as $link ) {
			$items .= sprintf(
				'<li class="menu-item aiml-switcher__menu-item%1$s"><a href="%2$s" hreflang="%3$s" lang="%3$s">%4$s</a></li>',
				$link['current'] ? ' current-menu-item' : '',
				esc_url( $link['url'] ),
				esc_attr( $link['hreflang'] ),
				esc_html( $link['label'] )
			);
		}

		return $items;
	}

	/**
	 * Renders the switcher markup.
	 *
	 * @param bool|null $native Force native names on or off; null uses the setting.
	 */
	public function render( ?bool $native = null ): string {
		$links = $this->links( $native );

		if ( count( $links ) < 2 ) {
			return '';
		}

		$out = '<ul class="aiml-switcher">';

		foreach ( $links as $link ) {
			$out .= sprintf(
				'<li class="aiml-switcher__item%1$s"><a href="%2$s" hreflang="%3$s" lang="%3$s">%4$s</a></li>',
				$link['current'] ? ' is-current' : '',
				esc_url( $link['url'] ),
				esc_attr( $link['hreflang'] ),
				esc_html( $link['label'] )
			);
		}

		return $out . '</ul>';
	}

	/**
	 * Builds switcher links, applying `switcher_hide_current` when enabled.
	 *
	 * @param bool|null $native Force native names on or off; null uses the setting.
	 * @return array<int, array{code: string, label: string, url: string, hreflang: string, current: bool}>
	 */
	public function links( ?bool $native = null ): array {
		return $this->collect_links( $native, $this->settings->switcher_hide_current() );
	}

	/**
	 * Authoritative language-link model for every presentation surface.
	 *
	 * Same Switcher / SB11 / SA7 URLs as {@see self::links()}, without applying
	 * `switcher_hide_current`. The floating selector must keep the current
	 * language visible.
	 *
	 * @param bool|null $native Force native names on or off; null uses the setting.
	 * @return array<int, array{code: string, label: string, url: string, hreflang: string, current: bool}>
	 */
	public function all_language_links( ?bool $native = null ): array {
		return $this->collect_links( $native, false );
	}

	/**
	 * Collects relationship-backed language links.
	 *
	 * @param bool|null $native       Force native names on or off; null uses the setting.
	 * @param bool      $hide_current Whether to omit the current language.
	 * @return array<int, array{code: string, label: string, url: string, hreflang: string, current: bool}>
	 */
	private function collect_links( ?bool $native, bool $hide_current ): array {
		$use_native  = null === $native ? $this->settings->switcher_show_native_name() : $native;
		$can_preview = current_user_can( Plugin::CAPABILITY );
		$path        = $this->relationships->current_unprefixed_path();

		$by_code = array();
		foreach ( $this->relationships->for_path( $path, $can_preview ) as $rel ) {
			$by_code[ $rel->language_code ] = $rel;
		}

		$links = array();
		foreach ( $this->languages->routable( $can_preview ) as $language ) {
			$code = (string) $language->code;
			$rel  = $by_code[ $code ] ?? null;
			if ( null === $rel ) {
				continue;
			}

			if ( $rel->is_current && $hide_current ) {
				continue;
			}

			$label = $use_native && '' !== (string) $language->native_name
				? (string) $language->native_name
				: (string) $language->name;

			$links[] = array(
				'code'     => $rel->language_code,
				'label'    => $label,
				'url'      => $rel->url,
				'hreflang' => $rel->hreflang,
				'current'  => $rel->is_current,
			);
		}

		return $links;
	}
}
