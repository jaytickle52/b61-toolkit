# B61 Toolkit

Banner 61's modular site toolkit for WordPress. Each feature is a module you switch on per site under **B61 Toolkit → Features**; on a multisite network every module starts off and only super admins can change them.

## Modules

| Module | Replaces |
|---|---|
| People directory | "Team" snippet / ACF staff fields |
| Testimonials | network "Testimonials" snippet |
| Events | "Events" snippet |
| Content Order | Post Types Order, ASE content order |
| Duplicate | ASE duplication |
| Replace Media | ASE media replacement |
| Admin Cleanup | ASE admin/dashboard/comments/feeds/external-link settings |
| Login Page | ASE login page customizer |
| Email Protection | ASE email obfuscation |
| Password Pages | "Password-Protected Page" snippet, Password Protected Page Design |
| Calendar | ICS Calendar (`[b61_calendar]`, also answers `[ics_calendar]`) |
| Organization Details | network "Options Page & Fields" snippet / ACF Global Info (`[b61_details field="…"]`; `[b61_school]` still works) |
| Announcement Bar | Bulletin Announcements (one scheduled notice bar, closable, no endpoints) |
| Scheduling & Expiration | PublishPress Future / Post Expirator (expiry date per item, per content type) |
| Media Folders | ASE media categories (adopts the existing `asenha-media-category` terms) |
| Media Usage | — ("Used in" column, attachment details, Used / Not used filter) |
| SEO | Rank Math / Yoast (titles, descriptions, noindex, canonical, sharing tags, sitemap, schema from Org Details/Events/People, redirects + 404 log, llms.txt, import from both) |
| Clear Cache | the cache plugins' own "purge" buttons — one admin-bar button for every layer, automatic after updates |
| Client Dashboard | WordPress's dashboard cards (Needs attention, Your site at a glance, Help & how-to, News) |
| Admin Theme | — (Banner 61 colours in wp-admin for everyone, rounded controls, per-person light / dark / match-system switch) |
| AI Alt Text | Alt Magic |
| Balanced headlines / Paragraph orphans | — (pairs with Banner 61 Elements) |

## SEO

Switch on **SEO** in Features. Every content type with public pages gets a **Search & sharing** box (title, description, sharing image, "hide from search engines", canonical) with a Google-style preview; **Suggest a description** appears when an OpenAI key is set under AI Alt Text.

- **SEO** screen: home page title/description, separator, organization type, default sharing image, Google/Bing verification, which archives search engines skip, title patterns per content type. **Import** tab copies Rank Math or Yoast data (pages, settings, redirects) without touching the original.
- **Redirects**: 301/302/307/410, exact or regular-expression rules, plus a 404 log with one-click "Redirect…".
- **Removing a page**: trash a published page and a notice offers to redirect its old address (to its parent page, its section, or the home page). If the page is published again, that redirect removes itself.
- **SEO Report**: a short list of things to fix (site blocked from search, hidden pages, missing descriptions, long or duplicate titles, images without alt text — by media folder, with a button to the AI Alt Text bulk tool — redirect chains, missing org details).
- Structured data comes from Org Details (name, logo, phone, address, social links), Events (dates, times, location), People (name, job title — never email or phone) and breadcrumbs.
- **Sitemap & robots.txt** tab: switch each content type and taxonomy in or out of WordPress's own sitemap (`/wp-sitemap.xml`; hidden pages are always left out), add extra robots.txt rules, and see the robots.txt being served. `/llms.txt` summarises the site for AI search tools.
- While Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework, Slim SEO or Squirrly is active, the module prints nothing in the page head — import first, check the report, then deactivate the old plugin.

## Scheduling & Expiration

The post-level version of the Announcement Bar's start/stop times. Switch it on in Features, then under **B61 Toolkit → Scheduling & Expiration** tick the content types that should get it (Posts by default — e.g. on for News, off for Pages) and pick the default action.

