<?php
// If uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

global $wpdb;

$athenic_api_base_url = 'https://api.app.athenic.com';
$athenic_table_name = $wpdb->prefix . 'athenic';
$athenic_stored_connection_id = $wpdb->get_var("SELECT connection_id FROM $athenic_table_name LIMIT 1");
$athenic_site_url = get_site_url();
$athenic_api_url = $athenic_api_base_url . '/api/woo-commerce-remove-connection';
$body = array(
    'backend_url' => $athenic_site_url,
    'connection_id' => $athenic_stored_connection_id
);
// Unschedules the event
wp_clear_scheduled_hook('athenic_data_push_schedule');

$response = wp_remote_post($athenic_api_url, array(
    'method' => 'POST',
    'body' => wp_json_encode($body),
));

if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    error_log("Error removing connection ID: $error_message");
} 

// Delete the 'athenic' table
$wpdb->query("DROP TABLE IF EXISTS $athenic_table_name");
