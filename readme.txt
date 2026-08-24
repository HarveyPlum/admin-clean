=== AdminClean ===
Contributors: harveyplum
Tags: admin, agency, plugins, white label
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.5.8
License: GPLv2 or later

This plugin hides irrelevant notices and default plugins for Harvey Plum Hosting customers to keep their admin interfaces clean. Please deactivate it to see all notices and plugins.

== Description ==

AdminClean hides irrelevant notices and default plugins for Harvey Plum Hosting customers to keep their admin interfaces clean. Deactivate it to see all notices and plugins.

Trusted manager users still see a private AdminClean menu with status for the protected plugins and a settings page.

Any administrator can open the AdminClean settings page and disable protected plugin hiding with the master hiding switch. Administrator users with `@harveyplum.com` email addresses are always exempt from AdminClean hiding.

AdminClean can also suppress standard WordPress and plugin admin notices for customer admin users with a separate setting.

== Setup ==

1. Upload the `admin-clean` folder to `wp-content/plugins/`.
2. Activate AdminClean from your own agency admin account.
3. Open AdminClean > Settings.
4. Add your trusted manager user IDs.
5. Add protected plugins using this format:

`plugin-folder/plugin-file.php | Friendly Label | menu-slug-1,menu-slug-2`

Examples:

`wordfence/wordfence.php | Security | Wordfence,WordfenceWAF`
`wp-rocket/wp-rocket.php | Performance | wp-rocket`

== Import and Export ==

Use AdminClean > Settings to export the protected plugin list as JSON. Import that JSON file on another site to reuse the same protected plugin and hidden menu configuration.

Imports only replace the protected plugin list. Trusted manager user IDs and behavior toggles stay specific to each site.

== Notes ==

AdminClean intentionally does not deactivate, delete, or modify protected plugins. It only changes visibility for non-manager administrator users.

== Changelog ==

= 0.5.8 =
* Added Git Updater to AdminClean's required protected plugins, including existing saved configurations.

= 0.5.7 =
* Added GitHub update metadata and standardized Harvey Plum branding.
* Hardened imports by requiring a genuine uploaded JSON or text file.
