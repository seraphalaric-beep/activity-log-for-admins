# Changelog

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
