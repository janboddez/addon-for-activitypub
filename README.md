# ActivityPub Add-on
Somewhat modifies the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/)’s behavior. **Probably not ready for production.** 😆

All features can be switched on or off separately.

## Unlisted Posts
This plugin allows for posts (of any type) in a certain category to appear “unlisted” (or “less public”) on other Fediverse instances. (Whether you also hide these posts on, e.g., your site’s homepage, is up to you. For now, at least.)

To use, e.g., a custom field or post format instead, to decide if a post should be “unlisted,” there’s this filter (which takes precedence over whatever category, if any, was selected):
```
add_filter( 'addon_for_activitypub_is_unlisted', function ( $is_unlisted, $post_or_comment ) {
  if ( $post_or_comment instanceof \WP_Post && has_post_format( 'aside', $post_or_comment ) ) {
    return true;
  }

  return $is_unlisted;
}, 10, 2 );
```
**Note:** To ensure this works, you'll want to make sure the category or post format _really is applied_ **before** publishing. If you use the block ("Gutenberg") editor, that may mean having to save your post as draft before hitting "Publish."

## Reply Posts
While the ActivityPub plugin will “federate” your replies to “Fediverse” comments, it does not (yet) support outright replying to others’ posts.

This add-on plugin addresses that. For posts [marked up as replies](https://indieweb.org/reply#How_To), it will attempt to detect if the “target post” supports the [ActivityPub protocol](https://www.w3.org/TR/activitypub/). If so, the target post’s author will receive a notification, and your reply will appear “correctly threaded” on other Fediverse instances, too.

## Content Templates
This plugin also adds “post type templates.” There’s no separate setting for them.

Either one or more template files exist—or not.

Template files go in `wp-content/themes/your-child-theme/activitypub/content-{$post->post_type}.php`.
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
