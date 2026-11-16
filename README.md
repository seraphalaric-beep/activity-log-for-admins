# Activity Log for Admins

A free activity history plugin for WordPress site administrators. Search changes, identify who or what made them, compare captured values, and export filtered events with their recorded changes. On Multisite networks, each site keeps a separate log and retention setting. One combined network view is not included. It does not manage redirects.

The Activity Log menu sits near the top of the WordPress admin menu. The main Dashboard includes recent changes and activity counts.

The 1.1.0 release is intended for disposable and staging sites while the project gathers feedback. It is licensed under GPL 2.0 or later.

## What it records

The plugin records public content, taxonomy, comment, profile, role, sign in, menu, widget, plugin, theme, and selected site setting activity. WooCommerce order events and Redirection rule events are optional and off by default. Events stay in the local WordPress database. The plugin sends no telemetry and uses no external service. It does not store comment text, changed email addresses, menu destinations, redirect URLs, customer names or addresses, passwords, tokens, request bodies, or IP addresses.

Administrators choose a retention period from 1 to 3650 days. The default is 90 days. Keep it as short as the site's purpose allows. Optional warning email digests are off by default and include event summaries and times.

See [the plugin readme](activity-log-for-admins/readme.txt) for the complete event list and privacy details.

## Install the pilot

1. Use a disposable or staging WordPress site running WordPress 6.9 or later and PHP 8.3 or later.
2. In the WordPress admin, open **Plugins**, then **Add New Plugin**, then **Upload Plugin**.
3. Choose the [installable 1.1.0 ZIP](https://github.com/seraphalaric-beep/activity-log-for-admins/releases/download/v1.1.0/activity-log-for-admins.1.1.0.zip), install it, and activate it.
4. Find the activity summary on the main Dashboard, or open **Activity Log** near the top of the admin menu.

The plugin has not completed WordPress.org review. Do not use the pilot as the only record keeping system for a production site.

## Build the installable archive

Install Node.js and Python, then run:

```sh
npm ci
npm run package
```

The build updates the bundled admin files and creates `release/activity-log-for-admins.1.1.0.zip`. The archive includes the plugin, frontend source, package lock, and third party license notices.

## Run the local pilot

The browser and database pilot uses a disposable WordPress installation, MariaDB, PHP, and Chrome. Follow the setup and test instructions in [PILOT.md](PILOT.md). The test fixture is only for a local loopback site and is not included in the installable plugin archive.

## Project notes

* [Pilot installation and verification](PILOT.md)
* [Interface design](DESIGN.md)
* [Changelog](CHANGELOG.md)
* [Security reporting](SECURITY.md)
* [Contributing](CONTRIBUTING.md)
* [WordPress.org assets and listing copy](docs/wordpress-org-assets.md)
* [Verification results for 1.1.0](PILOT.md)
* [WordPress design standard](docs/WordPress%20Design%20Standard.md)

