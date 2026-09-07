<?php
/**
 * Settings sanitization.
 *
 * @package AIMultilingual
 */

declare( strict_types=1 );

namespace AIMultilingual\Tests\Unit;

use AIMultilingual\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Sanitization must be total: any input produces a usable settings array and
 * nothing throws. A corrupted option should degrade to defaults, never fatal.
 */
final class SettingsSanitizeTest extends TestCase {

	public function test_defaults_are_conservative(): void {
		$defaults = Settings::defaults();

		$this->assertFalse(
			$defaults['remove_data_on_uninstall'],
			'Uninstall must retain translation work unless explicitly opted out.'
		);
		$this->assertTrue( $defaults['switcher_show_native_name'] );
		$this->assertFalse( $defaults['switcher_hide_current'] );
		$this->assertFalse( $defaults['floating_selector_enabled'] );
		$this->assertSame( 'right', $defaults['floating_selector_side'] );
		$this->assertSame( 'center', $defaults['floating_selector_vertical'] );
		$this->assertSame( 'code', $defaults['floating_selector_collapsed'] );
		$this->assertSame( 'edge_pill', $defaults['floating_selector_preset'] );
		$this->assertTrue( $defaults['floating_selector_show_desktop'] );
		$this->assertTrue( $defaults['floating_selector_show_mobile'] );
		$this->assertTrue( $defaults['floating_selector_persist_preference'] );
		$this->assertSame( 2, Settings::SCHEMA_VERSION );
		$this->assertFalse( $defaults['block_attr_registration_enabled'] );
		$this->assertFalse( $defaults['block_uuid_injection_enabled'] );
		$this->assertFalse( $defaults['block_extraction_enabled'] );
		$this->assertFalse( $defaults['block_frontend_rendering_enabled'] );
		$this->assertFalse( $defaults['elementor_extraction_enabled'] );
		$this->assertFalse( $defaults['elementor_frontend_rendering_enabled'] );
		$this->assertSame( 'off', $defaults['localized_urls_state'] );
		$this->assertNull( $defaults['localized_urls_activation_checkpoint'] );
		$this->assertSame( '', $defaults['localized_urls_activation_error'] );
		$this->assertSame( Settings::SCHEMA_VERSION, $defaults['schema_version'] );
	}

