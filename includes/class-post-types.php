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
		add_filter( 'save_post', array( __CLASS__, 'store_in_reply_to_url' ), 999 );
		add_filter( 'save_post', array( __CLASS__, 'store_repost_of_url' ), 999 );

		// Ensure any "target" account gets mentioned.
		add_filter( 'activitypub_extract_mentions', array( __CLASS__, 'add_mentions' ), 999, 3 );

		// Add `inReplyTo` URL.
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'add_in_reply_to_url' ), 999, 4 );

		// Transform "reposts" to Announce activities.
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'transform_to_announce' ), 999, 4 );

		// And the deletion of "reposts" to "Undo (Announce)" activities.
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'transform_to_undo_announce' ), 999, 4 );

		// Don't send Announce activities when reposts are updated.
		add_filter( 'activitypub_send_activity_to_followers', array( __CLASS__, 'disable_federation' ), 999, 4 );
		// Keep reposts out of "featured post" collections.
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_from_collections' ) );
		// Correct the total post count, for "featured post" collections.
		add_filter( 'get_usernumposts', array( __CLASS__, 'repair_count' ), 99, 2 );

		// And disable "fetching" of reposts.
		add_filter( 'template_include', array( __CLASS__, 'disable_fetch' ), 10 ); // Prio must be lower than `33`.
	}

	/**
	 * Stores a Fediverse-compatible `in-reply-to` URL, if any.
	 *
	 * Looks for microformats and a `in-reply-to` URL. Then tries to find out
	 * if it happens to be a "Fediverse" URL.
	 *
	 * @param int|\WP_Post $post Post ID or object, depending on where the request originated.
	 */
	public static function store_in_reply_to_url( $post ) {
		$options = get_options();
		if ( empty( $options['enable_replies'] ) ) {
			return;
		}

		$post = get_post( $post );

		$post_types = get_post_types_by_support( 'activitypub' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
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

		// If we have an author name, keep it around, too.
		if ( ! empty( $props['in-reply-to'][0]['properties']['author'][0] ) && is_string( $props['in-reply-to'][0]['properties']['author'][0] ) ) {
			$author = $props['in-reply-to'][0]['properties']['author'][0];
		}

		if ( empty( $url ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
			return;
		}

		error_log( '[Add-on for ActivityPub] Found `in-reply-to` URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Ensure the linked URL is actually Fediverse compatible.
		$response     = remote_get( $url, 'application/activity+json' ); // WordPress would return HTML in response to a HEAD request.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! empty( $content_type ) && is_string( $content_type ) ) {
			$content_type = strtok( $content_type, ';' );
			strtok( '', '' );
		}

		if ( empty( $content_type ) || ! in_array( $content_type, array( 'application/json', 'application/activity+json', 'application/ld+json' ), true ) ) {
			// Note: Mastodon will return `application/json` (and an error) when
			// "Authorized Fetch" is enabled.
			/** @link: https://docs.joinmastodon.org/admin/config/#authorized_fetch */
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
			return;
		}

		$json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $json->attributedTo ) && is_string( $json->attributedTo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// This is the type of JSON we want to see. This would be a Fediverse profile.
			error_log( '[Add-on for ActivityPub] Found an author URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$actor_url = $json->attributedTo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} elseif ( ! empty( $author ) && preg_match( '~^@([^@]+?@[^@]+?)$~', $author, $match ) && filter_var( $match[1], FILTER_VALIDATE_EMAIL ) ) {
			// Purely based off the author "handle," we could be replying to a Fediverse account here.
			error_log( '[Add-on for ActivityPub] Found something that sure looks like a "Fediverse handle."' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$handle = $match[1];
		} else {
			// Could *still* be a Fediverse instance that has "Authorized Fetch" enabled.
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( 0 === strpos( ltrim( $path, '/' ), '@' ) ) {
				error_log( '[Add-on for ActivityPub] Found a possible Mastodon URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				// Sure looks like a Mastodon URL. It's impossible to do this for all URLs, though.
				$path = strtok( $path, '/' );
				strtok( '', '' );

				$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
				$host   = wp_parse_url( $url, PHP_URL_HOST );

				$actor_url = "$scheme://$host/$path";
			}
		}

		if ( empty( $handle ) && empty( $actor_url ) ) {
			// We need either a "handle" or URL.
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
			return;
		}

		if ( ! class_exists( '\\Activitypub\\Webfinger' ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
			return;
		}

		if ( empty( $handle ) && ! empty( $actor_url ) && method_exists( \Activitypub\Webfinger::class, 'uri_to_acct' ) ) {
			// We got a URL, either from an ActivityPub object or because it
			// looked like a Mastodon one, but no `acct`.
			$handle = \Activitypub\Webfinger::uri_to_acct( $actor_url );
			if ( is_string( $handle ) && 0 === strpos( $handle, 'acct:' ) ) {
				// Whatever "actor URL" we had, it seems to support Webfinger.
				$handle = filter_var( substr( $handle, 5 ), FILTER_SANITIZE_EMAIL );
			} elseif ( ! empty( $json->attributedTo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// Might not support "reverse Webfinger," but should really be a
				// Fediverse account. Fallback to URL.
				$handle = esc_url_raw( $actor_url );
			}
		} elseif ( ! empty( $handle ) && empty( $actor_url ) && method_exists( \Activitypub\Webfinger::class, 'resolve' ) ) {
			// We got a `@user@example.org` handle but no URL.
			$actor_url = \Activitypub\Webfinger::resolve( $handle );
		}

		if ( empty( $handle ) || is_wp_error( $handle ) || empty( $actor_url ) || is_wp_error( $actor_url ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
			return;
		}

		$actor            = array();
		$actor[ $handle ] = esc_url_raw( $actor_url );

		update_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url', esc_url_raw( $url ) );
		update_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor', $actor );
	}

	/**
	 * Stores a Fediverse-compatible `repost-of` URL, if any.
	 *
	 * Looks for microformats and a `repost-of` URL. Then tries to find out
	 * if it happens to be a "Fediverse" URL.
	 *
	 * @param int|\WP_Post $post Post ID or object, depending on where the request originated.
	 */
	public static function store_repost_of_url( $post ) {
		$options = get_options();
		if ( empty( $options['enable_reposts'] ) ) {
			return;
		}

		$post = get_post( $post );

		$post_types = get_post_types_by_support( 'activitypub' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
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

		if ( ! empty( $props['repost-of'][0] ) && filter_var( $props['repost-of'][0], FILTER_VALIDATE_URL ) ) {
			$url = $props['repost-of'][0];
		} elseif ( ! empty( $props['repost-of'][0]['value'] ) && filter_var( $props['repost-of'][0]['value'], FILTER_VALIDATE_URL ) ) {
			$url = $props['repost-of'][0]['value'];
		}

		// If we have an author name, keep it around, too.
		if ( ! empty( $props['repost-of'][0]['properties']['author'][0] ) && is_string( $props['repost-of'][0]['properties']['author'][0] ) ) {
			$author = $props['repost-of'][0]['properties']['author'][0];
		}

		if ( empty( $url ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_actor' );
			return;
		}

		error_log( '[Add-on for ActivityPub] Found `repost-of` URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Ensure the linked URL is actually Fediverse compatible.
		$response     = remote_get( $url, 'application/activity+json' ); // WordPress would return HTML in response to a HEAD request.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! empty( $content_type ) && is_string( $content_type ) ) {
			$content_type = strtok( $content_type, ';' );
			strtok( '', '' );
		}

		if ( empty( $content_type ) || ! in_array( $content_type, array( 'application/json', 'application/activity+json', 'application/ld+json' ), true ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_actor' );
			return;
		}

		$json = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $json->attributedTo ) && is_string( $json->attributedTo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// This is the type of JSON we want to see. This would be a Fediverse profile.
			error_log( '[Add-on for ActivityPub] Found an author URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$actor_url = $json->attributedTo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} elseif ( ! empty( $author ) && preg_match( '~^@([^@]+?@[^@]+?)$~', $author, $match ) && filter_var( $match[1], FILTER_VALIDATE_EMAIL ) ) {
			// Purely based off the author "handle," we could be replying to a Fediverse account here.
			error_log( '[Add-on for ActivityPub] Found something that sure looks like a "Fediverse handle."' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$handle = $match[1];
		} else {
			// Could *still* be a Fediverse instance that has "Authorized Fetch" enabled.
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( 0 === strpos( ltrim( $path, '/' ), '@' ) ) {
				error_log( '[Add-on for ActivityPub] Found a possible Mastodon URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				// Sure looks like a Mastodon URL. It's impossible to do this for all URLs, though.
				$path = strtok( $path, '/' );
				strtok( '', '' );

				$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
				$host   = wp_parse_url( $url, PHP_URL_HOST );

				$actor_url = "$scheme://$host/$path";
			}
		}

		if ( empty( $handle ) && empty( $actor_url ) ) {
			// We need either a "handle" or URL.
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_actor' );
			return;
		}

		if ( ! class_exists( '\\Activitypub\\Webfinger' ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_actor' );
			return;
		}

		if ( empty( $handle ) && ! empty( $actor_url ) && method_exists( \Activitypub\Webfinger::class, 'uri_to_acct' ) ) {
			// We got a URL, either from an ActivityPub object or because it
			// looked like a Mastodon one, but no `acct`.
			$handle = \Activitypub\Webfinger::uri_to_acct( $actor_url );
			if ( is_string( $handle ) && 0 === strpos( $handle, 'acct:' ) ) {
				// Whatever "actor URL" we had, it seems to support Webfinger.
				$handle = filter_var( substr( $handle, 5 ), FILTER_SANITIZE_EMAIL );
			} elseif ( ! empty( $json->attributedTo ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				// Might not support "reverse Webfinger," but should really be a
				// Fediverse account. Fallback to URL.
				$handle = esc_url_raw( $actor_url );
			}
		} elseif ( ! empty( $handle ) && empty( $actor_url ) && method_exists( \Activitypub\Webfinger::class, 'resolve' ) ) {
			// We got a `@user@example.org` handle but no URL.
			$actor_url = \Activitypub\Webfinger::resolve( $handle );
		}

		if ( empty( $handle ) || is_wp_error( $handle ) || empty( $actor_url ) || is_wp_error( $actor_url ) ) {
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_url' );
			delete_post_meta( $post->ID, '_addon_for_activitypub_repost_of_actor' );
			return;
		}

		$actor            = array();
		$actor[ $handle ] = esc_url_raw( $actor_url );

		update_post_meta( $post->ID, '_addon_for_activitypub_repost_of_url', esc_url_raw( $url ) );
		update_post_meta( $post->ID, '_addon_for_activitypub_repost_of_actor', $actor );
	}

	/**
	 * Adds a mention to posts we think are replies.
	 *
	 * We want the remote post's author to know about our reply. This ensures a
	 * `Mention` tag gets added, and that they get added to the `cc` field.
	 *
	 * @param  array    $mentions     Associative array of accounts to mention.
	 * @param  string   $post_content Post content.
	 * @param  \WP_Post $wp_object    Post (or comment?) object.
	 * @return array                  Filtered array.
	 */
	public static function add_mentions( $mentions, $post_content, $wp_object ) {
		$options = get_options();
		if ( empty( $options['enable_replies'] ) && empty( $options['enable_reposts'] ) ) {
			return $mentions;
		}

		if ( ! $wp_object instanceof \WP_Post ) {
			return $mentions;
		}

		$in_reply_to_actor = get_post_meta( $wp_object->ID, '_addon_for_activitypub_in_reply_to_actor', true );
		$repost_of_actor   = get_post_meta( $wp_object->ID, '_addon_for_activitypub_repost_of_actor', true );

		if ( ! empty( $in_reply_to_actor ) && is_array( $in_reply_to_actor ) ) {
			foreach ( $in_reply_to_actor as $name => $href ) {
				if ( ! preg_match( '~^https?://~', $name ) ) {
					$name = "@{$name}";
				}
				$mentions[ $name ] = $href;
				break;
			}
		}

		if ( ! empty( $repost_of_actor ) && is_array( $repost_of_actor ) ) {
			foreach ( $repost_of_actor as $name => $href ) {
				if ( ! preg_match( '~^https?://~', $name ) ) {
					$name = "@{$name}";
				}
				$mentions[ $name ] = $href;
				break;
			}
		}

		return array_unique( $mentions );
	}

	/**
	 * Transforms a repost into an "Announce" activity.
	 *
	 * @param  array                             $array  Activity or object (array).
	 * @param  string                            $class  Class name.
	 * @param  string                            $id     Activity or object ID.
	 * @param  \Activitypub\Activity\Base_Object $object Activity or object (object).
	 * @return array                                     The updated array.
	 */
	public static function transform_to_announce( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$options = get_options();
		if ( empty( $options['enable_reposts'] ) ) {
			return $array;
		}

		if ( 'activity' !== $class ) {
			return $array;
		}

		if ( ! isset( $array['type'] ) ) {
			return $array;
		}

		if ( ! in_array( $array['type'], array( 'Create', 'Update' ), true ) ) {
			return $array;
		}

		$post_or_comment = get_object( $array, $class ); // Could probably also use `$id` or even `$object`, but this works.
		if ( ! $post_or_comment ) {
			return $array;
		}

		if ( ! $post_or_comment instanceof \WP_Post ) {
			return $array;
		}

		$url = get_post_meta( $post_or_comment->ID, '_addon_for_activitypub_repost_of_url', true );
		if ( empty( $url ) ) {
			return $array;
		}

		$array['type']   = 'Announce';
		$array['object'] = esc_url_raw( $url );

		if ( '' === get_post_meta( $post_or_comment->ID, '_addon_for_activitypub_announce_activity', true ) ) {
			// Store Announce activity, but don't override an existing one. The
			// idea being that we need it, and it has to be accurate (?) to ever
			// undo our reblog.
			add_post_meta( $post_or_comment->ID, '_addon_for_activitypub_announce_activity', $array );
		}

		return $array;
	}

	/**
	 * Transforms the deletion of a repost into an "Undo (Announce)" activity.
	 *
	 * @param  array                             $array  Activity or object (array).
	 * @param  string                            $class  Class name.
	 * @param  string                            $id     Activity or object ID.
	 * @param  \Activitypub\Activity\Base_Object $object Activity or object (object).
	 * @return array                                     The updated array.
	 */
	public static function transform_to_undo_announce( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'activity' !== $class ) {
			return $array;
		}

		if ( ! isset( $array['type'] ) || 'Delete' !== $array['type'] ) {
			return $array;
		}

		$post_or_comment = get_object( $array, $class ); // Could probably also use `$id` or even `$object`, but this works.
		if ( ! $post_or_comment ) {
			return $array;
		}

		if ( ! $post_or_comment instanceof \WP_Post ) {
			return $array;
		}

		$announce = get_post_meta( $post_or_comment->ID, '_addon_for_activitypub_announce_activity', true );
		if ( empty( $announce ) ) {
			return $array;
		}

		$array['type']   = 'Undo'; // Rather than Delete.
		$array['object'] = $announce;

		update_post_meta( $post_or_comment->ID, '_addon_for_activitypub_undo_announce_activity', $array );
		delete_post_meta( $post_or_comment->ID, '_addon_for_activitypub_announce_activity', $array );

		return $array;
	}

	/**
	 * Adds the `inReplyTo` property to reply posts.
	 *
	 * @param  array                             $array  Activity or object (array).
	 * @param  string                            $class  Class name.
	 * @param  string                            $id     Activity or object ID.
	 * @param  \Activitypub\Activity\Base_Object $object Activity or object (object).
	 * @return array                                     The updated array.
	 */
	public static function add_in_reply_to_url( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_or_comment = get_object( $array, $class ); // Could probably also use `$id` or even `$object`, but this works.
		if ( ! $post_or_comment ) {
			return $array;
		}

		$options = get_options();

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

	/**
	 * Disables content negotiation for reposts.
	 *
	 * Reposts will still appear in outboxes as Announce activities.
	 *
	 * @param  string $template The path of the template to include.
	 * @return string           The same template.
	 */
	public static function disable_fetch( $template ) {
		if ( ! class_exists( '\\Activitypub\\Activitypub' ) ) {
			return $template;
		}

		if ( ! method_exists( \Activitypub\Activitypub::class, 'render_json_template' ) ) {
			return $template;
		}

		if ( ! is_singular() ) {
			return $template;
		}

		$post     = get_queried_object();
		$announce = get_post_meta( $post->ID, '_addon_for_activitypub_announce_activity', true );

		if ( '' !== $announce ) {
			// Disable content negotiation.
			remove_filter( 'template_include', array( \Activitypub\Activitypub::class, 'render_json_template' ), 99 );
		}

		return $template;
	}

	/**
	 * Disables federation of repost updates.
	 *
	 * @param  bool                           $send      Whether we should post to followers' inboxes.
	 * @param  \Activitypub\Activity\Activity $activity  Activity object.
	 * @param  int                            $user_id   User ID.
	 * @param  \WP_Post|\WP_Comment|\WP_User  $wp_object Object of the activity.
	 */
	public static function disable_federation( $send, $activity, $user_id, $wp_object ) {
		if ( in_array( $activity->get_type(), array( 'Create', 'Delete' ), true ) ) {
			// We want to disable only Updates.
			return $send;
		}

		if ( ! $wp_object instanceof \WP_Post ) {
			return $send;
		}

		$url = get_post_meta( $wp_object->ID, '_addon_for_activitypub_repost_of_url', true );
		if ( empty( $url ) ) {
			// Leave non-reposts alone.
			return $send;
		}

		return false;
	}

	/**
	 * Keeps reposts out of featured post collections.
	 *
	 * @todo: Remove also reposts *from featured post collections*. It's okay for them to stay in outboxes.
	 *
	 * @param \WP_Query $query Database query object.
	 */
	public static function hide_from_collections( $query ) {
		// @codingStandardsIgnoreStart
		// Defaults to `true` (here), so we actually *don't* want to run this check.
		// if ( ! empty( $query->query_vars['suppress_filters'] ) ) {
		// 	return;
		// }
		// @codingStandardsIgnoreEnd

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_path = $_SERVER['REQUEST_URI'];
		if ( 0 !== strpos( $current_path, '/wp-json/activitypub' ) ) {
			return;
		}

		if ( false === strpos( $current_path, 'collections/featured' ) ) {
			return;
		}

		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_addon_for_activitypub_repost_of_url',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_addon_for_activitypub_repost_of_url',
					'compare' => '=',
					'value'   => '',
				),
			)
		);
	}

	/**
	 * Takes reposts into account when determine the featured collection's total
	 * item count.
	 *
	 * @param  string $count   Original post count.
	 * @param  int    $user_id User Id.
	 * @return int             Filtered post count.
	 */
	public static function repair_count( $count, $user_id ) {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $count;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_path = $_SERVER['REQUEST_URI'];
		if ( 0 !== strpos( $current_path, '/wp-json/activitypub' ) ) {
			return;
		}

		if ( false === strpos( $current_path, 'collections/featured' ) ) {
			return;
		}

		$post_types = get_post_types_by_support( 'activitypub' );
		if ( empty( $post_types ) ) {
			return $count;
		}

		$args = array(
			'fields'              => 'id',
			'post_type'           => $post_types,
			'post_status'         => 'publish', // Public only.
			'posts_per_page'      => -1,
			'ignore_sticky_posts' => 1,
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_addon_for_activitypub_repost_of_url',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_addon_for_activitypub_repost_of_url',
					'compare' => '=',
					'value'   => '',
				),
			),
		);

		if ( $user_id ) {
			$args['author__in'] = $user_id;
		}

		$posts = get_posts( $args );

		return count( $posts );
	}
}
