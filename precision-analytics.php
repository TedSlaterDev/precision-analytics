<?php
/**
 * Plugin Name:       Precision Analytics
 * Plugin URI:        https://orchardgrove.com/
 * Description:       Precise Google Analytics 4 — custom dimensions (author, type, role…), traffic sampling, Consent Mode v2, and a cached reporting layer you can reuse anywhere on the site.
 * Version:           0.3.0
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * Author:            Orchard Grove Media, LLC
 * Author URI:        https://orchardgrove.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       precision-analytics
 * Domain Path:       /languages
 *
 * @package OrchardGrove\PrecisionAnalytics
 */

declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics;

defined( 'ABSPATH' ) || exit;

define( 'PRECISION_ANALYTICS_VERSION', '0.3.0' );
define( 'PRECISION_ANALYTICS_FILE', __FILE__ );
define( 'PRECISION_ANALYTICS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRECISION_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );
define( 'PRECISION_ANALYTICS_BASENAME', plugin_basename( __FILE__ ) );

require_once PRECISION_ANALYTICS_DIR . 'src/Autoloader.php';
Autoloader::register();

require_once PRECISION_ANALYTICS_DIR . 'src/template-tags.php';

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
