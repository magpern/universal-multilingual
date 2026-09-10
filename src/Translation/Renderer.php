<?php
/**
 * Front-end translation overlays.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Translation;

use AIMultilingual\Language\LanguageContext;
use WP_Post;

/**
 * Applies translations at render time.
 *
 * Nothing here writes: the canonical post is read, its translation is looked up
 * and the translated string is returned in place of the source. The stored
 * object is never modified, which is the whole point of the overlay model
 * (invariants I1–I3).
 *
 * Two details matter more than they look:
 *
 * `the_content` is hooked at priority 1 because core attaches
 * `apply_block_hooks_to_content_from_post_object` at 8 and `do_blocks` at 9.
 * Substituting after those have run would mean translating already-rendered
 * block output instead of the stored body.
 *
 * That same filter is applied by plugins to arbitrary strings that have nothing
 * to do with the current post — excerpt widgets, meta boxes, page builders. So
 * the overlay only fires when the incoming string is byte-identical to the
 * queried post's raw `post_content`. Anything else passes through untouched.
 */
final class Renderer {

	/**
	 * Request language state.
	 *
	 * @var LanguageContext
	 */
	private LanguageContext $context;

	/**
	 * Segment store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Body classifier for block vs classic content routing.
	 *
	 * @var Extractor|null
	 */
	private ?Extractor $extractor;

	/**
	 * Strategy F gated block renderer.
	 *
	 * @var BlockFrontendRenderer|null
	 */
	private ?BlockFrontendRenderer $block_frontend;

	/**
	 * Guards against a filter re-entering itself.
	 *
	 * @var bool
	 */
	private bool $rendering = false;

	/**
	 * Builds the overlay renderer.
	 *
	 * @param LanguageContext            $context        Request language state.
	 * @param Store                      $store          Segment store.
	 * @param Extractor|null             $extractor      Body classifier.
	 * @param BlockFrontendRenderer|null $block_frontend Strategy F block renderer.
	 */
	public function __construct(
		LanguageContext $context,
		Store $store,
		?Extractor $extractor = null,
		?BlockFrontendRenderer $block_frontend = null,
	) {
		$this->context        = $context;
		$this->store          = $store;
		$this->extractor      = $extractor;
		$this->block_frontend = $block_frontend;
	}

	/**
	 * Registers the overlay filters.
	 *
	 * Filters attach unconditionally and gate themselves at call time, so the
	 * set of registered hooks does not vary by request type.
	 */
	public function register(): void {
		add_filter( 'the_title', array( $this, 'filter_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 1 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 10, 2 );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title' ), 20 );

		// WooCommerce renders the product short description through its own
		// filter on the raw post_excerpt, never get_the_excerpt(), so the
		// generic excerpt overlay above misses it entirely. Priority 1 so the
		// overlay sees the raw excerpt before WooCommerce's wpautop/wptexturize
		// formatting filters (all priority 10+) transform it.
		add_filter( 'woocommerce_short_description', array( $this, 'filter_woocommerce_short_description' ), 1, 1 );
	}

	/**
	 * Translates a post title.
	 *
	 * @param string   $title   Source title.
	 * @param int|null $post_id Post id, when the caller supplied one.
	 */
	public function filter_title( $title, $post_id = null ) {
		if ( ! is_string( $title ) || null === $post_id ) {
			return $title;
		}

		$post_id = (int) $post_id;

		if ( ! $this->should_translate() || $post_id <= 0 ) {
			return $title;
		}

		// A.6 N1: custom nav_menu_item titles overlay via the same post_title
		// Store field as pages/posts. Empty custom titles never reach this
		// filter with the menu-item ID — WordPress uses the linked object ID.

		$translated = $this->lookup( $post_id, Extractor::FIELD_TITLE );

		return null === $translated ? $title : $translated;
	}

	/**
	 * Translates a post body.
	 *
	 * @param string $content Source content.
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || ! $this->should_translate() ) {
			return $content;
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		// Only substitute when this really is the queried post's stored body.
		if ( $content !== $post->post_content ) {
			return $content;
		}

		if ( null !== $this->extractor && null !== $this->block_frontend ) {
			if ( Extractor::BODY_BLOCKS === $this->extractor->body_status( $post ) ) {
				$this->rendering = true;

				try {
					return $this->block_frontend->render( $post, $content );
				} finally {
					$this->rendering = false;
				}
			}
		}

		if ( null !== $this->extractor && ! $this->extractor->can_translate_body( $post ) ) {
			return $content;
		}

		$translated = $this->lookup( (int) $post->ID, Extractor::FIELD_CONTENT );

		return null === $translated ? $content : $translated;
	}

	/**
	 * Translates a post excerpt.
	 *
	 * Only a manually written excerpt is overlaid. An auto-generated excerpt is
	 * derived from the body, which the content filter has already translated.
	 *
	 * @param string       $excerpt Source excerpt.
	 * @param WP_Post|null $post    Post the excerpt belongs to.
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		if ( ! is_string( $excerpt ) || ! $this->should_translate() ) {
			return $excerpt;
		}

		if ( ! $post instanceof WP_Post || '' === trim( (string) $post->post_excerpt ) ) {
			return $excerpt;
		}

		$translated = $this->lookup( (int) $post->ID, Extractor::FIELD_EXCERPT );

		return null === $translated ? $excerpt : $translated;
	}

	/**
	 * Translates the WooCommerce product short description.
	 *
	 * WooCommerce echoes `apply_filters( 'woocommerce_short_description',
	 * $post->post_excerpt )` from its single-product template — it does not go
	 * through `get_the_excerpt`, so {@see self::filter_excerpt()} never sees it.
	 * The stored translation is the same `post_excerpt` field overlay.
	 *
	 * @param mixed $excerpt Raw short description (post_excerpt).
	 * @return mixed
	 */
	public function filter_woocommerce_short_description( $excerpt ) {
		if ( ! is_string( $excerpt ) || ! $this->should_translate() ) {
			return $excerpt;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || 'product' !== (string) $post->post_type ) {
			return $excerpt;
		}

		// Only substitute the queried product's own stored short description.
		if ( '' === trim( (string) $post->post_excerpt ) || $excerpt !== $post->post_excerpt ) {
			return $excerpt;
		}

		$translated = $this->lookup( (int) $post->ID, Extractor::FIELD_EXCERPT );

		return null === $translated ? $excerpt : $translated;
	}

	/**
	 * Translates the document title of a singular view.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function filter_document_title( $parts ) {
		if ( ! is_array( $parts ) || ! isset( $parts['title'] ) || ! $this->should_translate() ) {
			return $parts;
		}

		if ( ! is_singular() ) {
			return $parts;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return $parts;
		}

		$translated = $this->lookup( (int) $post->ID, Extractor::FIELD_TITLE );

		if ( null !== $translated ) {
			$parts['title'] = $translated;
		}

		return $parts;
	}

	/**
	 * Whether overlays should run for this request.
	 */
	private function should_translate(): bool {
		if ( $this->rendering || is_admin() ) {
			return false;
		}

		return $this->context->is_translated();
	}

	/**
	 * Reads one translated field for a post in the current language.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $field_key Field key.
	 */
	private function lookup( int $post_id, string $field_key ): ?string {
		$this->rendering = true;

		try {
			return $this->store->translated_value(
				Store::SOURCE_POST,
				$post_id,
				$this->context->current_id(),
				$field_key
			);
		} finally {
			$this->rendering = false;
		}
	}
}
