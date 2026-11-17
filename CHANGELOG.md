# Changelog

## Unreleased

* Save generated statistics results with their report name or tag, exact date range, grouping, interval, total, and bounded result rows. Add permission checked viewing and private CSV and HTML downloads, with formula protection, escaped output, site retention cleanup, and privacy export and erasure.
* Add exact event ID, affected item type, and exact item ID filters to Activity queries and shared links. Keep registered event type validation and reuse the filters for exports and saved report templates. Do not add session ID collection to routine event records.
* Add bulk selection actions to exclude accounts and exact items, or stop selected event types, with confirmation, preserved history, and audit records containing count changes only. Keep exact item exclusions local to the site and available for removal in Settings.
* Add a permission checked Activity entry to the WordPress admin toolbar, with a five event live feed, an event detail deep link, and polling limited to visible admin pages.
* Add concurrent session limits and idle expiry with administrator safety exclusions, a grace period for existing sessions, account role exclusions, and warning events.
* Add a recommended alert rule for automatic session policy actions.
* Add a manager only session screen to review accounts, login times, expiry, and active WordPress sessions, with confirmed single session or account wide revocation and audit events.
* Keep session activity off by default, store one way session references and timestamps in short lived local transients, and offer separate opt ins for coarse browser details and IP display.
* Restrict Multisite session actions to Super Admins because WordPress sessions apply across the network account, and add privacy export, erasure, and bounded cleanup for tracked activity timestamps.
* Add a Super Admin network activity view with site scoped filters, composite event pagination, event details and notes, and a streamed CSV export across the current network.
* Add network site review for storage health, per site retention, and event category controls, while keeping settings and activity records in each site's local tables.
* Initialize activity storage on every existing site during network activation and keep new site setup on the existing site creation hook.
* Publish a versioned developer API for registered extension event types, safe details, WordPress actor attribution, privacy redaction, category controls, and per event controls.
* Invalidate delayed alert retries during privacy erasure, pause alert delivery across all erasure batches, and resume only after cleanup completes.
* Add WordPress privacy data export and erasure for activity, administrator notes, access settings, saved reports, warning digest recipients, and scheduled reports. Erasure removes personal details from retained audit rows in bounded batches and cancels active report jobs that may contain pre erasure data.
* Make warning email digests configurable by recipient, event category, summary inclusion, and a bounded event count. Keep recipient addresses local and out of settings downloads.
* Add preconfigured immediate email alerts and a custom rule builder with all or any condition matching, enable and pause controls, manager only access, and bounded delivery retries. Keep recipient addresses local, include them in privacy export, remove them during privacy erasure, and exclude them from settings downloads.
* Add optional Slack incoming webhooks and Twilio SMS delivery for immediate alert rules. Restrict Slack destinations to official HTTPS webhook hosts, encrypt credentials and SMS numbers with the site's WordPress keys, and avoid repeating channels that already accepted a message when another channel needs a retry.
* Add date based activity statistics grouped by category, event type, severity, actor role, request source, or day, with daily, weekly, and monthly date buckets limited by site retention.
* Add a private HTML report download that streams all matching events in batches, uses the Activity filters, and escapes recorded values before displaying them.
* Let administrators choose HTML report fields, set a report title, and optionally include the site's name. Report templates preserve field selection and branding choices.
* Add up to 20 saved report templates containing filters, grouping, selected fields, branding, and up to five local tags. Use a moving date window, exclude templates from settings downloads, and erase them when the administrator opts to erase plugin data.
* Add daily, weekly, monthly, and quarterly email schedules for saved reports. Build CSV or HTML attachments in bounded background batches, retry failed delivery after five and fifteen minutes, protect spreadsheet cells, escape HTML values, keep up to ten private report copies for download, and remove copies according to site retention or privacy erasure.
* Add optional Termly activity tracking for consent banner, automatic blocker, scan schedule, scanner allow list, and connection state changes. API keys and account data are not recorded.
* Add optional MainWP Dashboard activity tracking for child sites, clients, status changes, and synchronization outcomes. Both the Dashboard and child sites must opt in before events transfer. Sync transfers at most 50 child events per request and deduplicates retries. Site addresses, contact details, credentials, and sync payloads are not recorded.
* Add optional InfiniteWP activity tracking for authenticated management requests and completed backup records. Store the backup scope only, never backup names, paths, storage details, or file contents.
* Expand optional WooCommerce recording to products, variations, coupons, selected store settings, and order lifecycle changes.
* Capture product and coupon fields through explicit allowlists, with coupon codes, email restrictions, customer contact data, payment settings, product descriptions, and order contents excluded.
* Add event controls, filters, export labels, item labels, and Settings privacy text for the expanded WooCommerce event set.
* Add optional Advanced Custom Fields recording for supported posts, products, and custom content. Group field labels changed on the same item into one event, ignore unchanged values, and never store field values.
* Replace field labels suggesting personal or secret data with a generic sensitive field label, and include the category in filters, details, and CSV export.
* Add opt in bbPress recording for forums, topics, and replies, including status, moderation, sticky state, trash, restoration, and deletion. Forum and reply text, guest contact details, and IP addresses are not stored.
* Add opt in WPForms recording for form lifecycle changes and successful stored submissions by form. Form definitions, submitted answers, and individual submission identifiers are not stored.
* Add optional Yoast SEO and Rank Math recording for selected content metadata and approved settings groups. SEO text, keyphrases, canonical addresses, image addresses, and setting values are never stored.
* Group SEO metadata changes by content item, record field labels with change markers, ignore unchanged saves, and add separate category filters, exports, and privacy controls for each integration.
* Add optional Paid Memberships Pro recording for account access changes and membership level creation, edits, and deletion. Account and level identifiers are stored, not level names, contact details, subscription details, or payment data.
* Compare membership level edits using hashes of approved database fields held only in request memory, ignore unchanged saves, and explain that membership status may reveal personal information.

## 1.1.0

* Expanded capture to public content types, public taxonomies, comments, profiles, sign outs, navigation menus, and widgets.
* Added opt in WooCommerce order events and Redirection rule events, both disabled until an administrator enables them.
* Added per site logging for Multisite, event category controls, and optional warning email digests.
* Ensure cleanup is scheduled as each Multisite site is checked, and capture taxonomy and menu deletions through the WordPress deletion hook.
* Distinguish removing a user from one Multisite site from deleting the network account.
* Reduced stored personal data by recording profile email changes as markers and excluding comment text, menu destinations, redirect URLs, customer details, passwords, tokens, and IP addresses.
* Added immediate bounded cleanup when an administrator shortens the retention period.
* Clarified privacy and retention controls in Settings and the plugin listing.

## 1.0.5

* Resolve WordPress Plugin Check findings for translator context, prepared queries, and uninstall naming.
* Use a concise Unknown label when event attribution is unavailable in Activity and the Dashboard.
* Give WP CLI activity its own actor category, separate from WordPress background tasks.
* Report cleanup scheduling failures separately from cleanup run failures.
* Verify required event table columns and indexes before marking the schema current.
* Limit the item index prefix so it fits older InnoDB row formats.
* Report schema upgrade failures separately from event recording failures.
* Align the WordPress listing copy, pilot guide, and Settings screenshot with version 1.0.5.
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
