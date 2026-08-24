=== Milardovich File Modification Monitor ===
Contributors: milardovich
Tags: security, file integrity, plugins, themes, code changes
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect edits to your plugins and themes by comparing them against the original files from WordPress.org, and get warned before updates overwrite them.

== Description ==

When someone edits a file directly inside `wp-content/plugins/` or `wp-content/themes/`, those changes are silently lost the next time the plugin or theme is updated. **Milardovich File Modification Monitor** detects those modifications before they cost you.

It downloads the **original** copy of each plugin and theme from the official WordPress.org repository, stores a baseline (a SHA-256 hash of every file), and compares the files on your site against that baseline. Any drift — modified, added or deleted files — is flagged in the admin, and you are warned before an update would overwrite your customizations.

= Key features =

* **Baselines from WordPress.org** — the reference is the pristine, official release, not the files already on disk. That is what makes real drift detectable.
* **SHA-256 file comparison** — fast, reliable detection of modified, new and deleted files.
* **Visual diff viewer** — see exactly what changed, line by line, in unified or split view.
* **Inline admin warnings** — modified plugins are flagged on the Plugins screen and modified themes get a badge on the Themes screen.
* **Update protection** — a clear warning is shown before updating an item that has custom modifications, so changes are never lost unexpectedly.
* **Keep or revert** — for any detected change, either adopt your edits as the new baseline or restore the original files from WordPress.org. Restoring is always confirmed first.
* **Automatic baseline refresh** — after an authorized WordPress update, the baseline is regenerated automatically.
* **Configurable scan frequency** — hourly, twice daily, daily, weekly or disabled.
* **Scans in the background** — comparisons run on a scheduled WordPress cron event, never during a page load, so the admin stays responsive.
* **Optimized for large plugins** — change detection compares SHA-256 hashes, so even very large plugins (thousands of files) are checked in about a second.

= Use cases =

* Agencies and freelancers maintaining client sites who need to know whether anyone hand-edited a plugin.
* Site owners who want an early warning before an update wipes out a customization.
* Security-conscious admins who want a simple file-integrity check against the official source.

This plugin connects to the WordPress.org Plugins and Themes APIs (api.wordpress.org) to download the original release files used as the comparison baseline.

== Installation ==

1. Upload the `milardovich-file-modification-monitor` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **File Monitor** in the admin menu and click **Scan All Items** to create your first baselines.
4. Review detected changes from the **Plugin Changes** and **Theme Changes** screens, and adjust the scan frequency under **File Monitor → Settings**.

== Frequently Asked Questions ==

= Where do the baselines come from? =

From the official WordPress.org Plugins and Themes APIs. The plugin downloads the original release ZIP that matches the installed version and stores a hash of each file. Comparing against the pristine source — rather than the files already on disk — is what makes unauthorized changes detectable.

= Does it work with premium or custom plugins and themes? =

Detection relies on the original being available on WordPress.org. For premium or custom items that are not hosted there, the plugin falls back to using the current files on disk as the baseline, so it can still detect changes made *after* the baseline was created.

= Will it slow down my admin? =

No. The comparison runs in a background WordPress cron event, not while a page is loading. Admin screens only read the stored result of the last scan, so they render immediately regardless of how many plugins you have.

= Does it modify any of my files? =

Only when you explicitly ask it to. By default the plugin only reads and compares files. If you choose **Restore Original** on a detected change, it writes the baseline copy back over those files on disk — overwriting your edits, recreating files you deleted and removing files you added. That action always asks for confirmation first and tells you how many files it will touch. Choosing **Keep My Changes** instead leaves every file untouched and simply records the current files as the new baseline.

= Can I undo a restore? =

No. Restoring overwrites the files on disk with the original version and cannot be reversed from within the plugin, so review the diff first and keep backups as you would for any file change.

= What happens when I delete the plugin? =

Its database tables, options and transients are removed automatically on uninstall, leaving no orphan data behind.

== External services ==

This plugin relies on the official WordPress.org APIs to obtain the pristine copies it compares your files against. Nothing else is contacted, and no data about your site is ever sent anywhere.

* **api.wordpress.org** — queried to look up a plugin or theme and find the download URL for the installed version. The request contains only the public slug and version of that plugin or theme.
* **downloads.wordpress.org** — the original release ZIP is downloaded from here, unpacked into the server's temporary directory, hashed, and then deleted.

These requests happen only when a baseline is created or refreshed: when you run a scan, when you refresh a single baseline, or after WordPress updates a plugin or theme. No personal data, site URL, or usage statistics are transmitted, and the plugin sets no cookies and loads nothing on the front end.

== Screenshots ==

1. The dashboard: baseline and modification counters, with inline help explaining what a baseline is.
2. The Plugin Changes screen, listing each plugin's status and the actions available for it.
3. The diff viewer, showing exactly what changed and offering to keep the changes or restore the original files.
4. Settings: scan frequency, notifications, ignored file patterns and maintenance actions.

== Changelog ==

= 1.0.0 =
* Initial release.
* Baseline creation from WordPress.org for plugins and themes.
* SHA-256 based detection of modified, new and deleted files.
* Visual diff viewer with unified and split views.
* Keep-your-changes and restore-the-original actions for any detected change.
* Background scanning on a scheduled cron event, with a progress bar for batch scans.
* Inline admin flags and pre-update warnings.
* Automatic baseline refresh after authorized updates.
* Spanish translations (es_ES, es_AR).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
