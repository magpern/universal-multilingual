<?php
/**
 * TI.7 SEO / Woo acceptance — ownership and non-mutation contracts.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Database\Migrator;
use AIMultilingual\Settings;
use AIMultilingual\Translation\Assessment\AssessmentAssembler;
use AIMultilingual\Translation\Publication\PublicationAuditLogger;
use AIMultilingual\Translation\Publication\PublicationPolicy;
use AIMultilingual\Translation\Publication\PublicationService;
use AIMultilingual\Translation\Store;

/**
 * Segment publication must not redefine language SEO ownership or mutate Woo economics.
 */
final class PublicationSeoWooAcceptanceTest extends AimlTestCase {

	public function test_no_second_seo_emitter_pipeline_under_publication(): void {
		$root = dirname( __DIR__, 2 );
		$hits = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src/Translation/Publication' ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			foreach ( array( 'hreflang', 'sitemap', 'og:title', 'twitter:card', 'rel="canonical"' ) as $needle ) {
				if ( false !== stripos( $code, $needle ) ) {
					$hits[] = $file->getFilename() . ':' . $needle;
				}
			}
		}
		$this->assertSame( array(), $hits, 'Publication package must not implement SEO emitters.' );
	}

	public function test_language_status_axis_unchanged_by_segment_publish(): void {
		$language = $this->add_language( 'de', 'de_DE', \AIMultilingual\Language\Languages::STATUS_PUBLISHED );
		$post     = $this->create_page();
		$key      = 'seo-lang-axis';

		$this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'post_title',
				'segment_key'     => $key,
				'source_text'     => 'Hello',
				'translated_text' => 'Hallo',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
				'review_status'   => Store::REVIEW_APPROVED,
				'provider'        => 'openai',
				'model'           => 'gpt-test',
				'prompt_profile'  => 'default',
				'prompt_version'  => '1',
			)
		);

		$publication = new PublicationService(
			$this->store,
			new AssessmentAssembler(),
			new PublicationPolicy(),
			new PublicationAuditLogger(),
			new Settings()
		);
		$result      = $publication->publish(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			$key,
			false,
			1,
			'manual'
		);
		$this->assertIsArray( $result );

		$fresh = $this->languages->find( (int) $language->language_id );
		$this->assertNotNull( $fresh );
		$this->assertSame( \AIMultilingual\Language\Languages::STATUS_PUBLISHED, (string) $fresh->status );
		$this->assertSame( 9, Migrator::TARGET );
	}

	public function test_publication_does_not_mutate_post_status_or_meta_price_stock(): void {
		$post = $this->create_page();
		update_post_meta( $post->ID, '_price', '19.99' );
		update_post_meta( $post->ID, '_stock', '5' );
		update_post_meta( $post->ID, '_catalog_visibility', 'visible' );

		$language = $this->add_language();
		$key      = 'woo-safe';
		$this->store->save_translation(
			array(
				'source_type'     => Store::SOURCE_POST,
				'source_id'       => (int) $post->ID,
				'language_id'     => (int) $language->language_id,
				'field_key'       => 'post_title',
				'segment_key'     => $key,
				'source_text'     => 'Product',
				'translated_text' => 'Produkt',
				'status'          => Store::STATUS_MACHINE_TRANSLATED,
				'review_status'   => Store::REVIEW_APPROVED,
				'provider'        => 'openai',
				'model'           => 'gpt-test',
				'prompt_profile'  => 'default',
				'prompt_version'  => '1',
			)
		);

		$publication = new PublicationService(
			$this->store,
			new AssessmentAssembler(),
			new PublicationPolicy(),
			new PublicationAuditLogger(),
			new Settings()
		);
		$publication->publish(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			$key,
			false,
			1,
			'manual'
		);
		$publication->unpublish(
			Store::SOURCE_POST,
			(int) $post->ID,
			(int) $language->language_id,
			$key,
			1
		);

		$fresh = get_post( $post->ID );
		$this->assertInstanceOf( \WP_Post::class, $fresh );
		$this->assertSame( 'publish', $fresh->post_status );
		$this->assertSame( '19.99', (string) get_post_meta( $post->ID, '_price', true ) );
		$this->assertSame( '5', (string) get_post_meta( $post->ID, '_stock', true ) );
		$this->assertSame( 'visible', (string) get_post_meta( $post->ID, '_catalog_visibility', true ) );
	}

	public function test_integration_bridge_uses_overlay_eligibility(): void {
		$bridge = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Integration/IntegrationFrontendBridge.php'
		);
		$this->assertStringContainsString(
			'Store::is_publicly_overlay_eligible',
			$bridge,
			'IntegrationFrontendBridge must consume the central publication gate.'
		);
	}
}
