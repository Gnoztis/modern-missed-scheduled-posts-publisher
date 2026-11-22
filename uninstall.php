<?php
/**
 * Removes plugin data on uninstall.
 *
 * Only the plugin's own options, update-check transients and cron hook are
 * deleted; the shared update_plugins transient and core's publish_future_post
 * events are left alone, because other plugins and scheduled posts rely on
 * them. On multisite every site stores its own copy, so each site is cleaned
 * in turn.
 *
 * @package ModernMSPP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'modern_mspp_uninstall_site' ) ) {
	/**
	 * Deletes the plugin data of the current site.
	 *
	 * The names are literals on purpose: uninstall.php runs on its own,
	 * before the plugin code is loaded.
	 */
	function modern_mspp_uninstall_site() {
		delete_option( 'modern_mspp_last_run' );
		delete_option( 'modern_mspp_review_removed' );
		delete_option( 'modern_mspp_review_delayed' );

		// Update-check cache created by src/Updater.php.
		delete_transient( 'modern_mspp_github_release' );
		delete_transient( 'modern_mspp_github_release_error' );

		// 1.1.x does not schedule a cron event; clear the namespaced hook in
		// case a custom build or an older version left one behind.
		wp_clear_scheduled_hook( 'modern_mspp_publish_missed_posts' );
	}
}

if ( is_multisite() ) {
	$modern_mspp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $modern_mspp_sites as $modern_mspp_site_id ) {
		switch_to_blog( (int) $modern_mspp_site_id );
		modern_mspp_uninstall_site();
		restore_current_blog();
	}
} else {
	modern_mspp_uninstall_site();
}
