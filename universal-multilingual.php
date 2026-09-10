<?php
/**
 * Plugin Name: Universal Multilingual
 * Plugin URI: https://github.com/magpern/universal-multilingual
 * Description: Multilingual layer for WordPress and WooCommerce. One canonical object per content item; translations are stored as stable segments and applied as presentation overlays at render time.
 * Version: 1.15.0
 * Author: magpern
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: universal-multilingual
 * Requires at least: 6.5
 * Requires PHP: 8.1
 *
 * WooCommerce is deliberately NOT declared as a required plugin: Milestone 1
 * translates posts and pages only, and the WooCommerce integration layers
 * (Milestone 4) guard themselves at call time.
 *
 * @package AIMultilingual
 */

defined( 'ABSPATH' ) || exit;

define( 'AIML_VERSION', '1.15.0' );
define( 'AIML_PLUGIN_FILE', __FILE__ );

// PHP version guard. The "Requires PHP" header stops activation on WP 5.1+,
// but a file-drop install can bypass it, so fail closed with a notice.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Universal Multilingual requires PHP 8.1 or newer and is inactive.', 'universal-multilingual' )
			);
		}
	);
	return;
}

$aiml_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $aiml_autoload ) ) {
	require_once $aiml_autoload;
}

/**
 * Automatic updates via the private update server. Define PRIVATE_UPDATE_SERVER
 * (scheme + host, no trailing slash) in wp-config.php to enable; when it is not
 * defined the plugin does not check for updates.
 */
if ( defined( 'PRIVATE_UPDATE_SERVER' ) && PRIVATE_UPDATE_SERVER && class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		rtrim( (string) PRIVATE_UPDATE_SERVER, '/' ) . '/?action=get_metadata&slug=universal-multilingual',
		__FILE__,
		'universal-multilingual'
	);
}

$aiml_extension_functions = __DIR__ . '/src/Extension/functions.php';
if ( is_readable( $aiml_extension_functions ) ) {
	require_once $aiml_extension_functions;
}

/*
 * Declare compatibility with WooCommerce features we are structurally safe
 * against. Order data is only ever touched through WooCommerce CRUD (invariant
 * I11), and Milestone 1 does not touch orders at all.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AIML_PLUGIN_FILE, true );
		}
	}
);

if ( class_exists( \AIMultilingual\Plugin::class ) ) {
	register_activation_hook( __FILE__, array( \AIMultilingual\Plugin::class, 'activate' ) );
}

/*
 * No deactivation hook is registered, and that is deliberate: invariant I4
 * requires deactivation to remove nothing and leave the site byte-identical.
 * The plugin simply stops registering hooks. Data removal happens only in
 * uninstall.php, and only when explicitly opted into.
 */

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( \AIMultilingual\Plugin::class ) ) {
			// Autoloader missing (composer install not run); stay inert.
			return;
		}

		\AIMultilingual\Plugin::instance()->init();
	}
);
