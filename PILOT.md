# Activity Log for Admins pilot guide

## Install and investigate

1. On a disposable or staging site running WordPress 6.9 or later and PHP 8.3 or later, open **Plugins**, then **Add New Plugin**, then **Upload Plugin**.
2. Upload the installable ZIP from the [GitHub 1.1.0 release](https://github.com/seraphalaric-beep/activity-log-for-admins/releases/tag/v1.1.0), install it, and activate it. Confirm Settings shows version 1.1.0. The main Dashboard shows activity counts and recent changes. Open **Activity Log** near the top of the admin menu to investigate.
3. Create a test page named **Housing Guide**, publish it, and move it back to Draft. Add a few unrelated edits to make the list realistic.
4. Ask another administrator to start on the Activity screen with only the page name, without a date, user, or event hint. Record the elapsed time and whether they correctly find the unexpected change, identify the user, and verify the previous status without help. Pass if all are correct in under 30 seconds.
5. Open an event, check the previous and new values, and try the actor and affected item links. Combine search with the Action and Severity filters, reload the URL, and verify that CSV export contains every matching row and any captured previous and new values.
6. Open **Settings** to check the retention window, database estimate, cleanup status, and version. When reporting an issue, include the plugin version, WordPress and PHP versions, and what you expected to see.

During failure handling checks, reject a cleanup schedule once and simulate a cleanup database error separately. Confirm Settings reports each failure with its timestamp, then confirm a successful schedule clears the scheduling failure.

Record the tester role, elapsed seconds, whether they found the correct event, user, and previous value, whether they received any hints, and the WordPress, PHP, and plugin versions. A pass requires all three answers to be correct, no hints, and completion in under 30 seconds.

On the disposable site, update a plugin or theme individually, then use the bulk updater for another component. Confirm each event shows the previous and new version when both are available.

With a theme that offers a named page template, switch a page from Default to that template and back. Set, replace, and remove its featured image. Repeat the image sequence on a post. Confirm each event shows the correct previous and new values, and that unrelated custom metadata is not included.

Create a scheduled post, then change only its publish date and time while leaving its status as Scheduled. Confirm the event is easy to spot and its previous and new values show the exact time in the site timezone.

On a hierarchical page, change its parent page and content author. Confirm the activity row names the change and the event details show the previous and new names with their IDs.

Create or use an editor with a numeric username, make a page edit as that account, then confirm an administrator can find the event with the Actor filter using the exact username. Select each nonuser Actor type and confirm it filters to the intended category. Open an event with a background or unknown actor, follow its actor link, and confirm the shared URL restores that actor category separately from a similarly named account.

Use a disposable site with WooCommerce and Redirection installed. Confirm their switches start off, enable each, and verify WooCommerce order creation, status, and refund events plus Redirection rule changes. Confirm event details and CSV contain no customer contact data or redirect URLs. Check that disabling an event category stops new events but leaves existing history until cleanup.

On a disposable Multisite network, activate on a site and then network activate in a separate run. Make changes on two sites, confirm each site's activity is visible only in that site's admin, and set different retention periods. Create a new site while the plugin is network active and confirm its event table and cleanup task are ready when its admin loads. One combined network view is outside this release.

Reduce a site's retention period and confirm cleanup starts in bounded batches, then inspect the oldest retained event and cleanup status. The default remains 90 days, administrators choose their own period from 1 to 3650 days. Optional warning email digests are off by default. Verify scheduling and cancellation without sending test mail to a real inbox.

Run a supported setting change through WP CLI without a selected user. Confirm the actor is shown as WP CLI and its Actor type filter is separate from the WordPress background task filter.

The plugin records activity from activation onward. The full event list is in the plugin readme. Revision comparison depends on WordPress retaining both revisions. Scheduled cleanup runs through the WordPress scheduler, so quiet sites can run it later than the displayed schedule. Database allocation may remain the same after expired events are deleted, since the database can reuse the freed space.

Deactivation and deletion preserve history by default. To remove the stored history and settings when deleting the plugin, select **Erase all activity when deleting the plugin** in Settings and save it first.

## Build and repeat verification

From the workspace, run `npm ci` and `npm run package`. This builds the admin interface and writes the installable ZIP under `release`. The archive contains the frontend source, dependency lock, and third party license notices.

To build a candidate without replacing a checked in release ZIP, run `npm run build` and `python tools/package.py --output-dir .tmp/pilot-release`, then pass `-Archive .tmp/pilot-release/activity-log-for-admins.<version>.zip` to `tools/start-pilot.ps1`.

The automated pilot uses a disposable WordPress installation, MariaDB, PHP, and Chrome. The setup script needs an extracted copy of WordPress, a native PHP executable, and an empty database whose name starts with `alfa_`. Start the disposable site with `tools/start-pilot.ps1`, then run `npm run test:pilot -- <loopback URL> <test password> full`. The fixture is used only in the disposable test environment. Screenshots are written to `test-results`.

Set `ALFA_SKIP_REGRESSION=1` before the full pilot to skip only the Dashboard against Activity list comparison. Capture, investigation, CSV, access, cleanup, uninstall, and the 100,000 event performance checks still run.

The timed browser run checks the workflow with scripted input. Use step 4 to verify the time with a person during the staging pilot.

## Previous release checks

The 1.0.0 archive passed WordPress Plugin Check 2.2.0 on WordPress 7.1.4 and PHP 8.5.12 with no errors. The complete npm dependency audit reported zero vulnerabilities.

The 1.0.0 archive also passed the browser security and interface checks on that environment. Anonymous reads and exports returned 401, editor reads, exports, and settings changes returned 403, missing nonces were rejected, formula cells were neutralized, and injected markup appeared as text. Twelve axe core scans reported no violations at 320, 390, 768, 1024, and 1440 pixels. The browser reported no JavaScript errors. The report from that release is in `docs/verification/security-ui-report-1.0.0.json`.

Version 1.0.4 prevents shared caches from storing the plugin's private REST responses and adds a unique request value to each Activity Log API call. The regression suite compares the newest Dashboard event with the full list response and visible row. The 1.0.3 release added plugin author metadata and carried forward the approved visual style across Activity, Settings, event details, and the Dashboard summary. The earlier 1.0.0 report and measurements describe that release only.

The earlier full WordPress and PHP version matrix was run on the prior pilot build. The 1.0.2 release has been rerun on WordPress 7.1.4 and PHP 8.5.12. Treat the earlier 6.9 and 7.0 matrix results as evidence from that build.

## Previous 1.0.3 verification results

The full browser and database suite passed on 1.0.3 with WordPress 7.1.4, PHP 8.5.12, and MariaDB 11.4.9. It covered event capture, combined filters, revision links, permissions, export across batches, recording and cleanup failures, and data retention on uninstall. The database run seeded 100,000 events. It also confirmed that the Plugins page displays `By Bryan Mc Namara`.

The security and interface suite also passed on 1.0.3. Anonymous reads and exports returned 401. Editor reads, exports, and settings changes returned 403. Twelve axe core scans reported no violations at 320, 390, 768, 1024, and 1440 pixels. The browser reported no JavaScript errors. The report is `docs/verification/security-ui-report-1.0.3.json`.

On the local WordPress 7.1.4 and MariaDB 11.4.9 reference environment, the 100,000 event run measured REST first page latency at 43 ms and filtered request latency at 217 ms at the 95th percentile. These timings describe this local environment.

The earlier full browser and database suite also passed on WordPress 6.9 with PHP 8.3.36 and WordPress 7.0 with PHP 8.4.26. Desktop and narrow layouts were checked in Chrome on those earlier builds.

## Compatibility workflow

GitHub Actions is configured to run the full pilot on WordPress 7.1.4 with PHP 8.3, then run the ZIP install, REST, interface, and Settings smoke checks on WordPress 6.9 with PHP 8.3, WordPress 7.0.8 with PHP 8.4, and WordPress 7.1.4 with PHP 8.5. These compatibility jobs disable mbstring to exercise the fallback text handling. Treat this as workflow configuration only until the GitHub Actions run passes.

## 1.0.4 verification results

The [GitHub Actions run](https://github.com/seraphalaric-beep/activity-log-for-admins/actions/runs/43610394795) passed on WordPress 7.1.4, PHP 8.3, and MariaDB 11.4.9. It built and installed the 1.0.4 ZIP, then passed the dependency audit, PHP syntax checks, security and interface checks, and full browser and database pilot.

The regression check confirmed that the newest Dashboard event also appeared in the full event response and the visible Activity table. Activity list, event details, Settings, and CSV responses used private cache headers and could not be stored. The full pilot covered event capture, filters, event details, permissions, export, cleanup failures, retention, uninstall, and a database seeded with 100,000 events.

Anonymous reads and exports returned 401. Editor reads, exports, and settings changes returned 403. Twelve axe scans reported no findings across Activity, filters, event details, and Settings at widths from 320 to 1440 pixels. The browser reported no JavaScript errors. REST request latency at the 95th percentile was 52 ms for the first page and 178 ms for a filtered request in the hosted runner. These timings describe that runner. The current report is `docs/verification/security-ui-report.json`.

## 1.0.5 verification results

The [GitHub Actions run](https://github.com/seraphalaric-beep/activity-log-for-admins/actions/runs/43899600662) passed on source commit `eb56a5d`. The build job passed its dependency audit, PHP syntax checks, security and interface checks, and full activity pilot. The compatibility jobs passed on WordPress 6.9.11 with PHP 8.3, WordPress 7.0.8 with PHP 8.4, and WordPress 7.1.4 with PHP 8.5. The Dashboard comparison remained skipped as requested.

The installable ZIP from that run is attached to the [GitHub 1.0.5 release](https://github.com/seraphalaric-beep/activity-log-for-admins/releases/tag/v1.0.5). Its SHA256 is `032FAAC4CC7131A585326D8BB5CE14C49950434A66D159D4FDB9803792F74A77`. A copy downloaded from the release matched the CI artifact hash. The Settings preview shows version 1.0.5.

The human 30 second investigation check remains pending. Install the GitHub release on a disposable or staging site, follow steps 3 and 4 above, and record the elapsed time, actor, previous status, and whether the administrator received any hints.
