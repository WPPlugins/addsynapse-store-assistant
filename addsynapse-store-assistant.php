<?php
/**
 * Plugin Name:       AddSynapse – AI Website & Store Assistant for WooCommerce
 * Plugin URI:        https://addsynapse.com/store-assistant
 * Description:       Admin chat where shop managers ask, in their own language, about their store and for changes to one product at a time — with your own Anthropic or OpenAI API key. Every change is a preview a person approves, with Undo.
 * Version:           1.3.9
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            AddSynapse
 * Author URI:        https://addsynapse.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       addsynapse-store-assistant
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 *
 * @package Addsynapse\StoreAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'ADDSYNAPSE_ASSISTANT_VERSION', '1.3.9' );
define( 'ADDSYNAPSE_ASSISTANT_PLUGIN_FILE', __FILE__ );
define( 'ADDSYNAPSE_ASSISTANT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADDSYNAPSE_ASSISTANT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader when present (dev installs), and always a tiny PSR-4 autoloader for the plugin
// (Addsynapse\StoreAssistant\ → includes/), the bundled, WordPress-free connector core (Addsynapse\ConnectorCore\ →
// packages/connector-core/src/: only the platform-neutral change rules — status, unit decision, selection) and the bundled assistant
// brain for the manager's own AI key (Addsynapse\StoreAssistant\Brain\ → packages/assistant-brain/src/:
// prompt, tool catalog, Anthropic / OpenAI request adapters).
if ( is_readable( ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . 'vendor/autoload.php';
}
spl_autoload_register(
	static function ( $class_name ) {
		$roots = array(
			// Most specific first: the brain lives under the plugin's namespace but in its own package folder.
			'Addsynapse\\StoreAssistant\\Brain\\' => 'packages/assistant-brain/src/',
			'Addsynapse\\StoreAssistant\\'        => 'includes/',
			'Addsynapse\\ConnectorCore\\'         => 'packages/connector-core/src/',
		);
		foreach ( $roots as $prefix => $dir ) {
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				continue;
			}
			$file = ADDSYNAPSE_ASSISTANT_PLUGIN_DIR . $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
);

register_activation_hook( __FILE__, array( \Addsynapse\StoreAssistant\Install\Schema::class, 'install' ) );
// 1.3.5: remember the activation for one minute (continues a connection started in Claude, HomePage::maybe_start_from_claude).
register_activation_hook( __FILE__, array( \Addsynapse\StoreAssistant\Admin\HomePage::class, 'activated' ) );
register_deactivation_hook( __FILE__, array( \Addsynapse\StoreAssistant\Install\Lifecycle::class, 'deactivate' ) );

// Declare HPOS (custom order tables) compatibility — the hook only fires when WooCommerce loads,
// and the plugin also runs on plain WordPress sites (see includes/Environment.php).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( \Addsynapse\StoreAssistant\Plugin::class, 'boot' ) );
