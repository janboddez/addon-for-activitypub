<?php
/**
 * Implements PHP content templates.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

class Content_Templates {
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

			if ( '' !== $template ) {
				ob_start();
				require $template;
				$content = ob_get_clean();
			}
		} elseif ( $obj instanceof \WP_Comment ) {
			$comment  = $obj; // Used inside the included file.
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
		$content = wp_kses( $content, apply_filters( 'addon_for_activitypub_allowed_tags', self::ALLOWED_TAGS, $obj ) );
		// Strip whitespace, but ignore `pre` elements' contents.
		$content = preg_replace( '~<pre[^>]*>.*?</pre>(*SKIP)(*FAIL)|\r|\n|\t~s', '', $content );
		// Strip unnecessary whitespace.
		$content = zz\Html\HTMLMinify::minify( $content );

		return $content;
	}
}
