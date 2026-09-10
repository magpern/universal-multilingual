<?php
/**
 * AdminNavigation unit tests (AIT1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Admin;

use AIMultilingual\Admin\AdminNavigation;
use PHPUnit\Framework\TestCase;

/**
 * The shared internal admin navigation model + markup.
 */
final class AdminNavigationTest extends TestCase {

	private const EXPECTED_SLUGS = array(
		'ai-multilingual',
		'aiml-settings',
		'aiml-rollout',
		'aiml-seo-diagnostics',
		'aiml-translate',
		'aiml-translator',
		'aiml-glossary',
		'aiml-promotion',
	);

	public function test_every_expected_destination_is_registered_in_order(): void {
		$this->assertSame( self::EXPECTED_SLUGS, AdminNavigation::slugs() );
	}

	public function test_no_duplicate_slugs(): void {
		$slugs = AdminNavigation::slugs();
		$this->assertSame( $slugs, array_values( array_unique( $slugs ) ) );
	}

	public function test_destinations_carry_a_label_and_a_capability(): void {
		foreach ( AdminNavigation::destinations() as $entry ) {
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertNotSame( '', (string) $entry['label'] );
			$this->assertArrayHasKey( 'capability', $entry );
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', (string) $entry['capability'] );
		}
	}

	public function test_administrator_sees_every_section(): void {
		$items = AdminNavigation::build_items( 'aiml-settings', static fn(): bool => true );
		$this->assertCount( count( self::EXPECTED_SLUGS ), $items );
	}

	public function test_lower_capability_user_only_sees_accessible_sections(): void {
		$allowed = array( 'aiml_translate', 'aiml_workspace_access' );
		$items   = AdminNavigation::build_items(
			'aiml-translator',
			static fn( string $cap ): bool => in_array( $cap, $allowed, true )
		);

		$slugs = array_map( static fn( array $i ): string => $i['slug'], $items );
		$this->assertSame( array( 'aiml-translate', 'aiml-translator' ), $slugs );
		$this->assertNotContains( 'aiml-settings', $slugs );
		$this->assertNotContains( 'aiml-glossary', $slugs );
	}

	public function test_active_page_receives_the_active_state_and_nothing_else(): void {
		$items = AdminNavigation::build_items( 'aiml-glossary', static fn(): bool => true );

		$active = array_values( array_filter( $items, static fn( array $i ): bool => $i['active'] ) );
		$this->assertCount( 1, $active );
		$this->assertSame( 'aiml-glossary', $active[0]['slug'] );
	}

	public function test_urls_use_the_canonical_page_slugs(): void {
		$items = AdminNavigation::build_items( '', static fn(): bool => true );
		foreach ( $items as $item ) {
			$this->assertStringContainsString( 'admin.php?page=' . $item['slug'], $item['url'] );
		}
	}

	public function test_markup_is_escaped_and_marks_the_active_tab_accessibly(): void {
		$html = AdminNavigation::markup(
			array(
				array(
					'slug'   => 'aiml-settings',
					'label'  => 'Settings',
					'url'    => 'https://example.test/wp-admin/admin.php?page=aiml-settings',
					'active' => true,
				),
				array(
					'slug'   => 'aiml-glossary',
					'label'  => 'Glossary & <tags>',
					'url'    => 'https://example.test/wp-admin/admin.php?page=aiml-glossary',
					'active' => false,
				),
			)
		);

		$this->assertStringContainsString( '<nav class="aiml-ui-subnav"', $html );
		$this->assertStringContainsString( 'aiml-ui-subnav__link--active', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( 'Glossary &amp; &lt;tags&gt;', $html );
		$this->assertStringNotContainsString( '<tags>', $html );
		// Exactly one active link.
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
	}

	public function test_build_items_output_is_deterministic(): void {
		$a = AdminNavigation::build_items( 'aiml-settings', static fn(): bool => true );
		$b = AdminNavigation::build_items( 'aiml-settings', static fn(): bool => true );
		$this->assertSame( $a, $b );
	}
}
