<?php
/*
Plugin Name: Redirect Manager
Plugin URI: https://yourwebsite.com/
Description: Lightweight redirect engine. Supports exact and regex-based redirects, query strings, custom redirect types, CSV import/export, and built-in analytics with hit tracking. No .htaccess edits required.
Version: 1.4
Author: WEB RUNNER
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

//CHECK LICENSE ACTIVE
function is_redirect_manager_license_active() {
    return get_transient('redirect_manager_license_status') === 'valid';
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';
// Force license check before admin-page loads
redirect_manager_check_license_status();
require_once plugin_dir_path(__FILE__) . 'includes/redirect-functions.php';

// Load the EDD Software Licensing Updater
if (!class_exists('EDD_SL_Plugin_Updater')) {
    require_once plugin_dir_path(__FILE__) . 'includes/EDD_SL_Plugin_Updater.php';
}

// LC + CACHE 
add_action('admin_init', 'redirect_manager_check_license_status');

function redirect_manager_check_license_status() {
    if (!defined('REDIRECT_MANAGER_LICENSE_ACTIVE')) {
        $license_key = get_option('redirect_manager_license_key', '');
        $status = get_transient('redirect_manager_license_status');

        if ($status === false && !empty($license_key)) {
            $response = wp_remote_get(add_query_arg([
                'edd_action' => 'check_license',
                'license'    => $license_key,
                'item_name'  => urlencode('Redirect Manager'),
                'url'        => home_url()
            ], 'https://web-runner.net'));

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response));
                if (isset($body->license)) {
                    $status = ($body->license === 'valid') ? 'valid' : 'invalid';
                    set_transient('redirect_manager_license_status', $status, DAY_IN_SECONDS);
                }
            }
        }

        define('REDIRECT_MANAGER_LICENSE_ACTIVE', $status === 'valid');
    }
}


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

add_action('admin_enqueue_scripts', function () {
    wp_localize_script('jquery', 'ajaxurl', admin_url('admin-ajax.php'));
});

// Register license key setting
add_action('admin_init', function () {
    register_setting('redirect_manager_settings', 'redirect_manager_license_key');
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

//Export as CSV ANALYTICS
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
    $analytics_data = $wpdb->get_results("SELECT * FROM $analytics_table", ARRAY_A);

    if (!empty($analytics_data)) {
        // Clean output buffer
        if (ob_get_length()) {
            ob_end_clean();
        }

        // Set headers for CSV download with UTF-8 encoding
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="redirect_analytics.csv"');
        header("Pragma: no-cache");
        header("Expires: 0");

        // Open output stream
        $output = fopen('php://output', 'w');

        // Set delimiter (force semicolon for Excel compatibility)
        $delimiter = ";";

        // Add UTF-8 BOM to prevent Excel encoding issues
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write CSV Headers Properly
        fputcsv($output, ['Redirect From', 'Redirect To', 'Hit Count', 'Last Accessed'], $delimiter);

        // Write Each Row Properly into Separate Columns
        foreach ($analytics_data as $row) {
            fputcsv($output, [
                stripslashes($row['redirect_from']),
                stripslashes($row['redirect_to']),
                (int) $row['hit_count'], // Ensure hit count is an integer
                stripslashes($row['last_accessed']),
            ], $delimiter);
        }

        fclose($output);
        exit;
    } else {
        wp_die('No analytics data available for export.');
    }
});

// EXPORT CSV REDIRECTS
add_action('admin_post_redirect_manager_export_redirects', function () {
    if (!isset($_POST['redirect_manager_export_redirects_nonce_field']) || 
        !wp_verify_nonce($_POST['redirect_manager_export_redirects_nonce_field'], 'redirect_manager_export_redirects_nonce')) {
        wp_die('Invalid nonce.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to perform this action.');
    }

    global $wpdb;
    $redirects = get_option('redirect_manager_redirects', []);

    if (!empty($redirects)) {
        // Clean output buffer
        if (ob_get_length()) {
            ob_end_clean();
        }

        // Set headers for CSV download with UTF-8 encoding
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="redirects_list.csv"');
        header("Pragma: no-cache");
        header("Expires: 0");

        // Open output stream
        $output = fopen('php://output', 'w');

        // Set delimiter (semicolon for Excel compatibility)
        $delimiter = ";";

        // Add UTF-8 BOM to prevent Excel encoding issues
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write CSV Headers
        fputcsv($output, ['Redirect From', 'Redirect To', 'Redirect Type', 'Regex'], $delimiter);

        // Write Each Redirect Entry as a Row
        foreach ($redirects as $redirect) {
            fputcsv($output, [
                stripslashes($redirect['from']),
                stripslashes($redirect['to']),
                (int) $redirect['type'], // Ensure redirect type is an integer (301, 302, etc.)
                $redirect['regex'] ? 'Yes' : 'No', // Convert regex to human-readable "Yes" or "No"
            ], $delimiter);
        }

        fclose($output);
        exit;
    } else {
        wp_die('No redirects available for export.');
    }
});

//IMPORT REDIRECTS
add_action('admin_post_redirect_manager_import_redirects', function () {
    if (!isset($_POST['redirect_manager_import_redirects_nonce_field']) || 
        !wp_verify_nonce($_POST['redirect_manager_import_redirects_nonce_field'], 'redirect_manager_import_redirects_nonce')) {
        wp_die('Invalid nonce.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to perform this action.');
    }

    // Check if a file was uploaded
    if (!isset($_FILES['redirects_csv']) || $_FILES['redirects_csv']['error'] !== UPLOAD_ERR_OK) {
        wp_die('File upload failed. Please try again.');
    }

    $file = $_FILES['redirects_csv']['tmp_name'];

    // Open the CSV file
    if (($handle = fopen($file, 'r')) !== false) {
        $redirects = [];
        $delimiter = ";"; // Ensure the same delimiter as in the export

        // Skip the header row
        fgetcsv($handle, 1000, $delimiter);

        // Read each row
        while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
            if (count($data) < 4) continue; // Skip incomplete rows

            $redirects[] = [
                'from'  => esc_url_raw(trim($data[0])),
                'to'    => esc_url_raw(trim($data[1])),
                'type'  => in_array((int) $data[2], [301, 302, 307]) ? (int) $data[2] : 301, // Default to 301 if invalid
                'regex' => strtolower(trim($data[3])) === 'yes' ? 1 : 0, // Convert "Yes"/"No" to 1/0
            ];
        }
        fclose($handle);

        // Save to WordPress options
        if (!empty($redirects)) {
            update_option('redirect_manager_redirects', $redirects);
        }

        // Redirect with success flag
        wp_redirect(admin_url('options-general.php?page=redirect-manager&tab=general&message=imported'));
        exit;
    } else {
        // Redirect with error flag
        wp_redirect(admin_url('options-general.php?page=redirect-manager&tab=general&import_status=invalid_csv'));
        exit;
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

//Hides other WP or plugin notifications from redirect manager admin page
add_action('admin_head', function () {
    if (isset($_GET['page']) && $_GET['page'] === 'redirect-manager') {
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
    }
});

// Add Redirect Manager to the WP Admin Sidebar with Logo
function rm_add_admin_menu() {
    add_menu_page(
        'Redirect Manager',                       // Page Title
        'Redirect Manager',                       // Menu Title
        'manage_options',                         // Capability
        'redirect-manager',                       // Slug
        'redirect_manager_render_admin_page',     // Callback Function
        plugins_url('assets/rmlogo.png', __FILE__), // Icon Path
        81                                        // Position (just under Settings)
    );

    // Custom icon CSS
    add_action('admin_head', function () {
        echo '<style>
            #toplevel_page_redirect-manager .wp-menu-image img {
                width: 26px !important;
                height: 26px !important;
                margin-top: -5px !important;
            }
        </style>';
    });
}
add_action('admin_menu', 'rm_add_admin_menu');

// LICENSE INIT
add_action('wp_ajax_redirect_manager_activate_license', 'redirect_manager_activate_license');
function redirect_manager_activate_license() {
    check_ajax_referer('redirect_manager_license_nonce', '_ajax_nonce');

    $license_key = sanitize_text_field($_POST['license_key']);
    $store_url   = 'https://web-runner.net';
    $item_name   = urlencode('Redirect Manager');

    $api_params = [
        'edd_action' => 'activate_license',
        'license'    => $license_key,
        'item_name'  => $item_name,
        'url'        => home_url()
    ];

    $response = wp_remote_get(add_query_arg($api_params, $store_url));

    if (is_wp_error($response)) {
        wp_send_json(['success' => false, 'data' => ['message' => '❌ Connection failed.']]);
    }

    $license_data = json_decode(wp_remote_retrieve_body($response));

    if ($license_data && $license_data->success) {
        update_option('redirect_manager_license_key', $license_key);
        update_option('redirect_manager_license_status', 'valid');
        set_transient('redirect_manager_license_status', 'valid', DAY_IN_SECONDS); // 👈 THIS IS CRITICAL

        wp_send_json([
            'success' => true,
            'data' => [
                'message' => '✅ License activated and saved.'
            ]
        ]);
    } else {
        $error_msg = isset($license_data->error) ? ucfirst(str_replace('_', ' ', $license_data->error)) : 'Invalid license.';
        wp_send_json([
            'success' => false,
            'data' => [
                'message' => '❌ Activation failed: ' . esc_html($error_msg)
            ]
        ]);
    }
}
//Deactivation hook
add_action('wp_ajax_redirect_manager_deactivate_license', function () {
  check_ajax_referer('redirect_manager_license_nonce');

  $license_key = sanitize_text_field($_POST['license_key']);
  $api_params = [
    'edd_action' => 'deactivate_license',
    'license'    => $license_key,
    'item_name'  => urlencode('Redirect Manager'),
    'url'        => home_url()
  ];

  $response = wp_remote_get(add_query_arg($api_params, 'https://web-runner.net'));

  if (is_wp_error($response)) {
    wp_send_json(['success' => false, 'message' => 'Error connecting to server.']);
  }

  $license_data = json_decode(wp_remote_retrieve_body($response));

  if ($license_data && $license_data->license === 'deactivated') {
    delete_option('redirect_manager_license_key');
    delete_transient('redirect_manager_license_status');
    wp_send_json(['success' => true, 'message' => '✅ License deactivated.']);
  } else {
    wp_send_json(['success' => false, 'message' => '❌ Deactivation failed.']);
  }
});


add_action('admin_init', function() {
    // Make sure the updater class exists
    if (!class_exists('EDD_SL_Plugin_Updater')) {
        return;
    }

    $license_key = trim(get_option('redirect_manager_license_key')); // pull license key

    $edd_updater = new EDD_SL_Plugin_Updater('https://web-runner.net', __FILE__, array(
        'version'   => '1.4', // match your current version in plugin header
        'license'   => $license_key,
        'item_name' => 'Redirect Manager', // Must match EXACT EDD product name
        'author'    => 'Web Runner', // Nice touch
        'url'       => home_url(), // Optional but clean
    ));
});