- Each chosen type gets an **Expiration** box on its edit screen (block or classic editor): an *Expires* date and what happens then — **Unpublish** (back to Drafts), **Make private**, **Move to Trash**, or for Posts **Remove from featured** (un-sticks it; it stays published).
- Publishing later is WordPress's own scheduling (future date → Schedule); the box shows the scheduled publish date and warns if the expiry falls before it.
- An **Expires** column on the list screens, a **Coming up** list on the settings screen, and **Expiring soon** on the Client Dashboard.
- Each item gets its own WP-Cron event, plus an hourly sweep for anything missed. When Clear Cache is on, the item's page is cleared the moment it expires.
- Turning a type off pauses its dates (they're kept); anything overdue expires when it's turned back on. Duplicating an item doesn't copy its expiry. Developers: `b61_post_expired` fires after each expiry; `b61_expiration_available_types` filters the list of types.

## Editing screens

People, Testimonials and Events all use the classic edit screen, with their fields right under the title. The generic "Custom Fields" box is hidden, and so is "Page Attributes" when Content Order handles that type's order (the data behind both is kept). Developers can add types with the `b61_toolkit_editor_post_types` filter.

## Clear Cache

"Clear cache" in the admin bar (editors and admins by default) asks every caching layer on the site to clear itself: Breakdance and Elementor CSS, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze/Varnish (Cloudways), SiteGround, Nginx Helper, Cache Enabler, Hummingbird, Autoptimize, Kinsta, WP Engine, Pantheon, Cloudflare (with a Cache Purge token), WordPress's object cache (single sites only) and the Toolkit's own saved copies. On a page, "Clear this page only" clears just that page where the cache allows it.

It also runs by itself after plugin, theme, translation and WordPress updates (automatic ones included) and after saving Toolkit settings visitors see. The last ten clears are listed under B61 Toolkit → Clear Cache.

## Admin Theme

One switch gives the whole dashboard the Banner 61 colours (dark #3F3B4C, light #F0F1EE, text #101827, accent #EE5758) through WordPress's own colour-scheme system — `assets/css/admin-scheme.css` is compiled from core's `colors/_admin.scss` with `assets/css/admin-scheme.scss` (`npx sass`). Every text pair meets WCAG AA: the accent is used as a background with dark text (5.2:1), and a deeper #B24142 is used wherever it would be text on white (5.6:1). Everyone on the site gets the scheme; the per-person picker is hidden.

Each person can pick Light, Dark or Match system from the ☀ / ☾ / ◐ menu in the admin bar. Dark mode covers WordPress's own screens and the Toolkit's; the block editor keeps its light canvas, and other plugins' screens may stay partly light.

## Client Dashboard and help guides

Replaces WordPress's dashboard cards with four of our own. **Needs attention** lists only what the person viewing can fix (search engines blocked, SEO Report findings, removed pages still getting visitors, busy 404s, items awaiting review, missing Org Details). **Your site at a glance** shows the live announcement, the next events and recent edits with who made them. **Help & how-to** lists the guides and how to contact us (Toolkit → Client Dashboard). **News** reads any RSS feed — `https://banner61.com/feed/` by default; point it at a category feed to show only client news.

Guides are written once on banner61.com: switch the module on there and tick *This is the guides site*. That adds **Help Guides** (public at `/help/…`), each with the screens it belongs to, an optional video link and the excerpt as its summary. Every client site reads `https://banner61.com/wp-json/b61/v1/guides` (cached 12 hours), lists the guides on the dashboard and adds a **How-to guides** Help tab on the matching screens. White-labelled installs start with no guides or news address.

## Media Usage

Indexes where each file is used: its address (any size) in content or custom fields — which covers Breakdance pages, templates, headers and footers — image blocks and galleries, featured and SEO share images, the site icon and logo, Breakdance global settings and Toolkit settings. The first index runs in the background in batches of 50; after that each item is re-indexed when saved. "Not used" means nothing on this site points at the file; a file linked from an email or another website will still show, so there is a filter to review them but no bulk delete. Extra ID fields can be added with the `b61_media_usage_id_meta` filter.

## Copying settings between sites

**B61 Toolkit → Import / Export** downloads which features are on, plus each feature's settings, as a JSON file. Importing shows what the file holds and lets you tick what to apply before anything changes. API keys are never exported. Image choices (such as the login logo) are only imported onto the site they came from. Content such as people, events and testimonials is not included; use Tools → Export for that.

## Breakdance

When Breakdance (Free or Pro) is active, the Toolkit adds its content to Breakdance's dynamic data picker. Nothing to switch on. Fields appear only for modules that are on:

- **People**: title, credentials, email, phone, bio, groups, LinkedIn, plus ready-made `mailto:` and `tel:` links
- **Events**: when, time, location, link text, link URL, and start date in any format (e.g. `M` / `j` for date badges)
- **Testimonials**: quote, role
- **Organization**: every Organization Details field, plus `tel:` and `mailto:` links

People, Events and Testimonials are normal post types, so Breakdance Post Loops can list them, and hand order from Content Order applies in the builder preview as well as on the live site. Developers can add or change fields with the `b61_breakdance_fields` filter.

## White-label

Banner 61's names are the defaults. On a partner install, set any of these in `wp-config.php` and the admin menu, screens, Plugins list and update details show them instead:

```php
define( 'B61_TOOLKIT_BRAND_NAME', 'Acme Site Tools' );
define( 'B61_TOOLKIT_BRAND_MENU', 'Site Tools' );            // optional, defaults to the name
define( 'B61_TOOLKIT_BRAND_AUTHOR', 'Acme Web Co.' );
define( 'B61_TOOLKIT_BRAND_AUTHOR_URI', 'https://acme.example' );
define( 'B61_TOOLKIT_BRAND_ICON', 'dashicons-admin-generic' );
define( 'B61_TOOLKIT_BRAND_ELEMENTS', 'Acme Elements' );     // companion Elements plugin name
```

Internal names (post types like `b61_person`, option keys, shortcodes, the `b61-toolkit` folder) stay the same so content never needs migrating. Release notes appear in the "View details" box, so keep them brand-neutral.

## Releasing an update

1. Bump `Version:` and `B61_TOOLKIT_VERSION` in `b61-toolkit.php`.
2. Build the zip (top folder must be `b61-toolkit/`) — built zips go in `dist/`, which git ignores.
3. Push, then on GitHub: **Releases → Draft a new release**, tag `vX.Y.Z`, attach `b61-toolkit.zip` (or `b61-toolkit-X.Y.Z.zip`), publish.
4. Sites see the update on their Plugins screen within six hours ("Check for updates" link forces it).

## Third-party code

`vendor/ics-parser/` — ics-parser 3.6.0 (MIT), namespaced and patched; see its README.txt.

## Quality checks

Each release is run through WordPress's Plugin Check. The only remaining notices are rules for plugins listed on WordPress.org: a self-updater, a `readme.txt`, and calling OpenAI directly. They don't apply to a privately distributed plugin.
