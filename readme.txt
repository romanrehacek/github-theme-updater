=== GitHub Theme Updater ===
Contributors: romanrehacek
Tags: github, theme updates, theme updater
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect one WordPress theme to a GitHub repository and let WordPress handle updates through its native theme upgrader flow.

== Description ==

GitHub Theme Updater adds one GitHub-hosted theme into the normal WordPress theme update flow.

Features in this first version:

* checks the remote `style.css` file on the configured branch or tag,
* compares the remote `Version` header with the installed theme version,
* injects the update into WordPress core's native theme updates UI,
* downloads the update package from GitHub,
* supports private repositories with a GitHub token,
* adds a manual force-update action that reinstalls the configured theme from GitHub even when the version did not increase.

The plugin is designed to stay as close as possible to the standard WordPress upgrade process by using `Theme_Upgrader` instead of custom file copy logic.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/github-theme-updater/`.
2. Activate the plugin in **Plugins**.
3. Open **Settings > GitHub Theme Updater**.
4. Enter the GitHub repository URL, branch or tag, and the target theme stylesheet (theme folder name).
5. Add a GitHub token when the repository is private.
6. Save the settings and run **Check for updates now**.

== Frequently Asked Questions ==

= Which version is used for update detection? =

The plugin reads the remote `Version` header from the theme's `style.css` file on the configured branch or tag.

= Does this support private repositories? =

Yes. Provide a GitHub token with repository contents read access.

= Does force update still use WordPress core? =

Yes. The plugin forces the configured theme through WordPress's native theme upgrader flow instead of copying files directly.

== Changelog ==

= 0.1.0 =

* Initial release.
