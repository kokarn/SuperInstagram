=== SuperInstagram ===
Contributors: kokarn, jwilsson
Donate link: http://example.com/
Tags: instagram
Requires at least: 3.7
Tested up to: 3.9.1
Stable tag: 0.3.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Simple implementation of Instagram's Real-time Photo Updates.

== Description ==


== Installation ==

1. Create a Instagram client at http://instagram.com/developer/clients/manage/.
2. Upload `plugin-name.php` to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Instagram > Settings and enter your client details.

== Changelog ==

= 0.3 =
Changed name of meta location field.
Video URLs are now saved.

= 0.2.4 =
Changed post dates from server to Instagram.
Changed several file_get_contents() to WordPress HTTP API.
Added for-attributes to labels in the admin area.
Bug fixes.

= 0.2.3 =
Added support for user subscriptions.
Rewrote large parts of the plugin to use WordPress HTTP API instead of cURL.

= 0.1 =
Initial release
