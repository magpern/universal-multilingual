<?php
/**
 * TranslatableSegmentEligibility unit tests (AIT1 / WP1).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Workspace;

use AIMultilingual\Translation\Store;
use AIMultilingual\Workspace\TranslatableSegmentEligibility as Policy;
use PHPUnit\Framework\TestCase;

/**
 * The one shared AI-write eligibility gate.
 */
final class TranslatableSegmentEligibilityTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides Segment overrides.
	 * @return array<string, mixed>
	 */
	private function segment( array $overrides = array() ): array {
		return array_merge(
			array(
				'status'          => Store::STATUS_MISSING,
				'translated_text' => '',
				'is_stale'        => false,
				'review_status'   => Store::REVIEW_NOT_SUBMITTED,
			),
			$overrides
		);
	}

	public function test_missing_mode_only_matches_untranslated(): void {
		$this->assertTrue( Policy::is_ai_writable( $this->segment(), Policy::MODE_MISSING ) );
		$this->assertTrue(
			Policy::is_ai_writable(
				$this->segment(
					array(
						'status'          => Store::STATUS_MACHINE_TRANSLATED,
						'translated_text' => '',
					)
				),
				Policy::MODE_MISSING
			),
			'Empty translated_text counts as missing regardless of status.'
		);
		$this->assertFalse(
			Policy::is_ai_writable(
				$this->segment(
					array(
						'status'          => Store::STATUS_MACHINE_TRANSLATED,
						'translated_text' => 'hola',
					)
				),
				Policy::MODE_MISSING
			)
		);
	}

	public function test_stale_mode_only_matches_stale_machine(): void {
		$this->assertTrue(
			Policy::is_ai_writable(
				$this->segment(
					array(
						'status'          => Store::STATUS_MACHINE_TRANSLATED,
						'translated_text' => 'hola',
						'is_stale'        => true,
					)
				),
				Policy::MODE_STALE
			)
		);
		$this->assertFalse(
			Policy::is_ai_writable(
				$this->segment(
					array(
						'status'          => Store::STATUS_MACHINE_TRANSLATED,
						'translated_text' => 'hola',
						'is_stale'        => false,
					)
				),
				Policy::MODE_STALE
			)
		);
		$this->assertFalse(
			Policy::is_ai_writable(
				$this->segment(
					array(
						'status'   => Store::STATUS_MISSING,
						'is_stale' => true,
					)
				),
				Policy::MODE_STALE
			)
		);
	}

	public function test_machine_mode_matches_any_machine_but_not_missing(): void {
		$this->assertFalse(
			Policy::is_ai_writable( $this->segment(), Policy::MODE_MACHINE ),
			'An untranslated segment belongs to "Translate missing", not machine retranslation.'
		);
		foreach ( array( true, false ) as $stale ) {
			$this->assertTrue(
				Policy::is_ai_writable(
					$this->segment(
						array(
							'status'          => Store::STATUS_MACHINE_TRANSLATED,
							'translated_text' => 'hola',
							'is_stale'        => $stale,
						)
					),
					Policy::MODE_MACHINE
				)
			);
		}
	}

	/**
	 * @dataProvider protected_states
	 *
	 * @param array<string, mixed> $overrides Segment overrides.
	 */
	public function test_protected_segments_never_writable_in_any_mode( array $overrides ): void {
		$segment = $this->segment( $overrides );
		$this->assertTrue( Policy::is_protected( $segment ) );
		foreach ( Policy::modes() as $mode ) {
			$this->assertFalse(
				Policy::is_ai_writable( $segment, $mode ),
				"Mode {$mode} must not write a protected segment."
			);
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function protected_states(): array {
		return array(
			'manually edited' => array(
				array(
					'status'          => Store::STATUS_MANUALLY_EDITED,
					'translated_text' => 'x',
				),
			),
			'reviewed'        => array(
				array(
					'status'          => Store::STATUS_REVIEWED,
					'translated_text' => 'x',
				),
			),
			'review pending'  => array(
				array(
					'status'          => Store::STATUS_MACHINE_TRANSLATED,
					'translated_text' => 'x',
					'review_status'   => Store::REVIEW_PENDING,
				),
			),
			'review approved' => array(
				array(
					'status'          => Store::STATUS_MACHINE_TRANSLATED,
					'translated_text' => 'x',
					'review_status'   => Store::REVIEW_APPROVED,
				),
			),
			'review rejected' => array(
				array(
					'status'          => Store::STATUS_MACHINE_TRANSLATED,
					'translated_text' => 'x',
					'review_status'   => Store::REVIEW_REJECTED,
				),
			),
		);
	}

	public function test_unknown_mode_is_never_writable(): void {
		$this->assertFalse( Policy::is_ai_writable( $this->segment(), 'force' ) );
		$this->assertFalse( Policy::is_ai_writable( $this->segment(), '' ) );
	}

	public function test_missing_keys_default_to_safe_values(): void {
		$this->assertTrue( Policy::is_ai_writable( array(), Policy::MODE_MISSING ) );
		$this->assertFalse( Policy::is_protected( array() ) );
	}
}
