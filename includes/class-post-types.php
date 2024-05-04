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
		add_filter( 'save_post', array( __CLASS__, 'set_post_meta' ), 99 );
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'filter_object' ), 99, 4 );
	}

	/**
	 * Store (for now) Fediverse-compatible `in-reply-to` URLs.
	 *
	 * @param int|\WP_Post $post Post ID or object, depending on where the request originated.
	 */
	public static function set_post_meta( $post ) {
		$options = get_options();
		if ( empty( $options['enable_replies'] ) ) {
			return;
		}

		$post = get_post( $post );
		if ( empty( $post->post_content ) ) {
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

		if ( ! empty( $url ) ) {
			// Ensure the linked URL is actually Fediverse compatible.
			$response     = remote_get( $url, 'application/activity+json' ); // A HEAD request doesn't work for WordPress pages.
			$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		}

		if ( ! empty( $content_type ) && 'application/activity+json' === $content_type ) {
			// Save the URL for "Fediverse threading" purposes later on.
			update_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url', esc_url_raw( $url ) );

			$json = json_decode( wp_remote_retrieve_body( $response ) );
			if (
				! empty( $json->attributedTo ) &&
				is_string( $json->attributedTo ) &&
				class_exists( '\\Activitypub\\Webfinger' ) &&
				method_exists( \Activitypub\Webfinger::class, 'uri_to_acct' )
			) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$actor_url = $json->attributedTo; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$handle    = \Activitypub\Webfinger::uri_to_acct( $actor_url );

				if ( is_string( $handle ) && 0 === strpos( $handle, 'acct:' ) ) {
					$handle = substr( $handle, 5 );
					$actor  = array();
					$actor[ filter_var( $handle, FILTER_SANITIZE_EMAIL ) ] = esc_url_raw( $actor_url );
					update_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor', $actor ); // Will have to do for now.
				} else {
					delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_actor' );
				}
			}
		} else {
			delete_post_meta( $post->ID, '_addon_for_activitypub_in_reply_to_url' );
		}
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
			$url   = get_post_meta( $post_or_comment->ID, '_addon_for_activitypub_in_reply_to_url', true );
			$actor = get_post_meta( $post_or_comment->ID, '_addon_for_activitypub_in_reply_to_actor', true );
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

			// Add mentions. Note: does not yet do anything, presumably because
			// all this is done after the inboxes to send to are established
			// (and the mention thus ... never arrives).
			// @todo: set the `cc` earlier on!
			if ( ! empty( $actor ) ) {
				// Update `tag` and `cc` properties.
				if ( 'activity' === $class ) {
					if ( empty( $array['object']['tag'] ) ) {
						$array['object']['tag'] = array(
							array(
								'type' => 'Mention',
								'href' => reset( $actor ),
								'name' => '@' . array_key_first( $actor ),
							),
						);
					} else {
						$array['object']['tag'] = array_unique(
							array_merge(
								$array['object']['tag'],
								array(
									array(
										'type' => 'Mention',
										'href' => reset( $actor ),
										'name' => '@' . array_key_first( $actor ),
									),
								)
							)
						);
					}

					if ( empty( $array['object']['cc'] ) ) {
						$array['object']['cc'] = array_values( $actor );
					} else {
						$array['object']['cc'] = array_unique( array_merge( $array['object']['cc'], array_values( $actor ) ) );
					}
				} elseif ( 'base_object' === $class ) {
					if ( empty( $array['tag'] ) ) {
						$array['tag'] = array(
							array(
								'type' => 'Mention',
								'href' => reset( $actor ),
								'name' => '@' . array_key_first( $actor ),
							),
						);
					} else {
						$array['tag'] = array_unique(
							array_merge(
								$array['tag'],
								array(
									array(
										'type' => 'Mention',
										'href' => reset( $actor ),
										'name' => '@' . array_key_first( $actor ),
									),
								)
							)
						);
					}

					if ( empty( $array['cc'] ) ) {
						$array['cc'] = array_values( $actor );
					} else {
						$array['cc'] = array_unique( array_merge( $array['cc'], array_values( $actor ) ) );
					}
				}
			}
		}

		return $array;
	}
}
