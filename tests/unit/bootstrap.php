<?php
/**
 * Unit test bootstrap: composer autoloader only, WordPress is not loaded.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error stub for unit tests without WordPress bootstrap.
	 */
	class WP_Error {
		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}

		/**
		 * @param mixed $data Error data.
		 */
		public function add_data( $data ): void {
			$this->data = $data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Post', false ) ) {
	/**
	 * Minimal WP_Post stub for unit tests.
	 */
	class WP_Post {
		/** @var int */
		public $ID = 0;

		/** @var string */
		public $post_type = 'post';

		/** @var string */
		public $post_title = '';

		/** @var string */
		public $post_excerpt = '';

		/** @var string */
		public $post_content = '';

		/** @var string */
		public $post_status = 'publish';
	}
}

if ( ! class_exists( 'WP_Term', false ) ) {
	/**
	 * Minimal WP_Term stub for unit tests.
	 */
	class WP_Term {
		/** @var int */
		public $term_id = 0;

		/** @var string */
		public $taxonomy = '';

		/** @var string */
		public $name = '';

		/** @var string */
		public $slug = '';

		/** @var string */
		public $description = '';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data    Data to encode.
	 * @param int   $options json_encode options.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Deterministic jitter stub for unit tests.
	 *
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 */
	function wp_rand( $min = 0, $max = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature mirrors wp_rand().
		return (int) $min;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Hook arguments.
	 */
	function do_action( $hook, ...$args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * No-op WordPress hook registration for unit tests.
	 *
	 * Must not invoke the callback: real WordPress stores it for later
	 * `do_action` delivery. Calling it here breaks constructors that
	 * register methods with required arguments (e.g. BlockMetricsAggregator).
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 * @return true
	 */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Text.
	 */
	function wp_strip_all_tags( $text ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Unit stub of wp_strip_all_tags itself.
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url Raw URL.
	 */
	function esc_url( $url ) {
		return str_replace( array( '"', "'", '<', '>', ' ' ), array( '%22', '%27', '%3C', '%3E', '%20' ), (string) $url );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Text.
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function esc_attr__( $text, $domain = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Raw key.
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path Admin-relative path.
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	/**
	 * @param string $handle Handle.
	 * @param string $status Status.
	 */
	function wp_style_is( $handle, $status = 'enqueued' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return false;
	}
}

if ( ! isset( $GLOBALS['aiml_unit_filters'] ) ) {
	$GLOBALS['aiml_unit_filters'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $accepted Accepted args.
	 * @return true
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$GLOBALS['aiml_unit_filters'][ (string) $hook ][ (int) $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook Hook.
	 * @param mixed  $value Value.
	 * @param mixed  ...$args Extra args.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) {
		$hook = (string) $hook;
		if ( empty( $GLOBALS['aiml_unit_filters'][ $hook ] ) ) {
			return $value;
		}
		ksort( $GLOBALS['aiml_unit_filters'][ $hook ] );
		foreach ( $GLOBALS['aiml_unit_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * @param string $hook Hook.
	 */
	function remove_all_filters( $hook ): bool {
		unset( $GLOBALS['aiml_unit_filters'][ (string) $hook ] );
		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * @param string         $hook              Hook.
	 * @param callable|false $function_to_check Optional callback.
	 * @return bool|int
	 */
	function has_filter( $hook, $function_to_check = false ) {
		$hook = (string) $hook;
		if ( empty( $GLOBALS['aiml_unit_filters'][ $hook ] ) ) {
			return false;
		}
		if ( false === $function_to_check ) {
			return true;
		}
		foreach ( $GLOBALS['aiml_unit_filters'][ $hook ] as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( $callback === $function_to_check ) {
					return (int) $priority;
				}
			}
		}
		return false;
	}
}

if ( ! isset( $GLOBALS['aiml_unit_posts'] ) ) {
	$GLOBALS['aiml_unit_posts'] = array();
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @param int|WP_Post|null $post Post id or object.
	 * @return WP_Post|null
	 */
	function get_post( $post = null ) {
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		$id = (int) $post;
		if ( $id <= 0 ) {
			return null;
		}
		$found = $GLOBALS['aiml_unit_posts'][ $id ] ?? null;
		return $found instanceof WP_Post ? $found : null;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	/**
	 * @param int|WP_Post $post Post.
	 * @return int|false
	 */
	function wp_is_post_revision( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	/**
	 * @param int|WP_Post $post Post.
	 * @return int|false
	 */
	function wp_is_post_autosave( $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return false;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $cap     Capability.
	 * @param mixed  ...$args Extra args.
	 */
	function user_can( $user_id, $cap, ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return ! empty( $GLOBALS['aiml_unit_user_can'][ (int) $user_id ][ (string) $cap ] );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $cap Capability.
	 * @param mixed  ...$args Extra args.
	 */
	function current_user_can( $cap, ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return ! empty( $GLOBALS['aiml_unit_current_user_can'][ (string) $cap ] );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option         Option name.
	 * @param mixed  $default_value  Default.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['aiml_unit_options'][ (string) $option ] ?? $default_value;
	}
}

if ( ! isset( $GLOBALS['aiml_unit_object_cache'] ) ) {
	$GLOBALS['aiml_unit_object_cache'] = array();
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @param bool   $force Unused.
	 * @param bool   $found Whether the key was present.
	 * @return mixed
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$slot  = $GLOBALS['aiml_unit_object_cache'][ (string) $group ][ (string) $key ] ?? null;
		$found = null !== $slot;
		return $found ? $slot : false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param string $group Cache group.
	 * @param int    $ttl   Unused.
	 */
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$GLOBALS['aiml_unit_object_cache'][ (string) $group ][ (string) $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 */
	function wp_cache_delete( $key, $group = '' ): bool {
		unset( $GLOBALS['aiml_unit_object_cache'][ (string) $group ][ (string) $key ] );
		return true;
	}
}

if ( ! isset( $GLOBALS['aiml_unit_terms'] ) ) {
	$GLOBALS['aiml_unit_terms'] = array();
}

if ( ! function_exists( 'get_term' ) ) {
	/**
	 * @param int|WP_Term $term     Term id or object.
	 * @param string      $taxonomy Taxonomy.
	 * @return WP_Term|null
	 */
	function get_term( $term, $taxonomy = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $term instanceof WP_Term ) {
			return $term;
		}
		$found = $GLOBALS['aiml_unit_terms'][ (int) $term ] ?? null;
		return $found instanceof WP_Term ? $found : null;
	}
}

if ( ! isset( $GLOBALS['aiml_unit_taxonomies'] ) ) {
	$GLOBALS['aiml_unit_taxonomies'] = array();
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	/**
	 * @param string $taxonomy Taxonomy slug.
	 * @return object|false
	 */
	function get_taxonomy( $taxonomy ) {
		$found = $GLOBALS['aiml_unit_taxonomies'][ (string) $taxonomy ] ?? null;
		return is_array( $found ) ? (object) $found : false;
	}
}

if ( ! isset( $GLOBALS['aiml_unit_wc_attribute_taxonomies'] ) ) {
	$GLOBALS['aiml_unit_wc_attribute_taxonomies'] = array();
}

if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
	/**
	 * @return array<int, object>
	 */
	function wc_get_attribute_taxonomies() {
		return array_map(
			static function ( $attribute ) {
				return is_array( $attribute ) ? (object) $attribute : $attribute;
			},
			(array) $GLOBALS['aiml_unit_wc_attribute_taxonomies']
		);
	}
}

if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
	/**
	 * @param string $name Attribute name.
	 */
	function wc_attribute_taxonomy_name( $name ): string {
		return 'pa_' . (string) $name;
	}
}

if ( ! function_exists( 'wc_get_page_id' ) ) {
	/**
	 * @param string $page Page slug.
	 */
	function wc_get_page_id( $page ): int {
		return (int) ( $GLOBALS['aiml_unit_wc_pages'][ (string) $page ] ?? 0 );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string   $type Type (mysql / timestamp).
	 * @param int|bool $gmt  GMT flag.
	 */
	function current_time( $type, $gmt = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return 'mysql' === $type ? '2026-08-12 12:00:00' : time();
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Current user id stub.
	 */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['aiml_unit_current_user_id'] ?? 0 );
	}
}

// Shared unit-test fixtures (not *Test.php, so not autoloaded by the runner).
require_once __DIR__ . '/Promotion/PackageFactory.php';
