<?php
/**
 * ObjectLanguageStatus + ObjectLanguagesSummary unit tests (MLW1a / WP1).
 *
 * Covers every branch of the frozen state() ladder (ADR-0034 D2) and the
 * frozen ready_count / target_count / forgotten definitions (D3 / C5).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Workspace;

use AIMultilingual\Language\Languages;
use AIMultilingual\Workspace\ObjectLanguagesSummary;
use AIMultilingual\Workspace\ObjectLanguageStatus;
use PHPUnit\Framework\TestCase;

/**
 * Server-authoritative object × language state.
 */
final class ObjectLanguageStatusTest extends TestCase {

	/**
	 * Builds a status with sensible "clean, fully translated" defaults that
	 * individual cases override.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function status( array $overrides = array() ): ObjectLanguageStatus {
		$d = array_merge(
			array(
				'language_id'        => 2,
				'language_code'      => 'sv',
				'language_name'      => 'Swedish',
				'language_status'    => Languages::STATUS_PREVIEW,
				'total'              => 10,
				'translated'         => 10,
				'missing'            => 0,
				'stale'              => 0,
				'pending'            => 0,
				'approved'           => 0,
				'rejected'           => 0,
				'published_segments' => 0,
				'has_active_job'     => false,
				'has_qa_errors'      => false,
			),
			$overrides
		);

		return new ObjectLanguageStatus(
			$d['language_id'],
			$d['language_code'],
			$d['language_name'],
			$d['language_status'],
			$d['total'],
			$d['translated'],
			$d['missing'],
			$d['stale'],
			$d['pending'],
			$d['approved'],
			$d['rejected'],
			$d['published_segments'],
			$d['has_active_job'],
			$d['has_qa_errors']
		);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function state_cases(): array {
		return array(
			'active job wins over everything'  => array(
				array(
					'has_active_job' => true,
					'missing'        => 10,
					'rejected'       => 3,
				),
				ObjectLanguageStatus::STATE_TRANSLATING,
			),
			'all segments missing'             => array(
				array(
					'total'      => 10,
					'missing'    => 10,
					'translated' => 0,
				),
				ObjectLanguageStatus::STATE_NOT_TRANSLATED,
			),
			'some segments missing'            => array(
				array(
					'total'      => 10,
					'missing'    => 3,
					'translated' => 7,
				),
				ObjectLanguageStatus::STATE_MISSING_FIELDS,
			),
			'qa errors => needs attention'     => array(
				array( 'has_qa_errors' => true ),
				ObjectLanguageStatus::STATE_NEEDS_ATTENTION,
			),
			'rejected => needs attention'      => array(
				array( 'rejected' => 1 ),
				ObjectLanguageStatus::STATE_NEEDS_ATTENTION,
			),
			'stale => needs attention'         => array(
				array( 'stale' => 2 ),
				ObjectLanguageStatus::STATE_NEEDS_ATTENTION,
			),
			'pending review'                   => array(
				array( 'pending' => 4 ),
				ObjectLanguageStatus::STATE_PENDING_REVIEW,
			),
			'all approved, nothing published'  => array(
				array(
					'approved' => 10,
					'total'    => 10,
				),
				ObjectLanguageStatus::STATE_REVIEWED,
			),
			'all approved, segments published' => array(
				array(
					'approved'           => 10,
					'total'              => 10,
					'published_segments' => 10,
				),
				ObjectLanguageStatus::STATE_PUBLISHED,
			),
			'clean, language published'        => array(
				array( 'language_status' => Languages::STATUS_PUBLISHED ),
				ObjectLanguageStatus::STATE_PUBLISHED,
			),
			'clean, language preview'          => array(
				array( 'language_status' => Languages::STATUS_PREVIEW ),
				ObjectLanguageStatus::STATE_PREVIEW,
			),
			'clean, language status unknown'   => array(
				array( 'language_status' => '' ),
				ObjectLanguageStatus::STATE_TRANSLATED,
			),
		);
	}

	/**
	 * @dataProvider state_cases
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @param string               $expected  Expected canonical state.
	 */
	public function test_state_ladder( array $overrides, string $expected ): void {
		$this->assertSame( $expected, $this->status( $overrides )->state() );
	}

