<?php
/**
 * Language prefix routing.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Routing;

use AIMultilingual\Language\LanguageContext;
use AIMultilingual\Language\LanguageResolver;
use AIMultilingual\Language\Languages;
use AIMultilingual\Plugin;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Store;

/**
 * Resolves the request language from the URL and keeps generated URLs in that
 * language.
 *
 * Inbound, the language prefix is removed from `REQUEST_URI` before WordPress
 * parses the request. Every rewrite rule the site already has — core's, and any
 * plugin's — then matches unchanged, with no per-language rule duplication and
 * nothing to flush (ADR-0002).
 *
 * MSEO.2 adds localized path recognition, substitution, and outbound admission.
 */
final class Router {

	/**
	 * Language configuration.
	 *
	 * @var Languages
	 */
	private Languages $languages;

	/**
	 * Pure resolver.
	 *
	 * @var LanguageResolver
	 */
	private LanguageResolver $resolver;

	/**
	 * Request language state.
	 *
	 * @var LanguageContext
	 */
	private LanguageContext $context;

	/**
	 * Effective URL authority.
	 *
	 * @var EffectiveUrlService
	 */
	private EffectiveUrlService $effective_url;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Path canonicalizer.
	 *
	 * @var PathCanonicalizer
	 */
	private PathCanonicalizer $paths;

	/**
	 * Route repository.
	 *
	 * @var SlugRouteRepository
	 */
	private SlugRouteRepository $routes;

	/**
	 * Route history repository.
	 *
	 * @var RouteHistoryRepository
	 */
	private RouteHistoryRepository $history;

	/**
	 * Hierarchy / term path authority.
	 *
	 * @var HierarchyPathBuilder|null
	 */
	private ?HierarchyPathBuilder $hierarchy;

	/**
	 * Request-local route recognition context (B1/B6).
	 *
	 * @var RouteRecognitionContext
	 */
	private RouteRecognitionContext $recognition;

	/**
	 * Guards term_link localization against nested get_term_link re-entry (v1.5.1 D1).
	 *
	 * @var bool
	 */
	private bool $filtering_term_link = false;

	/**
	 * Request URI as it arrived, before the prefix was removed.
	 *
	 * @var string
	 */
	private string $original_uri = '';

	/**
	 * Whether this request carried a language prefix.
	 *
	 * @var bool
	 */
	private bool $prefixed = false;

	/**
	 * Site home path, e.g. '' for a root install or '/blog' for a subdirectory.
	 *
	 * @var string|null
	 */
	private ?string $home_path = null;

	/**
	 * Unprefixed path after language strip (before AIML substitution).
	 *
	 * @var string
	 */
	private string $request_unprefixed_path = '/';

	/**
	 * Original query string without leading ?.
	 *
	 * @var string
	 */
	private string $request_query = '';

	/**
	 * Builds the router.
	 *
	 * @param Languages                 $languages     Language configuration.
	 * @param LanguageResolver          $resolver      Pure resolver.
	 * @param LanguageContext           $context       Request language state.
	 * @param EffectiveUrlService       $effective_url Effective URL authority.
	 * @param Settings                  $settings      Plugin settings.
	 * @param PathCanonicalizer         $paths         Path canonicalizer.
	 * @param SlugRouteRepository       $routes        Route repository.
	 * @param RouteHistoryRepository    $history       History repository.
	 * @param HierarchyPathBuilder|null $hierarchy   Hierarchy path builder.
	 */
	public function __construct(
		Languages $languages,
		LanguageResolver $resolver,
		LanguageContext $context,
		EffectiveUrlService $effective_url,
		Settings $settings,
		PathCanonicalizer $paths,
		SlugRouteRepository $routes,
		RouteHistoryRepository $history,
		?HierarchyPathBuilder $hierarchy = null
	) {
		$this->languages     = $languages;
		$this->resolver      = $resolver;
		$this->context       = $context;
		$this->effective_url = $effective_url;
		$this->settings      = $settings;
		$this->paths         = $paths;
		$this->routes        = $routes;
		$this->history       = $history;
		$this->hierarchy     = $hierarchy;
		$this->recognition   = RouteRecognitionContext::none();
	}

	/**
	 * Registers routing hooks.
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'resolve' ), 999 );
	}

	/**
	 * Request-local route recognition context for the current request.
	 */
	public function recognition_context(): RouteRecognitionContext {
		return $this->recognition;
	}

