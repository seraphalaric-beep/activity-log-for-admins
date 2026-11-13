# Contributing

Thanks for helping improve Activity Log for Admins. The 1.0.0 release is a pilot, so reports about missing events, unclear changes, and difficult investigations are especially useful.

## Before opening an issue

For a bug report, include the plugin version shown on the Settings page, the WordPress version, the PHP version, the steps to reproduce the problem, and the result you expected. Remove usernames, page names, and other site details that should not be shared.

For a feature request, describe the administrator task and the information needed to complete it.

## Local changes

1. Install the locked frontend dependencies with `npm ci`.
2. Build the interface and installable archive with `npm run package`.
3. Use only a disposable local WordPress site for the pilot fixture and browser suite. See [PILOT.md](PILOT.md).
4. Keep changes within the documented pilot scope unless the issue explicitly discusses a later release.

The `tools/alfa-fixture.php` helper is restricted to loopback requests and an administrator account. Never copy it into a production site.

## Pull requests

Keep each change focused. Explain the administrator problem, the behavior changed, and how you verified it. Update the changelog and documentation when behavior or setup changes.
