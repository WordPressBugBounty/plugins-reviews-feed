<?php

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit();
}


$settings = get_option('sbr_settings', array());
if (! is_array($settings)) {
	$settings = array();
}

$preserve_settings = ! empty($settings['preserve_settings']) && $settings['preserve_settings'];

// Cron events are cleared unconditionally: they are runtime state, not
// settings, and leaving them scheduled after deletion fires handler-less
// events weekly forever.
$cron_keys = array(
	'sbr_cron_additional_batch',
	'sbr_feed_update',
	'sbr_usage_tracking_cron',
	'sbr_smash_usage_tracking_cron',
);
foreach ($cron_keys as $key) {
	wp_clear_scheduled_hook($key);
}

if (! $preserve_settings) {
	// wp_options
	$wp_options_keys = array(
		'sbr_apikeys',
		'sbr_apikeys_limit',
		'sbr_business_cache',
		'sbr_db_version',
		'sbr_settings',
		'sbr_statuses',
		'sbr_usage_tracking_config',
		'sbr_smash_usage_tracking',
		'sbr_smash_usage_tracking_site_token',
		'sbr_smash_usage_tracking_schedule',
		'sbr_smash_usage_events',
		'sbr_smash_usage_active_dates',
		'sbr_smash_usage_session_durations',
	);
	foreach ($wp_options_keys as $key) {
		delete_option($key);
	}

	// user roles
	global $wp_roles;
	$wp_roles->remove_cap('administrator', 'manage_reviews_feed_options');

	// custom tables
	global $wpdb;

	$table_keys = array(
		'sbr_feeds',
		'sbr_feed_caches',
		'sbr_feed_locator',
		'sbr_posts',
		'sbr_reviews_posts',
		'sbr_sources',
	);
	foreach ($table_keys as $key) {
		$table_name = $wpdb->prefix . $key;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
		$wpdb->query("DROP TABLE IF EXISTS $table_name");
	}

	// custom image files
	$upload = wp_upload_dir();
	$folder = trailingslashit($upload['basedir']) . trailingslashit('sbr-feed-images');
	$image_files = glob($folder . '*'); // get all file names
	foreach ((array) $image_files as $file) { // iterate files (glob can return false)
		if (is_file($file)) {
			unlink($file);
		}
	}

	// $wp_filesystem is not initialised during plugin deletion — calling
	// ->delete() on null fatals and aborts the uninstall midway.
	global $wp_filesystem;
	if (! $wp_filesystem && function_exists('WP_Filesystem')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	if ($wp_filesystem) {
		$wp_filesystem->delete($folder, true);
	}
}
