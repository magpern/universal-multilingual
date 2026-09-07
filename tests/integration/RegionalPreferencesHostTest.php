<?php
/**
 * Regional Preferences host composition.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Integration;

use AIMultilingual\Language\Languages;
use AIMultilingual\User\PreferredLanguage;
use AIMultilingual\User\PreferredLanguageField;
use AIMultilingual\User\RegionalPreferencesComposition;
use AIMultilingual\User\RegionalPreferencesHost;

/**
 * Host shell, provider claim, and currency fallback when UMC is absent.
 */
final class RegionalPreferencesHostTest extends AimlTestCase {

	public function test_host_renders_once_with_language_and_currency_fallback(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );

		$preference = new PreferredLanguage( $this->languages );
		( new PreferredLanguageField( $preference ) )->register();
		$host = new RegionalPreferencesHost();

		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( (int) $user->ID );

		ob_start();
		$host->render_profile( $user );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Regional preferences', $html );
		$this->assertStringContainsString( 'aiml_preferred_language', $html );
		$this->assertStringContainsString( 'um-regional-preference-currency-fallback', $html );
		$this->assertStringContainsString( 'Multicurrency support is not currently available.', $html );

		ob_start();
		$host->render_profile( $user );
		$second = (string) ob_get_clean();
		$this->assertSame( '', $second );
		$this->assertSame( 1, did_action( RegionalPreferencesComposition::ACTION_RENDERED ) );
	}

	public function test_language_save_does_not_write_currency_meta(): void {
		$this->add_language( 'sv', 'sv_SE', Languages::STATUS_PUBLISHED );

		$preference = new PreferredLanguage( $this->languages );
		$field      = new PreferredLanguageField( $preference );
		$field->register();
		$host = new RegionalPreferencesHost();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$_POST[ PreferredLanguageField::FIELD_NAME ] = 'sv';
		$_POST[ PreferredLanguageField::NONCE_NAME ] = wp_create_nonce( PreferredLanguageField::NONCE_ACTION );

		$host->save_profile( $user_id );

		$this->assertSame( 'sv', get_user_meta( $user_id, PreferredLanguage::META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'umc_preferred_currency', true ) );
	}
}
