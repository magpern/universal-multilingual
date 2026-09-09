<?php
/**
 * Three-way merge truth table (ADR-0030 §4).
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit\Promotion;

use AIMultilingual\Promotion\ImportCategory;
use AIMultilingual\Promotion\TranslationConflictDetector;
use PHPUnit\Framework\TestCase;

/**
 * The category depends on S_match and target_diverged, computed from the
 * package segment, the local row and the last-promoted baseline.
 */
final class TranslationConflictDetectorTest extends TestCase {

	private TranslationConflictDetector $detector;

	protected function setUp(): void {
		$this->detector = new TranslationConflictDetector();
	}

	public function test_new_when_no_local_row(): void {
		$segment = PackageFactory::segment();

		$this->assertSame(
			ImportCategory::NEW_TRANSLATION,
			$this->detector->classify( $segment, null, null, null )['category']
		);
	}

	public function test_unchanged_when_hashes_all_match(): void {
		$segment = PackageFactory::segment(
			array(
				'translated_text' => 'Blå pryl',
				'source_hash'     => sha1( 'Blue Widget' ),
			)
		);
		$local   = $this->row( sha1( 'Blå pryl' ), sha1( 'Blue Widget' ) );

		$this->assertSame(
			ImportCategory::UNCHANGED,
			$this->detector->classify( $segment, $local, sha1( 'Blue Widget' ), sha1( 'Blå pryl' ) )['category']
		);
	}

	public function test_update_when_source_matches_and_translation_differs_but_not_diverged(): void {
		$segment = PackageFactory::segment(
			array(
				'translated_text'  => 'Ny text',
				'translation_hash' => sha1( 'Ny text' ),
				'source_hash'      => sha1( 'src' ),
			)
		);
		// Local translation equals what we last promoted ⇒ not diverged.
		$local = $this->row( sha1( 'Gammal text' ), sha1( 'src' ) );

		$this->assertSame(
			ImportCategory::UPDATE,
			$this->detector->classify( $segment, $local, sha1( 'src' ), sha1( 'Gammal text' ) )['category']
		);
	}

	public function test_conflict_target_modified_when_prod_edited_since_last_promotion(): void {
		$segment = PackageFactory::segment(
			array(
				'translation_hash' => sha1( 'from-dev' ),
				'source_hash'      => sha1( 'src' ),
			)
		);
		// Local translation differs from the last-promoted baseline ⇒ diverged.
		$local = $this->row( sha1( 'edited-on-prod' ), sha1( 'src' ) );

		$this->assertSame(
			ImportCategory::CONFLICT_TARGET,
			$this->detector->classify( $segment, $local, sha1( 'src' ), sha1( 'what-we-promoted-before' ) )['category']
		);
	}

	public function test_no_baseline_but_local_translation_exists_is_a_conflict(): void {
		$segment = PackageFactory::segment( array( 'source_hash' => sha1( 'src' ) ) );
		$local   = $this->row( sha1( 'prod-authored' ), sha1( 'src' ) );

		$this->assertSame(
			ImportCategory::CONFLICT_TARGET,
			$this->detector->classify( $segment, $local, sha1( 'src' ), null )['category']
		);
	}

	public function test_stale_source_when_source_differs_and_not_diverged(): void {
		$segment = PackageFactory::segment(
			array(
				'translation_hash' => sha1( 'promoted' ),
				'source_hash'      => sha1( 'old-source' ),
			)
		);
		$local   = $this->row( sha1( 'promoted' ), sha1( 'new-source-on-prod' ) );

		$this->assertSame(
			ImportCategory::STALE_SOURCE,
			$this->detector->classify( $segment, $local, sha1( 'new-source-on-prod' ), sha1( 'promoted' ) )['category']
		);
	}

	public function test_conflict_both_changed_when_source_differs_and_diverged(): void {
		$segment = PackageFactory::segment(
			array(
				'translation_hash' => sha1( 'from-dev' ),
				'source_hash'      => sha1( 'old-source' ),
			)
		);
		$local   = $this->row( sha1( 'edited-on-prod' ), sha1( 'new-source' ) );

		$this->assertSame(
			ImportCategory::CONFLICT_BOTH,
			$this->detector->classify( $segment, $local, sha1( 'new-source' ), sha1( 'last-promoted' ) )['category']
		);
	}

	/**
	 * A fake local aiml_translations row.
	 *
	 * @param string $translation_hash translation_hash.
	 * @param string $source_hash      source_hash.
	 */
	private function row( string $translation_hash, string $source_hash ): object {
		return (object) array(
			'translation_hash' => $translation_hash,
			'source_hash'      => $source_hash,
			'updated_at'       => '2026-09-05 10:00:00',
		);
	}
}
