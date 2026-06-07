<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics;

defined( 'ABSPATH' ) || exit;

/**
 * The resolved type of the current front-end request. Doubles as the value of
 * the optional `page_type` custom dimension.
 */
enum PageType: string {
	case Front           = 'front';
	case Home            = 'home';
	case Singular        = 'singular';
	case Term            = 'term';
	case Author          = 'author';
	case PostTypeArchive = 'post_type_archive';
	case Date            = 'date';
	case Search          = 'search';
	case NotFound        = 'not_found';
	case Feed            = 'feed';
	case Other           = 'other';
}
