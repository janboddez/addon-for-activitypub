<?php
/**
 * Main plugin class.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

use Activitypub\Activity\Activity;
use Activitypub\Activity\Base_Object;

class Plugin {
	/**
	 * Plugin version.
	 */
	const PLUGIN_VERSION = '0.1.0';

	/**
	 * Options handler.
	 *
	 * @var Options_Handler $options_handler Options handler.
	 */
	private $options_handler;

	/**
	 * This class's single instance.
	 *
	 * @var Plugin $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * Returns the single instance of this class.
	 *
	 * @return Plugin This class's single instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks and such.
	 */
	public function register() {
		// Enable i18n.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Set up the settings page.
		$this->options_handler = new Options_Handler();
		$this->options_handler->register();

		Post_Types::register();
		Templates::register();
		Reschedule_Requests::register();

		// Don't `POST` local-only posts to followers (or anywhere).
		add_filter( 'activitypub_send_activity_to_followers', array( $this, 'disable_federation' ), 99, 4 );
		// Disable content negotiation for these posts.
		add_filter( 'template_include', array( $this, 'disable_fetch' ), 10 );
		// Keep local-only posts out of outboxes and "featured" collections.
		add_action( 'pre_get_posts', array( $this, 'hide_from_collections' ) );
		// Correct the total post count, for outboxes and "featured" collections.
		add_filter( 'get_usernumposts', array( $this, 'repair_count' ), 99, 2 );

		// Mark "unlisted" posts as unlisted.
		add_filter( 'activitypub_activity_object_array', array( $this, 'set_unlisted' ), 999, 4 );

		$options = $this->options_handler
			->get_options();

		if ( ! empty( $options['limit_updates'] ) ) {
			Limit_Updates::register();
		}

		if ( ! empty( $options['edit_notifications'] ) ) {
			add_action( 'activitypub_handled_update', array( $this, 'send_edit_notification' ), 999, 4 );
		}

		if ( ! empty( $options['cache_avatars'] ) ) {
			add_filter( 'preprocess_comment', array( $this, 'store_avatar' ), 999 );
		}

		if ( ! empty( $options['proxy_avatars'] ) ) {
			add_filter( 'get_avatar_data', array( $this, 'proxy_avatar' ), 999, 3 );
		}

		// Close comments on "older" posts.
		add_filter( 'pre_comment_approved', array( $this, 'close_comments' ), 999, 2 );

		// We *have to* "delay" federation until the REST API has processed categories and the like, for our
		// "local-only" category to work reliably.
		add_action( 'transition_post_status', array( $this, 'delay_scheduling' ), 30, 3 );

		// Support incoming likes and reposts.
		add_action( 'activitypub_inbox_like', array( $this, 'handle_like' ), 10, 2 );
		add_action( 'activitypub_inbox_announce', array( $this, 'handle_announce' ), 10, 2 );
		add_action( 'activitypub_inbox_undo', array( $this, 'handle_undo' ), 10, 2 );
	}

	/**
	 * Enable i18n.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'addon-for-activitypub', false, basename( dirname( __DIR__ ) ) . '/languages' );
	}

	/**
	 * Returns our options handler.
	 *
	 * @return Options_Handler Options handler.
	 */
	public function get_options_handler() {
		return $this->options_handler;
	}

	/**
	 * Disables content negotiation for local-only posts.
	 *
	 * @param  string $template The path of the template to include.
	 * @return string           The same template.
	 */
	public function disable_fetch( $template ) {
		if ( ! class_exists( '\\Activitypub\\Activitypub' ) ) {
			return $template;
		}

		if ( ! method_exists( \Activitypub\Activitypub::class, 'render_json_template' ) ) {
			return $template;
		}

		if ( ! is_singular() ) {
			return $template;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $template;
		}

		$options = get_options();
		if ( in_category( $options['local_cat'], $post ) ) {
			// Disable content negotiation.
			remove_filter( 'template_include', array( \Activitypub\Activitypub::class, 'render_json_template' ), 99 );
		}

		return $template;
	}

	/**
	 * Disables federation of local-only posts.
	 *
	 * @param  bool                          $send      Whether we should post to followers' inboxes.
	 * @param  Activity                      $activity  Activity object.
	 * @param  int                           $user_id   User ID.
	 * @param  \WP_Post|\WP_Comment|\WP_User $wp_object Object of the activity.
	 * @return bool                                     Whether we should post to followers' inboxes.
	 */
	public function disable_federation( $send, $activity, $user_id, $wp_object ) {
		if ( ! $wp_object instanceof \WP_Post ) {
			return $send;
		}

		$options = get_options();
		if ( ! in_category( $options['local_cat'], $wp_object ) ) {
			return $send;
		}

		return false;
	}

	/**
	 * Keeps local-only posts out of outboxes and featured post collections.
	 *
	 * @param \WP_Query $query Database query object.
	 */
	public function hide_from_collections( $query ) {
		// @codingStandardsIgnoreStart
		// Defaults to `true` (here), so we actually *don't* want to run this check.
		// if ( ! empty( $query->query_vars['suppress_filters'] ) ) {
		// 	return;
		// }
		// @codingStandardsIgnoreEnd

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			// Not a REST request.
			return;
		}

		if ( ! is_activitypub() ) {
			// Not an ActivityPub REST request.
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false === strpos( $_SERVER['REQUEST_URI'], 'outbox' ) && false === strpos( $_SERVER['REQUEST_URI'], 'collections' ) ) {
			// Neither an outbox nor a collection request.
			return;
		}

		$options = get_options();
		if ( ! isset( $options['local_cat'] ) || '' === $options['local_cat'] ) {
			return;
		}

		$category = get_category_by_slug( $options['local_cat'] );
		if ( empty( $category->term_id ) ) {
			return;
		}

		$query->set( 'category__not_in', array( $category->term_id ) );
	}

	/**
	 * Takes local-only posts into account when determine the total item count.
	 *
	 * @param  string $count   Original post count.
	 * @param  int    $user_id User Id.
	 * @return int             Filtered post count.
	 */
	public function repair_count( $count, $user_id ) {
		if ( ! is_activitypub() ) {
			// Not an ActivityPub REST request.
			return $count;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false === strpos( $_SERVER['REQUEST_URI'], 'outbox' ) && false === strpos( $_SERVER['REQUEST_URI'], 'collections' ) ) {
			// Neither an outbox nor a collection request.
			return $count;
		}

		$options = get_options();
		if ( ! isset( $options['local_cat'] ) || '' === $options['local_cat'] ) {
			return $count;
		}

		$category = get_category_by_slug( $options['local_cat'] ); // @todo: Verify it exists, still.
		if ( empty( $category->term_id ) ) {
			return $count;
		}

		$post_types = get_post_types_by_support( 'activitypub' );
		if ( empty( $post_types ) ) {
			return $count;
		}

		$args = array(
			'fields'              => 'id',
			'post_type'           => $post_types,
			'post_status'         => 'publish', // Public only.
			'category__not_in'    => $category->term_id, // Exclude local-only posts.
			'posts_per_page'      => -1,
			'ignore_sticky_posts' => 1,
		);

		if ( $user_id ) {
			$args['author__in'] = $user_id;
		}

		$posts = get_posts( $args );

		return count( $posts );
	}

	/**
	 * Implements "unlisted" posts and comments.
	 *
	 * @todo: Make the category configurable.
	 *
	 * @param  array       $array  Activity object's array representation.
	 * @param  string      $class  Class name.
	 * @param  string      $id     Activity object ID.
	 * @param  Base_Object $object Activity (or object) object.
	 * @return array               The updated array.
	 */
	public function set_unlisted( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_or_comment = get_object( $array, $class );
		if ( ! $post_or_comment ) {
			return $array;
		}

		$is_unlisted = false;
		$options     = get_options();

		if (
			$post_or_comment instanceof \WP_Post &&
			isset( $options['unlisted_cat'] ) && '' !== $options['unlisted_cat'] &&
			has_category( $options['unlisted_cat'], $post_or_comment->ID )
		) {
			// Show posts in a certain category as "unlisted." If somehow that's not enough, there's the filter below.
			$is_unlisted = true;
		} elseif ( $post_or_comment instanceof \WP_Comment && ! empty( $options['unlisted_comments'] ) ) {
			// "Unlist" all comments.
			$is_unlisted = true;
		}

		// Let others filter the "unlisted" attribute. Like, one could check for certain post formats and whatnot.
		if ( apply_filters( 'addon_for_activitypub_is_unlisted', $is_unlisted, $post_or_comment ) ) {
			$to = isset( $array['to'] ) ? $array['to'] : array();
			$cc = isset( $array['cc'] ) ? $array['cc'] : array();

			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found,Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			if ( false !== ( $key = array_search( 'https://www.w3.org/ns/activitystreams#Public', $to, true ) ) ) {
				unset( $to[ $key ] ); // Remove the "Public" value ...
			}

			$cc[] = 'https://www.w3.org/ns/activitystreams#Public'; // And add it to `cc`.

			$to = array_values( $to ); // Renumber.
			$cc = array_values( array_unique( $cc ) ); // Remove duplicates.

			$array['to'] = $to;
			$array['cc'] = $cc;

			if ( 'activity' === $class ) {
				// Update "base object," too.
				$array['object']['to'] = $to;
				$array['object']['cc'] = $cc;
			}
		}

		return $array;
	}

	/**
	 * Emails a moderator when an ActivityPub comment was edited.
	 *
	 * @param array            $activity      Activity object.
	 * @param null             $unknown_param Literally always `null`.
	 * @param array|int        $state         Comment data, or `1` if a comment was updated.
	 * @param \WP_Comment|null $reaction      Comment object if that comment was updated, `null` otherwise.
	 */
	public function send_edit_notification( $activity, $unknown_param, $state, $reaction ) {
		if ( ! $reaction instanceof \WP_Comment ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found,Squiz.Commenting.InlineComment.InvalidEndChar
		// wp_set_comment_status( $reaction, 'hold' );
		wp_notify_moderator( $reaction->comment_ID );
	}

	/**
	 * Stores ActivityPub avatars locally, and resizes them to 150 x 150 pixels.
	 *
	 * Requires the IndieBlocks plugin (for now).
	 *
	 * @param  array $commentdata Comment data.
	 * @return array              Comment data.
	 */
	public function store_avatar( $commentdata ) {
		if ( ! function_exists( '\\IndieBlocks\\store_image' ) ) {
			return $commentdata;
		}

		if ( empty( $commentdata['comment_meta']['protocol'] ) || 'activitypub' !== $commentdata['comment_meta']['protocol'] ) {
			return $commentdata;
		}

		if ( empty( $commentdata['comment_meta']['avatar_url'] ) || false === wp_http_validate_url( $commentdata['comment_meta']['avatar_url'] ) ) {
			return $commentdata;
		}

		// Image URL.
		$url = $commentdata['comment_meta']['avatar_url'];

		// Create a unique, "reasonably short" filename. We prefer using an actor URL over their actual avatar URL, as
		// the latter may change, which would prevent outdated avatars from being updated (i.e., overwritten).
		$hash = hash(
			'sha256',
			! empty( $commentdata['comment_author_url'] )
				? $commentdata['comment_author_url']
				: $url
		);

		$ext      = pathinfo( $url, PATHINFO_EXTENSION );
		$filename = $hash . ( ! empty( $ext ) ? '.' . $ext : '' ); // Add a file extension if there was one.

		$dir  = 'activitypub-avatars'; // The folder we're saving our avatars to.
		$dir .= '/' . substr( $hash, 0, 2 ); // To somewhat "spread out" the images over various subfolders.

		// Make cache directory filterable. Like, if a site owner did not want the month and year in there, they could
		// do so.
		$dir = apply_filters( 'addon_for_activitypub_avatar_dir', $dir, $url );

		$local_url = \IndieBlocks\store_image( $url, $filename, $dir ); // Attempt to store and resize the avatar.
		if ( null !== $local_url ) {
			$commentdata['comment_meta']['avatar_url'] = $local_url; // Replace the original URL by the local one.
		}

		return $commentdata;
	}

	/**
	 * Runs previously stored avatar URLs through IndieBlocks "reverse image proxy."
	 *
	 * Requires the IndieBlocks plugin (for now).
	 *
	 * @param  array $args        Arguments passed to `get_avatar_data()`.
	 * @param  mixed $id_or_email User object, ID or email, Gravatar hash, post object, or comment object.
	 * @return string             Filtered URL.
	 */
	public function proxy_avatar( $args, $id_or_email ) {
		if ( ! function_exists( '\\IndieBlocks\\proxy_image' ) ) {
			return $args;
		}

		if ( empty( $args['url'] ) ) {
			return $args;
		}

		// Because ActivityPub uses `pre_get_avatar_data` and, in its callback, sets a `url`, the rest of core's
		// `get_avatar_data()` is skipped. Instead `$args` is returned early, but not without being filtered first.
		if ( ! $id_or_email instanceof \WP_Comment ) {
			return $args;
		}

		if ( 'activitypub' !== get_comment_meta( $id_or_email->comment_ID, 'protocol', true ) ) {
			return $args;
		}

		if ( get_comment_meta( $id_or_email->comment_ID, 'avatar_url', true ) === $args['url'] ) {
			$args['url'] = \IndieBlocks\proxy_image( $args['url'] );
		}

		return $args;
	}

	/**
	 * Returns an instance of `\WP_Error` when "ActivityPub" comments are closed, causing the comment to be dropped.
	 *
	 * @param  int|string|\WP_Error $approved    Approval status.
	 * @param  array                $commentdata Comment data.
	 * @return int|string|\WP_Error              Filtered approval status.
	 */
	public function close_comments( $approved, $commentdata ) {
		if ( empty( $commentdata['comment_post_ID'] ) ) {
			return $approved;
		}

		$options = get_options();
		if ( empty( $options['close_comments'] ) ) {
			return $approved;
		}

		$post_time = get_post_time( 'U', true, $commentdata['comment_post_ID'] );

		if ( false === $post_time ) {
			return $approved;
		}

		if ( $post_time >= time() - $options['close_comments'] * DAY_IN_SECONDS ) {
			return $approved;
		}

		return \WP_Error( 'comments_closed', __( 'Comments are closed.', 'addon-for-activitypub' ) );
	}

	/**
	 * Delay scheduling for posts created or updated through the REST API.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function delay_scheduling( $new_status, $old_status, $post ) {
		if ( ! class_exists( '\\Activitypub\\Scheduler' ) ) {
			return;
		}

		if ( ! method_exists( \Activitypub\Scheduler::class, 'schedule_post_activity' ) ) {
			return;
		}

		if ( post_password_required( $post ) ) {
			// Stop right there.
			return;
		}

		if ( 'publish' !== $new_status ) {
			// We "delay" creates and updates only, and both require a post that's published. If we didn't check this,
			// drafts would get federated, too.
			return;
		}

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			// "Delay" for REST API requests only.
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_path = $_SERVER['REQUEST_URI'];
		if ( 0 === strpos( $current_path, '/wp-json/micropub' ) ) {
			// "Delay" for non-Micropub requests only.
			return;
		}

		// We'll need these later on.
		current_post_statuses( $post->ID, array( 'old' => $old_status, 'new' => $new_status ) );

		// Unhook the original callback.
		error_log( '[Add-on for ActivityPub] Removing original callback.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		remove_action( 'transition_post_status', array( \Activitypub\Scheduler::class, 'schedule_post_activity' ), 33 );

		// And hook up our own instead.
		$post_types = get_post_types_by_support( 'activitypub' );
		if ( in_array( $post->post_type, $post_types, true ) ) {
			error_log( '[Add-on for ActivityPub] Hooking up new callback.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			add_action( "rest_after_insert_{$post->post_type}", array( $this, 'schedule_post_activity' ), 999 );
		}
	}

	/**
	 * Schedule federation of Create, Update and Announce activities.
	 *
	 * @param int|\WP_Post $post Inserted or updated post ID or object.
	 */
	public function schedule_post_activity( $post ) {
		$post = get_post( $post );

		if ( post_password_required( $post ) ) {
			// Stop right there.
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			// Avoid federating non-published posts. Remember that we don't deal with deletes here.
			return;
		}

		// Retrieve previously stored old and new statuses. Using a static var (rather than `wp_cache_set()` or similar)
		// seemed like the more robust solution.
		$status = current_post_statuses( $post->ID );
		if ( ! $status ) {
			return;
		}

		if ( 'publish' === $status['new'] && 'publish' !== $status['old'] ) {
			$type = 'Create';
		} elseif ( 'publish' === $status['new'] ) {
			$type = 'Update';
		} elseif ( 'trash' === $status['new'] ) {
			$type = 'Delete';
		}

		$hook = 'activitypub_send_post';
		$args = array( $post->ID, $type );

		if ( false === wp_next_scheduled( $hook, $args ) ) {
			if ( function_exists( '\\Activitypub\\set_wp_object_state' ) ) {
				\Activitypub\set_wp_object_state( $post, 'federate' );
			}

			wp_schedule_single_event( time(), $hook, $args );
		}
	}

	/**
	 * Handles incoming likes.
	 *
	 * @param array $array   Activity array.
	 * @param int   $user_id User ID.
	 */
	public function handle_like( $array, $user_id ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound
		if ( defined( 'ACTIVITYPUB_DISABLE_INCOMING_INTERACTIONS' ) && ACTIVITYPUB_DISABLE_INCOMING_INTERACTIONS ) {
			return;
		}

		if ( ! class_exists( '\\Activitypub\\Comment' ) || ! method_exists( \Activitypub\Comment::class, 'object_id_to_comment' ) ) {
			return;
		}

		if ( empty( $array['object'] ) && empty( $array['object']['id'] ) ) {
			return;
		}

		if ( ! empty( $array['object'] ) && filter_var( $array['object'], FILTER_VALIDATE_URL ) ) {
			$url = $array['object'];
		} elseif ( ! empty( $array['object']['id'] ) && filter_var( $array['object']['id'], FILTER_VALIDATE_URL ) ) {
			$url = $array['object']['id'];
		}

		if ( empty( $url ) ) {
			return;
		}

		$exists = \Activitypub\Comment::object_id_to_comment( esc_url_raw( $url ) );
		if ( $exists ) {
			return;
		}

		$state    = $this->add_like_or_repost( $array, $url, 'like' );
		$reaction = null;

		if ( $state && ! is_wp_error( $state ) ) {
			$reaction = get_comment( $state );
		}

		do_action( 'activitypub_handled_like', $array, $user_id, $state, $reaction );
	}

	/**
	 * Handles incoming reposts.
	 *
	 * @param array $array   Activity array.
	 * @param int   $user_id User ID.
	 */
	public function handle_announce( $array, $user_id ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound
		if ( defined( 'ACTIVITYPUB_DISABLE_INCOMING_INTERACTIONS' ) && ACTIVITYPUB_DISABLE_INCOMING_INTERACTIONS ) {
			return;
		}

		if ( ! class_exists( '\\Activitypub\\Comment' ) || ! method_exists( \Activitypub\Comment::class, 'object_id_to_comment' ) ) {
			return;
		}

		if ( empty( $array['object'] ) && empty( $array['object']['id'] ) ) {
			return;
		}

		if ( ! empty( $array['object'] ) && filter_var( $array['object'], FILTER_VALIDATE_URL ) ) {
			$url = $array['object'];
		} elseif ( ! empty( $array['object']['id'] ) && filter_var( $array['object']['id'], FILTER_VALIDATE_URL ) ) {
			$url = $array['object']['id'];
		}

		if ( empty( $url ) ) {
			return;
		}

		$exists = \Activitypub\Comment::object_id_to_comment( esc_url_raw( $url ) );
		if ( $exists ) {
			return;
		}

		$state = $this->add_like_or_repost( $array, $url, 'repost' );

		if ( $state && ! is_wp_error( $state ) ) {
			$reaction = get_comment( $state );
		}

		do_action( 'activitypub_handled_announce', $array, $user_id, $state, isset( $reaction ) ? $reaction : null );
	}

	/**
	 * Handles incoming undoes of a like or repost.
	 *
	 * @param array $array   Activity array.
	 * @param int   $user_id User ID.
	 */
	public function handle_undo( $array, $user_id ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound
		if ( defined( 'ACTIVITYPUB_DISABLE_INCOMING_INTERACTIONS' ) && ACTIVITYPUB_DISABLE_INCOMING_INTERACTIONS ) {
			return;
		}

		if ( ! class_exists( '\\Activitypub\\Comment' ) || ! method_exists( \Activitypub\Comment::class, 'object_id_to_comment' ) ) {
			return;
		}

		if ( empty( $array['object']['type'] ) ) {
			// We'll need this later on.
			return;
		}

		if ( ! empty( $array['object']['id'] ) && filter_var( $array['object']['id'], FILTER_VALIDATE_URL ) ) {
			$comment = \Activitypub\Comment::object_id_to_comment( esc_url_raw( $array['object']['id'] ) );
		}

		if ( empty( $comment ) ) {
			return;
		}

		if ( 'Like' === $array['object']['type'] && 'like' === get_comment_meta( $comment->comment_ID, 'indieblocks_webmention_kind', true ) ) {
			$state = wp_trash_comment( $comment );
		} elseif ( 'Announce' === $array['object']['type'] && 'repost' === get_comment_meta( $comment->comment_ID, 'indieblocks_webmention_kind', true ) ) {
			$state = wp_trash_comment( $comment );
		}

		if ( ! empty( $state ) ) {
			do_action( 'activitypub_handled_undo', $array, $user_id, isset( $state ) ? $state : null, null );
		}
	}

	/**
	 * Adds an incoming like.
	 *
	 * @param  array  $activity Activity array.
	 * @param  string $url      Object URL.
	 * @param  string $type     `'like'` or `'repost'`.
	 * @return array|false      Comment data or `false` on failure.
	 */
	protected function add_like_or_repost( $activity, $url, $type ) {
		if ( ! function_exists( '\\Activitypub\\url_to_commentid' ) ) {
			return;
		}

		if ( ! function_exists( '\\Activitypub\\object_to_uri' ) ) {
			return;
		}

		if ( ! function_exists( '\\Activitypub\\get_remote_metadata_by_actor' ) ) {
			return;
		}

		$comment_post_id   = url_to_postid( $url );
		$parent_comment_id = \Activitypub\url_to_commentid( $url );

		if ( ! $comment_post_id && $parent_comment_id ) {
			$parent_comment  = get_comment( $parent_comment_id );
			$comment_post_id = $parent_comment->comment_post_ID;
		}

		if ( ! $comment_post_id ) {
			// Not a reply to a post or comment.
			return false;
		}

		$actor = \Activitypub\object_to_uri( $activity['actor'] );
		$meta  = \Activitypub\get_remote_metadata_by_actor( $actor );

		if ( ! $meta || is_wp_error( $meta ) ) {
			return false;
		}

		switch ( $type ) {
			case 'like':
				$comment_content = __( '&hellip; liked this!', 'addon-for-activitypub' );
				break;

			case 'repost':
				$comment_content = __( '&hellip; reposted this!', 'addon-for-activitypub' );
				break;
		}

		$commentdata = array(
			'comment_post_ID'      => $comment_post_id,
			'comment_author'       => isset( $meta['name'] ) ? \esc_attr( $meta['name'] ) : \esc_attr( $meta['preferredUsername'] ),
			'comment_author_url'   => esc_url_raw( \Activitypub\object_to_uri( $meta['url'] ) ),
			'comment_content'      => $comment_content,
			'comment_type'         => '',
			'comment_author_email' => '',
			'comment_parent'       => $parent_comment_id ? $parent_comment_id : 0,
			'comment_meta'         => array(
				'source_id'                     => esc_url_raw( $activity['id'] ), // To be able to detect existing comments.
				'protocol'                      => 'activitypub', // So we can cache avatars (hoping it doesn't break anything else).
				'indieblocks_webmention_source' => esc_url_raw( \Activitypub\object_to_uri( $meta['url'] ) ), // To allow IndieBlocks' Facepile block to "link" someplace.
				'indieblocks_webmention_target' => esc_url_raw( $url ), // Just because.
				'indieblocks_webmention_kind'   => $type, // Because otherwise IndieBlocks' Facepile block wouldn't pick it up. Could also set `comment_type`, like the Webmention plugin would.
			),
		);

		if ( isset( $meta['icon']['url'] ) ) {
			$commentdata['comment_meta']['avatar_url'] = esc_url_raw( $meta['icon']['url'] );
		}

		if ( isset( $activity['object']['url'] ) ) {
			$commentdata['comment_meta']['source_url'] = esc_url_raw( \Activitypub\object_to_uri( $activity['object']['url'] ) );
		}

		// Disable flood control.
		remove_action( 'check_comment_flood', 'check_comment_flood_db', 10 );

		// No nonce possible for this submission route.
		add_filter(
			'akismet_comment_nonce',
			function () {
				return 'inactive';
			}
		);

		$comment = wp_new_comment( $commentdata, true );

		// Re-add flood control.
		add_action( 'check_comment_flood', 'check_comment_flood_db', 10, 4 );

		return $comment;
	}
}
