=== Activity Log for Admins ===
Tags: activity log, audit log, history
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search WordPress activity, see who changed what, compare recorded values, and export filtered history as CSV or HTML.

== Description ==

Activity Log for Admins keeps a searchable history of supported changes on WordPress sites. On a Multisite network, each site has its own activity and retention settings.

Start with a quick summary on the Dashboard, or open Activity to search by page or post title, user, and change. Combine filters for date, user, action, affected item, and severity.

Open an event to see who acted, what changed, and when. Review previous and new values when they were captured. Follow a WordPress revision comparison when both revisions remain available.
Plugin and theme updates show the previous and new version when both are available.

Export every event matching the current filters to CSV, including records beyond the visible page.
The CSV includes previous and new values for recorded field changes, plus the structured event details.
Authorized activity viewers can open the five newest changes from the WordPress admin toolbar. The feed updates every 30 seconds while a WordPress admin page is visible, and event links open the matching detail panel.
Download a formatted HTML report using the current filters. Choose the event fields and title, and optionally include the site name. The report is streamed to the authorized viewer, is not stored by the plugin, and escapes event values before displaying them.
Review activity statistics by category, event type, severity, actor role, request source, or date. Date grouping supports daily, weekly, and monthly intervals. Statistics cannot include dates outside the site's configured retention period.
Save up to 20 report templates with filters, grouping, selected event fields, branding choices, and up to five tags. Templates use a moving date window, stay on the site, and do not copy event records. Avoid personal information in tags. Settings downloads do not include templates. Templates are erased when the administrator selects the option to erase plugin data on uninstall.

Managers can schedule saved reports daily, weekly, monthly, or quarterly as CSV or formatted HTML attachments. Reports are prepared in background batches and sent to addresses entered by the manager. Failed email delivery is retried after about five minutes and fifteen minutes. Up to ten private report copies can be downloaded from report history. Each attachment is limited to 10 MB, and copies are removed according to the site's retention period. Privacy erasure removes active and stored report copies because they can contain activity details. Email addresses stay on the site and are not included in settings downloads. Delivery history stores format, status, event count, time, and report name. WordPress scheduled tasks depend on site traffic, so delivery can be delayed on quiet sites.

Managers can review and end WordPress sessions, and can optionally limit concurrent sessions or end inactive sessions. Administrators and activity managers are protected from automatic session policies. Existing sessions receive a grace period when idle expiry is first enabled. Last activity, browser and device labels, and IP display each have explicit privacy controls. On Multisite, only Super Admins can manage sessions because account sessions apply across the network.

Create immediate alert rules from recommended presets or match every condition or any condition across event type, severity, actor, role, source, item, and summary. Send matching events by email, Slack incoming webhook, or SMS through Twilio. Pause or delete rules at any time. Delivery retries twice when a service does not accept a message. A channel that already accepted a message is skipped during a retry to avoid duplicate notifications.

Slack and Twilio are optional external destinations. Their credentials and SMS numbers are encrypted using the site's WordPress keys, are excluded from settings downloads, and stay saved until removed in Settings. If the WordPress keys change, enter the credentials again. SMS numbers are not linked to WordPress account email addresses, so remove them manually when no longer needed. Selected event summaries, actor names, roles, item names, and severity are sent to the services chosen for each rule. External services apply their own message retention, and Twilio or carrier charges may apply to SMS.

Activity begins when the plugin is activated. Records stay in your WordPress database. The plugin sends no telemetry. Optional email features use the WordPress mail service and recipients selected by an administrator. Slack and Twilio messages are sent only after an administrator saves credentials, selects a channel for a rule, and the rule matches an event. MainWP event transfer sends opted in child activity to its Dashboard.

== Supported events ==

Plugin authors can register custom event types and record events through the versioned developer API. See the repository documentation for the event schema and privacy limits.

* Public content: creation, title and core field edits, scheduled publish date changes, template assignments and featured image changes, content and excerpt changes, status changes, trash, and restoration. Revision comparisons depend on the revisions retained by WordPress.
* Categories and tags: creation, edits, deletion, and assignment changes on public taxonomies.
* Comments: creation, edits, moderation, and deletion. Comment text is not stored.
* Accounts: creation, deletion, selected profile changes, role changes, password change markers, and sign outs. On Multisite, site removal is distinguished from network account deletion.
* Sign ins: successful and failed authentication through standard WordPress hooks.
* Appearance: navigation menu and widget changes. Redirect destinations are not stored.
* Plugins: installation, activation, deactivation, updates, and deletion.
* Themes: installation, switching, updates, and deletion.
* Site settings: site visibility, default role, permalink structure, site address, and WordPress address.
* Optional WooCommerce integration: products, variations, coupons, selected store settings, and order creation, status, refund, trash, restoration, and deletion events. It is off by default. Coupon codes, email restrictions, payment details, customer contact data, product descriptions, and order contents are not recorded.
* Optional Advanced Custom Fields integration: changed field labels on supported content and products. It is off by default. Field values are never recorded, and labels suggesting personal or secret data are shown as Sensitive custom field.
* Optional bbPress integration: forum, topic, and reply changes, including moderation and sticky state. It is off by default. Forum and reply text, guest contact details, and IP addresses are not recorded.
* Optional WPForms integration: form lifecycle changes and successful stored submissions by form. It is off by default. Form definitions, submitted answers, and individual submission identifiers are not recorded. Submission events require WPForms entry storage.
* Optional Yoast SEO integration: selected content metadata and approved title, metadata, and taxonomy settings changes. It is off by default. SEO text, keyphrases, canonical addresses, image addresses, and setting values are not recorded.
* Optional Rank Math integration: selected content metadata and approved general, title, metadata, and sitemap settings changes. It is off by default. SEO text, keyphrases, canonical addresses, image addresses, and setting values are not recorded.
* Optional Paid Memberships Pro integration: account membership access changes and membership level creation, edits, and deletion. It is off by default. Account and level identifiers are recorded without level names, contact details, subscription details, or payment data. Membership access can reveal personal information, so enable this only when needed and choose a suitable retention period.
* Optional Termly integration: consent banner, automatic blocker, scan schedule, scanner allow list, and connection state changes. It is off by default. API keys, account details, cookie maps, scan results, and site addresses are not recorded.
* Optional MainWP Dashboard integration: child site and client changes, status, removal, and synchronization outcomes. It is off by default. Both the Dashboard and child sites must opt in before child events transfer. Each sync request transfers at most 50 child events and retries do not duplicate accepted events. Site addresses, client names, contact details, connection credentials, and synchronization payloads are not recorded.
* Optional InfiniteWP integration: identify changes from authenticated requests and record completed backups when the InfiniteWP Client activity log is enabled. Backup scope only is recorded. Backup names, paths, storage details, and file contents are not recorded.
* Optional Redirection integration: redirect rule changes. It is off by default and stores rule identifiers without source or destination addresses.

