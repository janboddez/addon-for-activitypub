# ActivityPub Add-on
Somewhat modify the ActivityPub plugin's behavior.

**Probably not ready for production.** In fact, there's a bunch of options that aren't implemented, yet. 😆

All features can be switched on or off separately. Some may also require, for now, the IndieBlocks plugin.

This plugin also adds “post type templates.” There’s no separate setting for them.

Either one or more template files exist—or not.

Template files go in `wp-content/themes/your-child-theme/activitypub/content-{$post->post_type}.php`.
