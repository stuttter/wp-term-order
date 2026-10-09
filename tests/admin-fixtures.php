<?php
/**
 * Administration helpers for script localization tests.
 *
 * @package WP_Term_OrderTests
 */

/**
 * Return the current screen fixture.
 *
 * @return mixed Current screen.
 */
function get_current_screen() {
	return wpto_test_call( __FUNCTION__, array() );
}

/**
 * Return a stable nonce for localized script data.
 *
 * @return string Test nonce.
 */
function wp_create_nonce() {
	return 'test-nonce';
}

/**
 * Record localized script data.
 *
 * @param mixed ...$arguments Localization arguments.
 * @return mixed Recorded call result.
 */
function wp_localize_script( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}
