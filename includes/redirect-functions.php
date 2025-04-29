<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('Redirect_Manager_Functions')) {
    class Redirect_Manager_Functions {
        public function __construct() {
            add_action('admin_init', [$this, 'save_redirects']);
            add_action('init', [$this, 'handle_redirects']);
            register_activation_hook(__FILE__, [$this, 'create_analytics_table']);
        }

        public function save_redirects() {
            if (!isset($_POST['redirect_manager_nonce']) || !wp_verify_nonce($_POST['redirect_manager_nonce'], 'save_redirects')) {
                return;
            }

            if (!current_user_can('manage_options')) {
                return;
            }

            $redirects = isset($_POST['redirects']) ? array_values($_POST['redirects']) : [];
            $filtered_redirects = [];

            foreach ($redirects as $redirect) {
                // Save only valid rows with "from", "to", "type", and optionally "regex"
                if (!empty($redirect['from']) && !empty($redirect['to']) && !empty($redirect['type'])) {
                    if (!empty($redirect['regex']) && @preg_match('#' . $redirect['from'] . '#', '') === false) {
                        continue; // Skip invalid regex
                    }

                    $filtered_redirects[] = [
                        'from'  => esc_url_raw(trim($redirect['from'])),
                        'to'    => esc_url_raw(trim($redirect['to'])),
                        'type'  => intval($redirect['type']),
                        'regex' => isset($redirect['regex']) ? 1 : 0, // Save as 1 or 0
                    ];
                }
            }

            if (!empty($filtered_redirects)) {
                update_option('redirect_manager_redirects', $filtered_redirects);
            } else {
                delete_option('redirect_manager_redirects');
            }
			wp_redirect(admin_url('options-general.php?page=redirect-manager&tab=general&message=saved'));
			exit;
        }

        public function handle_redirects() {
            global $wpdb;
            $analytics_table = $wpdb->prefix . 'redirect_manager_analytics'; // Analytics table name
            $redirects = get_option('redirect_manager_redirects', []);

            if (!is_array($redirects) || empty($redirects)) {
                return;
            }

            foreach ($redirects as $redirect) {
                if (!isset($redirect['from'], $redirect['to'], $redirect['type'], $redirect['regex'])) {
                    continue;
                }

                $current_url = trim(home_url(add_query_arg([], $_SERVER['REQUEST_URI'])));

                if ($redirect['regex'] === 1) {
                    // Use regex matching
                    if (preg_match('#' . $redirect['from'] . '#', $current_url)) {
                        $this->log_analytics($analytics_table, $redirect['from'], $redirect['to']);
                        $redirect_to = preg_replace('#' . $redirect['from'] . '#', $redirect['to'], $current_url);
                        wp_redirect($redirect_to, $redirect['type']);
                        exit;
                    }
                } else {
                    // Exact match
                    if ($redirect['from'] === $current_url) {
                        $this->log_analytics($analytics_table, $redirect['from'], $redirect['to']);
                        wp_redirect($redirect['to'], $redirect['type']);
                        exit;
                    }
                }
            }
        }

        private function log_analytics($table, $from, $to) {
            global $wpdb;

            // Check if analytics for this redirect already exists
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE redirect_from = %s AND redirect_to = %s", $from, $to));

            if ($row) {
                // Update existing record
                $wpdb->update(
                    $table,
                    [
                        'hit_count' => $row->hit_count + 1,
                        'last_accessed' => current_time('mysql'),
                    ],
                    ['id' => $row->id]
                );
            } else {
                // Insert new record
                $wpdb->insert(
                    $table,
                    [
                        'redirect_from' => $from,
                        'redirect_to' => $to,
                        'hit_count' => 1,
                        'last_accessed' => current_time('mysql'),
                    ]
                );
            }
        }

        public function create_analytics_table() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'redirect_manager_analytics';
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE $table_name (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                redirect_from TEXT NOT NULL,
                redirect_to TEXT NOT NULL,
                hit_count BIGINT(20) DEFAULT 0 NOT NULL,
                last_accessed DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
	
    new Redirect_Manager_Functions();
}