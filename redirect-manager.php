<?php
/*
Plugin Name: Redirect Manager
Description: Redirect URLs with or without query parameters, choose redirect types, check analytics, and more.
Version: 1.0
Author: Razvan Faraon
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/redirect-functions.php';

add_action('admin_enqueue_scripts', function ($hook_suffix) {
    // Load CSS for all admin pages
    if (strpos($hook_suffix, 'redirect-manager') !== false) {
        wp_enqueue_style(
            'redirect-manager-styles',
            plugin_dir_url(__FILE__) . 'assets/style.css',
            [],
            '1.0',
            'all'
        );
    }
});

// Add settings link in the plugins list
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="options-general.php?page=redirect-manager">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Create database table for analytics during plugin activation
register_activation_hook(__FILE__, 'redirect_manager_create_analytics_table');

function redirect_manager_create_analytics_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'redirect_manager_analytics';
    $charset_collate = $wpdb->get_charset_collate();

    // SQL to create the analytics table
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        redirect_from TEXT NOT NULL,
        redirect_to TEXT NOT NULL,
        hit_count BIGINT(20) DEFAULT 0,
        last_accessed DATETIME DEFAULT NULL
    ) $charset_collate;";

    // Include WordPress upgrade file for dbDelta function
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_action('admin_post_redirect_manager_export_csv', function () {
    if (!isset($_POST['redirect_manager_export_csv_nonce_field']) || 
        !wp_verify_nonce($_POST['redirect_manager_export_csv_nonce_field'], 'redirect_manager_export_csv_nonce')) {
        wp_die('Invalid nonce.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to perform this action.');
    }

    global $wpdb;
    $analytics_table = $wpdb->prefix . 'redirect_manager_analytics';
    $analytics_data = $wpdb->get_results("SELECT * FROM $analytics_table");

    if (!empty($analytics_data)) {
        // Clean output buffer
        if (ob_get_length()) {
            ob_end_clean();
        }

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="redirect_analytics.csv"');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add CSV headers
        fputcsv($output, ['Redirect From', 'Redirect To', 'Hit Count', 'Last Accessed']);

        // Add rows to the CSV
        foreach ($analytics_data as $data) {
            fputcsv($output, [
                $data->redirect_from,
                $data->redirect_to,
                $data->hit_count,
                $data->last_accessed,
            ]);
        }

        fclose($output);

        // End script execution to avoid WordPress rendering extra output
        exit;
    } else {
        wp_die('No analytics data available for export.');
    }
});

	// Clear analytics table 
	add_action('admin_post_redirect_manager_clear_analytics', function () {
    if (!isset($_POST['redirect_manager_clear_analytics_nonce_field']) || 
        !wp_verify_nonce($_POST['redirect_manager_clear_analytics_nonce_field'], 'redirect_manager_clear_analytics_nonce')) {
        wp_die('Invalid nonce.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to perform this action.');
    }

    global $wpdb;
    $analytics_table = $wpdb->prefix . 'redirect_manager_analytics';
    $wpdb->query("TRUNCATE TABLE $analytics_table");

    // Redirect back to the analytics page with a query parameter for notification
    wp_redirect(admin_url('options-general.php?page=redirect-manager&tab=analytics&message=data_cleared'));
    exit;
});

// CUSTOM NOTIFICATION SYSTEM
function show_notification($message, $color = "blue") {
    echo "<div id='notification-box' style='
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background-color: {$color};
        color: white;
        padding: 10px 20px;
        border-radius: 5px;
        border: 1px solid white;
        z-index: 1000;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.8);
        opacity: 0;
        transition: opacity 0.5s ease, transform 0.5s ease;
    '>
        {$message}
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const notificationBox = document.getElementById('notification-box');
            if (notificationBox) {
                // Show the notification
                setTimeout(() => {
                    notificationBox.style.opacity = '1';
                    notificationBox.style.transform = 'translate(-50%, -45%)'; // Slight slide up
                }, 10);
                
                // Hide and remove it after 2 seconds
                setTimeout(() => {
                    notificationBox.style.opacity = '0';
                    notificationBox.style.transform = 'translate(-50%, -55%)'; // Slide down
                    setTimeout(() => notificationBox.remove(), 500);
                }, 2000);
            }
        });
    </script>";
}
