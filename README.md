# Activity Log for Admins

A free activity history plugin for WordPress site administrators. Search changes, identify who or what made them, compare captured values, and download matching events as CSV or a formatted HTML report. On Multisite networks, each site keeps a separate log and retention setting, while Super Admins can review activity and manage settings across sites in the current network. It does not manage redirects.

The Activity Log menu sits near the top of the WordPress admin menu. The main Dashboard includes recent changes and activity counts. Authorized viewers can also open the five newest changes from the WordPress admin toolbar, with polling only on visible admin pages.

The 1.2.1 release is an expanded pilot for disposable and staging sites while the project gathers feedback. It is licensed under GPL 2.0 or later.

Version 1.2.1 includes the expanded pilot features from 1.2.0 and fixes findings from the WordPress automated review. These include a Super Admin network activity center, session inventory and revocation, optional concurrent session limits and idle expiry, the live admin toolbar feed, expanded WooCommerce capture, optional Advanced Custom Fields field label events, optional bbPress activity, optional WPForms form and submission events, optional Yoast SEO and Rank Math change records, optional Paid Memberships Pro membership events, optional Termly setting events, optional MainWP Dashboard tracking with child event transfer, optional InfiniteWP source and backup tracking, configurable warning email digests, immediate email, Slack, and Twilio SMS alert rules with all or any condition matching, private HTML reports, activity statistics, saved report templates, and scheduled report delivery. External storage and archives, WordPress core checksum comparison, and several real connector checks remain on the roadmap.

Extension developers can register custom event types and record sanitized events through the versioned [developer API](docs/DEVELOPER-API.md), without writing to the plugin's tables.

## What it records

The working branch records public content, taxonomy, comment, profile, role, sign in, menu, widget, plugin, theme, and selected site setting activity. WooCommerce, Advanced Custom Fields, bbPress, WPForms, Yoast SEO, Rank Math, Paid Memberships Pro, Termly, MainWP Dashboard, and InfiniteWP events are optional and off by default. ACF values are never stored, and sensitive field labels are shown generically. bbPress forum and reply text, guest contact details, and IP addresses are not stored. WPForms form definitions, submitted answers, and individual submission identifiers are not stored. SEO text, keyphrases, canonical addresses, image addresses, and setting values are not stored. Paid Memberships Pro stores account and level identifiers, not level names, contact details, subscription details, or payment data. Termly changes are recorded as status markers, and API keys, account details, cookie maps, scan results, and site addresses are not stored. MainWP child events transfer to its Dashboard only after both sides opt in, in batches of at most 50, with duplicate protection. MainWP site addresses, client names, contact details, connection credentials, and synchronization payloads are not stored. InfiniteWP labels matched management requests and can record completed backups when the client activity log is enabled. Backup scope is recorded, but names, paths, storage details, and file contents are not. Membership access can reveal personal information, so enable it only when needed and choose a retention period that fits its purpose. Redirection rule events are optional. Events stay in the local WordPress database. The plugin sends no telemetry. Optional email features use the WordPress mail service and the recipients selected by the administrator. MainWP event transfer sends opted in child activity to its Dashboard. The plugin does not store comment text, changed email addresses, menu destinations, redirect URLs, customer names or addresses, passwords, tokens, request bodies, or IP addresses.

Administrators choose a retention period from 1 to 3650 days. The default is 90 days. Keep it as short as the site's purpose allows. Optional warning email digests are off by default. Administrators can choose recipients, event categories, whether summaries are included, and the maximum number of summaries. Recipient addresses remain on the site and are not included in settings downloads.

Activity Log adds an exporter and eraser to the WordPress privacy tools. Exports include matching events, notes, saved reports, access settings, and configured email recipients. Erasure removes matching notes, recipient addresses, account references, and saved filters, then removes personal details from related events while keeping anonymized audit context. The site administrator decides whether that retained context remains appropriate for the site's purpose.

The HTML report uses the current filters, lets you choose which event fields appear, set a title, and optionally include the site name. It streams to the authorized viewer, is not stored by the plugin, and escapes event values before displaying them.

Activity statistics group matching events by category, event type, severity, actor role, request source, or date. Date groups can use daily, weekly, or monthly intervals, and the selected range cannot exceed the site's retention period.

Save up to 20 report templates with filter criteria, grouping, selected event fields, branding choices, and up to five tags. Templates use a moving date window and do not copy event records. Keep tags free of personal information. Settings downloads exclude templates. If the administrator chooses to erase plugin data on uninstall, saved templates are erased with it.

Managers can schedule saved reports daily, weekly, monthly, or quarterly as CSV or formatted HTML attachments. The plugin prepares reports in background batches and sends them to addresses entered by the manager. Failed email delivery is retried after about five minutes and fifteen minutes. Up to ten private report copies can be downloaded from report history, each attachment is limited to 10 MB, and copies follow the site's retention setting. Privacy erasure removes active and stored report copies because they can contain activity details. Email addresses stay on the site and are excluded from settings downloads. Delivery history stores format, status, event count, time, and report name. WordPress scheduled tasks depend on site traffic, so delivery can be delayed on quiet sites.

See [the plugin readme](activity-log-for-admins/readme.txt) for the complete event list and privacy details.

## Install the pilot

1. Use a disposable or staging WordPress site running WordPress 6.9 or later and PHP 8.3 or later.
2. In the WordPress admin, open **Plugins**, then **Add New Plugin**, then **Upload Plugin**.
3. Choose the [installable 1.2.1 ZIP](https://github.com/seraphalaric-beep/activity-log-for-admins/releases/download/v1.2.1/activity-log-for-admins.1.2.1.zip), install it, and activate it.
4. Find the activity summary on the main Dashboard, or open **Activity Log** near the top of the admin menu.

The plugin has not completed WordPress.org review. Do not use the pilot as the only record keeping system for a production site.

## Build the installable archive

Install Node.js and Python, then run:

```sh
npm ci
npm run package
```

The build updates the bundled admin files and creates `release/activity-log-for-admins.1.2.1.zip`. The archive includes the plugin, frontend source, package lock, and third party license notices.

## Run the local pilot

The browser and database pilot uses a disposable WordPress installation, MariaDB, PHP, and Chrome. Follow the setup and test instructions in [PILOT.md](PILOT.md). The test fixture is only for a local loopback site and is not included in the installable plugin archive.

## Project notes

* [Pilot installation and verification](PILOT.md)
* [Interface design](DESIGN.md)
* [Changelog](CHANGELOG.md)
* [Security reporting](SECURITY.md)
* [Contributing](CONTRIBUTING.md)
* [WordPress.org assets and listing copy](docs/wordpress-org-assets.md)
* [Verification results for 1.2.1](PILOT.md)
* [WordPress design standard](docs/WordPress%20Design%20Standard.md)

