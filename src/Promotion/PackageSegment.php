<?php
/**
 * One translated segment inside a promotion package (ADR-0030).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Promotion;

/**
 * Immutable value object. Pure — no WordPress functions.
 */
final class PackageSegment {

	/**
	 * Builds an immutable segment.
	 *
	 * @param string               $field_key        Logical field.
	 * @param string               $segment_key       Addressable segment key.
	 * @param string               $segment_kind      field|block.
	 * @param string               $text_format       plain|html|json|code|slug.
	 * @param int                  $segment_order     Document order.
	 * @param string               $language_code     Stable language code.
	 * @param string               $source_text       Source text at export time.
	 * @param string               $source_hash       sha1(normalize(source_text, format)).
	 * @param int                  $norm_version      Normalization algorithm version.
	 * @param string               $translated_text   Translated text.
	 * @param string               $translation_hash  sha1(translated_text).
	 * @param string               $status            Provenance status.
	 * @param string               $review_status     Review axis.
	 * @param string               $publish_status    Publication axis.
	 * @param string               $slug_origin       Slug origin marker.
	 * @param array<string,string> $provenance Provider/model/prompt provenance.
	 * @param string               $updated_at        Source row updated timestamp.
	 * @param string|null          $block_uuid   aimlBlockId when kind=block.
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly string $segment_key,
		public readonly string $segment_kind,
		public readonly string $text_format,
		public readonly int $segment_order,
		public readonly string $language_code,
		public readonly string $source_text,
		public readonly string $source_hash,
		public readonly int $norm_version,
		public readonly string $translated_text,
		public readonly string $translation_hash,
		public readonly string $status,
		public readonly string $review_status,
		public readonly string $publish_status,
		public readonly string $slug_origin,
		public readonly array $provenance,
		public readonly string $updated_at,
		public readonly ?string $block_uuid = null
	) {
	}

	/**
	 * Segment hash: sha1(field_key . "\x1f" . segment_key), mirroring
	 * Store::segment_hash().
	 */
	public function segment_hash(): string {
		return sha1( $this->field_key . "\x1f" . $this->segment_key );
	}

	/**
	 * Array form for serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'field_key'        => $this->field_key,
			'segment_key'      => $this->segment_key,
			'segment_kind'     => $this->segment_kind,
			'text_format'      => $this->text_format,
			'segment_order'    => $this->segment_order,
			'language_code'    => $this->language_code,
			'source_text'      => $this->source_text,
			'source_hash'      => $this->source_hash,
			'norm_version'     => $this->norm_version,
			'translated_text'  => $this->translated_text,
			'translation_hash' => $this->translation_hash,
			'status'           => $this->status,
			'review_status'    => $this->review_status,
			'publish_status'   => $this->publish_status,
			'slug_origin'      => $this->slug_origin,
			'provenance'       => $this->provenance,
			'updated_at'       => $this->updated_at,
			'block_uuid'       => $this->block_uuid,
		);
	}

	/**
	 * Rebuilds a segment from its array form.
	 *
	 * @param array<string, mixed> $row Array from {@see to_array()}.
	 */
	public static function from_array( array $row ): self {
		$provenance = array();
		if ( isset( $row['provenance'] ) && is_array( $row['provenance'] ) ) {
			foreach ( $row['provenance'] as $key => $value ) {
				$provenance[ (string) $key ] = (string) $value;
			}
		}

		return new self(
			(string) ( $row['field_key'] ?? '' ),
			(string) ( $row['segment_key'] ?? '' ),
			(string) ( $row['segment_kind'] ?? 'field' ),
			(string) ( $row['text_format'] ?? 'plain' ),
			(int) ( $row['segment_order'] ?? 0 ),
			(string) ( $row['language_code'] ?? '' ),
			(string) ( $row['source_text'] ?? '' ),
			(string) ( $row['source_hash'] ?? '' ),
			(int) ( $row['norm_version'] ?? 1 ),
			(string) ( $row['translated_text'] ?? '' ),
			(string) ( $row['translation_hash'] ?? '' ),
			(string) ( $row['status'] ?? '' ),
			(string) ( $row['review_status'] ?? 'not_submitted' ),
			(string) ( $row['publish_status'] ?? 'unpublished' ),
			(string) ( $row['slug_origin'] ?? '' ),
			$provenance,
			(string) ( $row['updated_at'] ?? '' ),
			isset( $row['block_uuid'] ) && '' !== $row['block_uuid'] ? (string) $row['block_uuid'] : null
		);
	}
}
