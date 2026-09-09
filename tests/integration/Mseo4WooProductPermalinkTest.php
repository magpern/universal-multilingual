<?php
/**
 * MSEO.4 WooCommerce localized product permalink hardening tests.
 *
 * @package AIMultilingual
 */

declare(strict_types=1);

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Jobs\WooProductRouteReindexJob;
use AIMultilingual\Routing\FrontierRecord;
use AIMultilingual\Routing\ReindexFrontierRepository;
use AIMultilingual\Routing\RoutingCapabilityAdmission;
use AIMultilingual\Routing\RoutingCapabilityRegistry;
use AIMultilingual\Routing\WooProductCategoryAuthority;
use AIMultilingual\Routing\WooProductPathBuilder;
use AIMultilingual\Routing\WooProductPermalinkFingerprint;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Store;
use WP_Post;
use WP_Term;

/**
 * Focused MSEO.4 coverage for Woo authority, fingerprint, frontier, admission.
 */
final class Mseo4WooProductPermalinkTest extends AimlTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_permalink_structure( '/%postname%/' );
	}

	protected function tearDown(): void {
		$this->set_permalink_structure( '' );
		parent::tearDown();
	}

	public function test_target_remains_eight(): void {
		$this->assertSame( 10, Migrator::TARGET );
	}

	public function test_capability_epoch_is_two(): void {
		$this->assertSame( 2, RoutingCapabilityAdmission::CODE_CAPABILITY_EPOCH );
		$this->assertContains(
			RoutingCapabilityAdmission::SHAPE_PRODUCT_CATEGORY_PERMALINK,
			RoutingCapabilityAdmission::CODE_SHAPES
		);
	}

	public function test_product_category_permalink_implemented_not_admitted_by_default(): void {
		$this->set_woo_product_base( '/shop/%product_cat%' );

		$registry = new RoutingCapabilityRegistry();
		$this->assertFalse( $registry->is_plain_product_permalink() );

		$product_id = $this->create_simple_product( 'Red Shoes MSEO4' );
		$post       = get_post( $product_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame(
			RoutingCapabilityRegistry::PRODUCT_CATEGORY_PERMALINK,
			$registry->capability_for_post( $post )
		);
		$this->assertTrue( $registry->supports_post( $post ) );

		$settings  = new Settings(
			array(
				'localized_urls_state'                   => 'on',
				'localized_urls_admitted_capabilities'   => array(),
				'localized_urls_woo_product_fingerprint' => '',
			)
		);
		$admission = new RoutingCapabilityAdmission( $settings, $registry );
		$this->assertFalse(
			$admission->is_publicly_admitted( RoutingCapabilityAdmission::SHAPE_PRODUCT_CATEGORY_PERMALINK )
		);
		$this->assertFalse( $admission->is_post_publicly_localizable( $post ) );
	}

	public function test_admission_requires_fingerprint_match(): void {
		$this->set_woo_product_base( '/shop/%product_cat%' );

		$fingerprint = ( new WooProductPermalinkFingerprint() )->hash();
		$settings    = new Settings(
			array(
				'localized_urls_state'                     => 'on',
				'localized_urls_admitted_capabilities'     => array(
					RoutingCapabilityAdmission::SHAPE_PRODUCT_CATEGORY_PERMALINK,
				),
				'localized_urls_verified_capability_epoch' => 2,
				'localized_urls_woo_product_fingerprint'   => $fingerprint,
			)
		);
		update_option( Settings::OPTION, $settings->get() );

		$registry   = new RoutingCapabilityRegistry();
		$admission  = new RoutingCapabilityAdmission( $settings, $registry );
		$product_id = $this->create_simple_product( 'Fingerprint Gate Product' );
		$post       = get_post( $product_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertTrue( $admission->is_post_publicly_localizable( $post ) );

		$settings->save(
			Settings::sanitize(
				array_merge(
					$settings->get(),
					array( 'localized_urls_woo_product_fingerprint' => str_repeat( 'a', 64 ) )
				)
			)
		);
		$settings->reload();
		$admission = new RoutingCapabilityAdmission( $settings, $registry );
		$this->assertFalse( $admission->is_post_publicly_localizable( $post ) );
	}

	public function test_fingerprint_changes_with_product_base(): void {
		$this->set_woo_product_base( '/shop/%product_cat%' );
		$a = ( new WooProductPermalinkFingerprint() )->hash();

		$this->set_woo_product_base( '/products/%product_cat%' );
		$b = ( new WooProductPermalinkFingerprint() )->hash();
		$this->assertNotSame( $a, $b );

		// category_base alone must not alter route-semantic product fingerprint.
		update_option(
			'woocommerce_permalinks',
			array_merge(
				(array) get_option( 'woocommerce_permalinks', array() ),
				array(
					'product_base'  => '/products/%product_cat%',
					'category_base' => 'katalog',
				)
			)
		);
		$c = ( new WooProductPermalinkFingerprint() )->hash();
		$this->assertSame( $b, $c );
	}

	public function test_category_authority_removes_capture_filter(): void {
		$this->set_woo_product_base( '/shop/%product_cat%' );

		$term = wp_insert_term( 'Shoes Cap ' . wp_generate_password( 4, false ), 'product_cat' );
		$this->assertIsArray( $term );
		$product_id = $this->create_simple_product( 'Cap Cleanup Product' );
		wp_set_object_terms( $product_id, array( (int) $term['term_id'] ), 'product_cat' );
		$post = get_post( $product_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$authority = new WooProductCategoryAuthority();
		$resolved  = $authority->resolve( $post );
		$this->assertIsArray( $resolved );
		$this->assertFalse( has_filter( 'wc_product_post_type_link_product_cat' ) );

		$threw = false;
		add_filter(
			'post_type_link',
			static function () {
				throw new \RuntimeException( 'forced permalink failure' );
			},
			1
		);
		try {
			$authority->resolve( $post );
		} catch ( \Throwable $e ) {
			$threw = true;
			unset( $e );
		}
		remove_all_filters( 'post_type_link' );
		$this->assertTrue( $threw );
		$this->assertFalse( has_filter( 'wc_product_post_type_link_product_cat' ) );
	}

	public function test_path_builder_outcome_constants_exist(): void {
		$this->assertSame( 'synchronized', WooProductPathBuilder::OUTCOME_SYNCHRONIZED );
		$this->assertSame( 'source_fallback_authority_disagreement', WooProductPathBuilder::OUTCOME_SOURCE_FALLBACK_AUTHORITY_DISAGREE );
		$this->assertSame( 'source_fallback_nondeterministic_filter', WooProductPathBuilder::OUTCOME_SOURCE_FALLBACK_NONDETERMINISTIC );
		$this->assertSame( 'source_fallback_missing_component', WooProductPathBuilder::OUTCOME_SOURCE_FALLBACK_MISSING_COMPONENT );
	}

	public function test_frontier_claim_isolation_by_type(): void {
		$frontiers = new ReindexFrontierRepository();

		$frontiers->upsert_checkpoint(
			new FrontierRecord( Store::SOURCE_POST, 900001, wp_json_encode( array( 'mode' => 'bfs' ) ), 1, 'pending' )
		);
		$frontiers->upsert_checkpoint(
			new FrontierRecord( WooProductRouteReindexJob::TYPE_PRODUCT_DEP, 900002, wp_json_encode( array( 'mode' => 'dependent_products' ) ), 1, 'pending' )
		);

		$hierarchy = $frontiers->find_workable( array( Store::SOURCE_POST, Store::SOURCE_TERM ) );
		$this->assertNotNull( $hierarchy );
		$this->assertSame( Store::SOURCE_POST, (string) $hierarchy->parent_source_type );

		$woo = $frontiers->find_workable(
			array( WooProductRouteReindexJob::TYPE_PRODUCT_DEP, WooProductRouteReindexJob::TYPE_WOO_CONFIG )
		);
		$this->assertNotNull( $woo );
		$this->assertSame( WooProductRouteReindexJob::TYPE_PRODUCT_DEP, (string) $woo->parent_source_type );

		$this->assertSame( 100, WooProductRouteReindexJob::MAX_PER_TICK );
		$this->assertSame( 1, WooProductRouteReindexJob::CONFIG_ROOT_ID );
	}

	public function test_woo_job_rejects_hierarchy_frontier_types(): void {
		$frontiers = new ReindexFrontierRepository();
		$frontiers->upsert_checkpoint(
			new FrontierRecord( Store::SOURCE_TERM, 900003, wp_json_encode( array( 'mode' => 'bfs' ) ), 1, 'pending' )
		);

		$woo = $frontiers->find_workable(
			array( WooProductRouteReindexJob::TYPE_PRODUCT_DEP, WooProductRouteReindexJob::TYPE_WOO_CONFIG )
		);
		$this->assertNull( $woo );
	}

	public function test_get_permalink_source_authority_shape(): void {
		$this->set_woo_product_base( '/shop/%product_cat%' );
		$term = wp_insert_term( 'Boots Src ' . wp_generate_password( 4, false ), 'product_cat' );
		$this->assertIsArray( $term );
		$product_id = $this->create_simple_product( 'Source Authority Product' );
		wp_set_object_terms( $product_id, array( (int) $term['term_id'] ), 'product_cat' );
		$post = get_post( $product_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$permalink = get_permalink( $post );
		$this->assertIsString( $permalink );
		$this->assertNotSame( '', $permalink );

		// Pretty permalinks required for path-shape characterization.
		$path_part  = (string) wp_parse_url( $permalink, PHP_URL_PATH );
		$query_part = (string) wp_parse_url( $permalink, PHP_URL_QUERY );
		if ( '' !== $query_part || '/' === $path_part || '' === $path_part ) {
			$this->markTestSkipped( 'Pretty Woo product permalinks unavailable in this harness state.' );
		}

		$authority = new WooProductCategoryAuthority();
		$resolved  = $authority->resolve( $post );
		$this->assertIsArray( $resolved );
		$path = $resolved['source_path']->to_string();
		$this->assertNotSame( '/', $path );
		$this->assertStringContainsString( (string) $post->post_name, $path );

		$selected = $resolved['selected'];
		if ( $selected instanceof WP_Term ) {
			$this->assertSame( (int) $term['term_id'], (int) $selected->term_id );
		}
	}

	/**
	 * Sets Woo product permalink base for tests.
	 *
	 * @param string $product_base Product base including optional %product_cat%.
	 */
	private function set_woo_product_base( string $product_base ): void {
		$current = (array) get_option( 'woocommerce_permalinks', array() );
		update_option(
			'woocommerce_permalinks',
			array_merge(
				$current,
				array(
					'product_base' => $product_base,
				)
			)
		);
		update_option(
			'woocommerce_permalink_structure',
			array(
				'product_base' => $product_base,
			)
		);
		if ( class_exists( '\WC_Post_Types', false ) && method_exists( '\WC_Post_Types', 'register_post_type' ) ) {
			\WC_Post_Types::register_post_types();
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Creates a simple published product.
	 *
	 * @param string $title Product title.
	 */
	private function create_simple_product( string $title ): int {
		if ( class_exists( \WC_Product_Simple::class ) ) {
			$product = new \WC_Product_Simple();
			$product->set_name( $title );
			$product->set_status( 'publish' );
			$product->set_regular_price( '10' );
			$product->save();

			return (int) $product->get_id();
		}

		$id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ),
				'post_type'   => 'product',
				'post_status' => 'publish',
			),
			true
		);
		$this->assertIsInt( $id );

		return $id;
	}
}