	/**
	 * Resolves the request language and strips the prefix from REQUEST_URI.
	 */
	public function resolve(): void {
		$this->recognition = RouteRecognitionContext::none();
		$this->context->set_default( $this->languages->default() );

		if ( ! $this->should_route() ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$this->original_uri = (string) $uri;

		$path  = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( (string) $uri, PHP_URL_QUERY );

		$this->request_query = $query;

		$resolved = $this->resolver->resolve(
			$this->strip_home_path( $path ),
			$this->languages->all(),
			current_user_can( Plugin::CAPABILITY )
		);

		$this->context->set_current( $resolved['language'] );

		if ( ! $resolved['prefixed'] ) {
			return;
		}

		$this->prefixed = true;

		$unprefixed = '/' . ltrim( (string) $resolved['path'], '/' );
		if ( '' === $unprefixed ) {
			$unprefixed = '/';
		}
		$this->request_unprefixed_path = $unprefixed;

		$language = $this->context->current();
		if ( null === $language ) {
			return;
		}

		if ( $this->recognize_localized_request( $language, $unprefixed, $path, $query ) ) {
			return;
		}

		$this->recognition = new RouteRecognitionContext(
			RouteRecognitionContext::KIND_SOURCE_PATH,
			$path,
			$unprefixed,
			(int) $language->language_id,
			null,
			null,
			null,
			null,
			'' !== $query ? $query : null
		);

		$rebuilt = $this->home_path() . ltrim( $unprefixed, '/' );
		$rebuilt = '/' . ltrim( $rebuilt, '/' );

		if ( '' !== $query ) {
			$rebuilt .= '?' . $query;
		}

		$_SERVER['REQUEST_URI'] = $rebuilt;

		add_filter( 'locale', array( $this, 'filter_locale' ) );
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ) );

