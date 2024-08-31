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
 * Used to "forward" the status arguments of `transition_post_status` to
 * `rest_after_insert{$post->post_type}`.
 *
 * @param  array $post_id Post ID.
 * @param  array $value   Associative array containing the post's old and new statuses.
 * @return array|null     Current known statuses.
 */
function current_post_statuses( $post_id, $value = null ) {
	static $post_statuses = array();

	if ( is_array( $value ) ) {
		// Store.
		$post_statuses[ $post_id ] = $value;
	} elseif ( ! empty( $post_statuses[ $post_id ] ) ) {
		// Fetch.
		$current_value = $post_statuses[ $post_id ];
		// And ... forget.
		unset( $post_statuses[ $post_id ] );

		return $current_value;
	}

	return null;
}

/**
 * Checks whether the current REST request is for an ActivityPub endpoint.
 *
 * @return bool Whether the current REST request is for an ActivityPub endpoint.
 */
function is_activitypub() {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		// Not a REST request.
		return false;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return ( false !== strpos( $_SERVER['REQUEST_URI'], trailingslashit( rest_get_url_prefix() ) . ACTIVITYPUB_REST_NAMESPACE ) );
}

/**
 * Stores a remote image locally.
 *
 * @param  string $url      Image URL.
 * @param  string $filename Target file name.
 * @param  string $dir      Target directory, relative to WordPress' uploads directory.
 * @param  string $width    Target width.
 * @param  string $height   Target height.
 * @return string|null      Local image URL, or nothing on failure.
 */
function store_image( $url, $filename, $dir, $width = 150, $height = 150 ) {
	if ( 0 === strpos( $url, home_url() ) ) {
		// Not a remote URL.
		return $url;
	}

	$upload_dir = wp_upload_dir();
	$dir        = trailingslashit( $upload_dir['basedir'] ) . trim( $dir, '/' );

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir ); // Recursive directory creation. Permissions are taken from the nearest parent folder.
	}

	// Where we'll eventually store the image.
	$file_path = trailingslashit( $dir ) . sanitize_file_name( $filename );

	if ( file_exists( $file_path ) && ( time() - filectime( $file_path ) ) < MONTH_IN_SECONDS ) {
		// File exists and is under a month old. We're done here.
		return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
	}

	// In case we previously added a file extension.
	foreach ( glob( "$file_path.*" ) as $match ) {
		$file_path = $match;

		if ( ( time() - filectime( $file_path ) ) < MONTH_IN_SECONDS ) {
			// So, _this_ file exists and is under a month old. Let's return it.
			return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		}

		break; // Stop after the first match.
	}

	// To be able to move files around.
	global $wp_filesystem;

	if ( ! function_exists( 'download_url' ) || empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	// OK, so either the file doesn't exist or is over a month old. Attempt to
	// download the image.
	$temp_file = download_url( esc_url_raw( $url ) );

	if ( is_wp_error( $temp_file ) ) {
		debug_log( '[Add-on for ActivityPub] Could not download the image at ' . esc_url_raw( $url ) . '.' );
		return null;
	}

	if ( '' === pathinfo( $file_path, PATHINFO_EXTENSION ) && function_exists( 'mime_content_type' ) ) {
		// Some filesystem drivers--looking at you, S3 Uploads--take issue with
		// extensionless files.
		$mime = mime_content_type( $temp_file );

		if ( is_string( $mime ) ) {
			$mimes = new Mimey\MimeTypes(); // A MIME type to file extension map, essentially.
			$ext   = $mimes->getExtension( $mime );
		}
	}

	if ( ! empty( $ext ) ) {
		if ( '' === pathinfo( $temp_file, PATHINFO_EXTENSION ) ) {
			// If our temp file is missing an extension, too, rename it before
			// attempting to run any image resizing functions on it.
			if ( $wp_filesystem->move( $temp_file, "$temp_file.$ext" ) ) {
				// Successfully renamed the file.
				$temp_file .= ".$ext";
			} elseif ( $wp_filesystem->put_contents( "$temp_file.$ext", $wp_filesystem->get_contents( $temp_file ), 0644 ) ) {
				// This here mainly because, once again,  plugins like S3
				// Uploads, or rather, the AWS SDK for PHP, doesn't always play
				// nice with `WP_Filesystem::move()`.
				wp_delete_file( $temp_file ); // Delete the original.
				$temp_file .= ".$ext"; // Our new file path from here on out.
			}
		}

		// Tack our newly discovered extension onto our target file name, too.
		$file_path .= ".$ext";
	}

	if ( ! function_exists( 'wp_crop_image' ) ) {
		// Load WordPress' image functions.
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	if ( ! file_is_valid_image( $temp_file ) || ! file_is_displayable_image( $temp_file ) ) {
		debug_log( '[Add-on for ActivityPub] Invalid image file: ' . esc_url_raw( $url ) . '.' );

		// Delete temp file and return.
		wp_delete_file( $temp_file );

		return null;
	}

	// Move the altered file to its final destination.
	if ( ! $wp_filesystem->move( $temp_file, $file_path ) ) {
		// If `WP_Filesystem::move()` failed, do it this way.
		$wp_filesystem->put_contents( $file_path, $wp_filesystem->get_contents( $temp_file ), 0644 );
		wp_delete_file( $temp_file ); // Always delete the original.
	}

	// Try to scale down and crop it. Somehow, at least in combination with S3
	// Uploads, `WP_Image_Editor::save()` attempts to write the image to S3
	// storage, which I guess fails because, well, for one, the path doesn't
	// match. Which is why we moved it before doing this.
	$image = wp_get_image_editor( $file_path );

	if ( ! is_wp_error( $image ) ) {
		$image->resize( $width, $height, true );
		$result = $image->save( $file_path );

		if ( isset( $result['path'] ) && $file_path !== $result['path'] ) {
			// The image editor's `save()` method has altered our temp file's
			// path (e.g., added an extension that wasn't there).
			wp_delete_file( $file_path ); // Delete "old" image.
			$file_path = $result['path']; // And update the file path (and name).
		} elseif ( is_wp_error( $result ) ) {
			debug_log( "[Add-on for ActivityPub] Could not resize $file_path: " . $result->get_error_message() . '.' );
		}
	} else {
		debug_log( "[Add-on for ActivityPub] Could not load $file_path into WordPress' image editor: " . $image->get_error_message() . '.' );
	}

	// And return the local URL.
	return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
}
