# ActivityPub Add-on
Somewhat modifies the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/)’s behavior.

Most features can be switched on or off separately. All of this is subject to change. No warranties whatsoever.

## Incoming Likes and Reposts
This plugin adds support for incoming likes and reposts. (For [outgoing replies and reposts](https://github.com/janboddez/addon-for-activitypub?tab=readme-ov-file#enable-replies), see below.)

## Options
### Local-only Category
Posts (of any post type, as long as it supports WordPress’ built-in categories) in this category will not get “federated” and remain “local-only.”

It's currently not possible to use, e.g., post formats or a certain custom field to “set” local-only posts. Reason is the advanced queries needed to disable “content negotation” for such posts, and keep them out of, e.g., your ActivityPub outbox. (I might eventually add a sidebar panel/meta box with a “Federate” checkbox, but for now, it’s categories only.)

### Unlisted Category
Posts in this category will appear “unlisted” (or “less public”) on various Fediverse instances. (Whether you also hide these posts on, e.g., your site’s homepage, is up to you!)

To instead use, e.g., a custom field or post format, to decide if a post should be “unlisted,” there’s this filter (which takes precedence over whatever category, if any, was selected):
```
add_filter( 'addon_for_activitypub_is_unlisted', function ( $is_unlisted, $post_or_comment ) {
  if ( $post_or_comment instanceof \WP_Post && has_post_format( 'aside', $post_or_comment ) ) {
    return true;
  }

  return $is_unlisted;
}, 10, 2 );
```

### Unlisted Comments
Have (all) comments appear “unlisted.” (Not sure if this is in any way useful, but, “Why not?”)

### Edit Notifications
Receive an email when an earlier “Fediverse” comment is modified.

### Limit Updates
Don’t send Update activities when nothing’s changed. (Note that “nothing” is rather relative here. E.g., an Update might still get federated even if a post’s ActivityPub representation, which could contain only an excerpt, hasn’t actually changed, but the “real” post content did. Also, this whole thing might conflict with how you’ve set up ActivityPub, so beware.)

### Enable “Replies”
While the ActivityPub plugin will “federate” your replies to “Fediverse” comments, it does not (yet) support outright replying to others’ posts.

This setting addresses that, but only for posts [marked up as replies](https://indieweb.org/reply#How_To). (It will attempt to detect if the “target post” supports the [ActivityPub protocol](https://www.w3.org/TR/activitypub/). If so, the target post’s author will receive a notification, and your reply will appear “correctly threaded” on other Fediverse instances, too.)

### Enable “Reposts”
Similar to replies; This should translate “[reposts](https://indieweb.org/repost#How_to_Publish)” into “boosts” (or “reblogs”) on Mastodon and other Fediverse platforms.

### Enable “Likes”
Enables outgoing likes. That is, this setting turns posts [marked up as likes](https://indieweb.org/like#How) into “actual Fediverse likes,” but only if the remote URL actually supports ActivityPub.

### Close Comments
Allow closing ActivityPub reactions after a certain number of days. Much like core WordPress’ setting for regular comments.

## Content Templates
This plugin also adds “post type templates.” There’s no separate setting for them.

Either one or more template files exist—or not.

Template files go in your (child) theme, and may be called, e.g., `wp-content/themes/your-child-theme/activitypub/content-{$post->post_type}.php`.
Here’s one example called `content-indieblocks_note.php`, for a custom post type with an `indieblocks_note` slug:
```
<?php

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}
?>

<?php echo apply_filters( 'the_content', $post->post_content ); ?>
<p><a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_permalink( $post ) ); ?></a></p>
```
The idea here is that you _could_ append custom fields and whatnot.

A comment template file would be named `wp-content/themes/your-child-theme/activitypub/content-comment.php`.

## User Profiles
These are somewhat like the “post type templates” above.

In this case, the plugin would look for a file called `wp-content/themes/your-child-theme/activitypub/profile-user-{$user->ID}.php`.

Big difference with “content templates” is that this file _has to_ return an array, which can hold up to 4 fields.

Here’s one example called `profile-user-3.php`, for the user with ID `3`:
```
<?php

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'Blog'        => home_url(),
	'IndieBlocks' => 'https://indieblocks.xyz',
	'Feed Reader' => 'https://feedreader.site',
	'Location'    => '🇧🇪🇳🇱',
);
