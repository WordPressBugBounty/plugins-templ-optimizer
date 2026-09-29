=== Templ Optimizer ===
Contributors: Templ
Tags: optimize, performance, clean-up, speed, database
Stable tag: 2.4.0
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Optimize your site and improve its performance with a few clicks.

== Description ==

Templ Optimizer lets you optimize your site and improve its performance with just a few clicks.

We at Templ have optimized 1000s of WordPress websites through the years, and we've gathered some of our best optimization tricks in this easy-to-use plugin.

Clean your site's database and tweak WordPress settings to make your website faster.

Features:

* Clean your site's database
* Add high-performance database indexes (and revert them)
* Delete trashed posts
* Delete revisions
* Delete auto-drafts
* Delete orphaned post meta
* Delete expired transients
* Delete database tables with other prefix
* Convert MyISAM database tables to InnoDB
* Optimize database tables
* Change heartbeat interval (a.k.a. "heartbeat control")
* Change WP Rocket cache preload interval
* Limit post revisions
* Increase WordPress memory limit
* Diagnose slow outbound HTTP requests that block PHP
* WP-CLI support

### Clean your site's database

Your WordPress database grows with time and often contains a lot of unnecessary data. Clean your database with Templ Optimizer and see the effect on your database size and experience the difference in performance.

### Add high-performance database indexes

As your site grows, WordPress's default database keys can leave MySQL scanning large tables to find data. Templ Optimizer can add high-performance keys to the core WordPress tables (posts, postmeta, comments, commentmeta, termmeta, users, usermeta and options) so WordPress finds what it needs faster. The change is safe and fully reversible: revert to WordPress's standard keys at any time. On large tables, use the WP-CLI command to avoid browser timeouts. This capability is inspired by the excellent Index WP MySQL For Speed plugin.

### Diagnose slow outbound HTTP requests

Sometimes a site is slow even though CPU load and database queries look fine. A common cause is PHP waiting on slow third-party HTTP calls (`wp_remote_get()` and similar): these run synchronously, so a slow remote server can hold up a whole page. Enable HTTP request diagnostics to capture completed WordPress HTTP API calls into a dedicated, bounded log (7 days / 100,000 records per site), then find the slowest calls, the destinations with the highest cumulative blocking wait, and the plugin or theme responsible. Only the destination host and a redacted path are stored — never query values, headers, cookies or response bodies. Manage it from the admin app or with WP-CLI (`wp templ-optimizer http enable|status|list|summary|show|clear|disable`).

### Tweak WordPress & plugin settings

Contains many smart tweaks and settings for WordPress as well as some of the most popular plugins. Free up more resources for your site to handle visitors and speed up your website.

### Got a feature request?

Are you missing a feature? Do you have ideas on how Templ Optimizer can be improved? Get in touch with us on our website: [https://templ.io/](https://templ.io/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=templ-optimizer-readme)

== Frequently Asked Questions ==

= Do I need to take any precautions? =

Yes, always backup your database before you do anything with your site's database.

= Where can I get support? =

You can find [documentation for Templ Optimizer here](https://help.templ.io/en/articles/5749500-templ-optimizer), where you also can chat with us.

== Screenshots ==

1. A clean and simple interface to optimize your WordPress website.

== Changelog ==

= 2.4.0 =
* Added: HTTP request diagnostics — find slow outbound `wp_remote_*` calls that block PHP, with summaries by destination and by plugin/theme
* Added: A dedicated, per-site log with bounded retention (7 days / 100,000 records) storing only host + redacted path — never query values, headers, cookies or bodies
* Added: WP-CLI commands `wp templ-optimizer http enable|disable|status|list|summary|show|clear`
* Added: A "HTTP Requests" screen in the admin app to enable capture, review the slowest calls and clear the log

= 2.3.0 =
* Added: High-performance database indexes for the core WordPress tables, with one-click enable and revert
* Added: Per-table status, drift detection (re-apply keys removed by a WordPress/WooCommerce core update) and a dashboard reminder
* Added: WP-CLI commands `wp templ-optimizer indexes status|enable|disable` (the timeout-safe path for large tables)

= 2.2.0 =
* Changed: Minimum required PHP version is now 8.0
* Improved: Added strict typing and full type declarations across the plugin's PHP code
* Improved: Documented all classes, methods and properties with PHPDoc blocks

= 2.1.3 =
* Fixed: Blank admin screen caused by the settings app mounting before its router was ready
* Fixed: Expired transients cleanup now counts and deletes expired transients correctly
* Fixed: PHP deprecation notices (dynamic properties) on PHP 8.2+
* Fixed: "Translation loading was triggered too early" notice on WordPress 6.7+
* Tested up to WordPress 7.1 and PHP 8.5
* Updated: Bundled Vue framework to 3.5

= 2.1.2 =
* Fixed: Prevent disabling of "optimize tables" button (Thanks Lars! :))

= 2.1.1 =
* Fixed: Added assets that had gone missing

= 2.1.0 =
* Fixed: Many "under the hood" improvements

= 2.0.1 =
* Fixed: Removed unnecessary logging

= 2.0.0 =
* Added: All new and improved design
* Fixed: Multisite support (Deletion of database tables with other prefix now works on multisite)

= 1.2.1 =
* Bug fix

= 1.2.0 =
* Added "limit post revisions" function
* Added "WordPress memory limit" function
* Bug fixes

= 1.1.0 =
* Added "delete auto-drafts" function
* Added "optimize" function
* Added WP-CLI command

= 1.0.4 =
* Added plugin assets and screenshot

= 1.0.3 =
* Minor improvements

= 1.0.2 =
* Updated readme.txt

= 1.0.1 =
* Minor improvements

= 1.0.0 =
* First public release
