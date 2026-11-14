=== Activity Log for Admins ===
Tags: activity log, audit log, history
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search WordPress activity, see who changed what, compare recorded values, and export filtered history as CSV.

== Description ==

Activity Log for Admins keeps a searchable history of supported changes on a single WordPress site.

Start with a quick summary on the Dashboard, or open Activity to search by page or post, actor, and change. Combine filters for date, actor, action, affected item, and severity.

Open an event to see who acted, what changed, and when. Review previous and new values when they were captured. Follow a WordPress revision comparison when both revisions remain available.

Export every event matching the current filters to CSV, including records beyond the visible page.

Activity begins when the plugin is activated. Records stay in your WordPress database. The plugin sends no telemetry and uses no external service.

== Supported events ==

* Pages and posts: creation, title and core field edits, content and excerpt changes, status changes, trash, and restoration. Revision comparisons depend on the revisions retained by WordPress.
* Accounts: creation, deletion, and role changes.
* Sign ins: successful and failed authentication through standard WordPress hooks.
* Plugins: installation, activation, deactivation, updates, and deletion.
* Themes: installation, switching, updates, and deletion.
* Site settings: site visibility, default role, permalink structure, site address, and WordPress address.

== Privacy and retention ==

Events may contain usernames, content titles, and selected setting values. The plugin stores records in the site's database. It does not record passwords, tokens, IP addresses, or full request bodies. Retention defaults to 90 days and can be changed in Settings. Cleanup uses the WordPress schedule, so it may run later on quiet sites. Deactivation preserves history. Erasing all plugin data on deletion is an explicit option and is off by default.

== Installation ==

1. Use a single WordPress site running WordPress 6.9 or later and PHP 8.3 or later.
2. In the WordPress admin, open Plugins, then Add New Plugin, then Upload Plugin.
3. Select the plugin ZIP, install it, and activate it.
4. Open the Dashboard for the activity summary, or choose Activity Log from the admin menu.

== Frequently Asked Questions ==

= Does the plugin show changes from before activation? =

No. Recording starts when the plugin is activated. The plugin does not import history from other activity log tools.

= Which users can view and export activity? =

Users with the WordPress `manage_options` capability can view, export, and change plugin settings.

= Does it support multisite or WooCommerce? =

Not in version 1.0.2. This release is for a single WordPress site and does not include WooCommerce events.

= Why might an event not include a previous value or revision comparison? =

The plugin records a defined set of fields. A previous value appears when that field is available to the event capture hook. A revision comparison requires WordPress to retain both revisions.

= What happens when I deactivate or delete the plugin? =

Deactivation preserves history. To erase activity and settings when deleting the plugin, turn on the erase option in Settings and save it before deletion.

== Screenshots ==

1. Search and filter recent activity, then export matching results as CSV.
2. Open an event to review its actor, timestamp, and captured previous and new values.
3. See activity counts and recent changes from the WordPress Dashboard.
4. Set retention and review database space, cleanup status, and the plugin version.

== Changelog ==

= 1.0.2 =
* Refined the Activity, Settings, event details, and Dashboard summary interfaces with a shared visual style.
* Added the bundled Manrope font, consistent spacing, clearer information hierarchy, and a severity legend with traffic light colors.
* Improved event row spacing and aligned the user disclosure control with its label.

= 1.0.1 =
* Added an activity summary to the WordPress Dashboard and placed Activity Log near the top of the admin menu.
* Improved spacing, button sizing, and narrow screen layouts.
