<?php
/**
 * Implements PHP content templates.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

use Activitypub\Model\User;

class Templates {
	const ALLOWED_TAGS = array(
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

	/**
	 * Registers hook callbacks.
	 */
	public static function register() {
		add_filter( 'activitypub_the_content', array( __CLASS__, 'apply_template' ), 999, 2 );
		add_filter( 'activitypub_activity_user_object_array', array( __CLASS__, 'actor_profile' ), 999, 3 );
	}

	/**
	 * Applies "content templates" for post types (and comments).
	 *
	 * @param  string               $content Original content.
	 * @param  \WP_Post|\WP_Comment $obj     Post or comment object.
	 * @return string                        Altered content.
	 */
	public static function apply_template( $content, $obj ) {
		if ( $obj instanceof \WP_Post ) {
			$post      = $obj;
			$post_type = $post->post_type;
			$template  = locate_template( "activitypub/content-{$post_type}.php" );
		} elseif ( $obj instanceof \WP_Comment ) {
			$comment  = $obj; // Used inside the included file.
			$template = locate_template( 'activitypub/content-comment.php' );
		}

		if ( empty( $template ) ) {
			// Return unaltered.
			return $content;
		}

		ob_start();
		require $template; // *Not* `require_once`.
		$content = ob_get_clean();

		// If a template was used, "sanitize" the new content (somewhat).
		$content = wp_kses( $content, apply_filters( 'addon_for_activitypub_allowed_tags', self::ALLOWED_TAGS, $obj ) );
		// Strip whitespace, but ignore `pre` elements' contents.
		$content = preg_replace( '~<pre[^>]*>.*?</pre>(*SKIP)(*FAIL)|\r|\n|\t~s', '', $content );
		// Strip unnecessary whitespace.
		$content = zz\Html\HTMLMinify::minify( $content );

		return $content;
	}

	/**
	 * Grabs and converts an "actor profile" array into the format expected by
	 * the plugin (and Mastodon).
	 *
	 * @param  array $array  Original profile array.
	 * @param  int   $id     ActivityPub user ID (i.e., URL).
	 * @param  User  $object ActivityPub user (I think) object.
	 * @return array         Updated array.
	 */
	public static function actor_profile( $array, $id, $object ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound,Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound
		$user_id  = $object->get__id(); // Could be different URLs, depending on what filters are in use.
		$template = locate_template( "activitypub/profile-user-{$user_id}.php" );

		if ( empty( $template ) ) {
			return $array;
		}

		$profile = include $template;

		if ( ! is_array( $profile ) ) {
			return $array;
		}

		$attachment = array();

		foreach ( $profile as $key => $value ) {
			$key = wp_strip_all_tags( html_entity_decode( $key ) );

			if ( ! is_string( $value ) ) {
				continue;
			}

			$value = wp_strip_all_tags( html_entity_decode( $value ) );
			if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$value = esc_url_raw( $value );
				$value = '<a title="' . esc_attr( $value ) . '" target="_blank" href="' . esc_url( $value ) . '">' . wp_parse_url( $value, PHP_URL_HOST ) . '</a>';
			}

			$attachment[] = array(
				'type'  => 'PropertyValue',
				'name'  => $key,
				'value' => $value,
			);
		}

		$array['attachment'] = $attachment;

		return $array;
	}
}
