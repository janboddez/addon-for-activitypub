<?php
/**
 * Main plugin class.
 *
 * @package AddonForActivityPub
 */

namespace AddonForActivityPub;

class Options_Handler {
	/**
	 * Plugin option schema.
	 */
	const SCHEMA = array(
		'unlisted_cat'       => array(
			'type'    => 'string',
			'default' => '',
		),
		'unlisted_comments'  => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'edit_notifications' => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'limit_updates'      => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'enable_replies'     => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'enable_reposts'     => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'cache_avatars'      => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'proxy_avatars'      => array(
			'type'    => 'boolean',
			'default' => false,
		),
	);

	/**
	 * Plugin options.
	 *
	 * @var array $options Plugin options.
	 */
	protected $options = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$options = get_option( 'addon_for_activitypub_settings' );

		// Ensure `$this->options` is an array, and that all keys get a value.
		$this->options = array_merge(
			array_combine( array_keys( self::SCHEMA ), array_column( self::SCHEMA, 'default' ) ),
			is_array( $options )
				? $options
				: array()
		); // Note that this affects only `$this->options` as used by this plugin, and not, e.g., whatever shows in the REST API.
	}

	/**
	 * Interacts with WordPress's Plugin API.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'rest_api_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the plugin settings page.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Add-on for ActivityPub', 'addon-for-activitypub' ),
			__( 'Add-on for ActivityPub', 'addon-for-activitypub' ),
			'manage_options',
			'addon-for-activitypub',
			array( $this, 'settings_page' )
		);

		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Registers the actual options.
	 */
	public function add_settings() {
		// Pre-initialize settings. `add_option()` will _not_ override existing
		// options, so it's safe to use here.
		add_option( 'addon_for_activitypub_settings', $this->options );

		$schema = self::SCHEMA;
		foreach ( $schema as &$row ) {
			unset( $row['default'] );
		}

		// Prep for Gutenberg.
		register_setting(
			'addon-for-activitypub-settings-group',
			'addon_for_activitypub_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'type'              => 'object',
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => $schema,
					),
				),
			)
		);
	}

	/**
	 * Handles submitted options.
	 *
	 * @param  array $settings Settings as submitted through WP Admin.
	 * @return array           Options to be stored.
	 */
	public function sanitize_settings( $settings ) {
		$options = array(
			'unlisted_cat'       => isset( $settings['unlisted_cat'] ) && '' !== $settings['unlisted_cat']
				? sanitize_title( $settings['unlisted_cat'] )
				: '',
			'unlisted_comments'  => isset( $settings['unlisted_comments'] ) ? true : false,
			'edit_notifications' => isset( $settings['edit_notifications'] ) ? true : false,
			'limit_updates'      => isset( $settings['limit_updates'] ) ? true : false,
			'enable_replies'     => isset( $settings['enable_replies'] ) ? true : false,
			'enable_reposts'     => isset( $settings['enable_reposts'] ) ? true : false,
			'cache_avatars'      => isset( $settings['cache_avatars'] ) ? true : false,
			'proxy_avatars'      => isset( $settings['proxy_avatars'] ) ? true : false,
		);

		$this->options = array_merge( $this->options, $options );

		return $this->options;
	}

	/**
	 * Echoes the plugin options form.
	 */
	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add-on for ActivityPub', 'addon-for-activitypub' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				// Print nonces and such.
				settings_fields( 'addon-for-activitypub-settings-group' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="addon_for_activitypub_settings[unlisted_cat]"><?php esc_html_e( '“Unlisted” Category', 'addon-for-activitypub' ); ?></label></th>
						<td>
							<select name="addon_for_activitypub_settings[unlisted_cat]" id="addon_for_activitypub_settings[unlisted_cat]">
								<option value="">&nbsp;</option>
								<?php
								$categories = get_categories(
									array(
										'orderby'    => 'name',
										'order'      => 'ASC',
										'hide_empty' => false,
									)
								);

								if ( ! empty( $categories ) ) :
									foreach ( $categories as $category ) :
										?>
										<option value="<?php echo esc_attr( $category->slug ); ?>" <?php ( ! empty( $this->options['unlisted_cat'] ) ? selected( $category->slug, $this->options['unlisted_cat'] ) : '' ); ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
										<?php
									endforeach;
								endif;
								?>
							</select>
							<p class="description">
								<?php _e( 'Posts (of any type) in this category will appear “unlisted” on platforms like Mastodon. (Blank means all posts will be “public.”)', 'addon-for-activitypub' ); // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction ?><br />
								<?php _e( 'Alternatively, use the <code>addon_for_activitypub_is_unlisted</code> filter.', 'addon-for-activitypub' ); // phpcs:ignore WordPress.Security.EscapeOutput.UnsafePrintingFunction ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( '“Unlisted” Comments', 'addon-for-activitypub' ); ?></th>
						<td><label><input type="checkbox" name="addon_for_activitypub_settings[unlisted_comments]" value="1" <?php checked( ! empty( $this->options['unlisted_comments'] ) ); ?>/> <?php esc_html_e( '“Unlist” comments', 'addon-for-activitypub' ); ?></label>
						<p class="description"><?php esc_html_e( 'Federate comments as “unlisted.”', 'addon-for-activitypub' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( '“Edit” Notifications', 'addon-for-activitypub' ); ?></th>
						<td><label><input type="checkbox" name="addon_for_activitypub_settings[edit_notifications]" value="1" <?php checked( ! empty( $this->options['edit_notifications'] ) ); ?>/> <?php esc_html_e( 'Enable “edit” notifications', 'addon-for-activitypub' ); ?></label>
						<p class="description"><?php esc_html_e( 'Receive an email notification when a (remote) comment gets edited.', 'addon-for-activitypub' ); ?></p></td>
					</tr>
					<!--
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Limit Updates', 'addon-for-activitypub' ); ?></th>
						<td><label><input type="checkbox" name="addon_for_activitypub_settings[limit_updates]" value="1" <?php checked( ! empty( $this->options['limit_updates'] ) ); ?>/> <?php esc_html_e( 'Limit updates', 'addon-for-activitypub' ); ?></label>
						<p class="description"><?php esc_html_e( '(Experimental) Attempts to “federate” only “meaningful” updates.', 'addon-for-activitypub' ); ?></p></td>
					</tr>
					//-->
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable “Replies”', 'addon-for-activitypub' ); ?></th>
						<td><label><input type="checkbox" name="addon_for_activitypub_settings[enable_replies]" value="1" <?php checked( ! empty( $this->options['enable_replies'] ) ); ?>/> <?php esc_html_e( 'Enable “replies”', 'addon-for-activitypub' ); ?></label>
						<p class="description"><?php esc_html_e( '(Experimental) Reply not just to comments, but to any post in the Fediverse.', 'addon-for-activitypub' ); ?></p></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable “Reposts”', 'addon-for-activitypub' ); ?></th>
						<td><label><input type="checkbox" name="addon_for_activitypub_settings[enable_reposts]" value="1" <?php checked( ! empty( $this->options['enable_reposts'] ) ); ?>/> <?php esc_html_e( 'Enable “reposts”', 'addon-for-activitypub' ); ?></label>
						<p class="description"><?php esc_html_e( '(Experimental) Turn so-called “reposts” in “proper Fediverse ‘reblogs.’”', 'addon-for-activitypub' ); ?></p></td>
					</tr>
					<?php if ( function_exists( '\\IndieBlocks\\store_image' ) ) : ?>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( '“Cache” Avatars', 'addon-for-activitypub' ); ?></th>
							<td><label><input type="checkbox" name="addon_for_activitypub_settings[cache_avatars]" value="1" <?php checked( ! empty( $this->options['cache_avatars'] ) ); ?>/> <?php esc_html_e( '“Cache” avatars', 'addon-for-activitypub' ); ?></label>
							<p class="description"><?php esc_html_e( '(Experimental) Store avatars locally for at least a month.', 'addon-for-activitypub' ); ?></p></td>
						</tr>
					<?php endif; ?>

					<?php if ( function_exists( '\\IndieBlocks\\proxy_image' ) ) : ?>
						<tr valign="top">
							<th scope="row"><?php esc_html_e( '“Proxy” Avatars', 'addon-for-activitypub' ); ?></th>
							<td><label><input type="checkbox" name="addon_for_activitypub_settings[proxy_avatars]" value="1" <?php checked( ! empty( $this->options['proxy_avatars'] ) ); ?>/> <?php esc_html_e( '“Proxy” avatars', 'addon-for-activitypub' ); ?></label>
							<p class="description"><?php esc_html_e( '(Experimental) Alternatively, serve remote avatars from this site’s domain.', 'addon-for-activitypub' ); ?></p></td>
						</tr>
					<?php endif; ?>

				</table>
				<p class="submit"><?php submit_button( __( 'Save Changes' ), 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns this plugin's settings.
	 *
	 * @return array This plugin's settings.
	 */
	public function get_options() {
		return $this->options;
	}
}
