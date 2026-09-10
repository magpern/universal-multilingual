<?php
/**
 * FieldLabelResolver unit tests (RVQ1 / WP1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Workspace;

use AIMultilingual\Workspace\FieldLabelResolver;
use PHPUnit\Framework\TestCase;

/**
 * Server-authoritative human labels for review/workspace field rows.
 */
final class FieldLabelResolverTest extends TestCase {

	private FieldLabelResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new FieldLabelResolver();
	}

	public function test_core_title_field(): void {
		$this->assertSame( 'Title', $this->resolver->label( 'post_title', 'post_title', 'page' ) );
	}

	public function test_excerpt_is_product_aware(): void {
		$this->assertSame( 'Short description', $this->resolver->label( 'post_excerpt', 'post_excerpt', 'product' ) );
		$this->assertSame( 'Excerpt', $this->resolver->label( 'post_excerpt', 'post_excerpt', 'page' ) );
	}

	public function test_content_is_product_aware(): void {
		$this->assertSame( 'Description', $this->resolver->label( 'post_content', 'post_content', 'product' ) );
		$this->assertSame( 'Content', $this->resolver->label( 'post_content', 'post_content', 'post' ) );
	}

	public function test_woocommerce_attribute_key_becomes_readable(): void {
		$this->assertSame(
			'Attribute: Strength',
			$this->resolver->label( '', 'pwoocommerce:product:3602:attribute_name:strength', 'product' )
		);
	}

	public function test_integration_supplied_label_wins(): void {
		$this->assertSame(
			'Checkout: Company',
			$this->resolver->label( 'whatever', 'x:y:z', 'product', 'Checkout: Company' )
		);
	}

	public function test_block_and_elementor_keys(): void {
		$this->assertSame( 'Content block', $this->resolver->label( '', 'b:uuid:content', 'page' ) );
		$this->assertSame( 'Elementor content', $this->resolver->label( '', 'e:d:1:2:title', 'page' ) );
	}

	public function test_unknown_key_is_humanized(): void {
		$this->assertSame( 'Some Meta Field', $this->resolver->label( 'some_meta_field', 'some_meta_field', 'post' ) );
	}

	public function test_object_noun(): void {
		$this->assertSame( 'Product', $this->resolver->object_noun( 'product' ) );
		$this->assertSame( 'Page', $this->resolver->object_noun( 'page' ) );
		$this->assertSame( 'Post', $this->resolver->object_noun( 'anything_else' ) );
	}
}
