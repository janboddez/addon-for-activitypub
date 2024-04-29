<?php
/**
 * Main plugin class.
 *
 * @package ActivityPub\Addon
 */

namespace Activitypub\Addon;

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

		$options = $this->options_handler->get_options();

		if ( ! empty( $options['unlisted'] ) ) {
			add_filter( 'activitypub_activity_object_array', array( $this, 'enable_unlisted' ), 99, 4 );
		}

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
	 * Renders a status unlisted, if it is in the `rss-club` category.
	 *
	 * @todo: Make the category configurable.
	 *
	 * @param  array       $array  Activity object's array representation.
	 * @param  string      $class  Class name.
	 * @param  string      $id     Activity object ID.
	 * @param  Base_Object $object Activity object.
	 * @return array               The updated array.
	 */
	public function enable_unlisted( $array, $class, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.classFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound
		if ( 'activity' === $class && isset( $array['object']['id'] ) ) {
			// Activity.
			$post = get_post( url_to_postid( $array['object']['id'] ) );
		} elseif ( 'base_object' === $class && isset( $array['id'] ) ) {
			// Post.
			$post = get_post( url_to_postid( $array['id'] ) );
		}

		if ( empty( $post->post_author ) ) {
			// Not a post, most likely. Bail.
			// @todo: Add comment support?
			return $array;
		}

		$is_unlisted     = false;
		$post_or_comment = $post; // Thinking ahead, right?

		// Show "RSS-only" posts as unlisted.
		if ( has_category( 'rss-club', $post->ID ) ) { // @todo: Make this configurable. And, eventually, also exlude these from archives and the like?
			// Note that Gutenberg users may have to set the category, then save
			// a draft, and only then publish, due to federation being scheduled
			// early.
			$is_unlisted = true;
		}

		// Let others filter the "unlisted" attribute. Like, one could check for
		// certain post formats and whatnot. Or, *in the future*, unlist all
		// comments ...
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
	public static function filter_content( $content, $obj ) {
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
			$post_type = $obj->post_type;
			$template  = locate_template( "activitypub/content-{$post_type}.php" );

			if ( '' !== $template ) {
				ob_start();
				require $template;
				$content = ob_get_clean();
			}
		} elseif ( $obj instanceof \WP_Comment ) {
			$template = locate_template( 'activitypub/content-comment.php' );

			if ( '' !== $template ) {
				ob_start();
				require_once $template;
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

		return $content;
	}
}
