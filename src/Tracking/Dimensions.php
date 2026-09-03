<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

use OrchardGrove\PrecisionAnalytics\Context;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The custom-dimension registry. Maps each toggleable attribute to a GA4
 * parameter name + scope, and resolves the parameter map for a request.
 *
 * GA4 only makes a parameter queryable once a matching custom dimension exists
 * in the property — the Attributes settings tab reads REGISTRY (and any
 * per-attribute name overrides) to show the exact names and scopes to register.
 * Overrides exist so a site can keep an existing dimension flowing — e.g. send
 * the author as MonsterInsights' `author` instead of `post_author`.
 *
 * Scope matters for delivery, not just registration: event-scoped values ride
 * on the config as event parameters, while user-scoped values must be sent as
 * GA4 *user properties* or a user-scoped dimension stays "(not set)".
 */
final class Dimensions {

	/**
	 * key => [ param, scope ('event'|'user'), label ].
	 *
	 * @var array<string,array{param:string,scope:string,label:string}>
	 */
	public const REGISTRY = [
		'author'         => [ 'param' => 'post_author',     'scope' => 'event', 'label' => 'Author' ],
		'post_type'      => [ 'param' => 'post_type',       'scope' => 'event', 'label' => 'Post type' ],
		'category'       => [ 'param' => 'primary_category', 'scope' => 'event', 'label' => 'Primary category' ],
		'tags'           => [ 'param' => 'post_tags',       'scope' => 'event', 'label' => 'Tags' ],
		'post_id'        => [ 'param' => 'post_id',         'scope' => 'event', 'label' => 'Post ID' ],
		'page_type'      => [ 'param' => 'page_type',       'scope' => 'event', 'label' => 'Page type' ],
		'logged_in'      => [ 'param' => 'logged_in',       'scope' => 'user',  'label' => 'Logged-in status' ],
		'user_role'      => [ 'param' => 'user_role',       'scope' => 'user',  'label' => 'User role' ],
		'published_year' => [ 'param' => 'published_year',  'scope' => 'event', 'label' => 'Published year' ],
	];

	/**
	 * Parameter names other plugins registered in GA4, offered as a one-look
	 * migration hint in the settings UI (attribute key => their name).
	 */
	public const MONSTERINSIGHTS_NAMES = [
		'author'    => 'author',
		'category'  => 'category',
		'tags'      => 'tags',
		'post_type' => 'post_type',
	];

	public function __construct( private Options $options ) {}

	/**
	 * A valid GA4 parameter name, or '' when the input is unusable. GA4 rules:
	 * letters, digits, underscores; starts with a letter; at most 40 chars;
	 * the google_/ga_/firebase_ prefixes are reserved.
	 */
	public static function sanitizeParam( string $raw ): string {
		$raw = trim( $raw );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $raw ) ) {
			return '';
		}
		if ( preg_match( '/^(google_|ga_|firebase_)/i', $raw ) ) {
			return '';
		}
		return $raw;
	}

	/** The GA4 parameter name an attribute is sent as (override or default). */
	public function paramName( string $key ): string {
		$default  = self::REGISTRY[ $key ]['param'] ?? $key;
		$override = self::sanitizeParam( $this->options->str( "attributes.params.$key" ) );
		return '' !== $override ? $override : $default;
	}

	/** Registry scope for a resolved parameter name ('event' unless known user-scoped). */
	public function scopeOf( string $param ): string {
		foreach ( self::REGISTRY as $key => $meta ) {
			if ( $this->paramName( $key ) === $param ) {
				return $meta['scope'];
			}
		}
		return 'event';
	}

	/**
	 * The GA4 parameter map for the current request (param_name => value),
	 * limited to enabled attributes with a non-empty value. Flat — event and
	 * user scopes together — for consumers that don't distinguish (GTM's
	 * dataLayer, the filter). Use collectScoped() for gtag delivery.
	 *
	 * @return array<string,string>
	 */
	public function collect( Context $context ): array {
		$params = [];
		foreach ( self::REGISTRY as $key => $meta ) {
			if ( ! $this->options->bool( "attributes.$key" ) ) {
				continue;
			}
			$value = $this->value( $key, $context );
			if ( '' !== $value ) {
				$params[ $this->paramName( $key ) ] = $value;
			}
		}
		/**
		 * Filter the resolved parameter map before output.
		 *
		 * @param array<string,string> $params
		 */
		return apply_filters( 'precision_analytics/dimensions', $params, $context );
	}

	/**
	 * The parameter map split by GA4 scope: `event` parameters and `user`
	 * properties. Anything the filter added under an unknown name is treated
	 * as event-scoped.
	 *
	 * @return array{event:array<string,string>,user:array<string,string>}
	 */
	public function collectScoped( Context $context ): array {
		return $this->split( $this->collect( $context ) );
	}

	/**
	 * @param array<string,string> $params
	 * @return array{event:array<string,string>,user:array<string,string>}
	 */
	public function split( array $params ): array {
		$scoped = [ 'event' => [], 'user' => [] ];
		foreach ( $params as $param => $value ) {
			$scoped[ $this->scopeOf( (string) $param ) ][ $param ] = $value;
		}
		return $scoped;
	}

	private function value( string $key, Context $context ): string {
		$post = $context->post();

		switch ( $key ) {
			case 'author':
				return $post ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '';

			case 'post_type':
				return $post ? (string) $post->post_type : '';

			case 'category':
				if ( ! $post ) {
					return '';
				}
				$cats = get_the_category( $post->ID );
				return ( $cats && isset( $cats[0] ) ) ? (string) $cats[0]->name : '';

			case 'tags':
				if ( ! $post ) {
					return '';
				}
				$tags = get_the_tags( $post->ID );
				if ( ! is_array( $tags ) ) {
					return '';
				}
				return implode( ', ', array_map( static fn( $t ) => (string) $t->name, array_slice( $tags, 0, 10 ) ) );

			case 'post_id':
				return $post ? (string) $post->ID : '';

			case 'page_type':
				return $context->type()->value;

			case 'logged_in':
				return is_user_logged_in() ? 'yes' : 'no';

			case 'user_role':
				$user = wp_get_current_user();
				if ( ! $user || ! $user->ID ) {
					return 'visitor';
				}
				$roles = (array) $user->roles;
				return $roles ? (string) reset( $roles ) : 'visitor';

			case 'published_year':
				return $post ? (string) get_post_time( 'Y', false, $post ) : '';

			default:
				return '';
		}
	}
}
