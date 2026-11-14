# WordPress.org listing assets

This folder records the listing copy and graphics prepared for Activity Log for Admins 1.0.1.

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

The activity and event detail images use the current 1.0.1 React bundle and styles, with illustrative event records. The Dashboard image uses the current widget styles and matching widget markup, with illustrative counts and events inside a simple dashboard card. These are interface previews, not captures from a live or staging WordPress site. No site data is included.

## Confirm before a directory release

* The project does not yet specify the WordPress.org contributor username. Add a `Contributors` field to the plugin readme only after confirming that username. A GitHub account name is not a substitute.
* The current `Tested up to: 7.1` value was retained from the existing readme. The detailed full suite documented in the project notes covers version 1.0.0, while the Dashboard addition in 1.0.1 has not been retested. Confirm this field before uploading the current version.
* Run the [official readme validator](https://wordpress.org/plugins/developers/readme-validator/) on the final readme.
* WordPress.org image files have strict names and dimensions. The banner and icon names in this folder use the documented sizes. Screenshots need one numbered readme caption for each image.

See the official [plugin asset guidance](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) and [readme guidance](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/).
