=== WP Code Guardian ===
Contributors: milardovich
Tags: security, file integrity, plugins, themes, code changes
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect unauthorized code changes in your plugins and themes by comparing them against the original files from WordPress.org, and get warned before updates overwrite them.

== Description ==

When someone edits a file directly inside `wp-content/plugins/` or `wp-content/themes/`, those changes are silently lost the next time the plugin or theme is updated. **WP Code Guardian** detects those modifications before they cost you.

It downloads the **original** copy of each plugin and theme from the official WordPress.org repository, stores a baseline (a SHA-256 hash of every file), and compares the files on your site against that baseline. Any drift — modified, added or deleted files — is flagged in the admin, and you are warned before an update would overwrite your customizations.

= Key features =

* **Baselines from WordPress.org** — the reference is the pristine, official release, not the files already on disk. That is what makes real drift detectable.
* **SHA-256 file comparison** — fast, reliable detection of modified, new and deleted files.
* **Visual diff viewer** — see exactly what changed, line by line, in unified or split view.
* **Inline admin warnings** — modified plugins are flagged on the Plugins screen and modified themes get a badge on the Themes screen.
* **Update protection** — a clear warning is shown before updating an item that has custom modifications, so changes are never lost unexpectedly.
* **Automatic baseline refresh** — after an authorized WordPress update, the baseline is regenerated automatically.
* **Configurable scan frequency** — hourly, twice daily, daily, weekly or disabled.
* **Optimized for large plugins** — change detection short-circuits on hashes, so even very large plugins (thousands of files) are checked in well under a second.

= Use cases =

* Agencies and freelancers maintaining client sites who need to know whether anyone hand-edited a plugin.
* Site owners who want an early warning before an update wipes out a customization.
* Security-conscious admins who want a simple file-integrity check against the official source.

This plugin connects to the WordPress.org Plugins and Themes APIs (api.wordpress.org) to download the original release files used as the comparison baseline.

== Installation ==

1. Upload the `wp-code-guardian` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Code Guardian** in the admin menu and click **Scan All Items** to create your first baselines.
4. Review detected changes from the **Plugin Changes** and **Theme Changes** screens, and adjust the scan frequency under **Code Guardian → Settings**.

== Frequently Asked Questions ==

= Where do the baselines come from? =

From the official WordPress.org Plugins and Themes APIs. The plugin downloads the original release ZIP that matches the installed version and stores a hash of each file. Comparing against the pristine source — rather than the files already on disk — is what makes unauthorized changes detectable.

= Does it work with premium or custom plugins and themes? =

Detection relies on the original being available on WordPress.org. For premium or custom items that are not hosted there, the plugin falls back to using the current files on disk as the baseline, so it can still detect changes made *after* the baseline was created.

= Will it slow down my admin? =

No. Change detection compares file hashes and stops at the first difference, so even very large plugins are scanned in a fraction of a second. Results are cached according to your configured scan frequency.

= Does it modify any of my files? =

No. WP Code Guardian only reads files and compares them. It never edits plugin or theme files.

= What happens when I delete the plugin? =

Its database tables, options and transients are removed automatically on uninstall, leaving no orphan data behind.

== Screenshots ==

1. The Code Guardian dashboard with baseline and modification counters.
2. The Plugin Changes screen listing each plugin's status.
3. The line-by-line diff viewer (unified and split views).
4. Update warning shown before overwriting a modified item.
5. Settings screen with scan frequency and maintenance actions.

== Changelog ==

= 1.0.0 =
* Initial release.
* Baseline creation from WordPress.org for plugins and themes.
* SHA-256 based detection of modified, new and deleted files.
* Visual diff viewer with unified and split views.
* Inline admin flags and pre-update warnings.
* Automatic baseline refresh after authorized updates.
* Spanish translations (es_ES, es_AR).

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Code Guardian.
