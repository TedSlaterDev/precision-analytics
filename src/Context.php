<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics;

use WP_Post;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request page context. Resolves the page type and queried object once,
 * lazily, and is read by the tracking + sampling modules instead of repeating
 * is_*() checks.
 */
final class Context {

	private static ?self $instance = null;

	private bool $resolved = false;
	private PageType $type  = PageType::Other;
	private WP_Post|WP_Term|WP_User|null $object = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Reset memoized state (test helper). */
	public static function reset(): void {
		self::$instance = null;
	}

	private function resolve(): void {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;

		if ( is_404() ) {
			$this->type = PageType::NotFound;
		} elseif ( is_feed() ) {
			$this->type = PageType::Feed;
		} elseif ( is_search() ) {
			$this->type = PageType::Search;
		} elseif ( is_front_page() ) {
			$this->type   = PageType::Front;
			$this->object = $this->queriedPost();
		} elseif ( is_home() ) {
			$this->type = PageType::Home;
		} elseif ( is_singular() ) {
			$this->type   = PageType::Singular;
			$this->object = $this->queriedPost();
		} elseif ( is_author() ) {
			$this->type   = PageType::Author;
			$object       = get_queried_object();
			$this->object = $object instanceof WP_User ? $object : null;
		} elseif ( is_post_type_archive() ) {
			$this->type = PageType::PostTypeArchive;
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$this->type   = PageType::Term;
			$object       = get_queried_object();
			$this->object = $object instanceof WP_Term ? $object : null;
		} elseif ( is_date() ) {
			$this->type = PageType::Date;
		} else {
			$this->type = PageType::Other;
		}
	}

	private function queriedPost(): ?WP_Post {
		$object = get_queried_object();
		return $object instanceof WP_Post ? $object : null;
	}

	public function type(): PageType {
		$this->resolve();
		return $this->type;
	}

	public function post(): ?WP_Post {
		$this->resolve();
		return $this->object instanceof WP_Post ? $this->object : null;
	}

	public function term(): ?WP_Term {
		$this->resolve();
		return $this->object instanceof WP_Term ? $this->object : null;
	}

	public function user(): ?WP_User {
		$this->resolve();
		return $this->object instanceof WP_User ? $this->object : null;
	}

	/**
	 * Whether this request should ever carry tracking output. Filters out the
	 * request types that never make sense to track (admin, feeds, REST, cron,
	 * CLI, XML-RPC, previews). Per-visitor exclusions live in Sampler.
	 */
	public function isTrackable(): bool {
		if ( is_admin() || is_feed() || is_preview() || is_trackback() || is_robots() ) {
			return false;
		}
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return (bool) apply_filters( 'precision_analytics/is_trackable', true, $this );
	}

	/** Current pagination index (1-based). */
	public function pageNumber(): int {
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 1 ) {
			$paged = (int) get_query_var( 'page' );
		}
		return max( 1, $paged );
	}

	public function isPaged(): bool {
		return $this->pageNumber() > 1;
	}
}
