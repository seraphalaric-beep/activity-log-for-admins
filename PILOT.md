# Activity Log for Admins 1.0.2 pilot

## Install and investigate

1. On a disposable or staging site running WordPress 6.9 or later and PHP 8.3 or later, open **Plugins**, then **Add New Plugin**, then **Upload Plugin**.
2. Upload the 1.0.2 ZIP, install it, and activate it. The main Dashboard shows activity counts and recent changes. Open **Activity Log** near the top of the admin menu to investigate.
3. Create a test page named **Housing Guide**, publish it, and move it back to Draft. Add a few unrelated edits to make the list realistic.
4. Ask another administrator to start on the Activity screen with only the page name. Time how long it takes to identify the actor and verify the previous status. The pilot goal is under 30 seconds.
5. Open an event, check the previous and new values, and try the actor and affected item links. Combine search with the Action and Severity filters, reload the URL, and verify that CSV export contains the filtered results.
6. Open **Settings** to check the retention window, database estimate, cleanup status, and version. When reporting an issue, include the plugin version, WordPress and PHP versions, and what you expected to see.

The plugin records activity from activation onward. The full event list is in the plugin readme. Revision comparison depends on WordPress retaining both revisions. Cleanup runs through the WordPress scheduler, so quiet sites can run it later than the displayed schedule. Database allocation may remain the same after expired events are deleted, since the database can reuse the freed space.

Deactivation preserves history. To remove the stored history and settings when deleting the plugin, select **Erase all activity when deleting the plugin** in Settings and save it first.

## Build and repeat verification

From the workspace, run `npm ci` and `npm run package`. This builds the admin interface and writes the installable ZIP under `release`. The archive contains the frontend source, dependency lock, and third party license notices.

The automated pilot uses a disposable WordPress installation, local MariaDB, and Chrome. The setup script needs an extracted copy of WordPress, a native PHP executable, and an empty database whose name starts with `alfa_`. Start the disposable site with `tools/start-pilot.ps1`, then run `npm run test:pilot -- <loopback URL> <test password> full`. The fixture is used only in the disposable test environment. Screenshots are written to `test-results`.

The timed browser run checks the workflow with scripted input. Use step 4 to verify the time with a person during the staging pilot.

## Previous release checks

The 1.0.0 archive passed WordPress Plugin Check 2.2.0 on WordPress 7.1.4 and PHP 8.5.12 with no errors. The complete npm dependency audit reported zero vulnerabilities.

The 1.0.0 archive also passed the browser security and interface checks on that environment. Anonymous reads and exports returned 401, editor reads, exports, and settings changes returned 403, missing nonces were rejected, formula cells were neutralized, and injected markup appeared as text. Twelve axe core scans reported no violations at 320, 390, 768, 1024, and 1440 pixels. The browser reported no JavaScript errors. The report from that release is in `docs/verification/security-ui-report-1.0.0.json`.

Version 1.0.2 applies the approved visual style across Activity, Settings, event details, and the Dashboard summary. The current release verification results are recorded below. The earlier 1.0.0 report and measurements describe that release only.

The earlier full WordPress and PHP version matrix was run on the prior pilot build. The 1.0.2 release has been rerun on WordPress 7.1.4 and PHP 8.5.12. Treat the earlier 6.9 and 7.0 matrix results as evidence from that build.

## Verification results

The full browser and database suite passed on 1.0.2 with WordPress 7.1.4, PHP 8.5.12, and MariaDB 11.4.9. It covered event capture, combined filters, revision links, permissions, export across batches, recording and cleanup failures, and data retention on uninstall. The database run seeded 100,000 events.

The security and interface suite also passed on 1.0.2. Anonymous reads and exports returned 401. Editor reads, exports, and settings changes returned 403. Twelve axe core scans reported no violations at 320, 390, 768, 1024, and 1440 pixels. The browser reported no JavaScript errors. The current report is `docs/verification/security-ui-report.json`.

On the local WordPress 7.1.4 and MariaDB 11.4.9 reference environment, the 100,000 event run measured REST first page latency at 41 ms and filtered request latency at 226 ms at the 95th percentile. These timings describe this local environment.

The earlier full browser and database suite also passed on WordPress 6.9 with PHP 8.3.36 and WordPress 7.0 with PHP 8.4.26. Desktop and narrow layouts were checked in Chrome on those earlier builds.
