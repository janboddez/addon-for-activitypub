<?php
/**
 * Main plugin class.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

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

		add_filter( 'activitypub_activity_object_array', array( $this, 'filter_object' ), 99, 4 );

		if ( ! empty( $options['edit_notifications'] ) ) {
			add_action( 'activitypub_handled_update', array( $this, 'send_edit_notification' ), 99, 4 );
		}

		if ( ! empty( $options['cache_avatars'] ) ) {
			add_filter( 'preprocess_comment', array( $this, 'store_avatar' ), 99 );
		}

		if ( ! empty( $options['proxy_avatars'] ) ) {
			add_filter( 'get_avatar_data', array( $this, 'proxy_avatar' ), 99, 3 );
		}

		add_filter( 'activitypub_the_content', array( $this, 'filter_content' ), 99, 2 );
		add_action( 'transition_post_status', array( $this, 'delay_scheduling' ), 32, 3 );
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
	 * Filters (Activities') objects before they're rendered or federated.
	 *
	 * @todo: Make the category configurable.
	 *
	 * @param  array       $array  Activity object's array representation.
	 * @param  string      $class  Class name.
	 * @param  string      $id     Activity object ID.
	 * @param  Base_Object $object Activity object.
	 * @return array               The updated array.
	 */
	public function filter_object( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'activity' === $class && isset( $array['object']['id'] ) ) {
			// Activity.
			$object_id = $array['object']['id'];
		} elseif ( 'base_object' === $class && isset( $array['id'] ) ) {
			$object_id = $array['id'];
		}

		if ( empty( $object_id ) ) {
			error_log( "[Add-on for ActivityPub] Couldn't find object ID." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $array;
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
			return $array;
		}

		// Right. Now we can finally get going.
		$options = $this->options_handler->get_options();

		/**
		 * The "unlisted" stuff.
		 */
		$is_unlisted = false;

		if ( $post_or_comment instanceof \WP_Post && ! empty( $options['unlisted'] ) && has_category( 'rss-club', $post_or_comment->ID ) ) { // @todo: Make this configurable. And, eventually, also exlude these from archives and the like?
			// Show "RSS-only" posts as unlisted. We need to make this smarter.
			$is_unlisted = true;
		} elseif ( $post_or_comment instanceof \WP_Comment && ! empty( $options['unlisted_comments'] ) ) {
			// "Unlist" all comments.
			$is_unlisted = true;
		}

		// Let others filter the "unlisted" attribute. Like, one could check for
		// certain post formats and whatnot.
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

		/**
		 * The "reply" stuff.
		 */
		if ( $post_or_comment instanceof \WP_Post && ! empty( $options['enable_replies'] ) ) {
			// Get the (filtered) post content.
			$content = apply_filters( 'the_content', $post_or_comment->post_content );

			// We'll want `php-mf` to parse it as a `h-entry`.
			if ( ! preg_match( '~ class=("|\')([^"\']*?)e-content([^"\']*?)("|\')~', $content ) ) {
				$content = '<div class="e-content">' . $content . '</div>';
			}
			$content = '<div class="h-entry">' . $content . '</div>';

			// Look for a cached result.
			$hash = hash( 'sha256', $content );
			$mf2  = get_transient( 'indieblocks:mf2:' . $hash );

			if ( false === $mf2 ) {
				// Nothing in cache. Parse post.
				$mf2 = Mf2\parse( $content );
				set_transient( 'indieblocks:mf2:' . $hash, $mf2, 1800 ); // Cache for half an hour.
			}

			// Look for an `in-reply-to` URL.
			if ( ! empty( $mf2['items'][0]['properties'] ) ) {
				$props = $mf2['items'][0]['properties'];

				if ( ! empty( $props['in-reply-to'][0] ) && filter_var( $props['in-reply-to'][0], FILTER_VALIDATE_URL ) ) {
					$reply_url = $props['in-reply-to'][0];
				} elseif ( ! empty( $props['in-reply-to'][0]['value'] ) && filter_var( $props['in-reply-to'][0]['value'], FILTER_VALIDATE_URL ) ) {
					$reply_url = $props['in-reply-to'][0]['value'];
				}
			}

			if ( ! empty( $reply_url ) ) {
				error_log( '[Add-on for ActivityPub] Got a reply.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				// Ensure the linked URL is actually Fediverse compatible.
				$response     = remote_get( $reply_url, 'application/activity+json' ); // A HEAD request doesn't work for WordPress pages.
				$content_type = wp_remote_retrieve_header( $response, 'content-type' );
			}

			if ( ! empty( $content_type ) && 'application/activity+json' === $content_type ) {
				error_log( '[Add-on for ActivityPub] Fediverse compatible.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

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

	/**
	 * Emails a moderator when an ActivityPub comment was edited.
	 *
	 * @param array            $activity      Activity object.
	 * @param null             $unknown_param Literally always `null`.
	 * @param array|int        $state         Comment data, or `1` if a comment was updated.
	 * @param \WP_Comment|null $reaction      Comment object if that comment was updated, `null` otherwise.
	 */
	public function send_edit_notification( $activity, $unknown_param, $state, $reaction ) {
		/** @todo: Send an email or something, because if you get quite a few of these, it's impossible to keep up. */
		if ( $reaction instanceof \WP_Comment ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found,Squiz.Commenting.InlineComment.InvalidEndChar
			// wp_set_comment_status( $reaction, 'hold' );
			wp_notify_moderator( $reaction->comment_ID );
		}
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

		$url      = $commentdata['comment_meta']['avatar_url'];
		$hash     = hash( 'sha256', esc_url_raw( $url ) ); // Create a (hopefully) unique, "reasonably short" filename.
		$ext      = pathinfo( $url, PATHINFO_EXTENSION );
		$filename = $hash . ( ! empty( $ext ) ? '.' . $ext : '' ); // Add a file extension if there was one.

		$dir = 'activitypub-avatars'; // The folder we're saving our avatars to.

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['subdir'] ) ) {
			// Add month and year, to be able to keep track of things. This will
			// lead to duplicate files (for comments created in a different
			// month), but so be it.
			$dir .= '/' . trim( $upload_dir['subdir'], '/' );
		}

		// Make cache directory filterable. Like, if a site owner did not want
		// the month and year in there, they could do so.
		$dir = apply_filters( 'addon_for_activitypub_avatar_dir', $dir, $url );

		$local_url = \IndieBlocks\store_image( $url, $filename, $dir ); // Attempt to store and resize the avatar.
		if ( null !== $local_url ) {
			$commentdata['comment_meta']['avatar_url'] = $local_url; // Replace the original URL by the local one.
		}

		return $commentdata;
	}

	/**
	 * Runs previously stored avatar URLs through IndieBlocks "reverse image
	 * proxy."
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

		// Because ActivityPub uses `pre_get_avatar_data` and, in its callback,
		// sets a `url`, the rest of core's `get_avatar_data()` is skipped.
		// Instead `$args` is returned early, but not without being filtered
		// first.
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
	 * Filters ActivityPub objects' contents.
	 *
	 * @param  string               $content Original content.
	 * @param  \WP_Post|\WP_Comment $obj     Post or comment object.
	 * @return string                        Altered content.
	 */
	public function filter_content( $content, $obj ) {
		$allowed_tags = array(
			'a'          => array(
				'href'  => array(),
				'title' => array(),
				'class' => array(),
				'rel'   => array(),
			),
			'br'         => array(),
			'p'          => array(
				'class' => array(),
			),
			'span'       => array(
				'class' => array(),
			),
			'ul'         => array(),
			'ol'         => array(
				'reversed' => array(),
				'start'    => array(),
			),
			'li'         => array(
				'value' => array(),
			),
			'strong'     => array(
				'class' => array(),
			),
			'b'          => array(
				'class' => array(),
			),
			'i'          => array(
				'class' => array(),
			),
			'em'         => array(
				'class' => array(),
			),
			'blockquote' => array(),
			'cite'       => array(),
			'code'       => array(
				'class' => array(),
			),
			'pre'        => array(
				'class' => array(),
			),
		);

		if ( $obj instanceof \WP_Post ) {
			$post      = $obj;
			$post_type = $post->post_type;
			$template  = locate_template( "activitypub/content-{$post_type}.php" );

			if ( '' !== $template ) {
				ob_start();
				require $template;
				$content = ob_get_clean();
			}
		} elseif ( $obj instanceof \WP_Comment ) {
			$comment  = $obj;
			$template = locate_template( 'activitypub/content-comment.php' );

			if ( '' !== $template ) {
				ob_start();
				require $template;
				$content = ob_get_clean();
			}
		}

		if ( empty( $template ) ) {
			// Return unaltered.
			return $content;
		}

		// If a template was used, "sanitize" the new content (somewhat).
		$content = wp_kses( $content, $allowed_tags );
		// Strip whitespace, but ignore `pre` elements' contents.
		$content = preg_replace( '~<pre[^>]*>.*?</pre>(*SKIP)(*FAIL)|\r|\n|\t~s', '', $content );
		// Strip unnecessary whitespace.
		$content = zz\Html\HTMLMinify::minify( $content );

		return $content;
	}

	/**
	 * Delay scheduling for posts created or updated through the REST API.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function delay_scheduling( $new_status, $old_status, $post ) {
		if ( ! class_exists( '\\Activitypub\\Scheduler' ) || ! method_exists( \Activitypub\Scheduler::class, 'schedule_post_activity' ) ) {
			// Missing dependency. Bail.
			return;
		}

		if ( 'trash' === $new_status ) {
			// Leave deletes alone.
			error_log( '[Add-on for ActivityPub] Deleting. Bail.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			// Do nothing.
			error_log( '[Add-on for ActivityPub] Not a REST request.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		if ( post_password_required( $post ) ) {
			// Stop right there.
			return;
		}

		if ( 'publish' !== $new_status ) {
			// We "delay" creates and updates only, and both require a post
			// that's published. If we didn't check this, drafts would get
			// federated, too.
			return;
		}

		// Unhook the original callback.
		error_log( '[Add-on for ActivityPub] Removing original callback.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		remove_action( 'transition_post_status', array( \Activitypub\Scheduler::class, 'schedule_post_activity' ), 33 );

		// And hook up our own instead.
		$post_types = get_post_types_by_support( 'activitypub' );
		if ( in_array( $post->post_type, $post_types, true ) ) {
			error_log( '[Add-on for ActivityPub] Hooking up new callback.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			add_action( "rest_after_insert_{$post->post_type}", array( $this, 'schedule_post_activity' ), 10 );
		}
	}

	/**
	 * Delay scheduling for posts created or updated through the REST API.
	 *
	 * @param \WP_Post $post Inserted or updated post object.
	 */
	public function schedule_post_activity( $post ) {
		if ( post_password_required( $post ) ) {
			// Stop right there.
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			// This function concerns only Creates and Updates.
			return;
		}

		$status = get_post_meta( $post->ID, 'activitypub_status', true );
		if ( 'federated' === $status ) {
			// Post was federated previously.
			error_log( '[Add-on for ActivityPub] Updating!' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$type = 'Update';
		} else {
			error_log( '[Add-on for ActivityPub] Creating!' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$type = 'Create';
		}

		if ( empty( $type ) ) {
			error_log( '[Add-on for ActivityPub] Neither creating nor updating!' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$hook = 'activitypub_send_post';
		$args = array( $post->ID, $type );

		if ( false === wp_next_scheduled( $hook, $args ) ) {
			error_log( '[Add-on for ActivityPub] Scheduling ...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			if ( function_exists( '\\Activitypub\\set_wp_object_state' ) ) {
				\Activitypub\set_wp_object_state( $post, 'federate' );
			}

			wp_schedule_single_event( time(), $hook, $args );
		}
	}
}
