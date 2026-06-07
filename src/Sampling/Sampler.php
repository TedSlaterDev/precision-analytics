<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Sampling;

use OrchardGrove\PrecisionAnalytics\Context;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the current visitor should send analytics data.
 *
 * Resolution order: hard exclusions (admin/role/logged-in/user ID) →
 * first matching per-segment rate rule → global send-rate. The yes/no is made
 * deterministic per visitor with a salted hash of request signals, so a visitor
 * is consistently in or out of the sample (no half-tracked sessions) without
 * needing a cookie set before headers are sent.
 */
final class Sampler {

	public function __construct( private Options $options ) {}

	public function shouldTrack( Context $context ): bool {
		if ( $this->isExcluded() ) {
			return false;
		}
		if ( ! $this->options->bool( 'sampling.enabled' ) ) {
			return true;
		}

		$rate = $this->rateFor( $context );
		if ( $rate >= 100 ) {
			return true;
		}
		if ( $rate <= 0 ) {
			return false;
		}
		return $this->coin() < $rate;
	}

	/** Hard exclusions — applied even when rate sampling is off. */
	private function isExcluded(): bool {
		if ( $this->options->bool( 'sampling.exclude_logged_in' ) && is_user_logged_in() ) {
			return true;
		}

		$user = wp_get_current_user();
		if ( $user && $user->ID ) {
			$roles = $this->options->arr( 'sampling.exclude_roles' );
			if ( $roles && array_intersect( $roles, (array) $user->roles ) ) {
				return true;
			}
			$ids = $this->userIdList();
			if ( $ids && in_array( (int) $user->ID, $ids, true ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'precision_analytics/exclude_request', false, $this );
	}

	/** The send-rate (0–100) that applies to this request. */
	public function rateFor( Context $context ): int {
		foreach ( $this->rules() as $rule ) {
			if ( $this->matches( $rule, $context ) ) {
				return $rule['rate'];
			}
		}
		return $this->clampRate( $this->options->int( 'sampling.rate', 100 ) );
	}

	/**
	 * Parse the rules textarea into structured rules.
	 *
	 * @return array<int,array{attribute:string,value:string,rate:int}>
	 */
	public function rules(): array {
		$parsed = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $this->options->str( 'sampling.rules' ) ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ':', $line ) );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			[ $attribute, $value, $rate ] = $parts;
			if ( '' === $attribute || '' === $value ) {
				continue;
			}
			$parsed[] = [
				'attribute' => strtolower( $attribute ),
				'value'     => $value,
				'rate'      => $this->clampRate( (int) $rate ),
			];
		}
		return $parsed;
	}

	/**
	 * @param array{attribute:string,value:string,rate:int} $rule
	 */
	private function matches( array $rule, Context $context ): bool {
		$value = $rule['value'];

		switch ( $rule['attribute'] ) {
			case 'post_type':
				$post = $context->post();
				return $post && $post->post_type === $value;

			case 'post_id':
				$post = $context->post();
				return $post && (string) $post->ID === $value;

			case 'page_type':
				return $context->type()->value === $value;

			case 'author':
				$post = $context->post();
				if ( ! $post ) {
					return false;
				}
				$author_id = (int) $post->post_author;
				if ( (string) $author_id === $value ) {
					return true;
				}
				$nicename = (string) get_the_author_meta( 'user_nicename', $author_id );
				return '' !== $nicename && strtolower( $nicename ) === strtolower( $value );

			case 'category':
				$post = $context->post();
				return $post && has_category( $value, $post );

			case 'tag':
				$post = $context->post();
				return $post && has_tag( $value, $post );

			case 'logged_in':
				$want = in_array( strtolower( $value ), [ '1', 'true', 'yes' ], true );
				return is_user_logged_in() === $want;

			case 'user_role':
				$user = wp_get_current_user();
				return $user && in_array( $value, (array) $user->roles, true );

			default:
				return (bool) apply_filters( 'precision_analytics/sample_rule_match', false, $rule, $context );
		}
	}

	/** A stable 0–99 value for this visitor. */
	private function coin(): int {
		$seed = (string) apply_filters( 'precision_analytics/sample_seed', $this->defaultSeed(), $this );
		return (int) ( hexdec( substr( hash( 'sha256', $seed ), 0, 8 ) ) % 100 );
	}

	private function defaultSeed(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return wp_salt( 'auth' ) . '|' . $ip . '|' . $ua . '|' . gmdate( 'Y-m-d' );
	}

	/** @return int[] */
	private function userIdList(): array {
		$list = [];
		foreach ( explode( ',', $this->options->str( 'sampling.exclude_user_ids' ) ) as $id ) {
			$id = (int) trim( $id );
			if ( $id > 0 ) {
				$list[] = $id;
			}
		}
		return $list;
	}

	private function clampRate( int $rate ): int {
		return max( 0, min( 100, $rate ) );
	}
}
