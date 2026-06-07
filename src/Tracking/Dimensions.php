<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

use OrchardGrove\PrecisionAnalytics\Context;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The custom-dimension registry. Maps each toggleable attribute to a GA4 event
 * parameter name + scope, and resolves the parameter map for a request.
 *
 * GA4 only makes a parameter queryable once a matching custom dimension exists
 * in the property — the Attributes settings tab reads REGISTRY to show the exact
 * names and scopes to register.
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

	public function __construct( private Options $options ) {}

	/**
	 * The GA4 parameter map for the current request (param_name => value),
	 * limited to enabled attributes with a non-empty value.
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
				$params[ $meta['param'] ] = $value;
			}
		}
		/**
		 * Filter the resolved parameter map before output.
		 *
		 * @param array<string,string> $params
		 */
		return apply_filters( 'precision_analytics/dimensions', $params, $context );
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
