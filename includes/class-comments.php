<?php
/**
 * Modify comment behavior.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

class Comments {
	/**
	 * Registers hook callbacks.
	 */
	public static function register() {
		$options = get_options();

		if ( ! empty( $options['disable_reply_modal'] ) ) {
			add_action( 'init', array( __CLASS__, 'disable_reply_modal' ), PHP_INT_MAX );
		}
	}

	/**
	 * Disables the "Reply on the Fediverse" button and modal.
	 */
	public static function disable_reply_modal() {
		if ( ! class_exists( '\\Activitypub\\Comment' ) ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', array( \Activitypub\Comment::class, 'enqueue_scripts' ) );
		remove_filter( 'comment_reply_link', array( \Activitypub\Comment::class, 'comment_reply_link' ) );
	}
}
