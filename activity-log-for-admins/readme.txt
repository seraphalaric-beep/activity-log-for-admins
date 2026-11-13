=== Activity Log for Admins ===
Tags: activity log, audit log, history
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search your WordPress activity, inspect changes, and export matching events.

== Description ==

Activity Log for Admins records changes locally on single WordPress sites. Administrators can search and combine filters, inspect previous and new values, and download matching events as CSV. Recording begins when the plugin is activated.

== Supported events ==

* Pages and posts: creation, title and core field edits, content and excerpt changes, status changes, trash, and restoration. WordPress revision comparisons depend on available revisions.
* Accounts: creation, deletion, and role changes.
* WordPress sign ins: successful and failed authentication through standard WordPress hooks.
* Plugins: installation, activation, deactivation, update, and deletion through WordPress.
* Themes: installation, switch, update, and deletion through WordPress.
* Settings: site visibility, default role, permalink structure, site address, and WordPress address.

== Privacy and retention ==

Events can contain usernames, content titles, and selected setting values. Data is stored in the site's database and stays there unless an authorised admin exports it. The plugin uses no telemetry or external service. IP addresses, credentials, and raw request bodies are excluded. Retention defaults to 90 days. Deactivation preserves history. Settings offers an explicit option to erase all plugin data on deletion.

== Installation ==

On a staging or disposable single WordPress site, go to Plugins, then Add New Plugin, then Upload Plugin. Select the ZIP, install it, and activate. Open Activity Log in the WordPress admin menu.

== Frontend source ==

The source, package lock, and build configuration are included in the source directory. In that directory, run npm ci and npm run build. Third party license notices are included in the archive.

== Changelog ==

= 1.0.0 =
* Initial pilot release with search, filters, event details, revision links, CSV export, retention, and log health.
