<?php
/**
 * Builds valid promotion packages for unit tests (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\ObjectNaturalKey;
use AIMultilingual\Promotion\PackageObject;
use AIMultilingual\Promotion\PackageSegment;
use AIMultilingual\Promotion\TranslationPackage;

/**
 * Deterministic fixtures so a test can assert byte-identical output.
 */
final class PackageFactory {

	/**
	 * A segment with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	public static function segment( array $overrides = array() ): PackageSegment {
		$data = array_merge(
			array(
				'field_key'        => 'post_title',
				'segment_key'      => 'post_title',
				'segment_kind'     => 'field',
				'text_format'      => 'plain',
				'segment_order'    => 0,
				'language_code'    => 'sv',
				'source_text'      => 'Blue Widget',
				'source_hash'      => sha1( 'Blue Widget' ),
				'norm_version'     => 1,
				'translated_text'  => 'Blå pryl',
				'translation_hash' => sha1( 'Blå pryl' ),
				'status'           => 'reviewed',
				'review_status'    => 'approved',
				'publish_status'   => 'published',
				'slug_origin'      => '',
				'provenance'       => array(
					'provider'       => 'openai',
					'model'          => 'gpt',
					'prompt_profile' => 'default',
					'prompt_version' => '1',
				),
				'updated_at'       => '2026-09-01T09:00:00Z',
				'block_uuid'       => null,
			),
			$overrides
		);

		return PackageSegment::from_array( $data );
	}

	/**
	 * An object with one title segment.
	 *
	 * @param string                     $uuid      Object UUID.
	 * @param array<int, PackageSegment> $segments Segments (default: one title).
	 */
	public static function object( string $uuid = '3f2c1a4b-5d6e-4f70-8901-234567890abc', array $segments = array() ): PackageObject {
		if ( array() === $segments ) {
			$segments = array( self::segment() );
		}

		return new PackageObject(
			$uuid,
			'post',
			'product',
			ObjectNaturalKey::for_post( 'product', 'blue-widget' ),
			ObjectNaturalKey::parts( 'post', 'product', 'blue-widget' ),
			array(
				'title_hash'   => sha1( 'Blue Widget' ),
				'slug_hash'    => sha1( 'blue-widget' ),
				'wp_guid_hash' => hash( 'sha256', 'https://dev.example/?p=42' ),
				'sku_hint'     => 'BW-001',
			),
			$segments
		);
	}

	/**
	 * A whole package.
	 *
	 * @param array<int, PackageObject> $objects     Objects (default: one).
	 * @param string                    $package_id  Package UUID.
	 */
	public static function package( array $objects = array(), string $package_id = '11111111-2222-4333-8444-555555555555' ): TranslationPackage {
		if ( array() === $objects ) {
			$objects = array( self::object() );
		}

		return TranslationPackage::create(
			$package_id,
			array(
				'plugin'         => 'universal-multilingual',
				'plugin_version' => '1.14.0',
				'db_version'     => 10,
			),
			array(
				'site_uuid'        => 'site-uuid',
				'home_url_hash'    => hash( 'sha256', 'https://dev.example' ),
				'exported_at'      => '2026-09-09T12:00:00Z',
				'exported_by_hash' => hash( 'sha256', 'admin' ),
			),
			1,
			array(
				array(
					'code'   => 'sv',
					'locale' => 'sv_SE',
					'name'   => 'Swedish',
					'status' => 'published',
				),
			),
			array(
				'field_keys'             => array( 'post_title', 'post_content' ),
				'segment_key_namespaces' => array( '', 'm:rank-math' ),
				'source_types'           => array( 'post', 'term' ),
			),
			$objects
		);
	}
}
