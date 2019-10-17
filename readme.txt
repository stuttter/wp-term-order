=== WP Term Order ===
Contributors: johnjamesjacoby, stuttter, ramonfincken
Tags: taxonomy, term, order, term_order
Requires at least: 4.3
Tested up to: 5.2.4
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: http://donate.ramonfincken.com

== Description ==

Sort taxonomy terms, your way. This fork uses the WP Core `term_order` column in the `terms` table, instead of creating a new colum in the `wp_term_taxonomy` table.

WP Term Order allows users to order any visible category, tag, or taxonomy term numerically, providing a customized order for their taxonomies.


== Screenshots ==

1. Drag and drop your categories, tags, and custom taxonomy terms

== Installation ==

Download and install using the built in WordPress plugin installer.

Activate in the "Plugins" area of your admin by clicking the "Activate" link.

No further setup or configuration is necessary, as long as your get_terms code uses 'orderby'    => 'term_order', 

== Frequently Asked Questions ==

= Does this create new database tables? =

No. There are no new database tables with this plugin.

= Does this modify existing database tables? =

No. It uses the WP Core `term_order` colum in the `terms` table

= Where can I get support? =

Github

= Where can I find documentation? =

http://github.com/stuttter/wp-term-order/

== Changelog ==

= 0.1.5 =
* Use Core term_order<br>
* Pre-calculate the next order number<br>
* Add some dev notices

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
