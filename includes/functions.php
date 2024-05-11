<?php
/**
 * Helper functions.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

/**
 * Returns this plugin's options.
 *
 * @return array Plugin options.
 */
function get_options() {
	return Plugin::get_instance()
		->get_options_handler()
		->get_options();
}

/**
 * Derives the post or comment object from an "ActivityPub" array.
 *
 * @param  array  $array             The ActivityPub activity or object.
 * @param  string $class             The ActivityPub "class" name.
 * @return \WP_Post|\WP_Comment|null Post or comment object, or null.
 */
function get_object( $array, $class ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
	if ( 'activity' === $class && isset( $array['object']['id'] ) ) {
		// Activity.
		$object_id = $array['object']['id'];
	} elseif ( 'base_object' === $class && isset( $array['id'] ) ) {
		$object_id = $array['id'];
	}

	if ( empty( $object_id ) ) {
		error_log( "[Add-on for ActivityPub] Couldn't find object ID." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return null;
	}

	$query = wp_parse_url( $object_id, PHP_URL_QUERY );
	if ( ! empty( $query ) ) {
		parse_str( $query, $args );
	}

	if ( isset( $args['c'] ) && ctype_digit( $args['c'] ) ) {
		// Comment.
		$post_or_comment = get_comment( $args['c'] );
	} else {
		// Post.
		$post_or_comment = get_post( url_to_postid( $object_id ) );
	}

	if ( empty( $post_or_comment->post_author ) && empty( $post_or_comment->user_id ) ) {
		// Not a post or user comment, most likely. Bail.
		return null;
	}

	return $post_or_comment;
}

/**
 * Parses HTML for microformats.
 *
 * @param  string $html HTML.
 * @return array        `php-mf` output.
 */
function parse( $html ) {
	// We'll want `php-mf` to parse it as a `h-entry`.
	if ( ! preg_match( '~ class=("|\')([^"\']*?)e-content([^"\']*?)("|\')~', $html ) ) {
		$html = '<div class="e-content">' . $html . '</div>';
	}
	if ( ! preg_match( '~ class=("|\')([^"\']*?)h-entry([^"\']*?)("|\')~', $html ) ) {
		$html = '<div class="h-entry">' . $html . '</div>';
	}

	// Look for a cached result.
	$hash = hash( 'sha256', $html );
	$mf2  = get_transient( 'indieblocks:mf2:' . $hash ); // Re-using the IndieBlocks plugin's hash means doing less work, possibly.
	if ( false === $mf2 ) {
		// Nothing in cache. Parse post content.
		$mf2 = Mf2\parse( $html );
		set_transient( 'indieblocks:mf2:' . $hash, $mf2, HOUR_IN_SECONDS / 2 );
	}

	return $mf2;
}

/**
 * Wrapper around `wp_remote_get()`.
 *
 * Unfortunately, the ActivityPub plugin doesn't return JSON for HEAD requests.
 * On top of that, Mastodon may not return Activity Streams JSON for
 * unauthenticated requests.
 *
 * @param  string $url            URL.
 * @param  string $content_type   Request content type.
 * @return \WP_Response|\WP_Error Response.
 */
function remote_get( $url, $content_type = '' ) {
	$hash     = hash( 'sha256', esc_url_raw( $url ) );
	$response = get_transient( "addon-for-activitypub:$hash:get" );

	if ( false !== $response ) {
		return $response;
	}

	$args = array(
		'timeout'             => 11,
		'limit_response_size' => 1048576,
	);

	if ( ! empty( $content_type ) ) {
		$args['headers'] = array( 'Accept' => $content_type );
	}

	$response = wp_remote_get( esc_url_raw( $url ), $args );
	set_transient( "addon-for-activitypub:$hash:get", $response, 600 ); // Cache for (up to) ten minutes.

	return $response;
}

/**
 * Stores a post's "old" and "new" status for the duration of the request.
 *
 * @param  array $post_id Post ID.
 * @param  array $value   Post's old and new status.
 * @return array          Current known statuses.
 */
function current_post_statuses( $post_id, $value = null ) {
	static $post_statuses = array();

	if ( is_array( $value ) ) {
		// Store.
		$post_statuses[ $post_id ] = $value;
	} else {
		// Fetch.
		$current_value = $post_statuses[ $post_id ];
		// And ... forget.
		unset( $post_statuses[ $post_id ] );

		return $current_value;
	}
}
