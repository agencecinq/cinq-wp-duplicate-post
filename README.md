# CINQ Duplicate Post

WordPress plugin that adds a Duplicate link to the admin list screens that copies a post into a new draft. No settings screen.

The [Yoast Duplicate Post](https://wordpress.org/plugins/duplicate-post/) plugin adds Clone, New Draft and Rewrite & Republish links, a settings screen, links in the admin bar and notices. A site whose editors only clone posts needs the Clone link alone. This plugin replaces Yoast Duplicate Post for that.

**Repository:** [`agencecinq/cinq-wp-duplicate-post`](https://github.com/agencecinq/cinq-wp-duplicate-post)

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Lint (WordPress Coding Standards)

```bash
composer install
composer lint          # phpcs
composer lint:fix     # phpcbf (auto-fix)
```

Use `./vendor/bin/phpcs`, not the global `phpcs` binary — the global install does not register the WordPress standards.

## Install

Copy the plugin file into `mu-plugins`. WordPress loads it on every request, and it cannot be deactivated from the admin. It is listed under **Plugins → Must-Use**, not with the regular plugins.

```bash
cp cinq-wp-duplicate-post.php /path/to/wp-content/mu-plugins/
```

Replace that file when the plugin changes.

To install it as a regular plugin, copy the folder to `wp-content/plugins/cinq-wp-duplicate-post` and activate **CINQ Duplicate Post** in the WordPress admin.

### Replacing Yoast Duplicate Post

Publish or delete any Rewrite & Republish copy first: without Yoast Duplicate Post, it is a plain draft, and publishing it creates a second post.

Yoast Duplicate Post has no uninstall routine: deleting it from **Plugins** leaves its `duplicate_post_*` options, the `copy_posts` capability it gave to roles, and the `_dp_original` meta on the copies it made. Remove them with WP-CLI:

```bash
wp option list --search='duplicate_post_*' --field=option_name | xargs -n1 wp option delete
wp eval 'delete_post_meta_by_key( "_dp_original" ); foreach ( wp_roles()->role_objects as $role ) { $role->remove_cap( "copy_posts" ); }'
```

## Translations

French is included in [`languages/`](languages). The plugin does not load translations itself: WordPress loads them from its languages folder, which also works when the plugin is a must-use plugin.

Copy both French files to `wp-content/languages/plugins/`:

- `cinq-wp-duplicate-post-fr_FR.l10n.php`, read by WordPress 6.5 and later;
- `cinq-wp-duplicate-post-fr_FR.mo`, for earlier versions.

To update them after changing a string:

```bash
wp i18n make-pot . languages/cinq-wp-duplicate-post.pot --exclude=vendor
msgmerge --update --backup=none languages/cinq-wp-duplicate-post-fr_FR.po languages/cinq-wp-duplicate-post.pot
# Translate the new strings in the .po file, then:
msgfmt -o languages/cinq-wp-duplicate-post-fr_FR.mo languages/cinq-wp-duplicate-post-fr_FR.po
wp i18n make-php languages
```

`wp i18n make-php` needs WP-CLI 2.8 or later.

## Behaviour

The Duplicate link sits in the row actions of the list screens, next to Edit and Trash. It creates a draft owned by the current user, then goes back to the list, where the draft shows up, like the Clone link of Yoast Duplicate Post but without a notice.

The draft copies the title, content, excerpt, password, parent, order, comment and ping status, terms and meta (ACF fields, featured image, page template). It gets its own slug and date when it is published. The edit lock, last editor and old slugs and dates stay with the original, and only the first value of a meta key is copied.

The user needs to be able to edit the original and to create posts of its type.

### Hooks

| Hook | What it does |
| --- | --- |
| `post_row_actions`, `page_row_actions` | Adds the Duplicate link to the row actions of the list screens |
| `admin_post_cinq_wp_duplicate_post` | Copies the post into a draft, then goes back to the list |

### Optional filter

```php
// Post types that can be duplicated (default: every post type with its own admin menu, except attachment).
add_filter(
	'cinq_wp_duplicate_post_post_types',
	fn ( array $post_types ) => array_diff( $post_types, array( 'client' ) )
);
```

## Scope

- Post types: every post type with its own admin menu by default, except attachment, more or fewer through `cinq_wp_duplicate_post_post_types`. This leaves out the internal types of WordPress (patterns, navigation menus) and of ACF (field groups, post types, taxonomies, options pages), which ACF duplicates itself.
- No options, no admin UI beyond the row action, no notice, no front-end output

## License

MIT © [CINQ](https://agencecinq.com)
