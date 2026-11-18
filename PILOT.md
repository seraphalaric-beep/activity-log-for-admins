# Change Vigil Site Journal pilot guide

## Install and investigate

1. On a disposable or staging site running WordPress 6.9 or later and PHP 8.3 or later, open **Plugins**, then **Add New Plugin**, then **Upload Plugin**.
2. Upload the installable ZIP from the [GitHub 1.2.2 release](https://github.com/seraphalaric-beep/change-vigil-site-journal/releases/tag/v1.2.2), install it, and activate it. Confirm Settings shows version 1.2.2. The main Dashboard shows activity counts and recent changes. Open **Change Vigil** near the top of the admin menu to investigate.
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

On a disposable Multisite network, activate on a site and then network activate in a separate run. Make changes on two sites, confirm each site's activity is visible only in that site's admin, and set different retention periods. Use the Super Admin network view to check site filters, details, notes, export, and per site settings. Create a new site while the plugin is network active and confirm its event table and cleanup task are ready when its admin loads. A second network isolation check remains open.

Reduce a site's retention period and confirm cleanup starts in bounded batches, then inspect the oldest retained event and cleanup status. The default remains 90 days, administrators choose their own period from 1 to 3650 days. Optional warning email digests are off by default. Verify scheduling and cancellation without sending test mail to a real inbox.

Run a supported setting change through WP CLI without a selected user. Confirm the actor is shown as WP CLI and its Actor type filter is separate from the WordPress background task filter.

The plugin records activity from activation onward. The full event list is in the plugin readme. Revision comparison depends on WordPress retaining both revisions. Scheduled cleanup runs through the WordPress scheduler, so quiet sites can run it later than the displayed schedule. Database allocation may remain the same after expired events are deleted, since the database can reuse the freed space.

Deactivation and deletion preserve history by default. To remove the stored history and settings when deleting the plugin, select **Erase all activity when deleting the plugin** in Settings and save it first.

## Build and repeat verification

From the workspace, run `npm ci` and `npm run package`. This builds the admin interface and writes the installable ZIP under `release`. The archive contains the frontend source, dependency lock, and third party license notices.

To build a candidate without replacing a checked in release ZIP, run `npm run build` and `python tools/package.py --output-dir .tmp/pilot-release`, then pass `-Archive .tmp/pilot-release/change-vigil-site-journal.<version>.zip` to `tools/start-pilot.ps1`.

The automated pilot uses a disposable WordPress installation, MariaDB, PHP, and Chrome. The setup script needs an extracted copy of WordPress, a native PHP executable, and an empty database whose name starts with `cvsj_`. Start the disposable site with `tools/start-pilot.ps1`, then run `npm run test:pilot -- <loopback URL> <test password> full`. The fixture is used only in the disposable test environment. Screenshots are written to `test-results`.

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

The [GitHub Actions run](https://github.com/seraphalaric-beep/change-vigil-site-journal/actions/runs/43610394795) passed on WordPress 7.1.4, PHP 8.3, and MariaDB 11.4.9. It built and installed the 1.0.4 ZIP, then passed the dependency audit, PHP syntax checks, security and interface checks, and full browser and database pilot.

The regression check confirmed that the newest Dashboard event also appeared in the full event response and the visible Activity table. Activity list, event details, Settings, and CSV responses used private cache headers and could not be stored. The full pilot covered event capture, filters, event details, permissions, export, cleanup failures, retention, uninstall, and a database seeded with 100,000 events.

Anonymous reads and exports returned 401. Editor reads, exports, and settings changes returned 403. Twelve axe scans reported no findings across Activity, filters, event details, and Settings at widths from 320 to 1440 pixels. The browser reported no JavaScript errors. REST request latency at the 95th percentile was 52 ms for the first page and 178 ms for a filtered request in the hosted runner. These timings describe that runner. The current report is `docs/verification/security-ui-report.json`.

## 1.0.5 verification results

The [GitHub Actions run](https://github.com/seraphalaric-beep/change-vigil-site-journal/actions/runs/43899600662) passed on source commit `eb56a5d`. The build job passed its dependency audit, PHP syntax checks, security and interface checks, and full activity pilot. The compatibility jobs passed on WordPress 6.9.11 with PHP 8.3, WordPress 7.0.8 with PHP 8.4, and WordPress 7.1.4 with PHP 8.5. The Dashboard comparison remained skipped as requested.

The installable ZIP from that run is attached to the [GitHub 1.0.5 release](https://github.com/seraphalaric-beep/change-vigil-site-journal/releases/tag/v1.0.5). Its SHA256 is `032FAAC4CC7131A585326D8BB5CE14C49950434A66D159D4FDB9803792F74A77`. A copy downloaded from the release matched the CI artifact hash. The Settings preview shows version 1.0.5.

The human 30 second investigation check remains pending. Install the GitHub release on a disposable or staging site, follow steps 3 and 4 above, and record the elapsed time, actor, previous status, and whether the administrator received any hints.

## 1.2.0 release verification

The actual 1.2.0 GitHub ZIP was installed on a clean WordPress 7.1.4 site with PHP 8.5.12 and MariaDB 11.4.9 on 17 November 2026. The production build passed, and PHP syntax checks passed for all 11 plugin PHP files.

The security and interface suite passed. Anonymous reads and exports returned 401. Editor reads, exports, and settings changes returned 403. Requests without a REST nonce returned 401. Formula protection passed, injected markup rendered as text, and the browser reported no errors. Twelve axe scans reported no violations across Activity, filters, event details, and Settings at widths of 320, 390, 768, 1024, and 1440 pixels. The report is `docs/verification/security-ui-report-1.2.0.json`.

The full automated activity pilot passed, including capture, filters, event details and notes, CSV export, permissions, cleanup failures, retention, uninstall behavior, and the 100000 event performance check. REST first page latency measured 180 milliseconds at the 95th percentile, and filtered REST results measured 353 milliseconds. Event capture measured 27 milliseconds with the plugin enabled and 35 milliseconds with it disabled. Public page requests measured 207 milliseconds with the plugin enabled and 194 milliseconds with it disabled. These measurements describe this local test environment.

The scripted investigation completed in 1267 milliseconds. This does not replace the pending human check in step 4. The Dashboard comparison was skipped as requested. WordPress 6.9 with PHP 8.3, isolation between two separate networks, and remaining roadmap items still need verification. Structured results are in `docs/verification/pilot-report-1.2.0.json`.

## 1.2.1 release verification

The WordPress automated review findings were corrected for report output escaping, translation context, prepared database queries, and scheduled report file handling. Privacy erasure now preserves the affected account label when removing an email address from the same event.

The security and interface suite passed on WordPress 7.1.4 with PHP 8.5.12 and MariaDB 11.4.13. Anonymous reads and exports returned 401, and editor requests were denied. Twelve axe scans reported no violations across Activity, filters, event details, and Settings at widths of 320, 390, 768, 1024, and 1440 pixels. The browser reported no errors.

The privacy export and erasure flow passed, including bounded batches, account anonymization, report cleanup, recipient cleanup, note removal, stale alert retry rejection, and editor denial. Scheduled report authorization, recipient and format validation, batched delivery, bounded retries, private history downloads, retention cleanup, privacy erasure, and editor denial passed.

The production dependency audit reported zero vulnerabilities. A full audit of development tools reported five high severity entries stemming from the known [picomatch advisory](https://github.com/advisories/GHSA-fc5q-39xc-vjpf), pulled in through Vite. The project lockfile records picomatch, but its code is not bundled in the installable ZIP. Its [upstream fix remains an open pull request](https://github.com/micromatch/picomatch/pull/237), so the build workflow audits production dependencies and the remaining development tool finding is recorded here.

The full automated activity pilot passed on the same environment, with the Dashboard comparison skipped as requested. The 100000 event check measured 178 milliseconds for the first REST page and 333 milliseconds for representative filtered REST results at the 95th percentile. Event capture measured 29 milliseconds with the plugin enabled and 26 milliseconds with it disabled. Public page requests measured 232 milliseconds with the plugin enabled and 202 milliseconds with it disabled. The scripted investigation completed in 1214 milliseconds. These measurements describe this local test environment.

## 1.2.2 directory review candidate

The [GitHub Actions run](https://github.com/seraphalaric-beep/change-vigil-site-journal/actions/runs/44398188342) for commit `39f35f2` passed PHP syntax, the production dependency audit, the security and browser suite, the full activity pilot, and the coexistence check with the published 1.2.1 ZIP. The compatibility jobs installed the same artifact on WordPress 6.9.11 and PHP 8.3, WordPress 7.0.8 and PHP 8.4, and WordPress 7.1.4 and PHP 8.5.

Locally, `npm run test:quality` and `npm run test:pilot` passed against the installed ZIP on WordPress 7.1.4, PHP 8.5.12, and MariaDB 11.4.13. The automated investigation took 1.214 seconds. With 100,000 events, first page and filtered REST requests took 179 ms and 322 ms at the 95th percentile. Twelve axe scans found no violations at widths from 320 to 1440 pixels and the browser reported no errors. The separate Dashboard comparison was skipped by request. The timing comes from automation and does not measure an administrator using the plugin unaided.

The `tools/coexistence-smoke.php` check confirmed both versions recorded the test change in their own logs, exported independently, retained separate settings and scheduled tasks, and could each erase their own data without changing the other plugin's records. Manual inspection covered the refreshed directory banner and the Activity and Settings views on narrow and desktop layouts. Neither the production nor full npm audit reported vulnerabilities at the time of this build.

WordPress Plugin Check's directory category returned zero errors and 27 advisory warnings about direct or uncached database calls. The official readme validator returned only a note about the optional donation link. The full name and slug returned no obvious conflicting match in a public search, which is an initial screen.

The [1.2.2 release](https://github.com/seraphalaric-beep/change-vigil-site-journal/releases/tag/v1.2.2) contains the CI ZIP. All 123 archive entries matched the local build after normalising text line endings. The published download's SHA256 matches the CI artifact, `c415ce8990d78912eba523d099a4d0e38e7532723329f2d621eee0cbd6ee66c2`.
