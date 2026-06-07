<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PSR-4 autoloader for the OrchardGrove\PrecisionAnalytics namespace.
 *
 * No Composer runtime dependency — the plugin ships as pure PHP.
 */
final class Autoloader {

	private const PREFIX = 'OrchardGrove\\PrecisionAnalytics\\';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	public static function autoload( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = PRECISION_ANALYTICS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
