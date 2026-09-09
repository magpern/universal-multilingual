<?php
/**
 * Milestone-1 promotion scope (ADR-0030 §9).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\PromotionScope;
use PHPUnit\Framework\TestCase;

/**
 * The scope list is the boundary between promoted and reported-as-unsupported.
 */
final class PromotionScopeTest extends TestCase {

	public function test_supported_post_subtypes(): void {
		$this->assertTrue( PromotionScope::subtype_supported( 'post', 'post' ) );
		$this->assertTrue( PromotionScope::subtype_supported( 'post', 'page' ) );
		$this->assertTrue( PromotionScope::subtype_supported( 'post', 'product' ) );
	}

	public function test_deferred_post_subtypes(): void {
		$this->assertFalse( PromotionScope::subtype_supported( 'post', 'nav_menu_item' ) );
		$this->assertFalse( PromotionScope::subtype_supported( 'post', 'product_variation' ) );
	}

	public function test_supported_and_deferred_taxonomies(): void {
		$this->assertTrue( PromotionScope::subtype_supported( 'term', 'product_cat' ) );
		$this->assertTrue( PromotionScope::subtype_supported( 'term', 'category' ) );
		$this->assertFalse( PromotionScope::subtype_supported( 'term', 'pa_color' ) );
	}

	public function test_unknown_source_type(): void {
		$this->assertFalse( PromotionScope::subtype_supported( 'menu', 'primary' ) );
	}

	public function test_segment_key_support(): void {
		$this->assertTrue( PromotionScope::segment_key_supported( 'post_title', 'post_title' ) );
		$this->assertTrue( PromotionScope::segment_key_supported( 'content', 'b:3f2c1a4b-5d6e-4f70-8901-234567890abc:content' ) );
		$this->assertTrue( PromotionScope::segment_key_supported( '_meta', 'm:rank-math:rank_math_title' ) );
		$this->assertFalse( PromotionScope::segment_key_supported( '_elementor', 'p:elementor:widget:1:text' ) );
		$this->assertFalse( PromotionScope::segment_key_supported( '_meta', 'm:acme:custom_field' ) );
	}

	public function test_capabilities_descriptor_shape(): void {
		$caps = PromotionScope::capabilities();

		$this->assertArrayHasKey( 'field_keys', $caps );
		$this->assertArrayHasKey( 'segment_key_namespaces', $caps );
		$this->assertContains( 'post', $caps['source_types'] );
		$this->assertContains( 'term', $caps['source_types'] );
	}
}