		add_action( 'parse_request', array( $this, 'enable_url_prefixing' ), 0 );
	}

	/**
	 * Attempts localized/history recognition; returns true when inbound handling finished.
	 *
	 * @param object $language   Current language row.
	 * @param string $unprefixed Unprefixed path after language strip.
	 * @param string $prefixed   Original prefixed path component.
	 * @param string $query      Query string without ?.
	 */
	private function recognize_localized_request( object $language, string $unprefixed, string $prefixed, string $query ): bool {
		try {
			$canonical = $this->paths->canonicalize( $unprefixed );
		} catch ( InvalidPathException $e ) {
			unset( $e );

			return false;
		}

		$language_id = (int) $language->language_id;
		$route       = $this->routes->find_active_by_localized_path( $language_id, $canonical );
		if ( null !== $route ) {
			$this->recognition = new RouteRecognitionContext(
				RouteRecognitionContext::KIND_CURRENT_LOCALIZED,
				$prefixed,
				$unprefixed,
				$language_id,
				(string) ( $route->source_type ?? '' ),
				(int) ( $route->source_id ?? 0 ),
				(int) ( $route->route_id ?? 0 ),
				null,
				'' !== $query ? $query : null
			);

			if ( ! $this->settings->is_localized_url_generation_enabled() ) {
				$target = (string) ( $route->source_path ?? $unprefixed );
				$this->redirect_and_exit(
					$this->build_prefixed_url( $language, $target, $query ),
					302
				);

				return true;
			}

			// MSEO.4 A5: config-transition recognition — when generation is gated for this
			// object, redirect once to EffectiveUrl (may be source) instead of mass-404.
			if ( $this->should_redirect_current_localized_to_effective( $route, $language_id, $unprefixed ) ) {
				$source_path = (string) ( $route->source_path ?? $unprefixed );
				$effective   = $this->effective_url->unprefixed_effective_path( $source_path, $language_id );
				$destination = $this->build_prefixed_url( $language, $effective, $query );
				$recognized  = $this->build_prefixed_url( $language, $unprefixed, $query );
				if ( ! $this->is_same_normalized_url( $recognized, $destination ) ) {
					$this->redirect_and_exit( $destination, 301 );

					return true;
				}
			}

			$source_path = (string) ( $route->source_path ?? $unprefixed );
			$rebuilt     = $this->home_path() . ltrim( $source_path, '/' );
			$rebuilt     = '/' . ltrim( $rebuilt, '/' );
			if ( '' !== $query ) {
				$rebuilt .= '?' . $query;
			}

			$_SERVER['REQUEST_URI'] = $rebuilt;

			add_filter( 'locale', array( $this, 'filter_locale' ) );
			add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );
			add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ) );
			add_action( 'parse_request', array( $this, 'enable_url_prefixing' ), 0 );

			return true;
		}

		$hist = $this->history->find_by_historical_path( $language_id, $canonical );
		if ( null === $hist ) {
			return false;
		}

		$this->recognition = new RouteRecognitionContext(
			RouteRecognitionContext::KIND_HISTORICAL_LOCALIZED,
			$prefixed,
			$unprefixed,
			$language_id,
			(string) ( $hist->source_type ?? '' ),
			(int) ( $hist->source_id ?? 0 ),
			null,
			(int) ( $hist->history_id ?? 0 ),
			'' !== $query ? $query : null
		);

		if ( $this->settings->is_localized_url_generation_enabled() ) {
			$source_path = $this->source_path_for_object(
				(string) ( $hist->source_type ?? '' ),
				(int) ( $hist->source_id ?? 0 ),
				$language_id
			);
			$effective   = $this->effective_url->unprefixed_effective_path( $source_path, $language_id );
			$destination = $this->build_prefixed_url( $language, $effective, $query );
			$recognized  = $this->build_prefixed_url( $language, $unprefixed, $query );
			if ( ! $this->is_same_normalized_url( $recognized, $destination ) ) {
				$this->redirect_and_exit( $destination, 301 );

				return true;
			}

			// Self-redirect guard: treat as current and continue to source rewrite below.
			$rebuilt = $this->home_path() . ltrim( $source_path, '/' );
			$rebuilt = '/' . ltrim( $rebuilt, '/' );
			if ( '' !== $query ) {
				$rebuilt .= '?' . $query;
			}
			$_SERVER['REQUEST_URI'] = $rebuilt;
			add_filter( 'locale', array( $this, 'filter_locale' ) );
			add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );
			add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ) );
			add_action( 'parse_request', array( $this, 'enable_url_prefixing' ), 0 );

			return true;
		}

		$source_path = $this->source_path_for_object(
			(string) ( $hist->source_type ?? '' ),
			(int) ( $hist->source_id ?? 0 ),
			$language_id
		);
		$this->redirect_and_exit(
			$this->build_prefixed_url( $language, $source_path, $query ),
			302
		);

		return true;
	}

	/**
	 * Starts prefixing generated URLs, once routing is finished.
	 */
	public function enable_url_prefixing(): void {
		add_filter( 'home_url', array( $this, 'filter_home_url' ) );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10, 3 );
	}

	/**
	 * Serves the active language's locale.
	 *
	 * @param string $locale Locale WordPress determined.
	 */
	public function filter_locale( $locale ) {
		$language = $this->context->current();

		if ( null === $language ) {
			return $locale;
		}

		return (string) $language->locale;
	}

	/**
	 * Emits the active language's lang and dir attributes.
	 *
	 * @param string $output Attribute string WordPress built.
	 */
	public function filter_language_attributes( $output ) {
		$language = $this->context->current();

		if ( null === $language ) {
			return $output;
		}

		$attributes = array( 'lang="' . esc_attr( str_replace( '_', '-', (string) $language->locale ) ) . '"' );

		if ( 'rtl' === (string) $language->direction ) {
			$attributes[] = 'dir="rtl"';
		} else {
			$attributes[] = 'dir="ltr"';
		}

		return implode( ' ', $attributes );
	}

	/**
	 * Language-preserving redirect_canonical policy (A.SEOb + MSEO.2 B2/B3).
	 *
	 * @param string|false $redirect_url URL core wants to redirect to.
	 * @return string|false
	 */
	public function filter_redirect_canonical( $redirect_url ) {
		if ( RouteRecognitionContext::KIND_CURRENT_LOCALIZED === $this->recognition->kind() ) {
			return false;
		}

		if (
			RouteRecognitionContext::KIND_SOURCE_PATH === $this->recognition->kind()
			&& $this->prefixed
			&& $this->settings->is_localized_url_generation_enabled()
		) {
			$language = $this->context->current();
			if ( null !== $language && empty( $language->is_default ) ) {
				$localized = $this->maybe_localized_redirect_target( $language, $this->request_unprefixed_path, $this->request_query );
				if ( null !== $localized ) {
					return $localized;
				}
			}
		}

		if ( ! $this->prefixed ) {
			return $redirect_url;
		}

		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return false;
		}

		$language = $this->context->current();
		if ( null === $language || ! empty( $language->is_default ) ) {
			return false;
		}

		// Issue #64: `resolve()` rewrites `REQUEST_URI` to the unprefixed path,
		// so core's own `$redirect_url !== $requested_url` guard compares
		// against the stripped URI and cannot see that the proposed target is
		// the URL the visitor actually requested. The common case is a bare
		// non-default language home (`/sv/`): its front-page canonical is
		// `home_url( '/' )`, which the `home_url` filter turns straight back
		// into `/sv/`, so the 301 loops. Suppress a redirect that leads back to
		// the current request; a canonicalisation to a genuinely different URL
		// (adding a trailing slash, changing scheme, dropping a query) is not
		// matched and still proceeds.
		if ( $this->redirect_leads_to_current_request( $redirect_url ) ) {
			return false;
		}

		$code = (string) $language->code;
		$path = (string) wp_parse_url( $redirect_url, PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );

		$home = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		$home = trim( $home, '/' );
		if ( '' !== $home && 0 === strpos( $path, '/' . $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) + 1 );
			$path = '/' . ltrim( (string) $path, '/' );
		} elseif ( '' !== $home && rtrim( $path, '/' ) === '/' . $home ) {
			$path = '/';
		}

		$prefix        = '/' . $code . '/';
		$path_noslash  = rtrim( $path, '/' );
		$language_root = '/' . $code;
		if ( 0 === strpos( $path, $prefix ) || $language_root === $path_noslash ) {
			return $redirect_url;
		}

		return false;
	}

	/**
	 * Injects the language prefix into generated home URLs.
	 *
	 * @param string $url Fully qualified URL.
	 */
	public function filter_home_url( $url ) {
		if ( OutboundLocalizationSuspender::is_suspended() ) {
			return $url;
		}

		if ( ! is_string( $url ) || ! $this->context->is_translated() ) {
			return $url;
		}

		$language = $this->context->current();
		if ( null === $language ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return $url;
		}

		$url_path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$code     = (string) $language->code;
		$home     = $this->home_path();

		$rest_prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		if ( '' !== $rest_prefix && false !== strpos( $url_path, '/' . $rest_prefix ) ) {
			return $url;
		}

		if ( false !== strpos( $url_path, '/wp-admin' ) || false !== strpos( $url_path, '/wp-login.php' ) ) {
			return $url;
		}

		$relative = $this->strip_home_path( $url_path );

		if ( '/' . $code === $relative || 0 === strpos( $relative, '/' . $code . '/' ) ) {
			return $url;
		}

		if ( $this->should_deny_home_url_localization( $relative, $parts ) ) {
			$prefixed = $home . $code . ( '/' === $relative ? '/' : $relative );
		} else {
			$path_for_url = $this->admit_localized_path( $relative, (int) $language->language_id );
			$prefixed     = $home . $code . ( '/' === $path_for_url ? '/' : $path_for_url );
		}

		$prefixed = '/' . ltrim( $prefixed, '/' );

		$rebuilt = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' ) . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$rebuilt .= ':' . $parts['port'];
		}

		$rebuilt .= $prefixed;

		if ( isset( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$rebuilt .= '#' . $parts['fragment'];
		}

		return $rebuilt;
	}

	/**
	 * Prefixes a same-site URL with the current language without EffectiveUrl localization.
	 *
	 * Used by PreviewService so preview forever remains source-slug (M2AC28).
	 *
	 * @param string $url Fully qualified URL.
	 */
	public function prefix_url_without_localization( string $url ): string {
		if ( ! $this->context->is_translated() ) {
			return $url;
		}

		$language = $this->context->current();
		if ( null === $language ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return $url;
		}

		$url_path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$code     = (string) $language->code;
		$home     = $this->home_path();
		$relative = $this->strip_home_path( $url_path );

		if ( '/' . $code === $relative || 0 === strpos( $relative, '/' . $code . '/' ) ) {
			return $url;
		}

		$prefixed = '/' . ltrim( $home . $code . ( '/' === $relative ? '/' : $relative ), '/' );
		$rebuilt  = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' ) . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$rebuilt .= ':' . $parts['port'];
		}

		$rebuilt .= $prefixed;

		if ( isset( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$rebuilt .= '#' . $parts['fragment'];
		}

		return $rebuilt;
	}

	/**
	 * Localizes outbound term archive URLs when generation + admission allow.
	 *
	 * Nested get_term_link calls (e.g. HierarchyPathBuilder::source_path_for_term)
	 * must observe the WordPress-generated source URL without re-entering
	 * localization — otherwise LU ON + translated context recurses unboundedly.
	 *
	 * @param string   $url      Term link URL.
	 * @param \WP_Term $term    Term.
	 * @param string   $taxonomy Taxonomy slug.
	 */
	public function filter_term_link( $url, $term, $taxonomy ) {
		if ( ! is_string( $url ) || ! $this->context->is_translated() ) {
			return $url;
		}

		if ( OutboundLocalizationSuspender::is_suspended() || $this->filtering_term_link ) {
			return $url;
		}

		if ( ! $this->settings->is_localized_url_generation_enabled() ) {
			return $url;
		}

		$language = $this->context->current();
		if ( null === $language || ! empty( $language->is_default ) ) {
			return $url;
		}

		if ( ! $term instanceof \WP_Term ) {
			$term = get_term( (int) $term, (string) $taxonomy );
		}
		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return $url;
		}

		$this->filtering_term_link = true;
		try {
			$source_path = $this->source_path_for_object( Store::SOURCE_TERM, (int) $term->term_id, (int) $language->language_id );
			if ( '/' === $source_path ) {
				return $url;
			}

			$effective = $this->effective_url->unprefixed_effective_path( $source_path, (int) $language->language_id );
			if ( $effective === $source_path ) {
				// Still prefix with language via home_url path rebuild.
				return $this->build_prefixed_url( $language, $source_path );
			}

			return $this->build_prefixed_url( $language, $effective );
		} finally {
			$this->filtering_term_link = false;
		}
	}

	/**
	 * The request URI exactly as it arrived, prefix included.
	 */
	public function original_uri(): string {
		return $this->original_uri;
	}

	/**
	 * Whether this request carried a language prefix.
	 */
	public function is_prefixed(): bool {
		return $this->prefixed;
	}

	// -- Internals --

	/**
	 * Whether language resolution applies to this request.
	 */
	private function should_route(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $script && in_array( basename( $script ), array( 'wp-login.php', 'wp-register.php', 'xmlrpc.php' ), true ) ) {
			return false;
		}

		$uri         = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rest_prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		if ( '' !== $rest_prefix && false !== strpos( $uri, '/' . $rest_prefix . '/' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Home path with leading and trailing slashes, e.g. '/' or '/blog/'.
	 */
	private function home_path(): string {
		if ( null === $this->home_path ) {
			$path = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
			$path = trim( $path, '/' );

			$this->home_path = '' === $path ? '/' : '/' . $path . '/';
		}

		return $this->home_path;
	}

	/**
	 * Removes the site's home path from a path, leaving a site-relative path.
	 *
	 * @param string $path Absolute path.
	 */
	private function strip_home_path( string $path ): string {
		$home = $this->home_path();

		if ( '/' === $home ) {
			return '' === $path ? '/' : '/' . ltrim( $path, '/' );
		}

		$normalized = '/' . ltrim( $path, '/' );

		if ( 0 === strpos( $normalized, $home ) ) {
			$normalized = '/' . substr( $normalized, strlen( $home ) );
		} elseif ( rtrim( $home, '/' ) === rtrim( $normalized, '/' ) ) {
			$normalized = '/';
		}

		return '' === $normalized ? '/' : $normalized;
	}

	/**
	 * Builds an absolute prefixed URL for one language path.
	 *
	 * @param object $language Language row.
	 * @param string $path     Unprefixed path.
	 * @param string $query    Query string without ?.
	 */
	private function build_prefixed_url( object $language, string $path, string $query = '' ): string {
		$home = trailingslashit( (string) get_option( 'home' ) );
		$code = (string) $language->code;

		if ( ! empty( $language->is_default ) ) {
			$url = $home . ltrim( $path, '/' );
		} elseif ( '/' === $path ) {
			$url = $home . $code . '/';
		} else {
			$url = $home . $code . $path;
		}

		if ( '' !== $query ) {
			$url .= '?' . $query;
		}

		return $url;
	}

	/**
	 * Issues a safe redirect and terminates the request.
	 *
	 * @param string $location Target URL.
	 * @param int    $status   HTTP status code.
	 */
	private function redirect_and_exit( string $location, int $status ): void {
		$sent = wp_safe_redirect( $location, $status, 'AIML' );
		// When a `wp_redirect` filter cancels the redirect (integration tests),
		// do not terminate the PHP process.
		if ( false === $sent ) {
			return;
		}

		exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTTP redirect terminal.
	}

	/**
	 * Resolves source path for a stored object identity.
	 *
	 * @param string $source_type Source type.
	 * @param int    $source_id   Source id.
	 * @param int    $language_id Language id.
	 */
	private function source_path_for_object( string $source_type, int $source_id, int $language_id ): string {
		if ( $source_id <= 0 ) {
			return '/';
		}

		$route = $this->routes->find_by_object( $source_type, $source_id, $language_id );
		if ( null !== $route && '' !== (string) ( $route->source_path ?? '' ) ) {
			return (string) $route->source_path;
		}

		if ( Store::SOURCE_POST === $source_type ) {
			$post = get_post( $source_id );
			if ( $post instanceof \WP_Post ) {
				if ( null !== $this->hierarchy ) {
					$path = $this->hierarchy->source_path_for_post( $post );
					if ( ! $path instanceof \WP_Error ) {
						return $path->to_string();
					}
				}

				return '/' . ltrim( (string) get_page_uri( $post ), '/' );
			}
		}

		if ( Store::SOURCE_TERM === $source_type ) {
			$term = get_term( $source_id );
			if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
				if ( null !== $this->hierarchy ) {
					$path = $this->hierarchy->source_path_for_term( $term );
					if ( ! $path instanceof \WP_Error ) {
						return $path->to_string();
					}
				}

				$link = get_term_link( $term );
				if ( is_string( $link ) && '' !== $link ) {
					$path = (string) wp_parse_url( $link, PHP_URL_PATH );

					return '/' . ltrim( $path, '/' );
				}
			}
		}

		return '/';
	}

	/**
	 * Whether CURRENT_LOCALIZED should redirect to EffectiveUrl (config gate).
	 *
	 * @param object $route       Active route row.
	 * @param int    $language_id Language id.
	 * @param string $unprefixed  Recognized unprefixed path.
	 */
	private function should_redirect_current_localized_to_effective( object $route, int $language_id, string $unprefixed ): bool {
		unset( $unprefixed );
		$source_type = (string) ( $route->source_type ?? '' );
		$source_id   = (int) ( $route->source_id ?? 0 );
		if ( Store::SOURCE_POST !== $source_type || $source_id <= 0 ) {
			return false;
		}

		$post = get_post( $source_id );
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return false;
		}

		$capabilities = new RoutingCapabilityRegistry();
		if ( RoutingCapabilityRegistry::PRODUCT_CATEGORY_PERMALINK !== $capabilities->capability_for_post( $post ) ) {
			return false;
		}

		$admission = new RoutingCapabilityAdmission( $this->settings, $capabilities );

		return ! $admission->is_post_publicly_localizable( $post );
	}

	/**
	 * Self-redirect guard: recognized path equals EffectiveUrl destination.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 */
	private function is_same_normalized_url( string $a, string $b ): bool {
		$na_raw = strtok( $a, '?' );
		$nb_raw = strtok( $b, '?' );
		$na     = untrailingslashit( strtolower( is_string( $na_raw ) ? $na_raw : $a ) );
		$nb     = untrailingslashit( strtolower( is_string( $nb_raw ) ? $nb_raw : $b ) );

		return $na === $nb;
	}

	/**
	 * Whether a proposed canonical redirect target is the URL the visitor
	 * actually requested (issue #64 self-loop guard).
	 *
	 * Compares scheme, host, decoded path (trailing slash is significant) and
	 * the query string against the request exactly as it arrived, before
	 * `resolve()` stripped the language prefix. A redirect to a genuinely
	 * different URL — a missing trailing slash, an http → https upgrade, a
	 * dropped query parameter — does not match and is left to proceed.
	 *
	 * Reads only the request line (`original_uri`, host, scheme); no cookie,
	 * `Accept-Language` or other visitor-specific state (ADR-0024).
	 *
	 * @param string $redirect_url Absolute URL core wants to redirect to.
	 */
	private function redirect_leads_to_current_request( string $redirect_url ): bool {
		if ( '' === $this->original_uri ) {
			return false;
		}

		$target = wp_parse_url( $redirect_url );
		if ( ! is_array( $target ) || ! isset( $target['path'] ) ) {
			return false;
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] )
			? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: '';

		$current = wp_parse_url( $scheme . '://' . $host . $this->original_uri );
		if ( ! is_array( $current ) || ! isset( $current['path'] ) ) {
			return false;
		}

		if ( isset( $target['scheme'] ) && strtolower( (string) $target['scheme'] ) !== $scheme ) {
			return false;
		}

		if ( isset( $target['host'] ) && strtolower( (string) $target['host'] ) !== $host ) {
			return false;
		}

		if ( rawurldecode( (string) $target['path'] ) !== rawurldecode( (string) $current['path'] ) ) {
			return false;
		}

		$target_query  = isset( $target['query'] ) ? (string) $target['query'] : '';
		$current_query = isset( $current['query'] ) ? (string) $current['query'] : '';

		return $target_query === $current_query;
	}

	/**
	 * Returns a localized redirect target when SOURCE_PATH should 301.
	 *
	 * @param object $language       Current language.
	 * @param string $unprefixed     Request unprefixed path.
	 * @param string $query          Query string without ?.
	 */
	private function maybe_localized_redirect_target( object $language, string $unprefixed, string $query ): ?string {
		try {
			$canonical = $this->paths->canonicalize( $unprefixed );
		} catch ( InvalidPathException $e ) {
			unset( $e );

			return null;
		}

		$language_id = (int) $language->language_id;
		$route       = $this->routes->find_active_by_source_path( $language_id, $canonical );
		if ( null === $route ) {
			return null;
		}

		$source_path = (string) ( $route->source_path ?? $unprefixed );
		$effective   = $this->effective_url->unprefixed_effective_path( $source_path, $language_id );
		if ( $effective === $unprefixed ) {
			return null;
		}

		return $this->build_prefixed_url( $language, $effective, $query );
	}

	/**
	 * Whether home_url localization must be denied for this relative path.
	 *
	 * @param string               $relative Site-relative path.
	 * @param array<string, mixed> $parts    Parsed URL parts.
	 */
	private function should_deny_home_url_localization( string $relative, array $parts ): bool {
		if ( '/' === $relative ) {
			return true;
		}

		if ( false !== strpos( $relative, '/wp-content/uploads/' ) ) {
			return true;
		}

		if ( isset( $parts['query'] ) && is_string( $parts['query'] ) ) {
			if ( false !== strpos( $parts['query'], 'add-to-cart=' ) ) {
				return true;
			}
		}

		$deny_fragments = array(
			'/feed',
			'/comments/feed',
			'/cart',
			'/checkout',
			'/my-account',
			'/wp-cron.php',
			'/wp-admin/admin-ajax.php',
		);

		foreach ( $deny_fragments as $fragment ) {
			if ( false !== strpos( $relative, $fragment ) ) {
				return true;
			}
		}

		if ( preg_match( '#/page/\d+/?$#', $relative ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Applies outbound localization admission for an unprefixed path.
	 *
	 * @param string $relative    Site-relative unprefixed path.
	 * @param int    $language_id Current language id.
	 */
	private function admit_localized_path( string $relative, int $language_id ): string {
		if ( ! $this->settings->is_localized_url_generation_enabled() ) {
			return $relative;
		}

		try {
			$canonical = $this->paths->canonicalize( $relative );
		} catch ( InvalidPathException $e ) {
			unset( $e );

			return $relative;
		}

		$as_localized = $this->routes->find_active_by_localized_path( $language_id, $canonical );
		if ( null !== $as_localized ) {
			return $relative;
		}

		$route = $this->routes->find_active_by_source_path( $language_id, $canonical );
		if ( null === $route ) {
			return $relative;
		}

		$source_path = (string) ( $route->source_path ?? $relative );

		return $this->effective_url->unprefixed_effective_path( $source_path, $language_id );
	}
}
