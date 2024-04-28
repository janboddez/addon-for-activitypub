<?php
/**
 * Main plugin class.
 *
 * @package ActivityPub\Addon
 */

namespace Activitypub\Addon;

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
	 * @param array                             $array  Activity object's array representation.
	 * @param string                            $class  Class name.
	 * @param string                            $id     Activity object ID.
	 * @param \Activitypub\Activity\Base_Object $object Activity object.
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
}
