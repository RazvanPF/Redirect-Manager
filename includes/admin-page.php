<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('Redirect_Manager_Functions')) {
    class Redirect_Manager_Functions {
        public function __construct() {
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'save_redirects']);
            add_action('init', [$this, 'handle_redirects']);
            register_activation_hook(__FILE__, [$this, 'create_analytics_table']);
        }

        public function register_settings() {
            register_setting('redirect_manager_settings', 'redirect_manager_redirects');
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
                if (!empty($redirect['from']) && !empty($redirect['to'])) {
                    $filtered_redirects[] = [
                        'from'  => esc_url_raw(trim($redirect['from'])),
                        'to'    => esc_url_raw(trim($redirect['to'])),
                        'type'  => isset($redirect['type']) ? intval($redirect['type']) : 301,
                        'regex' => isset($redirect['regex']) ? 1 : 0, // Save as 1 or 0
                    ];
                }
            }

            update_option('redirect_manager_redirects', $filtered_redirects);
			
			// Add the refresh=1 parameter to the URL to trigger a fast auto-refresh
			wp_redirect(admin_url('options-general.php?page=redirect-manager&tab=general&refresh=1'));
			exit;
        }

        public function handle_redirects() {
            global $wpdb;
            $analytics_table = $wpdb->prefix . 'redirect_manager_analytics';
            $redirects = get_option('redirect_manager_redirects', []);

            if (!is_array($redirects) || empty($redirects)) {
                return;
            }

            $current_url = trim(home_url(add_query_arg([], $_SERVER['REQUEST_URI'])));
            $current_query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';

            foreach ($redirects as $redirect) {
                if (!isset($redirect['from'], $redirect['to'], $redirect['type'], $redirect['regex'])) {
                    continue;
                }

                if ($redirect['regex'] === 1) {
                    if (preg_match('#' . preg_quote($redirect['from'], '#') . '#', $current_url) ||
                        preg_match('#' . preg_quote($redirect['from'], '#') . '#', $current_query)) {
                        $this->log_analytics($analytics_table, $redirect['from'], $redirect['to']);
                        $redirect_to = preg_replace('#' . $redirect['from'] . '#', $redirect['to'], $current_url);
                        wp_redirect($redirect_to, $redirect['type']);
                        exit;
                    }
                } else {
                    if ($redirect['from'] === $current_url || $redirect['from'] === '?' . $current_query) {
                        $this->log_analytics($analytics_table, $redirect['from'], $redirect['to']);
                        wp_redirect($redirect['to'], $redirect['type']);
                        exit;
                    }
                }
            }
        }

        private function log_analytics($table, $from, $to) {
            global $wpdb;

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE redirect_from = %s AND redirect_to = %s", $from, $to));

            if ($row) {
                $wpdb->update(
                    $table,
                    [
                        'hit_count' => $row->hit_count + 1,
                        'last_accessed' => current_time('mysql'),
                    ],
                    ['id' => $row->id]
                );
            } else {
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

    // Add the admin menu
    add_action('admin_menu', function () {
        add_menu_page(
            'Redirect Manager',
            'Redirect Manager',
            'manage_options',
            'redirect-manager',
            'redirect_manager_render_admin_page',
            'dashicons-admin-links',
            99
        );
    });

    function redirect_manager_render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'redirect-manager'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap">
            <h1>Redirect Manager Settings</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=redirect-manager&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="?page=redirect-manager&tab=analytics" class="nav-tab <?php echo $active_tab === 'analytics' ? 'nav-tab-active' : ''; ?>">Analytics</a>
            </h2>
            <?php if ($active_tab === 'general') : ?>
			<!-- IMPORT & EXPORT BUTTONS HERE -->
				<div class="redirect-buttons-general">
					
					<!-- Export Redirects Button -->
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('redirect_manager_export_redirects_nonce', 'redirect_manager_export_redirects_nonce_field'); ?>
						<input type="hidden" name="action" value="redirect_manager_export_redirects">
						<button type="submit" class="export-redirects-button">Export CSV</button>
					</form>

					<!-- Import Redirects Button -->
					<button type="button" class="import-redirects-button" onclick="document.getElementById('import-redirects-file').click()"> Import CSV</button>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: none;">
						<?php wp_nonce_field('redirect_manager_import_redirects_nonce', 'redirect_manager_import_redirects_nonce_field'); ?>
						<input type="hidden" name="action" value="redirect_manager_import_redirects">
						<input type="file" id="import-redirects-file" name="redirects_csv" accept=".csv" style="display: none;" onchange="this.form.submit()">
					</form>
				</div>
				<!-- REDIRECTS TABLE STARTS HERE -->
                <form method="post" action="">
                    <?php wp_nonce_field('save_redirects', 'redirect_manager_nonce'); ?>
                    <table id="redirects-table">
                        <thead>
                            <tr>
                                <th>Redirect From</th>
                                <th>Redirect To</th>
                                <th>Redirect Type</th>
                                <th>Regex</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $redirects = get_option('redirect_manager_redirects', []);
                            if (!empty($redirects)) {
                                foreach ($redirects as $index => $redirect) {
                                    ?>
                                    <tr>
                                        <td><input type="text" name="redirects[<?php echo $index; ?>][from]" value="<?php echo esc_attr($redirect['from']); ?>" /></td>
                                        <td><input type="text" name="redirects[<?php echo $index; ?>][to]" value="<?php echo esc_attr($redirect['to']); ?>" /></td>
                                        <td>
                                            <select name="redirects[<?php echo $index; ?>][type]" class="redirect-type">
                                                <option value="301" <?php selected($redirect['type'], 301); ?>>301</option>
                                                <option value="302" <?php selected($redirect['type'], 302); ?>>302</option>
                                                <option value="307" <?php selected($redirect['type'], 307); ?>>307</option>
                                            </select>
                                        </td>
										<td>
											<label class="toggle-switch">
												<input type="checkbox" name="redirects[<?php echo $index; ?>][regex]" value="1" <?php checked($redirect['regex'], 1); ?> />
												<span class="slider"></span>
											</label>
										</td>
                                        <td><button type="button" class="remove-row">X</button></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
					<div class="button-container">
						<button type="button" id="add-row" class="button add-redirect">+ Add Redirect</button>
						<?php submit_button('Save Changes', 'primary', 'submit', false); ?>
					</div>
                </form>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const table = document.querySelector('#redirects-table tbody');
                        const addRowButton = document.querySelector('#add-row');

                        addRowButton.addEventListener('click', function () {
                            const rowCount = table.rows.length;
                            const newRow = document.createElement('tr');

                            newRow.innerHTML = `
                                <td><input type="text" name="redirects[${rowCount}][from]" value="" /></td>
                                <td><input type="text" name="redirects[${rowCount}][to]" value="" /></td>
                                <td>
                                    <select name="redirects[${rowCount}][type]" class="redirect-type">
                                        <option value="301">301</option>
                                        <option value="302">302</option>
                                        <option value="307">307</option>
                                    </select>
                                </td>
                                <td>
									<label class="toggle-switch">
										<input type="checkbox" name="redirects[${rowCount}][regex]" value="1" />
										<span class="slider"></span>
									</label>
								</td>
                                <td><button type="button" class="remove-row">X</button></td>
                            `;
                            table.appendChild(newRow);
                        });

                        table.addEventListener('click', function (e) {
                            if (e.target.classList.contains('remove-row')) {
                                e.target.closest('tr').remove();
                            }
                        });
                    });
                </script>

            <?php elseif ($active_tab === 'analytics') : ?>
				<?php
					if (isset($_GET['message']) && $_GET['message'] === 'data_cleared') {
						show_notification('Data cleared successfully!', 'red');
					}
				?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('redirect_manager_export_csv_nonce', 'redirect_manager_export_csv_nonce_field'); ?>
					<input type="hidden" name="action" value="redirect_manager_export_csv">
					<div class = "export-analytics">
						<button type="submit" class="export-button">⤵ Export CSV</button>
					</div>
				</form>

                <table class="widefat" id="analyitics-table">
                    <thead id="analytics-table-head">
                        <tr>
                            <th>Redirect From</th>
                            <th>Redirect To</th>
                            <th>Hit Count</th>
                            <th>Last Accessed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        global $wpdb;
                        $analytics_table = $wpdb->prefix . 'redirect_manager_analytics';
                        $analytics_data = $wpdb->get_results("SELECT * FROM $analytics_table");

                        if (!empty($analytics_data)) {
                            $highest_hit = max(array_column($analytics_data, 'hit_count'));
                            foreach ($analytics_data as $row) {
                                $highlight_class = ($row->hit_count == $highest_hit) ? 'top-redirect' : '';
                                echo '<tr class="' . esc_attr($highlight_class) . '">
                                    <td>' . esc_html($row->redirect_from) . '</td>
                                    <td>' . esc_html($row->redirect_to) . '</td>
                                    <td>' . esc_html($row->hit_count) . '</td>
                                    <td>' . esc_html($row->last_accessed) . '</td>
                                </tr>';
                            }
                        } else {
                            echo '<tr><td colspan="4">No analytics data available.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>

				<!-- Add Clear Data Button -->
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="clear-analytics-form">
				<?php wp_nonce_field('redirect_manager_clear_analytics_nonce', 'redirect_manager_clear_analytics_nonce_field'); ?>
				<input type="hidden" name="action" value="redirect_manager_clear_analytics">
				</form>
				<button id="clear-analytics" class="clear-button" style="margin-top: 20px;">Clear Data</button>

				<style>
                    .top-redirect {
                        background-color: #fff3cd;
                        font-weight: bold;
                        border-left: 4px solid #ffc107;
                    }
										.popup {
						display: none;
						position: fixed;
						top: 50%;
						left: 50%;
						transform: translate(-50%, -50%);
						z-index: 9999;
						background: white;
						border: 1px solid #ddd;
						border-radius: 5px;
						box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
						padding: 20px;
						width: 300px;
						text-align: center;
					}

					.popup button {
						margin: 10px 5px;
					}

					.popup-overlay {
						display: none;
						position: fixed;
						top: 0;
						left: 0;
						width: 100%;
						height: 100%;
						background: rgba(0, 0, 0, 0.5);
						z-index: 9998;
					}
                </style>

				<div class="popup-overlay" id="popup-overlay"></div>
				<div class="popup" id="confirmation-popup">
					<p>Are you sure you want to clear all analytics data?</p>
					<button id="confirm-clear" class="button button-primary">Yes</button>
					<button id="cancel-clear" class="button button-secondary">Cancel</button>
				</div>
				<script>
					document.addEventListener("DOMContentLoaded", function () {
						const clearButton = document.getElementById("clear-analytics");
						const popup = document.getElementById("confirmation-popup");
						const overlay = document.getElementById("popup-overlay");
						const confirmClear = document.getElementById("confirm-clear");
						const cancelClear = document.getElementById("cancel-clear");
						const form = document.getElementById("clear-analytics-form");

						if (!form) {
							console.error("Clear analytics form not found!");
							return;
						}

						clearButton.addEventListener("click", function () {
							popup.style.display = "block";
							overlay.style.display = "block";
						});

						cancelClear.addEventListener("click", function () {
							popup.style.display = "none";
							overlay.style.display = "none";
						});

						confirmClear.addEventListener("click", function () {
							form.submit();
						});
					});
						//Additional Refresh for fixing layout
						document.addEventListener("DOMContentLoaded", function () {
							const urlParams = new URLSearchParams(window.location.search);

							if (urlParams.get("refresh") === "1") {
								console.log("🔄 Auto-refreshing after Save Changes...");

								// Remove the refresh=1 parameter to prevent infinite reloads
								urlParams.delete("refresh");
								const newUrl = window.location.pathname + "?" + urlParams.toString();

								// Perform a fast, seamless refresh
								window.history.replaceState(null, "", newUrl);
								location.reload();
							}
						});
				</script>
            <?php endif; ?>
        </div>
        <?php
    }

    new Redirect_Manager_Functions();
}
