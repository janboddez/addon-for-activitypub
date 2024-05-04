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
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'filter_object' ), 99, 4 );
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
			// Get the (filtered) post content.
			$content = apply_filters( 'the_content', $post_or_comment->post_content );

			// Look for microformats.
			$mf2 = parse( $content );

			// Look for an `in-reply-to` URL.
			$props = ! empty( $mf2['items'][0]['properties'] )
				? $mf2['items'][0]['properties']
				: array();

			if (
				! empty( $props['in-reply-to'][0] ) &&
				filter_var( $props['in-reply-to'][0], FILTER_VALIDATE_URL )
			) {
				$reply_url = $props['in-reply-to'][0];
			} elseif (
				! empty( $props['in-reply-to'][0]['value'] ) &&
				filter_var( $props['in-reply-to'][0]['value'], FILTER_VALIDATE_URL )
			) {
				$reply_url = $props['in-reply-to'][0]['value'];
			}

			if ( ! empty( $reply_url ) ) {
				// Ensure the linked URL is actually Fediverse compatible.
				$response     = remote_get( $reply_url, 'application/activity+json' ); // A HEAD request doesn't work for WordPress pages.
				$content_type = wp_remote_retrieve_header( $response, 'content-type' );
				/** @todo: Do this on save, and store in a custom field, e.g., `addon_for_activitypub_in_reply_to_url` or something. */
			}

			if ( ! empty( $content_type ) && 'application/activity+json' === $content_type ) {
				// Remote page seems to be Fediverse compatible.
				if ( 'activity' === $class ) {
					$array['object']['inReplyTo'] = $reply_url;
				} elseif ( 'base_object' === $class ) {
					$array['inReplyTo'] = $reply_url;
				}

				// Now, trim any reply context off the post content.
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
		}

		return $array;
	}
}
