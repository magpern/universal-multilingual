<?php
/**
 * Human-readable field labels for review/workspace surfaces (RVQ1).
 *
 * Server-authoritative: the label produced here is what the UI renders. The
 * client keeps only a tiny generic fallback for the case where no label was
 * sent, so the two mappings cannot drift.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Workspace;

use AIMultilingual\Translation\Extractor;

/**
 * Maps a stable segment identity to a reviewer-friendly label.
 */
final class FieldLabelResolver {

	/**
	 * Returns the object-type noun used in object-level review wording.
	 *
	 * @param string $post_type Canonical post type.
	 * @return string One of "Product", "Page", "Post".
	 */
	public function object_noun( string $post_type ): string {
		switch ( $post_type ) {
			case 'product':
				return __( 'Product', 'universal-multilingual' );
			case 'page':
				return __( 'Page', 'universal-multilingual' );
			default:
				return __( 'Post', 'universal-multilingual' );
		}
	}

	/**
	 * Resolves a human label for one segment.
	 *
	 * @param string $field_key       Logical field key (Store column).
	 * @param string $segment_key     Stable segment identity.
	 * @param string $post_type       Canonical post type.
	 * @param string $assembled_label Optional label already produced by an
	 *                                integration extractor (authoritative when set).
	 * @return string
	 */
	public function label( string $field_key, string $segment_key, string $post_type, string $assembled_label = '' ): string {
		$assembled_label = trim( $assembled_label );
		if ( '' !== $assembled_label ) {
			return $assembled_label;
		}

		$is_product = 'product' === $post_type;

		switch ( $field_key ) {
			case Extractor::FIELD_TITLE:
				return __( 'Title', 'universal-multilingual' );
			case Extractor::FIELD_EXCERPT:
				return $is_product
					? __( 'Short description', 'universal-multilingual' )
					: __( 'Excerpt', 'universal-multilingual' );
			case Extractor::FIELD_CONTENT:
				return $is_product
					? __( 'Description', 'universal-multilingual' )
					: __( 'Content', 'universal-multilingual' );
			case Extractor::FIELD_SLUG:
				return __( 'URL slug', 'universal-multilingual' );
		}

		$attribute = $this->woocommerce_attribute_label( $segment_key );
		if ( '' !== $attribute ) {
			return $attribute;
		}

		if ( 0 === strpos( $segment_key, 'b:' ) ) {
			return __( 'Content block', 'universal-multilingual' );
		}

		if ( 0 === strpos( $segment_key, 'e:' ) ) {
			return __( 'Elementor content', 'universal-multilingual' );
		}

		return $this->humanize( '' !== $field_key ? $field_key : $segment_key );
	}

	/**
	 * Extracts "Attribute: Strength" style labels from a WooCommerce segment key.
	 *
	 * @param string $segment_key Segment identity.
	 * @return string Empty when the key is not a WooCommerce attribute key.
	 */
	private function woocommerce_attribute_label( string $segment_key ): string {
		if ( ! preg_match( '/(?:variation_)?attribute_name:([^:]+)$/', $segment_key, $matches ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: WooCommerce product attribute name */
			__( 'Attribute: %s', 'universal-multilingual' ),
			$this->humanize( $matches[1] )
		);
	}

	/**
	 * Turns a machine key into title-case words.
	 *
	 * @param string $value Raw key.
	 * @return string
	 */
	private function humanize( string $value ): string {
		$value = str_replace( array( '_', '-', ':' ), ' ', $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
		if ( '' === $value ) {
			return __( 'Segment', 'universal-multilingual' );
		}

		return ucwords( $value );
	}
}