	/**
	 * @dataProvider provide_garbage
	 *
	 * @param mixed $input Arbitrary stored value.
	 */
	public function test_garbage_input_yields_defaults( $input ): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_garbage(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'not-an-array' ),
			'int'    => array( 42 ),
			'float'  => array( 1.5 ),
			'bool'   => array( true ),
			'object' => array( new \stdClass() ),
		);
	}

	/**
	 * @dataProvider provide_truthy
	 *
	 * @param mixed $value    Raw checkbox-ish value.
	 * @param bool  $expected Interpreted boolean.
	 */
	public function test_loose_booleans_are_interpreted( $value, bool $expected ): void {
		$clean = Settings::sanitize( array( 'remove_data_on_uninstall' => $value ) );

		$this->assertSame( $expected, $clean['remove_data_on_uninstall'] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function provide_truthy(): array {
		return array(
			'checkbox on'  => array( '1', true ),
			'string true'  => array( 'true', true ),
			'string yes'   => array( 'YES', true ),
			'string on'    => array( ' on ', true ),
			'bool true'    => array( true, true ),
			'int one'      => array( 1, true ),
			'string zero'  => array( '0', false ),
			'empty string' => array( '', false ),
			'string off'   => array( 'off', false ),
			'bool false'   => array( false, false ),
			'int zero'     => array( 0, false ),
			'array'        => array( array(), false ),
		);
	}

	public function test_unknown_keys_are_dropped(): void {
		$clean = Settings::sanitize(
			array(
				'remove_data_on_uninstall' => true,
				'unexpected_key'           => 'value',
			)
		);

		$this->assertArrayNotHasKey( 'unexpected_key', $clean );
		$this->assertTrue( $clean['remove_data_on_uninstall'] );
	}

	public function test_schema_version_is_always_current(): void {
		$clean = Settings::sanitize( array( 'schema_version' => 99 ) );

		$this->assertSame( Settings::SCHEMA_VERSION, $clean['schema_version'] );
	}

	public function test_injection_without_registration_is_coerced_off(): void {
		$clean = Settings::sanitize(
			array(
				'block_attr_registration_enabled' => false,
				'block_uuid_injection_enabled'    => true,
			)
		);

		$this->assertFalse( $clean['block_uuid_injection_enabled'] );
	}

	public function test_extraction_without_injection_is_coerced_off(): void {
		$clean = Settings::sanitize(
			array(
				'block_attr_registration_enabled' => true,
				'block_uuid_injection_enabled'    => false,
				'block_extraction_enabled'        => true,
			)
		);

		$this->assertFalse( $clean['block_extraction_enabled'] );
	}

	public function test_frontend_rendering_without_extraction_dependency_is_coerced_off(): void {
		$clean = Settings::sanitize(
			array(
				'block_attr_registration_enabled'  => true,
				'block_uuid_injection_enabled'     => true,
				'block_extraction_enabled'         => false,
				'block_frontend_rendering_enabled' => true,
			)
		);

		$this->assertFalse( $clean['block_frontend_rendering_enabled'] );
	}

	public function test_settings_can_be_constructed_in_memory(): void {
		$settings = new Settings( array( 'remove_data_on_uninstall' => '1' ) );

		$this->assertTrue( $settings->remove_data_on_uninstall() );
		$this->assertTrue( $settings->switcher_show_native_name() );
		$this->assertFalse( $settings->block_attr_registration_enabled() );
		$this->assertFalse( $settings->block_uuid_injection_enabled() );
	}

	public function test_localized_urls_state_sanitization(): void {
		$clean = Settings::sanitize( array( 'localized_urls_state' => 'on' ) );
		$this->assertSame( 'on', $clean['localized_urls_state'] );

		$invalid = Settings::sanitize( array( 'localized_urls_state' => 'bogus' ) );
		$this->assertSame( 'off', $invalid['localized_urls_state'] );
	}

	public function test_floating_selector_enums_fall_back_to_defaults(): void {
		$clean = Settings::sanitize(
			array(
				'floating_selector_side'      => 'top',
				'floating_selector_vertical'  => 'left',
				'floating_selector_collapsed' => 'flag',
				'floating_selector_preset'    => 'custom',
			)
		);

		$this->assertSame( 'right', $clean['floating_selector_side'] );
		$this->assertSame( 'center', $clean['floating_selector_vertical'] );
		$this->assertSame( 'code', $clean['floating_selector_collapsed'] );
		$this->assertSame( 'edge_pill', $clean['floating_selector_preset'] );
	}

	public function test_floating_selector_valid_enums_and_booleans_persist(): void {
		$clean = Settings::sanitize(
			array(
				'floating_selector_enabled'            => '1',
				'floating_selector_side'               => 'LEFT',
				'floating_selector_vertical'           => 'bottom',
				'floating_selector_collapsed'          => 'globe',
				'floating_selector_preset'             => 'minimal',
				'floating_selector_show_desktop'       => '0',
				'floating_selector_show_mobile'        => '1',
				'floating_selector_persist_preference' => 'off',
			)
		);

		$this->assertTrue( $clean['floating_selector_enabled'] );
		$this->assertSame( 'left', $clean['floating_selector_side'] );
		$this->assertSame( 'bottom', $clean['floating_selector_vertical'] );
		$this->assertSame( 'globe', $clean['floating_selector_collapsed'] );
		$this->assertSame( 'minimal', $clean['floating_selector_preset'] );
		$this->assertFalse( $clean['floating_selector_show_desktop'] );
		$this->assertTrue( $clean['floating_selector_show_mobile'] );
		$this->assertFalse( $clean['floating_selector_persist_preference'] );
	}

	public function test_is_localized_url_generation_enabled_only_when_on(): void {
		$off = new Settings( array( 'localized_urls_state' => 'off' ) );
		$this->assertFalse( $off->is_localized_url_generation_enabled() );

		$on = new Settings( array( 'localized_urls_state' => 'on' ) );
		$this->assertTrue( $on->is_localized_url_generation_enabled() );
	}
}
