<?php
/**
 * PHPUnit bootstrap. Tests use Brain Monkey to stub WordPress functions, so no
 * WordPress install is required — run with `composer install && composer test`.
 *
 * @package OrchardGrove\PrecisionAnalytics
 */

declare( strict_types=1 );

// Plugin source files guard on ABSPATH; define it so they load under PHPUnit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'PRECISION_ANALYTICS_VERSION' ) ) {
	define( 'PRECISION_ANALYTICS_VERSION', 'test' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Minimal stubs for the WordPress classes used in type hints / instanceof.
if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Post {
		public int $ID            = 0;
		public string $post_type  = 'post';
		public int $post_author   = 0;

		/** @param array<string,mixed> $props */
		public function __construct( array $props = [] ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Term {
		public int $term_id    = 0;
		public string $taxonomy = 'category';
		public string $slug     = '';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_User {
		public int $ID = 0;
		/** @var string[] */
		public array $roles = [];
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Error {
		public function __construct(
			public string $code = '',
			public string $message = '',
			public mixed $data = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
