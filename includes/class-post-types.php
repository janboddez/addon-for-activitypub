<?php
/**
 * Translates "IndieWeb" post types to the proper activities and objects.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

class Post_Types {
	/**
	 * Registers hook callbacks.
	 */
	public static function register() {
		// Parse for microformats on save.
		add_filter( 'save_post', array( __CLASS__, 'set_post_meta' ), 999 );

		// If applicable, ensure the account we're targetting is mentioned.
		add_filter( 'activitypub_extract_mentions', array( __CLASS__, 'add_mentions' ), 999, 3 );

		// Add `inReplyTo` URL.
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'filter_object' ), 999, 4 );
	}

	/**
	 * Store (for now) Fediverse-compatible `in-reply-to` URLs.
	 *
	 * @param int|\WP_Post $post Post ID or object, depending on where the request originated.
	 */
	public static function set_post_meta( $post ) {
		$options = get_options();
		if ( empty( $options['enable_replies'] ) ) {
			error_log( '[Add-on for ActivityPub] Replies disabled.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$post = get_post( $post );

		$post_types = get_post_types_by_support( 'activitypub' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			error_log( '[Add-on for ActivityPub] Unsupported post type.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		if ( empty( $post->post_content ) ) {
			error_log( '[Add-on for ActivityPub] Post empty.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Apply content filters.
		$content = apply_filters( 'the_content', $post->post_content );
		// Look for microformats.
		$mf2 = parse( $content );
		// Look for an `in-reply-to` URL.
		$props = ! empty( $mf2['items'][0]['properties'] )
			? $mf2['items'][0]['properties']
			: array();

		if ( ! empty( $props['in-reply-to'][0] ) && filter_var( $props['in-reply-to'][0], FILTER_VALIDATE_URL ) ) {
			$url = $props['in-reply-to'][0];
		} elseif ( ! empty( $props['in-reply-to'][0]['value'] ) && filter_var( $props['in-reply-to'][0]['value'], FILTER_VALIDATE_URL ) ) {
			$url = $props['in-reply-to'][0]['value'];
		}

		if ( ! empty( $props['in-reply-to'][0]['properties']['author'][0] ) && is_string( $props['in-reply-to'][0]['properties']['author'][0] ) ) {
			$author = $props['in-reply-to'][0]['properties']['author'][0];
		}

		if ( ! empty( $url ) ) {
			error_log( '[Add-on for ActivityPub] Found `in-reply-to` URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			// Ensure the linked URL is actually Fediverse compatible.
			$response     = remote_get( $url, 'application/activity+json' ); // WordPress would return HTML in response to a HEAD request.
			$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		}

		if ( ! empty( $content_type ) && is_string( $content_type ) ) {
			$content_type = strtok( $content_type, ';' );
			strtok( '', '' );
		}

		if ( ! empty( $content_type ) && in_array( $content_type, array( 'application/json', 'application/activity+json', 'application/ld+json' ), true ) ) {
			// Doesn't mean all that much. E.g., Mastodon will return
			// `application/json` (and an error) when "Authorized Fetch" is
			// enabled.

			$json = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! empty( $json->attributedTo ) && is_string( $json->attributedTo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// Now this is clear. This would be a Fediverse profile.
				error_log( '[Add-on for ActivityPub] Found an author URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$actor_url = $json->attributedTo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			} elseif ( ! empty( $author ) && preg_match( '~^@([^@]+?@[^@]+?)$~', $author, $match ) && filter_var( $match[1], FILTER_VALIDATE_EMAIL ) ) {
				// We could be replying to a Fediverse account here.
				error_log( '[Add-on for ActivityPub] Found something that sure looks like a "Fediverse handle."' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$handle = $match[1];
			} else {
				// Could still be a Fediverse instance that has "Authorized Fetch" enabled.
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( 0 === strpos( ltrim( $path, '/' ), '@' ) ) {
					error_log( '[Add-on for ActivityPub] Found a possible Mastodon URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					// Sure looks like a Mastodon URL. It's impossible to do this for all kinds of URLs, though.
					$path = strtok( $path, '/' );
					strtok( '', '' );

					$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
					$host   = wp_parse_url( $url, PHP_URL_HOST );

					$actor_url = "$scheme://$host/$path";
				}
			}
		}

		if ( class_exists( '\\Activitypub\\Webfinger' ) ) {
			if ( empty( $handle ) && ! empty( $actor_url ) && method_exists( \Activitypub\Webfinger::class, 'uri_to_acct' ) ) {
				// We got a URL, either from an ActivityPub object or because it
				// looked like a Mastodon one, but no `acct`.
				$handle = \Activitypub\Webfinger::uri_to_acct( $actor_url );
				if ( is_string( $handle ) && 0 === strpos( $handle, 'acct:' ) ) {
					// Whatever "actor URL" we had, it seems to support Webfinger.
					$handle = substr( $handle, 5 );
				}
			} elseif ( ! empty( $handle ) && empty( $actor_url ) && method_exists( \Activitypub\Webfinger::class, 'resolve' ) ) {
				// We got a `@user@example.org` handle but no URL.
				$actor_url = \Activitypub\Webfinger::resolve( $handle );
			}
		}

		if ( ! empty( $handle ) && ! is_wp_error( $handle ) && ! empty( $actor_url ) && ! is_wp_error( $actor_url ) ) {
			$actor = array();
			$actor[ filter_var( $handle, FILTER_SANITIZE_EMAIL ) ] = esc_url_raw( $actor_url );
		}

		if ( ! empty( $url ) && ! empty( $actor ) ) {
			update_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url', esc_url_raw( $url ) );
			update_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor', $actor );
		} else {
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
		}
	}

	/**
	 * Store (for now) Fediverse-compatible `in-reply-to` URLs.
	 *
	 * @param  array    $mentions     Associative array of accounts to mention.
	 * @param  string   $post_content Post content.
	 * @param  \WP_Post $wp_object    Post (or comment?) object.
	 * @return array                  Filtered array.
	 */
	public static function add_mentions( $mentions, $post_content, $wp_object ) {
		$options = get_options();
		if ( empty( $options['enable_replies'] ) ) {
			return $mentions;
		}

		if ( ! $wp_object instanceof \WP_Post ) {
			return $mentions;
		}

		$url   = get_post_meta( $wp_object->ID, '_addon_for_activitypub_in_reply_to_url', true );
		$actor = get_post_meta( $wp_object->ID, '_addon_for_activitypub_in_reply_to_actor', true );

		if ( empty( $url ) || empty( $actor ) ) {
			return $mentions;
		}

		if ( ! is_array( $actor ) ) {
			return $mentions;
		}

		foreach ( $actor as $name => $href ) {
			$mentions[ '@' . $name ] = $href;
			break;
		}

		return array_unique( $mentions );
	}

	/**
	 * Filters (Activities') objects before they're rendered or federated.
	 *
	 * @param  array       $array  Activity or object.
	 * @param  string      $class  Class name.
	 * @param  string      $id     Activity object ID.
	 * @param  Base_Object $object Activity object.
	 * @return array               The updated array.
	 */
	public static function filter_object( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_or_comment = get_object( $array, $class ); // We can probably simpliy this by using `$object` directly, but this works.
		if ( ! $post_or_comment ) {
			return $array;
		}

		$options = get_options();

		/**
		 * Replies.
		 *
		 * @todo: Parse post content on save instead when being fetched, to save on all this overhead.
		 */
		if ( $post_or_comment instanceof \WP_Post && ! empty( $options['enable_replies'] ) ) {
			$url = get_post_meta( $post_or_comment->ID, '_addon_for_activitypub_in_reply_to_url', true );
		}

		if ( ! empty( $url ) ) {
			// Add `inReplyTo` property.
			if ( 'activity' === $class ) {
				$array['object']['inReplyTo'] = $url;
			} elseif ( 'base_object' === $class ) {
				$array['inReplyTo'] = $url;
			}

			// Trim any reply context off the post content.
			$content = apply_filters( 'the_content', $post_or_comment->post_content ); // Wish we didn't have to do this *again*.

			if ( preg_match( '~<div class="e-content">.+?</div>~s', $content, $match ) ) {
				$copy               = clone $post_or_comment;
				$copy->post_content = $match[0]; // The `e-content` only, without reply context (if any).

				// Regenerate "ActivityPub content" using the "slimmed down"
				// post content. We ourselves use the "original" post, hence
				// the need to pass a copy with modified content.
				$content = apply_filters( 'activitypub_the_content', $match[0], $copy );

				if ( 'activity' === $class ) {
					$array['object']['content'] = $content;

					foreach ( $array['object']['contentMap'] as $locale => $value ) {
						$array['object']['contentMap'][ $locale ] = $content;
					}
				} elseif ( 'base_object' === $class ) {
					$array['content'] = $content;

					foreach ( $array['contentMap'] as $locale => $value ) {
						$array['contentMap'][ $locale ] = $content;
					}
				}
			}
		}

		return $array;
	}
}
