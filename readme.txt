=== WP Term Order ===
Contributors:      johnjamesjacoby, stuttter
Tags:              taxonomy, term, order
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.3
Requires PHP:      7.0
Tested up to:      7.0
Stable tag:        2.2.0

== Description ==

Sort taxonomy terms, your way.

WP Term Order allows users to order any visible category, tag, or taxonomy term numerically, providing a customized order for their taxonomies.

= Also checkout =

* [WP Chosen](https://wordpress.org/plugins/wp-chosen/ "Make long, unwieldy select boxes much more user-friendly.")
* [WP Term Authors](https://wordpress.org/plugins/wp-term-authors/ "Authors for categories, tags, and other taxonomy terms.")
* [WP Term Colors](https://wordpress.org/plugins/wp-term-colors/ "Pretty colors for categories, tags, and other taxonomy terms.")
* [WP Term Icons](https://wordpress.org/plugins/wp-term-icons/ "Pretty icons for categories, tags, and other taxonomy terms.")
* [WP Term Visibility](https://wordpress.org/plugins/wp-term-visibility/ "Visibilities for categories, tags, and other taxonomy terms.")
* [WP User Groups](https://wordpress.org/plugins/wp-user-groups/ "Group users together with taxonomies & terms.")
* [WP User Activity](https://wordpress.org/plugins/wp-user-activity/ "The best way to log activity in WordPress.")
* [WP User Avatars](https://wordpress.org/plugins/wp-user-avatars/ "Allow users to upload avatars or choose them from your media library.")
* [WP User Profiles](https://wordpress.org/plugins/wp-user-profiles/ "A sophisticated way to edit users in WordPress.")

== Screenshots ==

1. Drag and drop your categories, tags, and custom taxonomy terms

== Installation ==

Download and install using the built in WordPress plugin installer.

Activate in the "Plugins" area of your admin by clicking the "Activate" link.

No further setup or configuration is necessary.

== Frequently Asked Questions ==

= Does this create new database tables? =

No. There are no new database tables with this plugin.

= Does this modify existing database tables? =

Yes. The `wp_term_taxonomy` table is altered, and an `order` column is added.

= Where can I get support? =

The WordPress support forums: https://wordpress.org/support/plugin/wp-term-order/

= Where can I find documentation? =

http://github.com/stuttter/wp-term-order/

== Changelog ==

= 2.2.0 =
* Fix CSRF. Thank you Nabil Irawan.

= 2.1.0 =
* PHP8 support

= 2.0.0 =
* Migrate existing order data to term meta on upgrade
* Fix order override when querying for terms
* Fix terms sometimes not saving their order
* Add filter for taxonomy overrides
* Add filter for database strategy
* Move init out of __construct
* "Order" is now a default hidden column
* Remove static designation from certain method calls
* More accurate cache usage & cleaning

= 1.0.0 =
* Do action when order is updated

= 0.1.5 =
* Version bumps and updated readme

= 0.1.4 =
* Fix order saving in non-fancy mode

= 0.1.3 =
* Add filter to target specific taxonomies

= 0.1.2 =
* Normalize textdomains

= 0.1.1 =
* Prevent move on "No items" table row

= 0.1.0 =
* Initial release
