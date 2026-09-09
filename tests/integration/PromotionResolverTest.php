<?php
/**
 * ObjectIdentityResolver — UUID-first, read-only cross-environment matching (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\ObjectIdentityRepository;
use AIMultilingual\Promotion\ObjectIdentityResolver;
use AIMultilingual\Promotion\ObjectNaturalKey;
use AIMultilingual\Promotion\PackageObject;
use AIMultilingual\Promotion\ResolvedObject;

/**
 * The resolver never writes; the UUID mapping wins once it exists.
 */
final class PromotionResolverTest extends AimlTestCase {

	private ObjectIdentityRepository $identities;
	private ObjectIdentityResolver $identity_resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->identities        = new ObjectIdentityRepository();
		$this->identity_resolver = new ObjectIdentityResolver( $this->identities );
	}

	public function test_unsupported_subtype_is_blocked(): void {
		$object = $this->post_object( 'nav_menu_item', 'anything', 'aaaaaaaa-1111-4111-8111-111111111111' );

		$result = $this->identity_resolver->resolve( $object );

		$this->assertSame( 'unsupported_type', $result->category );
		$this->assertFalse( $result->is_matched() );
	}

	public function test_natural_key_bootstrap_matches_and_proposes_adoption_without_writing(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_name'  => 'hello-promotion',
				'post_title' => 'Hello',
			)
		);

		$before = $this->identities->count();

		$object = $this->post_object( 'post', 'hello-promotion', 'bbbbbbbb-2222-4222-8222-222222222222' );
		$result = $this->identity_resolver->resolve( $object );

		$this->assertSame( $post_id, $result->local_source_id );
		$this->assertSame( ResolvedObject::MATCH_NATURAL_KEY, $result->match_method );
		$this->assertSame( ResolvedObject::ACTION_ADOPT_INCOMING, $result->identity_action );
		$this->assertSame( $before, $this->identities->count(), 'resolve() must not insert an identity row' );
	}

	public function test_ambiguous_natural_key_is_blocked(): void {
		global $wpdb;

		// WordPress uniquifies published slugs on insert, so force a genuine
		// same-slug collision the way a real environment could reach one.
		$a = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'dup-slug',
				'post_status' => 'draft',
			)
		);
		$b = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_name'   => 'dup-slug-tmp',
				'post_status' => 'draft',
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'dup-slug' ), array( 'ID' => $b ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( $a );
		clean_post_cache( $b );

		$result = $this->identity_resolver->resolve( $this->post_object( 'post', 'dup-slug', 'cccccccc-3333-4333-8333-333333333333' ) );

		$this->assertSame( 'ambiguous_source', $result->category );
	}

	public function test_no_match_is_missing_source(): void {
		$result = $this->identity_resolver->resolve( $this->post_object( 'post', 'nothing-here-at-all', 'dddddddd-4444-4444-8444-444444444444' ) );

		$this->assertSame( 'missing_source', $result->category );
	}

	public function test_uuid_pass_is_authoritative(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type' => 'post',
				'post_name' => 'joined-post',
			)
		);
		$uuid    = 'eeeeeeee-5555-4555-8555-555555555555';
		$this->identities->adopt_uuid( 'post', $post_id, 'post', $uuid, ObjectNaturalKey::for_post( 'post', 'joined-post' ) );

		$result = $this->identity_resolver->resolve( $this->post_object( 'post', 'joined-post', $uuid ) );

		$this->assertSame( $post_id, $result->local_source_id );
		$this->assertSame( ResolvedObject::MATCH_UUID, $result->match_method );
		$this->assertSame( ResolvedObject::ACTION_NONE, $result->identity_action );
	}

	public function test_uuid_pass_survives_a_rename_and_notes_the_divergence(): void {
		$original = self::factory()->post->create(
			array(
				'post_type' => 'post',
				'post_name' => 'old-slug',
			)
		);
		$other    = self::factory()->post->create(
			array(
				'post_type' => 'post',
				'post_name' => 'new-slug',
			)
		);
		$uuid     = 'ffffffff-6666-4666-8666-666666666666';
		$this->identities->adopt_uuid( 'post', $original, 'post', $uuid, ObjectNaturalKey::for_post( 'post', 'old-slug' ) );

		// The package was built after DEV renamed the object; its natural key now
		// points at a *different* local object.
		$object = $this->post_object( 'post', 'new-slug', $uuid );
		$result = $this->identity_resolver->resolve( $object );

		$this->assertSame( $original, $result->local_source_id, 'UUID mapping must win over a diverged natural key' );
		$this->assertSame( ResolvedObject::MATCH_UUID, $result->match_method );
		$this->assertNotEmpty( $result->notes );
		$this->assertStringContainsStringIgnoringCase( 'divergence', $result->notes[0] );
		$this->assertNotSame( $other, $result->local_source_id );
	}

	public function test_uuid_pass_conflicts_when_mapped_source_is_gone(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type' => 'post',
				'post_name' => 'doomed',
			)
		);
		$uuid    = '10101010-7777-4777-8777-777777777777';
		$this->identities->adopt_uuid( 'post', $post_id, 'post', $uuid, ObjectNaturalKey::for_post( 'post', 'doomed' ) );

		wp_delete_post( $post_id, true );

		$result = $this->identity_resolver->resolve( $this->post_object( 'post', 'doomed', $uuid ) );

		$this->assertSame( 'identity_conflict', $result->category );
	}

	public function test_bootstrap_conflicts_when_local_object_carries_a_different_uuid(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type' => 'post',
				'post_name' => 'already-joined',
			)
		);
		$this->identities->adopt_uuid( 'post', $post_id, 'post', 'aaaa1111-8888-4888-8888-888888888888', ObjectNaturalKey::for_post( 'post', 'already-joined' ) );

		// A different package (different UUID) tries to bootstrap onto the same
		// local object by natural key.
		$result = $this->identity_resolver->resolve( $this->post_object( 'post', 'already-joined', 'bbbb2222-9999-4999-8999-999999999999' ) );

		$this->assertSame( 'identity_conflict', $result->category );
	}

	public function test_term_resolution_by_ancestor_path(): void {
		$parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'slug'     => 'guides',
				'name'     => 'Guides',
			)
		);
		$child  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'slug'     => 'setup',
				'name'     => 'Setup',
				'parent'   => $parent,
			)
		);

		$object = new PackageObject(
			'cccc3333-0000-4000-8000-000000000000',
			'term',
			'category',
			ObjectNaturalKey::for_term( 'category', 'setup', array( 'guides' ) ),
			ObjectNaturalKey::parts( 'term', 'category', 'setup', array( 'guides' ) ),
			array(),
			array()
		);

		$result = $this->identity_resolver->resolve( $object );

		$this->assertSame( $child, $result->local_source_id );
		$this->assertSame( ResolvedObject::MATCH_NATURAL_KEY, $result->match_method );
	}

	/**
	 * A package object for a post-type object with one throwaway title segment.
	 *
	 * @param string $post_type Post type / subtype.
	 * @param string $slug      Raw slug.
	 * @param string $uuid      Object UUID.
	 */
	private function post_object( string $post_type, string $slug, string $uuid ): PackageObject {
		return new PackageObject(
			$uuid,
			'post',
			$post_type,
			ObjectNaturalKey::for_post( $post_type, $slug ),
			ObjectNaturalKey::parts( 'post', $post_type, $slug ),
			array(),
			array()
		);
	}
}
