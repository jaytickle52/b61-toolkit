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
| AI Alt Text | Alt Magic |
| Balanced headlines / Paragraph orphans | — (pairs with Banner 61 Elements) |

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
