<?php
/**
 * WP Term Order uninstall function fixtures.
 *
 * @package WP_Term_Order
 */

/**
 * Record metadata deletion.
 *
 * @param mixed ...$arguments Metadata deletion arguments.
 * @return mixed
 */
function delete_metadata( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

/**
 * Record option deletion.
 *
 * @param mixed ...$arguments Option deletion arguments.
 * @return mixed
 */
function delete_option( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

/** Return the configured multisite state. */
function is_multisite() {
	return (bool) ( wpto_test_call( __FUNCTION__, array() ) ?? false );
}

/**
 * Return configured site IDs.
 *
 * @param mixed ...$arguments Site-query arguments.
 * @return mixed
 */
function get_sites( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments ) ?? array();
}

/**
 * Record a site switch.
 *
 * @param mixed ...$arguments Site-switch arguments.
 * @return mixed
 */
function switch_to_blog( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

/**
 * Record restoration of the current site.
 *
 * @param mixed ...$arguments Site-restoration arguments.
 * @return mixed
 */
function restore_current_blog( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}
