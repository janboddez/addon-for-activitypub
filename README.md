# ActivityPub Add-on
Somewhat modify the ActivityPub plugin's behavior.

**Probably not ready for production.** In fact, there's a bunch of options that aren't implemented, yet. 😆

All features can be switched on or off separately. Some may also require, for now, the IndieBlocks plugin.

## Content Templates
This plugin also adds “post type templates.” There’s no separate setting for them.

Either one or more template files exist—or not.

Template files go in `wp-content/themes/your-child-theme/activitypub/content-{$post->post_type}.php`.
Here's one example called `content-indieblocks_note.php`, for a custom post type with an `indieblocks_note` slug:
```
<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$content = apply_filters( 'the_content', $post->post_content );

$shortlink = wp_get_shortlink( $post->ID );
if ( ! empty( $shortlink ) ) {
	$permalink = $shortlink;
} else {
	$permalink = get_permalink( $post );
}

// Since this a note, append only a permalink.
$content .= '<p>(<a href="' . esc_url( $permalink ) . '">' . esc_html( $permalink ) . '</a>)</p>';

echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
```
