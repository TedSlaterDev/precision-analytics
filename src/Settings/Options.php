<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor over the single autoloaded option row (`precision_analytics`).
 *
 * Stored values are deep-merged over defaults once and read by dotted path:
 *   $options->str( 'general.measurement_id' );
 *   $options->bool( 'attributes.author' );
 */
final class Options {

	public const OPTION = 'precision_analytics';

	private array $data;
	private ?array $merged = null;

	public function __construct() {
		$stored     = get_option( self::OPTION, [] );
		$this->data = is_array( $stored ) ? $stored : [];
	}

	public function defaults(): array {
		$defaults = [
			'general'    => [
				'measurement_id' => '',     // G-XXXXXXXXXX.
				'transport'      => 'gtag', // 'gtag' | 'gtm'.
				'gtm_id'         => '',     // GTM-XXXXXXX (transport = gtm).
				'debug_mode'     => false,  // Sends debug_mode → GA4 DebugView.
			],
			'attributes' => [
				// Which post/viewer facts to send as GA4 event parameters.
				'author'         => true,
				'post_type'      => true,
				'category'       => true,
				'tags'           => false,
				'post_id'        => true,
				'page_type'      => true,
				'logged_in'      => true,
				'user_role'      => false,
				'published_year' => false,
				// Optional per-attribute GA4 parameter-name overrides (key => name),
				// e.g. author => 'author' to keep a MonsterInsights dimension flowing.
				'params'         => [],
			],
			'sampling'   => [
				'enabled'           => false,           // Off = track every visitor.
				'rate'              => 100,             // Global send-rate (1–100).
				'exclude_logged_in' => false,
				'exclude_roles'     => [ 'administrator' ],
				'exclude_user_ids'  => '',              // Comma-separated IDs.
				'rules'             => '',              // One per line: attribute:value:rate.
			],
			'consent'    => [
				// Off by default: built for US sites/audiences, where GA4 should
				// collect from everyone. Turn on only for EEA/UK/CH visitors with
				// a consent banner. When on, "EEA only" keeps US visitors tracked.
				'enabled'           => false,           // Emit Consent Mode v2 defaults.
				'analytics_default' => 'denied',        // 'granted' | 'denied' (EEA default when on).
				'ad_default'        => 'denied',
				'eea_only'          => true,            // Restrict denied defaults to the EEA; grant elsewhere.
				'url_passthrough'   => true,
				'wait_for_update'   => 500,             // ms before the tag gives up waiting.
			],
			'reporting'  => [
				'enabled'              => false,
				'property_id'          => '',                 // GA4 numeric property ID.
				'auth_method'          => 'service_account',  // 'service_account' | 'oauth'.
				'service_account_json' => '',                 // Or PA_GA4_SERVICE_ACCOUNT_JSON constant.
				'oauth_client_id'      => '',                 // Or PA_GA4_OAUTH_CLIENT_ID constant.
				'oauth_client_secret'  => '',                 // Or PA_GA4_OAUTH_CLIENT_SECRET constant.
				'sync_interval'        => 900,                // Seconds between cron syncs.
				'widget_enabled'       => true,
			],
			'events'     => [
				'enabled'        => false,
				'outbound_links' => true,
				'file_downloads' => true,
				'download_exts'  => 'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,csv,mp3,mp4',
			],
			'advanced'   => [
				'delete_data_on_uninstall' => false,
			],
		];

		return apply_filters( 'precision_analytics/default_options', $defaults );
	}

	public function get( string $path, mixed $default = null ): mixed {
		$value = $this->merged();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	public function bool( string $path ): bool {
		return (bool) $this->get( $path, false );
	}

	public function str( string $path, string $default = '' ): string {
		$value = $this->get( $path, $default );
		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function int( string $path, int $default = 0 ): int {
		return (int) $this->get( $path, $default );
	}

	public function arr( string $path ): array {
		$value = $this->get( $path, [] );
		return is_array( $value ) ? $value : [];
	}

	public function all(): array {
		return $this->merged();
	}

	public function raw(): array {
		return $this->data;
	}

	public function save( array $data ): void {
		$this->data   = $data;
		$this->merged = null;
		update_option( self::OPTION, $data, true );
	}

	public function seedDefaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, [], '', 'yes' );
		}
	}

	private function merged(): array {
		return $this->merged ??= self::deepMerge( $this->defaults(), $this->data );
	}

	private static function deepMerge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if (
				is_array( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& self::isAssoc( $base[ $key ] )
			) {
				$base[ $key ] = self::deepMerge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	private static function isAssoc( array $array ): bool {
		if ( [] === $array ) {
			return true;
		}
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
