<?php
/**
 * Removes plugin data on uninstall.
 *
 * The plugin stores no options, tables or custom post types: the tag limit
 * lives in constants and filters, and the only database rows it creates are
 * the two update-check transients. On multisite each site keeps its own copy.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'lpt_uninstall_site' ) ) {
	/**
	 * Deletes the cached update check for the current site.
	 *
	 * The names are literals on purpose: uninstall.php runs on its own, before
	 * the plugin classes are loaded.
	 */
	function lpt_uninstall_site(): void {
		delete_transient( 'lpt_github_release' );
		delete_transient( 'lpt_github_release_error' );
	}
}

if ( is_multisite() ) {
	$lpt_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $lpt_sites as $lpt_site_id ) {
		switch_to_blog( (int) $lpt_site_id );
		lpt_uninstall_site();
		restore_current_blog();
	}
} else {
	lpt_uninstall_site();
}
