# Security

Activity Log for Admins is a 1.0.1 pilot. The security and interface checks in the verification report cover version 1.0.0 only. Version 1.0.1 has not been rerun through those checks. Install it on a disposable or staging site while the project gathers feedback.

The plugin requires the WordPress `manage_options` capability for event reads, exports, and settings changes. Event data stays in the local WordPress database. The pilot excludes passwords, tokens, full request bodies, and IP addresses.

If you find a security issue, do not post exploit details in a public issue. Use GitHub private vulnerability reporting for this repository when it is available, or contact the maintainer privately through GitHub.

