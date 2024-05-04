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
	if ( 'activity' === $class && isset( $activitypub_array['object']['id'] ) ) {
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
	$html = '<div class="h-entry">' . $html . '</div>';

	// Look for a cached result.
	$hash = hash( 'sha256', $html );
	$mf2  = get_transient( 'indieblocks:mf2:' . $hash );
	if ( false === $mf2 ) {
		// Nothing in cache. Parse post content.
		$mf2 = Mf2\parse( $html );
		set_transient( 'indieblocks:mf2:' . $hash, $mf2, 1800 ); // Cache for half an hour.
	}

	return $mf2;
}

/**
 * Wrapper around `wp_remote_get()`.
 *
 * Unfortunately, the ActivityPub doesn't return JSON for head requests ...
 *
 * @param  string $url            URL.
 * @param  string $content_type   Request content type.
 * @return \WP_Response|\WP_Error Response.
 */
function remote_get( $url, $content_type = '' ) {
	$response = get_transient( "addon-for-activitypub:$url:get" );

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

	set_transient( "addon-for-activitypub:$url:get", $response, 600 ); // Cache for (up to) ten minutes.

	/** @todo: Store the reply URL in a custom field, to prevent us from fetching the remote page all the time. */
}
