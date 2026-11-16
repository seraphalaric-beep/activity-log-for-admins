# Changelog

## Unreleased

* Resolve WordPress Plugin Check findings for translator context, prepared queries, and uninstall naming.
* Use a concise Unknown label when event attribution is unavailable in Activity and the Dashboard.
* Give WP CLI activity its own actor category, separate from WordPress background tasks.
* Report cleanup scheduling failures separately from cleanup run failures.
* Verify required event table columns and indexes before marking the schema current.
* Limit the item index prefix so it fits older InnoDB row formats.
* Report schema upgrade failures separately from event recording failures.
* Align the WordPress listing copy, pilot guide, and Settings screenshot with version 1.0.4.
* Clarify that redirect management remains outside the plugin scope.
* Include captured previous and new values plus structured details in CSV exports.
* Keep UTF 8 text handling available when the PHP mbstring extension is not installed.
* Require an explicitly enabled setting before erasing stored history on uninstall.
* Record previous and new versions for successful plugin and theme updates, including bulk updates, when both are available.
* Record page template and featured image assignments, changes, and removals on posts and pages.
* Record scheduled publish date changes with the previous and new values in the site timezone.
* Match numeric usernames as well as user IDs in the Actor filter.
* Label event sources as actors and add exact filters for user, unauthenticated, background, and unknown actors.
* Keep filter inputs in sync with shared views and browser history.
* Clear incompatible user filters from shared links and reject conflicting actor queries.
* Use exact account IDs for actor links, even when a username contains only digits.
* Honor the selected User account type for names that match actor categories.
* Show account and parent names when page or post authorship and hierarchy change.
* Record role changes made by deleting a user's role metadata.
* Include the title in new page and post event details and CSV exports.
* Keep a previous page or post title searchable when the same save changes other fields.

## 1.0.4

* Prevented shared caches from storing private Activity Log REST responses.
* Added a unique request value to Activity Log API calls to bypass stale cached responses.
* Added regression coverage for Dashboard and full list parity, and REST cache headers.

## 1.0.3

* Added Bryan Mc Namara as the plugin author in WordPress plugin metadata.

## 1.0.2

* Refined Activity, Settings, event details, and the Dashboard summary with a shared visual style.
* Added the bundled Manrope font, consistent spacing, clearer information hierarchy, and a severity legend with traffic light colors.
* Improved event row spacing and aligned the user disclosure control with its label.

## 1.0.1

* Added an activity summary widget near the top of the WordPress Dashboard, with recent changes and event counts.
* Placed Activity Log near the top of the admin menu.
* Improved spacing and button sizing across the plugin interface.

## 1.0.0

* Added Activity and Settings to the WordPress admin for single sites.
* Added searchable event history, combined filters, shareable search links, an event details panel, and CSV export.
* Added recording for core content, user accounts, roles, sign ins, plugins, themes, and selected site settings.
* Added configurable retention, cleanup and recording diagnostics, and an optional data erase on uninstall. Settings displays the installed version.
* Uses WordPress shared React scripts and improves accessible page landmarks.
