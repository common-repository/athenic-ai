<?php
/*
 * Plugin Name: Athenic AI: Advanced E-Commerce Analytics and Customer Engagement
 * Description: The power of Athenic AI integrated seamlessly with your WooCommerce store.
 * Version: 1.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI: https://athenic.com
 */


$athenic_db_version = '1.1';
$athenic_base_url = 'https://api.app.athenic.com';
$athenic_application_url = 'https://app.athenic.com';

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function athenic_initialize_app() {
    global $wpdb;
    global $athenic_db_version;
    // Fire immediately to initialize and then schedule subsequent data pushes to Athenic
    // Schedule daily data pushes to Athenic if not already scheduled
    if (!wp_next_scheduled('athenic_data_push_schedule')) {
        wp_schedule_event(time(), 'daily', 'athenic_data_push_schedule');
    }

    $table_name = $wpdb->prefix . 'athenic';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        connection_id varchar(55) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    $connection_id = uniqid();
    $wpdb->insert($table_name, array('connection_id' => $connection_id));

    add_option('athenic_db_version', $athenic_db_version);
}

add_action('athenic_data_push_schedule', 'athenic_daily_data_push');


function athenic_admin_page_styles() {
    echo '<style>
        #adminmenu .toplevel_page_athenic-ai img {
            max-height: 16px;
            width: auto;
        }
        .athenic-button-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 90vh;
            padding: 20px 0;
        } 
    </style>';
}
add_action('admin_head', 'athenic_admin_page_styles');


function athenic_add_admin_page() {
    add_menu_page('Athenic AI', 'Athenic AI', 'manage_options', 'athenic-ai', '', plugins_url('favicon-gold-white@256@2x.svg', __FILE__), 58.5);
}

add_action('admin_menu', 'athenic_add_admin_page');

function athenic_admin_page_scripts() {
    global $athenic_application_url;
    global $wpdb;

    $site_url = get_site_url();
    $plugin_version = '1.4'; // Used to disable outdated plugin

    $url = add_query_arg(
        array(
            'backend_url' => $site_url,
        ),
        $athenic_application_url,
    );
    $url = esc_url($url);

    $url = add_query_arg(
        array(
            'plugin_version' => $plugin_version,
        ),
        $url,
    );

    echo '<script type="text/javascript">
        jQuery(document).ready(function($) {
            $("a.toplevel_page_athenic-ai").attr("href", "' . $url . '");
            $("a.toplevel_page_athenic-ai").attr("target", "_blank");
        });
    </script>';
}

add_action('admin_footer', 'athenic_admin_page_scripts');



function athenic_get_first_fire_time($schedule) {
    switch ($schedule) {
        case 'hourly':
            return time() + HOUR_IN_SECONDS;
        case 'twicedaily':
            return time() + 12 * HOUR_IN_SECONDS;
        case 'daily':
        default:
            return time() + DAY_IN_SECONDS;
    }
}

