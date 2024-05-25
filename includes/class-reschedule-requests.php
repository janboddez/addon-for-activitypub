<?php
/**
 * Reschedules failed ActivityPub requests.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

class Reschedule_Requests {
	/**
	 * Hooks and such.
	 */
	public static function register() {
		add_action( 'activitypub_safe_remote_post_response', array( __CLASS__, 'reschedule_failed_posts' ), 10, 4 );
		add_action( 'addon_for_activitypub_resend_post', array( __CLASS__, 'resend_post' ), 10, 3 );
	}

	/**
	 * Reschedules failed POST requests.
	 *
	 * @param \WP_HTTP_Response|\WP_Error $response Response of the remote ActivityPub server, or an error.
	 * @param string                      $url      Remote inbox.
	 * @param string                      $body     Request payload.
	 * @param int                         $user_id  User ID.
	 */
	public static function reschedule_failed_posts( $response, $url, $body, $user_id ) {
		if ( ! is_wp_error( $response ) ) {
			// All good.
			return;
		}

		error_log( '[Add-on for ActivityPub] Error posting to ' . esc_url_raw( $url ) . ': ' . var_export( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_var_export

		$transient = 'addon-for-activitypub:retries:' . md5( 'url:' . $url . ';body:' . $body . ';user_id:' . $user_id );
		$retries   = get_transient( $transient );

		if ( false === $retries ) {
			// Transient does not (yet) exist (or no longer exists).
			set_transient( $transient, 3, HOUR_IN_SECONDS );

			if ( false === wp_next_scheduled( 'addon_for_activitypub_resend_post', array( $url, $body, $user_id ) ) ) {
				error_log( '[Add-on for ActivityPub] Rescheduling ...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_var_export
				wp_schedule_single_event( time() + 120, 'addon_for_activitypub_resend_post', array( $url, $body, $user_id ) );
			}
		} elseif ( $retries > 0 ) {
			// Some retries to go.
			if ( false === wp_next_scheduled( 'addon_for_activitypub_resend_post', array( $url, $body, $user_id ) ) ) {
				error_log( '[Add-on for ActivityPub] Rescheduling ...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_var_export
				wp_schedule_single_event( time() + 120, 'addon_for_activitypub_resend_post', array( $url, $body, $user_id ) );
			}
		} else {
			// Retries must be `0`.
			error_log( '[Add-on for ActivityPub] We\'re done with this one.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			delete_transient( $transient ); // Forget about it.

			$timestamp = wp_next_scheduled( 'addon_for_activitypub_reschedule_post', array( $url, $body, $user_id ) );
			if ( false !== $timestamp ) {
				// Should probably never happen.
				error_log( '[Add-on for ActivityPub] Unscheduling ...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_var_export
				wp_unschedule_event( $timestamp, 'addon_for_activitypub_reschedule_post', array( $url, $body, $user_id ) );
			}
		}
	}

	/**
	 * Resends a previously failed POST request.
	 *
	 * @param string $url     Remote inbox.
	 * @param string $body    Request payload.
	 * @param int    $user_id User ID.
	 */
	public static function resend_post( $url, $body, $user_id ) {
		if ( ! class_exists( '\\Activitypub\\Http' ) || ! method_exists( \Activitypub\Http::class, 'post' ) ) {
			return;
		}

		$transient = 'addon-for-activitypub:retries:' . md5( 'url:' . $url . ';body:' . $body . ';user_id:' . $user_id );
		$retries   = get_transient( $transient );
		if ( false === $retries ) {
			return;
		}

		if ( 0 === $retries ) {
			delete_transient( $transient );
		} else {
			--$retries;
			set_transient( $transient, $retries, DAY_IN_SECONDS );
		}

		error_log( '[Add-on for ActivityPub] Resending ...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		\Activitypub\Http::post( $url, $body, $user_id );

		error_log( "[Add-on for ActivityPub] Retries left: $retries." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
