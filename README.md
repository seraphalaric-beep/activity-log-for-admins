# Activity Log for Admins

A free activity history plugin for administrators of single WordPress sites. Search changes, identify the actor, compare captured values, and export the results.

The Activity Log menu sits near the top of the WordPress admin menu. The main Dashboard includes recent changes and activity counts.

The 1.0.4 release is intended for disposable and staging sites while the project gathers feedback. It is licensed under GPL 2.0 or later.

## What it records

The plugin records supported page and post changes, account and role changes, sign ins, plugin and theme lifecycle changes, and selected site settings. It stores records in the site's WordPress database. It does not send telemetry or use an external service. Passwords, tokens, request bodies, and IP addresses are excluded.

See [the plugin readme](activity-log-for-admins/readme.txt) for the complete event list and privacy details.

## Install the pilot

1. Use a disposable or staging WordPress site running WordPress 6.9 or later and PHP 8.3 or later.
2. In the WordPress admin, open **Plugins**, then **Add New Plugin**, then **Upload Plugin**.
3. Choose the [installable 1.0.4 ZIP](release/activity-log-for-admins.1.0.4.zip), install it, and activate it.
4. Find the activity summary on the main Dashboard, or open **Activity Log** near the top of the admin menu.

The plugin has not completed WordPress.org review. Do not use the pilot as the only record keeping system for a production site.

## Build the installable archive

Install Node.js and Python, then run:

```sh
npm ci
npm run package
```

The build updates the bundled admin files and creates `release/activity-log-for-admins.1.0.4.zip`. The archive includes the plugin, frontend source, package lock, and third party license notices.

## Run the local pilot

The browser and database pilot uses a disposable WordPress installation, MariaDB, PHP, and Chrome. Follow the setup and test instructions in [PILOT.md](PILOT.md). The test fixture is only for a local loopback site and is not included in the installable plugin archive.

## Project notes

* [Pilot installation and verification](PILOT.md)
* [Interface design](DESIGN.md)
* [Changelog](CHANGELOG.md)
* [Security reporting](SECURITY.md)
* [Contributing](CONTRIBUTING.md)
* [WordPress.org assets and listing copy](docs/wordpress-org-assets.md)
* [Security and interface check report for 1.0.4](docs/verification/security-ui-report.json)
* [WordPress design standard](docs/WordPress%20Design%20Standard.md)

