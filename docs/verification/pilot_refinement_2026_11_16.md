# Pilot refinement verification, 16 November 2026

## Scope

This report covers the current local workspace and its installable 1.0.4 candidate. Test sites were disposable local WordPress installations. The tested WordPress releases are listed in the [WordPress release archive](https://wordpress.org/download/releases/). The PHP release numbers are listed on the [PHP downloads page](https://www.php.net/downloads.php).

## Compatibility checks

| WordPress | PHP | Scope | Result | Notes |
| --- | --- | --- | --- | --- |
| 6.9.11 | 8.3.36 | ZIP install, activation, REST access, Activity screen, Settings version | Passed | Windows App Control blocked the local PHP curl extension. The smoke workflow completed without it. |
| 7.0.8 | 8.4.27 | ZIP install, activation, REST access, Activity screen, Settings version, Plugin Check | Passed | Plugin Check 2.1.0 reported no findings on the extracted candidate package. |
| 7.1.4 | 8.5.12 | Full pilot and security and interface checks | Passed | MariaDB 11.4.13 |

## Full pilot results

The pilot used 100,000 activity records. All automated capture, investigation, filter, details, CSV, access, retention, cleanup, and uninstall checks passed. The Dashboard comparison was skipped as requested.

The scripted investigation completed in 585 ms. That result does not measure the human 30 second acceptance check.

## Interactive admin task

On a fresh disposable WordPress 7.1.4 site with PHP 8.5.12 and the 1.0.4 candidate ZIP, I used the rendered admin interface. Given only the page title, “Quarterly Access Plan”, I searched the Activity screen and opened its status change. The details identified `pilotadmin` and showed Status, Previous Published, New Draft. The complete browser interaction took 646 ms. This is an agent operated interface check, not a human usability result, so the pilot guide still asks an administrator to repeat the task under 30 seconds.

| Measurement | 95th percentile |
| --- | ---: |
| First page database query | 0.23 ms |
| Filtered database query | 145.98 ms |
| First page REST request | 162 ms |
| Filtered REST request | 312 ms |
| Public page with the plugin enabled | 197 ms |
| Public page with the plugin disabled | 193 ms |
| Event capture with the plugin enabled | 32.06 ms |
| Event capture with the plugin disabled | 17.63 ms |

## Security and interface results

* Anonymous event reads and exports returned 401.
* Editor access checks returned 403.
* Twelve axe scans reported no findings at widths of 320, 390, 768, 1024, and 1440 pixels.
* The browser reported no JavaScript errors.
* The Activity list, event details, mobile Activity view, and Settings screen were visually reviewed.
* The settings check confirmed that a cleanup scheduling failure is shown, and that a successful retry clears the failure.

## Other checks

* The production interface build passed.
* All five plugin PHP files passed syntax checks.
* The Git whitespace check passed. Git reported line ending notices for four files.
* The extracted plugin files matched the workspace files in the tested candidate archive.

The tested candidate archive SHA256 is `1037952EC8A81B3DAA3BE5A6C2F7503FB3C9E08A019E987C2EF7F3FE7F1D4ED7`.

## Remaining checks

* A person must complete the page investigation with only the page name and finish in under 30 seconds.
* The Dashboard comparison remains intentionally untested.
* GitHub Actions has not run on these uncommitted workspace changes.
* The local PHP 8.3.36 environment could not load php_curl.dll because Windows App Control blocked it. The GitHub compatibility job is configured to enable curl.

The plugin metadata and Settings footer remain at version 1.0.4. No release was created from this refinement.
