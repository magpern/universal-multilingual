<?php
/**
 * Deterministic, environment-independent natural key (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\ObjectNaturalKey;
use PHPUnit\Framework\TestCase;

/**
 * The key is fixed per type and never depends on the local database.
 */
final class ObjectNaturalKeyTest extends TestCase {

	public function test_non_hierarchical_post(): void {
		$this->assertSame(
			'post|type=post|slug=hello-world',
			ObjectNaturalKey::for_post( 'post', 'hello-world' )
		);
	}

	public function test_product(): void {
		$this->assertSame(
			'post|type=product|slug=blue-widget',
			ObjectNaturalKey::for_post( 'product', 'blue-widget' )
		);
	}

	public function test_hierarchical_page_uses_ancestor_chain(): void {
		$this->assertSame(
			'post|type=page|ancestors=company/team|slug=leadership',
			ObjectNaturalKey::for_post( 'page', 'leadership', array( 'company', 'team' ), true )
		);
	}

	public function test_hierarchical_without_ancestors_is_flat(): void {
		$this->assertSame(
			'post|type=page|slug=about',
			ObjectNaturalKey::for_post( 'page', 'about', array(), true )
		);
	}

	public function test_term_with_ancestors(): void {
		$this->assertSame(
			'term|taxonomy=product_cat|ancestors=clothing/tops|slug=t-shirts',
			ObjectNaturalKey::for_term( 'product_cat', 't-shirts', array( 'clothing', 'tops' ) )
		);
	}

	public function test_deep_hierarchy_exceeds_255_bytes_and_stays_intact(): void {
		$ancestors = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$ancestors[] = 'level-' . $i . '-with-a-longish-slug-segment';
		}

		$key = ObjectNaturalKey::for_post( 'page', 'final-leaf-page', $ancestors, true );

		$this->assertGreaterThan( 255, strlen( $key ) );
		$this->assertStringStartsWith( 'post|type=page|ancestors=level-0-', $key );
		$this->assertStringEndsWith( '|slug=final-leaf-page', $key );
		// Same inputs ⇒ same key.
		$this->assertSame( $key, ObjectNaturalKey::for_post( 'page', 'final-leaf-page', $ancestors, true ) );
	}

	public function test_environment_independence_casing_and_whitespace(): void {
		$a = ObjectNaturalKey::for_post( 'Post', '  Hello-World  ' );
		$b = ObjectNaturalKey::for_post( 'post', 'hello-world' );

		$this->assertSame( $b, $a );
	}

	public function test_parts_are_structured(): void {
		$parts = ObjectNaturalKey::parts( 'post', 'page', 'leadership', array( 'company', 'team' ) );

		$this->assertSame(
			array(
				'kind'           => 'post',
				'type'           => 'page',
				'slug'           => 'leadership',
				'ancestor_slugs' => array( 'company', 'team' ),
			),
			$parts
		);
	}
}
