# WP Term Order

Sort taxonomy terms. This is an adapted fork from JJJ by RamonFincken. This fork uses the WP Core `term_order` column in the `terms` table, instead of creating a new colum in the `wp_term_taxonomy` table.

WP Term Order allows users to order any visible category, tag, or taxonomy term numerically, providing a customized order for their taxonomy terms.

# Installation

* Download and install using the built in WordPress plugin installer.
* Activate in the "Plugins" area of your admin by clicking the "Activate" link.
* No further setup or configuration is necessary.

# Demo

![Term Reorder](screenshot-1.gif)

# FAQ

### Does this create new database tables?

No. There are no new database tables with this plugin.

### Does this modify existing database tables?

No. It uses the WP Core `term_order` colum in the `terms` table

### Can I query and sort by `order`?

Yes. Use it like:

```
$terms = get_terms( 'category', array(
	'depth'      => 1,
	'number'     => 100,
	'parent'     => 0,
	'orderby'    => 'term_order', // <--- Looky looky!
	'order'      => 'ASC',
	'hide_empty' => false,
) );
```

### Where can I get support?

Github

### Can I contribute?

Yes, please! The number of users needing more robust taxonomy term ordering is growing fast. Having an easy-to-use UI and powerful set of functions is critical to managing complex WordPress installations. If this is your thing, please help us out!
