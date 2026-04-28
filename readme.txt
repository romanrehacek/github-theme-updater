=== GitHub Theme Updater ===
Contributors: romanrehacek
Tags: github, theme updates, theme updater
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect one or more WordPress themes to GitHub repositories and let WordPress handle updates through its native theme upgrader flow.

== Description ==

GitHub Theme Updater adds one or more GitHub-hosted themes into the normal WordPress theme update flow.

Features in this first version:

* connects multiple installed or not-yet-installed themes, each with its own GitHub repository settings,
* works with standalone themes and parent/child themes configured as separate theme entries,
* checks the remote `style.css` file on the configured branch or tag,
* compares the remote `Version` header with the installed theme version,
* injects updates into WordPress core's native theme updates UI,
* downloads update packages from GitHub,
* supports private repositories with a GitHub token per configured theme,
* installs a theme from GitHub before it exists on the site yet,
* adds manual force-update actions that reinstall a selected configured theme from GitHub even when the version did not increase,
* stores one saved backup per configured theme in `wp-content/uploads/github-theme-updater/` before install or update,
* restores a theme from its saved backup, including the preserved `.git` directory.

The plugin is designed to stay as close as possible to the standard WordPress upgrade process by using `Theme_Upgrader` instead of custom file copy logic.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/github-theme-updater/`.
2. Activate the plugin in **Plugins**.
3. Open **Appearance > GitHub Theme Updater**.
4. Add one or more theme configurations.
5. For each configuration, either select an already installed theme or leave it unselected for a first-time install.
6. Enter the matching GitHub repository URL, branch or tag, and add a GitHub token when the repository is private.
7. Optionally set a target theme directory when the theme is not installed yet; otherwise the plugin will derive it from the remote theme metadata or repository name.
8. Review the backup directory status in the plugin settings and confirm that `wp-content/uploads/github-theme-updater/` is writable when backups are enabled.
9. Save the settings and use **Install from GitHub** for new themes, or **Check now** / **Force update** for already installed themes.
10. Use **Restore backup** whenever you need to replace the installed theme with the latest saved backup snapshot.

== Frequently Asked Questions ==

= Which version is used for update detection? =

The plugin reads the remote `Version` header from the theme's `style.css` file on the configured branch or tag.

= Can I manage parent and child themes? =

Yes. Configure the parent theme and the child theme as two separate entries. The plugin reads their installed WordPress metadata automatically and does not require the theme to be currently active.

= Can I install a theme that is not on the site yet? =

Yes. Save the repository settings first, then use **Install from GitHub**. After the theme is installed, the same saved configuration is used for normal updates and force updates.

= Does this support private repositories? =

Yes. Provide a GitHub token with repository contents read access.

= Does force update still use WordPress core? =

Yes. The plugin forces the selected configured theme through WordPress's native theme upgrader flow instead of copying files directly.

= Where are backups stored? =

The plugin stores one latest backup per configured theme in `wp-content/uploads/github-theme-updater/`. The admin page shows the full path, whether the directory is writable, and whether backups are currently enabled.

== Changelog ==

= 0.1.0 =

* Initial release.
