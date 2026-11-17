# WordPress.org listing assets

This folder records the listing copy and graphics prepared for Activity Log for Admins 1.2.0.

## Files

* `assets/icon.svg`, vector plugin icon with PNG fallbacks.
* `assets/icon-128x128.png` and `assets/icon-256x256.png`, directory icon sizes.
* `assets/banner-772x250.png` and `assets/banner-1544x500.png`, standard and high density directory banners.
* `assets/screenshot-1.png` through `assets/screenshot-4.png`, activity, event details, Dashboard summary, and Settings views.
* `assets/lucide-license.txt`, license notice for the Lucide Activity glyph used in the icon and banner.
* `activity-log-for-admins/readme.txt`, directory description, supported events, privacy details, installation steps, FAQ, captions, and current changelog.

The top level `assets` folder follows the WordPress.org SVN layout. Copy these files to the directory level alongside `trunk`, not into the plugin folder. The plugin ZIP does not include this folder.

## Rebuilding

From the project root, run:

```sh
npm ci
npm run assets:wordpress
npm run package
```

The asset command builds a temporary preview bundle from the current interface and renders the screenshots with Playwright and Chrome. It writes images to `assets`. The temporary bundle stays under the ignored `.tmp` folder. The package command rebuilds the installable plugin ZIP with the updated readme.

## Screenshot provenance

The activity, event detail, Dashboard, and Settings images use the 1.2.0 React bundle and shared styles, with illustrative event records. The Settings footer takes its version from `package.json`. Fixture dates are fixed for reproducible screenshots. These are interface previews, not captures from a live or staging WordPress site. No site data is included. Saved pilot captures are kept as test evidence, since they contain repeated seed events or failure notices.

## Confirm before a directory release

* The official validator reports only a missing `Contributors` field. Add the field after confirming the WordPress.org username, then run the validator again. A GitHub account name is not a substitute.
* The current `Tested up to: 7.1` value matches the 1.2.0 pilot run on WordPress 7.1.4 and PHP 8.5. The minimum supported versions were not validated in this run.
* Banner and icon files must use the documented names and dimensions. Screenshot files use numbered names, each matched to a readme caption. The directory gallery supports screenshots with different proportions.

See the official [plugin asset guidance](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) and [readme guidance](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/).