== Privacy and retention ==

Events may contain usernames, changed display names, content titles, built in template assignment and featured image values, selected setting values, and identifiers needed to describe an event. Advanced Custom Fields values are never stored. Sensitive field labels are replaced with a generic label. WPForms form definitions, submitted answers, and individual submission identifiers are not stored. Yoast SEO and Rank Math text, keyphrases, canonical addresses, image addresses, and setting values are not recorded. Paid Memberships Pro events store account and level identifiers, not level names, contact details, subscription details, or payment data. Membership access can reveal personal information. Termly API keys, account details, cookie maps, scan results, and site addresses are not recorded. MainWP site addresses, client names, contact details, connection credentials, and synchronization payloads are not recorded. InfiniteWP backup names, paths, storage details, and file contents are not recorded. Email address changes are recorded as a marker, not as an address. Comment text, bbPress forum and reply text, guest contact details, menu destinations, redirect URLs, passwords, tokens, IP addresses, and full request bodies are not recorded.

On Multisite, removing a user from one site is recorded separately from deleting the account across the network.

Events stay in the WordPress database for each site. Retention defaults to 90 days and administrators can choose from 1 to 3650 days in Settings. Choose a period that fits the site's purpose and privacy obligations, and keep it as short as the purpose allows. The plugin does not determine which retention period is legally appropriate for a site. Cleanup uses the WordPress schedule, so it may run later on quiet sites.

The plugin adds an exporter and eraser to the WordPress privacy tools. Exports include activity, notes, saved reports, access settings, and configured email recipients linked to the requested address or account. Erasure removes matching notes, email recipients, access references, report filters, and personal change details. Activity rows remain for site audit context with the account identity removed. Review the result and decide whether this retention is appropriate for your site.

Optional warning email digests are off by default. Administrators can choose recipients, event categories, whether summaries are included, and a maximum of 20 summaries per digest. The empty recipient setting uses the WordPress admin email address. Recipient addresses stay on the site and are not included in settings downloads. The digest sends no more often than once every 15 minutes and includes summaries only, never event details. Deactivation and deletion preserve history by default. Erasing a site's activity on deletion is an explicit option and is off by default.

Scheduled report recipients are entered by a manager and stay on the site. They are not included in settings downloads. Scheduled reports contain only fields selected in the saved report and are attached as CSV files. Temporary attachment files are stored outside the public WordPress directory and removed after delivery. Delivery history contains report name, time, status, and event count, and follows the selected retention period.

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

Each site keeps a separate activity log and retention setting. Super Admins can also use the Network Activity Log screen to search events across sites in the current network, inspect site scoped details and notes, export matching events, and review storage health. Network Settings lists sites and lets Super Admins change retention and event categories for an individual site. Site activity and settings remain separate across networks. Session management is available to Super Admins only because WordPress sessions apply to the account across the network.

= Are WooCommerce, Advanced Custom Fields, Redirection, bbPress, WPForms, Yoast SEO, Rank Math, Paid Memberships Pro, Termly, MainWP, and InfiniteWP events included? =

Yes, these integrations are optional and off by default. Enable the integration you use in Activity Log Settings. WooCommerce records product, coupon, selected store setting, and order lifecycle events while excluding customer details and order contents. Advanced Custom Fields records changed field labels only, never field values. Redirection records rule changes without storing the rule URLs. bbPress records forum, topic, and reply changes without storing forum or reply text, guest contact details, or IP addresses. WPForms records form changes and successful stored submissions by form, never form definitions, submitted answers, or individual submission identifiers. Yoast SEO and Rank Math record selected content field and settings changes without storing SEO values or addresses. Paid Memberships Pro records account access and membership level lifecycle events with identifiers only, not level names, contact details, subscription details, or payment data. Termly records consent banner, automatic blocker, scan schedule, scanner allow list, and connection state changes without storing API keys, account details, cookie maps, scan results, or site addresses. MainWP records child site and client changes, status, removal, and synchronization outcomes without storing site addresses, client names, contact details, connection credentials, or synchronization payloads. Child events transfer to the MainWP Dashboard only when both the Dashboard and child site opt in. Transfers use batches of at most 50 and repeated sync does not duplicate accepted events. InfiniteWP labels authenticated management requests and records completed backup events when the InfiniteWP Client activity log is enabled. It stores backup scope only, not names, paths, storage details, or file contents. Membership access can reveal personal information, so enable it only when needed and set a suitable retention period.

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
* Added optional bbPress forum, topic, and reply events with privacy focused content handling.
* Added optional WPForms form and successful stored submission events without collecting form definitions, submitted answers, or submission identifiers.
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
