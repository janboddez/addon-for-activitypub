<?php
/**
 * Reduces sending "unnecesary" Update activities.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

use Activitypub\Activity\Activity;

class Limit_Updates {
	/**
	 * Hooks and such.
	 */
	public static function register() {
		// Don't send Update activities when a post hasn't actually changed.
		add_filter( 'activitypub_send_activity_to_followers', array( __CLASS__, 'limit_updates' ), PHP_INT_MAX, 4 );
		add_filter( 'activitypub_activity_object_array', array( __CLASS__, 'remove_updated_prop' ), 999, 4 );
	}

	/**
	 * Prevents sending "unnecessary" updates.
	 *
	 * Gets called just once per "round" of posting to followers (and mentioned
	 * accounts).
	 *
	 * @param  bool                          $send      Whether we should post to followers' inboxes.
	 * @param  Activity                      $activity  Activity object.
	 * @param  int                           $user_id   User ID.
	 * @param  \WP_Post|\WP_Comment|\WP_User $wp_object Object of the activity.
	 * @return bool                                     Whether we should post to followers' inboxes, filtered.
	 */
	public static function limit_updates( $send, $activity, $user_id, $wp_object ) {
		if ( 'Update' !== $activity->get_type() ) {
			return $send;
		}

		if ( ! $wp_object instanceof \WP_Post ) {
			/** @todo: Make compatible with comments, too. */
			return $send;
		}

		$old_hash = get_post_meta( $wp_object->ID, '_addon_for_activitypub_hash', true );

		// Filter to let developers do their own thing (like, add in other
		// fields).
		$new_hash = apply_filters( 'addon_for_activitypub_object_hash', null, $wp_object, $activity, $user_id );

		if ( null === $new_hash ) {
			// Convert `$wp_object` to an array, but keep only the following
			// properties.
			$obj = array_filter(
				(array) $wp_object,
				function ( $key ) {
					return in_array( $key, array( 'post_name', 'post_title', 'post_excerpt', 'post_content', 'post_type' ), true );
				},
				ARRAY_FILTER_USE_KEY
			);

			// Add in "current" tags. Thanks to our using the `rest_after_insert{$post->post_type}`
			// hook, this *should work even with the Gutenberg editor*.
			$obj['tags'] = wp_get_post_tags( $wp_object->ID, array( 'fields' => 'names' ) ); // Returns an array of tag names.
			// Convert to JSONN string, then hash.
			$new_hash = md5( wp_json_encode( $obj ) );
		}

		if ( $new_hash === $old_hash && '' !== $old_hash ) {
			// Post wasn't changed. Don't federate.
			return false;
		}

		// If they're different (or the post is new), update the hash.
		update_post_meta( $wp_object->ID, '_addon_for_activitypub_hash', $new_hash );

		return $send;
	}

	/**
	 * Leaves off the "updated" property for new posts.
	 *
	 * Gets called once before kicking of a round of posting to followers and
	 * the like, but after the (old and new) "hashes" have been compared.
	 *
	 * @param  array       $array  Activity object's array representation.
	 * @param  string      $class  Class name.
	 * @param  string      $id     Activity object ID.
	 * @param  Base_Object $object Activity (or object) object.
	 * @return array               The updated array.
	 */
	public static function remove_updated_prop( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( empty( $activity['type'] ) ) {
			return $array;
		}

		$post_or_comment = get_object( $array, $class );
		if ( ! $post_or_comment ) {
			return $array;
		}

		if ( ! $post_or_comment instanceof \WP_Post ) {
			return $array;
		}

		if ( is_activitypub() ) {
			// If this is a REST request (and not us federating), only include
			// `updated` when it actually adds something.
			if ( ! empty( $array['published'] ) && ! empty( $array['updated'] ) && $array['published'] === $array['updated'] ) {
				unset( $array['updated'] );
			}

			if ( ! empty( $array['object']['published'] ) && ! empty( $array['object']['updated'] ) && $array['object']['published'] === $array['object']['updated'] ) {
				unset( $array['object']['updated'] );
			}

			return $array;
		}

		// If we're "federating out" a Create, there's no need to include the
		// `updated` timestamp. Or, even if we could, there's no need to confuse
		// receiving servers.
		if ( 'Create' === $activity['type'] ) {
			unset( $array['updated'] );
			if ( 'activity' === $class ) {
				// Update "base object," too.
				unset( $array['object']['updated'] );
			}
		}

		return $array;
	}
}
