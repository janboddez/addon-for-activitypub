<?php
/**
 * Helper functions.
 *
 * @package ActivityPub\Addon
 */

namespace Activitypub\Addon;

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

	set_transient( "addon-for-activitypub:$url:get", $response, 300 );
}