function athenic_daily_data_push() {
    global $athenic_base_url;
    global $wpdb;

    $site_url = get_site_url();
    $api_url = $athenic_base_url . '/api/woo-commerce-pushes';
    $store_name = get_bloginfo('name'); // Get the store name
    $pagination_size = 10000;

    $table_name = $wpdb->prefix . 'athenic';
    $stored_connection_id = $wpdb->get_var("SELECT connection_id FROM $table_name LIMIT 1");

    $queries = array(
        'wc_customer_lookup' => array(
            'query' => "SELECT customer_id, first_name, last_name, email, date_last_active, date_registered, country, postcode, city, state FROM " . $wpdb->prefix . "wc_customer_lookup LIMIT %d, %d",
            'columns' => ['customer_id', 'first_name', 'last_name', 'email', 'date_last_active', 'date_registered', 'country', 'postcode', 'city', 'state']
        ),
        'posts' => array(
            'query' => "SELECT ID, post_title, post_type, post_parent FROM " . $wpdb->prefix . "posts LIMIT %d, %d",
            'columns' => ['ID', 'post_title', 'post_type', 'post_parent']
        ),
        'wc_product_meta_lookup' => array(
            'query' => "SELECT product_id, min_price, onsale, stock_quantity, stock_status, rating_count, average_rating, total_sales, tax_status, tax_class FROM " . $wpdb->prefix . "wc_product_meta_lookup LIMIT %d, %d",
            'columns' => ['product_id', 'min_price', 'onsale', 'stock_quantity', 'stock_status', 'rating_count', 'average_rating', 'total_sales', 'tax_status', 'tax_class']
        ),
        'wc_order_product_lookup' => array(
            'query' => "SELECT order_id, product_id, variation_id, customer_id, product_qty, product_net_revenue, product_gross_revenue, coupon_amount, tax_amount, shipping_amount, shipping_tax_amount FROM " . $wpdb->prefix . "wc_order_product_lookup LIMIT %d, %d",
            'columns' => ['order_id', 'product_id', 'variation_id', 'customer_id', 'product_qty', 'product_net_revenue', 'product_gross_revenue', 'coupon_amount', 'tax_amount', 'shipping_amount', 'shipping_tax_amount']
        ),
        'wc_order_stats' => array(
            'query' => "SELECT order_id, date_created, num_items_sold, total_sales, tax_total, shipping_total, net_total, returning_customer, status, customer_id, parent_id FROM " . $wpdb->prefix . "wc_order_stats LIMIT %d, %d",
            'columns' => ['order_id', 'date_created', 'num_items_sold', 'total_sales', 'tax_total', 'shipping_total', 'net_total', 'returning_customer', 'status', 'customer_id', 'parent_id']
        ),
        'woocommerce_sessions' => array(
            'query' => "SELECT session_id, session_key, session_value, session_expiry FROM " . $wpdb->prefix . "woocommerce_sessions LIMIT %d, %d",
            'columns' => ['session_id', 'session_key', 'session_value', 'session_expiry']
        ),
    );

    $timestamp = time();
    $timestamp_string = gmdate('Y-m-d H:i:s', $timestamp);

    foreach ($queries as $table => $data) {
        $page = 1;
        $table_complete = false;
        do {
            try {
                $offset = ($page - 1) * $pagination_size;
                $prepared_query = $wpdb->prepare($data['query'], $offset, $pagination_size);
                $results = $wpdb->get_results($prepared_query);
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
            if (count($results) < $pagination_size) {
                $table_complete = true;
            }

            $is_last_update = $table === end(array_keys($queries)) && $table_complete;

            $push_body = array(
                'is_last_update' => $is_last_update,
                'table_name' => $table,
                'timestamp' => $timestamp_string,
                'backend_url' => $site_url,
                'data' => $results,
                'connection_id' => $stored_connection_id,
                'columns' => $data['columns'], // Add column names to the body
                'store_name' => $store_name
            );

            $push_response = wp_remote_post($api_url, array(
                'method' => 'POST',
                'body' => wp_json_encode($push_body),
            ));

            if (is_wp_error($push_response)) {
                $error_message = $push_response->get_error_message();
                error_log("Something went wrong: $error_message");
            } else {
                if ($is_last_update) {
                    $response_body = json_decode(wp_remote_retrieve_body($push_response), true);
                    $schedule = $response_body['schedule'];
                    $next_scheduled_time = wp_next_scheduled('athenic_data_push_schedule');
                    if (!in_array($schedule, ['hourly', 'twicedaily', 'daily'])) {
                        $schedule = 'daily';
                    }
                    $new_first_fire_time = athenic_get_first_fire_time($schedule);
                    if ($next_scheduled_time != $new_first_fire_time) {
                        wp_unschedule_event($next_scheduled_time, 'athenic_data_push_schedule');
                        wp_schedule_event($new_first_fire_time, $schedule, 'athenic_data_push_schedule');
                    }
                }
            }

            $page++;
        } while (!$table_complete);
    }
}

register_activation_hook(__FILE__, 'athenic_initialize_app');


