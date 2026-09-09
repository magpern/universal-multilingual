<?php
/**
 * Hand-maintained additions to the generated locale registry.
 *
 * GlotPress `locales/locales.php` does not define WordPress's formal / informal
 * translation locales as `GP_Locale` objects, even though `.mo` files ship for
 * them on translate.wordpress.org and `WPLANG` accepts them verbatim. They are
 * legitimate WordPress runtime locales (ADR-0028 decision 9), so they are added
 * here. Each entry goes through the same validation and derivation as a
 * GlotPress-sourced locale.
 *
 * Keyed by the exact WordPress runtime locale string. `region`, `variant`,
 * `region_label` and `script` are derived by the generator.
 *
 * @package AIMultilingual
 */

return array(
	'de_DE_formal'   => array(
		'language_code' => 'de',
		'english_name'  => 'German (Formal)',
		'native_name'   => 'Deutsch (Sie)',
		'direction'     => 'ltr',
	),
	'de_CH_informal' => array(
		'language_code' => 'de',
		'english_name'  => 'German (Switzerland, Informal)',
		'native_name'   => 'Deutsch (Schweiz, Du)',
		'direction'     => 'ltr',
	),
	'nl_NL_formal'   => array(
		'language_code' => 'nl',
		'english_name'  => 'Dutch (Formal)',
		'native_name'   => 'Nederlands (formeel)',
		'direction'     => 'ltr',
	),
);
