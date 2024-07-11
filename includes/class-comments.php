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

		if ( ! empty( $options['comment_editing'] ) ) {
			add_action( 'init', array( __CLASS__, 'enable_editing' ), PHP_INT_MAX );
		}

		if ( ! empty( $options['disable_reply_modal'] ) ) {
			add_action( 'init', array( __CLASS__, 'disable_reply_modal' ), PHP_INT_MAX );
		}
	}

	/**
	 * Re-enables comment editing.
	 */
	public static function enable_editing() {
		if ( ! class_exists( '\\Activitypub\\Admin' ) ) {
			return;
		}

		if ( method_exists( \Activitypub\Admin::class, 'edit_comment' ) ) {
			remove_action( 'load-comment.php', array( \Activitypub\Admin::class, 'edit_comment' ) );
		}

		if ( method_exists( \Activitypub\Admin::class, 'comment_row_actions' ) ) {
			remove_filter( 'comment_row_actions', array( \Activitypub\Admin::class, 'comment_row_actions' ) );
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
