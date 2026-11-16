=== Activity Log for Admins ===
Tags: activity log, audit log, history
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search WordPress activity, see who changed what, compare recorded values, and export filtered history as CSV.

== Description ==

Activity Log for Admins keeps a searchable history of supported changes on WordPress sites. On a Multisite network, each site has its own activity and retention settings.

Start with a quick summary on the Dashboard, or open Activity to search by page or post title, user, and change. Combine filters for date, user, action, affected item, and severity.

Open an event to see who acted, what changed, and when. Review previous and new values when they were captured. Follow a WordPress revision comparison when both revisions remain available.
Plugin and theme updates show the previous and new version when both are available.

Export every event matching the current filters to CSV, including records beyond the visible page.
The CSV includes previous and new values for recorded field changes, plus the structured event details.

Activity begins when the plugin is activated. Records stay in your WordPress database. The plugin sends no telemetry and uses no external service.

== Supported events ==

* Public content: creation, title and core field edits, scheduled publish date changes, template assignments and featured image changes, content and excerpt changes, status changes, trash, and restoration. Revision comparisons depend on the revisions retained by WordPress.
* Categories and tags: creation, edits, deletion, and assignment changes on public taxonomies.
* Comments: creation, edits, moderation, and deletion. Comment text is not stored.
* Accounts: creation, deletion, selected profile changes, role changes, password change markers, and sign outs. On Multisite, site removal is distinguished from network account deletion.
* Sign ins: successful and failed authentication through standard WordPress hooks.
* Appearance: navigation menu and widget changes. Redirect destinations are not stored.
* Plugins: installation, activation, deactivation, updates, and deletion.
* Themes: installation, switching, updates, and deletion.
* Site settings: site visibility, default role, permalink structure, site address, and WordPress address.
* Optional WooCommerce integration: order creation, status changes, and refunds. It is off by default and does not copy customer names, contact details, or addresses.
* Optional Redirection integration: redirect rule changes. It is off by default and stores rule identifiers without source or destination addresses.

== Privacy and retention ==

Events may contain usernames, changed display names, content titles, built in template assignment and featured image values, selected setting values, and identifiers needed to describe an event. Email address changes are recorded as a marker, not as an address. Comment text, menu destinations, redirect URLs, passwords, tokens, IP addresses, and full request bodies are not recorded.

On Multisite, removing a user from one site is recorded separately from deleting the account across the network.

Events stay in the WordPress database for each site. Retention defaults to 90 days and administrators can choose from 1 to 3650 days in Settings. Choose a period that fits the site's purpose and privacy obligations, and keep it as short as the purpose allows. The plugin does not determine which retention period is legally appropriate for a site. Cleanup uses the WordPress schedule, so it may run later on quiet sites.

Optional warning email digests are off by default. When enabled, they send event summaries and times to the WordPress admin email address. Deactivation and deletion preserve history by default. Erasing a site's activity on deletion is an explicit option and is off by default.

== Installation ==

1. Use WordPress 6.9 or later and PHP 8.3 or later. On a Multisite network, activate the plugin on a site or network activate it.
2. In the WordPress admin, open Plugins, then Add New Plugin, then Upload Plugin.
3. Select the plugin ZIP, install it, and activate it.
4. Open the Dashboard for the activity summary, or choose Activity Log from the admin menu.

== Frequently Asked Questions ==

= Does the plugin show changes from before activation? =

No. Recording starts when the plugin is activated. The plugin does not import history from other activity log tools.

= Which users can view and export activity? =

Users with the WordPress `manage_options` capability can view, export, and change plugin settings.

= How does it work on Multisite networks? =

Each site keeps a separate activity log and retention setting. Visit that site's WordPress admin to review its activity. This release does not provide one combined network view.

= Are WooCommerce and Redirection events included? =

Yes, both integrations are optional and off by default. Enable the integration you use in Activity Log Settings. WooCommerce events include order creation, status changes, and refunds. Redirection events include rule changes without storing the rule URLs.

= Can Activity Log for Admins replace a redirect plugin? =

No. The plugin records selected site setting changes, but does not create or apply redirects. Keep your redirect manager active, or transfer and test its rules before removing it.

= Why might an event not include a previous value or revision comparison? =

The plugin records a defined set of fields. A previous value appears when that field is available to the event capture hook. A revision comparison requires WordPress to retain both revisions.

= What happens when I deactivate or delete the plugin? =

Deactivation and deletion preserve history by default. To erase activity and settings when deleting the plugin, turn on the erase option in Settings and save it before deletion.

== Screenshots ==

1. Search and filter recent activity, then export matching results as CSV.
2. Open an event to review its user, timestamp, and captured previous and new values.
3. See activity counts and recent changes from the WordPress Dashboard.
4. Choose a retention period, review database space and cleanup status, and note the plugin version.

== Changelog ==

= 1.1.0 =
* Expanded capture to public content types, taxonomies, comments, profiles, sign outs, menus, and widgets.
* Added optional WooCommerce order and Redirection rule events, both off by default.
* Added per site logging on Multisite networks, event category controls, and optional warning email digests.
* Reduced stored personal data by recording profile email changes as markers and excluding comment text, menu destinations, redirect URLs, customer details, passwords, tokens, and IP addresses.
* Corrected taxonomy and menu deletion capture, scheduled cleanup per Multisite site, and distinguished site removal from network account deletion.
* Clarified retention choice and privacy guidance in Settings and the plugin listing.

= 1.0.5 =
* Expanded supported content, account, role, software update, and site setting activity.
* Added clearer attribution for WP CLI, background tasks, unauthenticated attempts, and unknown actors.
* Improved CSV details, captured values, schema checks, and recording and cleanup diagnostics.
* Added page template, featured image, scheduled date, parent page, and content author changes.
* Improved shared filters and account matching, including numeric usernames.

= 1.0.4 =
* Prevented shared caches from storing the private Activity Log REST responses.
* Added a unique request value to Activity Log API calls to bypass stale cached responses.
* Added regression checks that compare Dashboard activity with the full event list.

= 1.0.3 =
* Added Bryan Mc Namara as the plugin author in WordPress plugin metadata.

= 1.0.2 =
* Refined the Activity, Settings, event details, and Dashboard summary interfaces with a shared visual style.
* Added the bundled Manrope font, consistent spacing, clearer information hierarchy, and a severity legend with traffic light colors.
* Improved event row spacing and aligned the user disclosure control with its label.

= 1.0.1 =
* Added an activity summary to the WordPress Dashboard and placed Activity Log near the top of the admin menu.
* Improved spacing, button sizing, and narrow screen layouts.

== Upgrade Notice ==

= 1.0.5 =
Expands activity coverage and improves event attribution, CSV exports, and storage diagnostics.

= 1.0.4 =
Improves cache handling for private Activity Log requests.
