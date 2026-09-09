<?php
/**
 * Converts a Store segment row to / from a PackageSegment (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Pure — no WordPress functions. `$row` is a hydrated `aiml_translations` row
 * (see {@see \AIMultilingual\Translation\Store::load_object()}).
 */
final class SegmentMapper {

	/**
	 * Builds a package segment from a Store row.
	 *
	 * @param object $row           Hydrated aiml_translations row.
	 * @param string $language_code Stable language code for this row's language.
	 */
	public static function from_store_row( object $row, string $language_code ): PackageSegment {
		$segment_key  = (string) $row->segment_key;
		$segment_kind = (string) ( $row->segment_kind ?? 'field' );

		return new PackageSegment(
			(string) $row->field_key,
			$segment_key,
			$segment_kind,
			(string) $row->text_format,
			(int) $row->segment_order,
			$language_code,
			(string) ( $row->source_text ?? '' ),
			(string) ( $row->source_hash ?? '' ),
			(int) ( $row->norm_version ?? 1 ),
			(string) ( $row->translated_text ?? '' ),
			(string) ( $row->translation_hash ?? '' ),
			(string) ( $row->status ?? '' ),
			(string) ( $row->review_status ?? 'not_submitted' ),
			(string) ( $row->publish_status ?? 'unpublished' ),
			(string) ( $row->slug_origin ?? '' ),
			array(
				'provider'       => (string) ( $row->provider ?? '' ),
				'model'          => (string) ( $row->model ?? '' ),
				'prompt_profile' => (string) ( $row->prompt_profile ?? '' ),
				'prompt_version' => (string) ( $row->prompt_version ?? '' ),
			),
			(string) ( $row->updated_at ?? '' ),
			'block' === $segment_kind ? self::block_uuid( $segment_key ) : null
		);
	}

	/**
	 * Extracts the block UUID from a `b:<uuid>:<field>` segment key.
	 *
	 * @param string $segment_key Segment key.
	 */
	public static function block_uuid( string $segment_key ): ?string {
		if ( 1 === preg_match( '/^b:([0-9a-f-]{36}):/i', $segment_key, $matches ) ) {
			return $matches[1];
		}

		return null;
	}
}
