<?php
/**
 * Language validation rules.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit;

use AIMultilingual\Language\Languages;
use PHPUnit\Framework\TestCase;

/**
 * The validators are static and WordPress-free precisely so these rules can be
 * pinned down here rather than through a database round-trip.
 */
final class LanguageValidationTest extends TestCase {

	/**
	 * @dataProvider provide_codes
	 *
	 * @param string $code  Candidate URL code.
	 * @param bool   $valid Whether it should be accepted.
	 */
	public function test_code_validation( string $code, bool $valid ): void {
		$this->assertSame( $valid, Languages::is_valid_code( $code ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function provide_codes(): array {
		return array(
			'two letters'         => array( 'sv', true ),
			'three letters'       => array( 'ceb', true ),
			'with region'         => array( 'pt-br', true ),
			'region and variant'  => array( 'de-de-formal', true ),
			'variant with digits' => array( 'pt-pt-ao90', true ),
			'informal variant'    => array( 'de-ch-informal', true ),
			'uppercase'           => array( 'SV', false ),
			'mixed case'          => array( 'pt-BR', false ),
			'too short'           => array( 's', false ),
			'too long'            => array( 'svenska', false ),
			'four letter lang'    => array( 'test', false ),
			'underscore region'   => array( 'pt_br', false ),
			'double hyphen'       => array( 'de--formal', false ),
			'trailing hyphen'     => array( 'de-', false ),
			'three letter region' => array( 'de-deu-formal', false ),
			'path traversal'      => array( '../', false ),
			'slash'               => array( 'sv/se', false ),
			'empty'               => array( '', false ),
			'digits'              => array( 's1', false ),
			'leading space'       => array( ' sv', false ),
		);
	}

	/**
	 * @dataProvider provide_locales
	 *
	 * @param string $locale Candidate locale.
	 * @param bool   $valid  Whether it should be accepted.
	 */
	public function test_locale_validation( string $locale, bool $valid ): void {
		$this->assertSame( $valid, Languages::is_valid_locale( $locale ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function provide_locales(): array {
		return array(
			'swedish'          => array( 'sv_SE', true ),
			'english'          => array( 'en_US', true ),
			'bare'             => array( 'en', true ),
			'three letter'     => array( 'ceb', true ),
			'variant'          => array( 'de_DE_formal', true ),
			// WordPress really does ship this one; a variant may follow the
			// language directly, with no region in between.
			'variant only'     => array( 'art_xemoji', true ),
			// A two-letter lowercase segment is a mistyped region, not a
			// variant, and must be caught rather than silently accepted.
			'lowercase region' => array( 'sv_se', false ),
			'hyphen'           => array( 'sv-SE', false ),
			'empty'            => array( '', false ),
			'injection'        => array( "sv_SE'; DROP TABLE", false ),
		);
	}

	public function test_only_three_states_exist(): void {
		$this->assertSame(
			array( 'disabled', 'preview', 'published' ),
			Languages::statuses()
		);

		$this->assertTrue( Languages::is_valid_status( 'preview' ) );
		$this->assertFalse( Languages::is_valid_status( 'active' ) );
		$this->assertFalse( Languages::is_valid_status( '' ) );
	}

	/**
	 * @dataProvider provide_transitions
	 *
	 * @param string $from    Current state.
	 * @param string $to      Requested state.
	 * @param bool   $allowed Whether the change is permitted.
	 */
	public function test_state_transitions( string $from, string $to, bool $allowed ): void {
		$this->assertSame( $allowed, Languages::can_transition( $from, $to ) );
	}

	/**
	 * A disabled language must pass back through preview before it can be
	 * published, so nothing goes public without someone looking at it first.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function provide_transitions(): array {
		return array(
			'preview to published'  => array( 'preview', 'published', true ),
			'published to preview'  => array( 'published', 'preview', true ),
			'preview to disabled'   => array( 'preview', 'disabled', true ),
			'disabled to preview'   => array( 'disabled', 'preview', true ),
			'published to disabled' => array( 'published', 'disabled', true ),
			'disabled to published' => array( 'disabled', 'published', false ),
			'no-op preview'         => array( 'preview', 'preview', true ),
			'unknown source'        => array( 'active', 'preview', false ),
			'unknown target'        => array( 'preview', 'live', false ),
		);
	}
}