	/**
	 * Ready = missing 0 && stale 0 && no qa errors && state in the ready set.
	 */
	public function test_is_ready_frozen_definition(): void {
		$this->assertTrue( $this->status()->is_ready(), 'clean preview language is ready' );
		$this->assertTrue( $this->status( array( 'pending' => 2 ) )->is_ready(), 'pending_review is ready' );
		$this->assertTrue(
			$this->status(
				array(
					'approved' => 10,
					'total'    => 10,
				)
			)->is_ready(),
			'reviewed is ready'
		);

		$this->assertFalse(
			$this->status(
				array(
					'missing'    => 1,
					'translated' => 9,
				)
			)->is_ready()
		);
		$this->assertFalse( $this->status( array( 'stale' => 1 ) )->is_ready() );
		$this->assertFalse( $this->status( array( 'has_qa_errors' => true ) )->is_ready() );
		$this->assertFalse( $this->status( array( 'has_active_job' => true ) )->is_ready() );
		$this->assertFalse(
			$this->status(
				array(
					'total'      => 10,
					'missing'    => 10,
					'translated' => 0,
				)
			)->is_ready()
		);
	}

	public function test_is_forgotten_only_for_not_translated(): void {
		$this->assertTrue(
			$this->status(
				array(
					'total'      => 10,
					'missing'    => 10,
					'translated' => 0,
				)
			)->is_forgotten()
		);
		$this->assertFalse(
			$this->status(
				array(
					'missing'    => 3,
					'translated' => 7,
				)
			)->is_forgotten()
		);
		$this->assertFalse( $this->status()->is_forgotten() );
	}

	public function test_is_approvable_requires_clean_pending(): void {
		$this->assertTrue( $this->status( array( 'pending' => 3 ) )->is_approvable() );
		$this->assertFalse( $this->status( array( 'pending' => 0 ) )->is_approvable() );
		$this->assertFalse(
			$this->status(
				array(
					'pending' => 3,
					'stale'   => 1,
				)
			)->is_approvable()
		);
		$this->assertFalse(
			$this->status(
				array(
					'pending'       => 3,
					'has_qa_errors' => true,
				)
			)->is_approvable()
		);
		$this->assertFalse(
			$this->status(
				array(
					'pending'    => 3,
					'missing'    => 1,
					'translated' => 9,
				)
			)->is_approvable()
		);
	}

	public function test_summary_counts_and_forgotten(): void {
		$statuses = array(
			// sv: ready + approvable.
			$this->status(
				array(
					'language_code' => 'sv',
					'pending'       => 2,
				)
			),
			// de: ready (reviewed).
			$this->status(
				array(
					'language_code' => 'de',
					'approved'      => 10,
					'total'         => 10,
				)
			),
			// da: not translated (forgotten).
			$this->status(
				array(
					'language_code' => 'da',
					'total'         => 10,
					'missing'       => 10,
					'translated'    => 0,
				)
			),
			// nb: missing_fields.
			$this->status(
				array(
					'language_code' => 'nb',
					'missing'       => 3,
					'translated'    => 7,
				)
			),
			// fi: translating.
			$this->status(
				array(
					'language_code'  => 'fi',
					'has_active_job' => true,
				)
			),
		);

		$summary = ObjectLanguagesSummary::from_statuses( $statuses );

		$this->assertSame( 5, $summary->target_count );
		$this->assertSame( 2, $summary->ready_count );
		$this->assertSame( 1, $summary->not_translated_count );
		$this->assertSame( 1, $summary->incomplete_count );
		$this->assertSame( 1, $summary->translating_count );
		$this->assertSame( 1, $summary->approvable_count );
		$this->assertTrue( $summary->forgotten );
		$this->assertSame( array( 'da' ), $summary->forgotten_languages );

		$array = $summary->to_array();
		$this->assertSame( 2, $array['ready_count'] );
		$this->assertSame( 5, $array['target_count'] );
	}

	public function test_summary_not_forgotten_when_all_started(): void {
		$summary = ObjectLanguagesSummary::from_statuses(
			array(
				$this->status( array( 'language_code' => 'sv' ) ),
				$this->status(
					array(
						'language_code' => 'de',
						'missing'       => 1,
						'translated'    => 9,
					)
				),
			)
		);

		$this->assertFalse( $summary->forgotten );
		$this->assertSame( array(), $summary->forgotten_languages );
		$this->assertSame( 2, $summary->target_count );
		$this->assertSame( 1, $summary->ready_count );
	}
}
