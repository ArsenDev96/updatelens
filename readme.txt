=== UpdateLens ===
Tags: updates, plugins, diagnostics, options, cron
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Understand what changes when your WordPress plugins update.

== Description ==

UpdateLens is in early development. When you update a single plugin from wp-admin, it records which options changed during the update and during the first admin page load afterwards (names, sizes and autoload state; never option values) in its own database table. These are observed changes; other site activity at the same time can also appear. Tools → UpdateLens lists the analyzed updates and shows a report for each one. Deleting the plugin removes this data.

UpdateLens does not send any data to external services.

== Installation ==

1. Upload the `updatelens` folder to `/wp-content/plugins/`, or install the plugin ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Open Tools → UpdateLens.

== Changelog ==

= 0.1.0 =
* Initial development foundation.
