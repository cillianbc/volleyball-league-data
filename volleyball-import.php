<?php
/**
 * Plugin Name: Volleyball League Import
 * Description: Custom database table and REST API for volleyball team data
 * Version: 1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class VolleyballImport {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'volleyball_teams';

        // Hook into WordPress
        add_action('rest_api_init', array($this, 'register_routes'));
        register_activation_hook(__FILE__, array($this, 'create_table'));
        add_action('admin_menu', array($this, 'add_admin_page'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('wp_ajax_volleyball_manual_import', array($this, 'ajax_manual_import'));
        add_action('wp', array($this, 'schedule_cron'));
        add_action('volleyball_weekly_import', array($this, 'perform_cron_import'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron'));
        add_shortcode('volleyball_table', array($this, 'table_shortcode'));
        add_shortcode('volleyball_trends', array($this, 'trends_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Create the volleyball teams database table
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            team_id varchar(50) NOT NULL,
            team_name varchar(100) NOT NULL,
            league varchar(50) NOT NULL,
            position int(3) NOT NULL,
            ranking_points int(6) NOT NULL,
            logo_url varchar(255),
            match_stats longtext,
            set_stats longtext,
            point_stats longtext,
            result_breakdown longtext,
            penalty varchar(50),
            import_date datetime DEFAULT CURRENT_TIMESTAMP,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY league_date (league, import_date),
            KEY team_league (team_id, league)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('volleyball/v1', '/import-teams', array(
            'methods' => 'POST',
            'callback' => array($this, 'import_teams'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Optional: Add a GET endpoint to retrieve data
        register_rest_route('volleyball/v1', '/teams/(?P<league>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_teams'),
            'permission_callback' => '__return_true' // Public access for display
        ));
    }

    /**
     * Check permissions for import endpoint
     */
    public function check_permissions($request) {
        return current_user_can('edit_posts');
    }

    /**
     * Import teams data from GitHub
     */
    public function import_teams($request) {
        global $wpdb;

        // Get GitHub config from options
        $owner = get_option('volleyball_github_owner', 'cillianbc');
        $repo = get_option('volleyball_github_repo', 'volleyball-league-data');
        $branch = get_option('volleyball_github_branch', 'main');
        $token = get_option('volleyball_github_token', '');

        if (empty($token)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'GitHub token not configured'
            ), 400);
        }

        // Fetch /current directory contents
        $dir_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/current?ref={$branch}";
        $response = wp_remote_get($dir_url, array(
            'headers' => array(
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress Volleyball Plugin'
            )
        ));

        if (is_wp_error($response)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to fetch GitHub directory: ' . $response->get_error_message()
            ), 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data) || !is_array($data)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid GitHub directory response'
            ), 400);
        }

        $all_teams = array();
        $results = array();
        $today = current_time('Y-m-d');

        // Ensure $data is an array before iterating
        if (!is_array($data)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid response from GitHub - expected array of files'
            ), 400);
        }

        foreach ($data as $file) {
            // Skip if file is not an array (safety check)
            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }
            
            if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            $filename = pathinfo($file['name'], PATHINFO_FILENAME);

            // Map filename to proper league name
            $league_name_map = array(
                'mens-premier-division' => "Men's Premier Division",
                'womens-premier-division' => "Women's Premier Division",
                'mens-division-1' => "Men's Division 1",
                'womens-division-1' => "Women's Division 1",
                'division-2-men' => "Men's Division 2",
                'division-3-men' => "Men's Division 3",
                'wo-division-2-women' => "Women's Division 2",
                'wo-division-3-women' => "Women's Division 3"
            );

            $league = isset($league_name_map[$filename]) ? $league_name_map[$filename] : $filename;

            // Fetch file content - use download_url if available
            $file_url = isset($file['download_url']) ? $file['download_url'] : null;
            if (!$file_url) {
                error_log('Volleyball Import: No download URL for file ' . $file['name']);
                continue;
            }
            $file_response = wp_remote_get($file_url, array(
                'headers' => array(
                    'Authorization' => 'token ' . $token,
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress Volleyball Plugin'
                )
            ));

            if (is_wp_error($file_response)) {
                error_log('Volleyball Import: Failed to fetch file ' . $file['name'] . ': ' . $file_response->get_error_message());
                continue;
            }

            $file_body = wp_remote_retrieve_body($file_response);
            $file_data = json_decode($file_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('Volleyball Import: JSON decode error in ' . $file['name'] . ': ' . json_last_error_msg());
                continue;
            }

            if (!is_array($file_data)) {
                error_log('Volleyball Import: File ' . $file['name'] . ' did not contain an object');
                continue;
            }

            // Handle different JSON structures
            $teams = array();
            if (isset($file_data['teams']) && is_array($file_data['teams'])) {
                $teams = $file_data['teams'];
            } elseif (is_array($file_data) && isset($file_data[0]['teamId'])) {
                // Direct array of teams
                $teams = $file_data;
            } else {
                error_log('Volleyball Import: Could not find teams array in ' . $file['name']);
                continue;
            }

            if (empty($teams)) {
                error_log('Volleyball Import: No teams found in ' . $file['name']);
                continue;
            }

            // Normalize team data and add league
            foreach ($teams as &$team) {
                if (is_array($team)) {
                    $team['league'] = $league;

                    // Handle different field mappings
                    // Some files use 'rankingPoints', others use 'points'
                    if (!isset($team['rankingPoints']) && isset($team['points'])) {
                        $team['rankingPoints'] = intval($team['points']);
                    }

                    // Ensure position is set
                    if (!isset($team['position'])) {
                        $team['position'] = 0;
                    }

                    // Handle detailedStats if present
                    if (isset($team['detailedStats']) && is_array($team['detailedStats'])) {
                        if (isset($team['detailedStats']['rankingPoints'])) {
                            $team['rankingPoints'] = intval($team['detailedStats']['rankingPoints']);
                        }
                    }
                }
            }
            $all_teams = array_merge($all_teams, $teams);
        }

        if (empty($all_teams)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No valid team data found in GitHub /current folder'
            ), 400);
        }

        // Process teams as before
        foreach ($all_teams as $team) {
            // Skip if team is not an array
            if (!is_array($team)) {
                error_log('Volleyball Import: Invalid team data - not an array');
                continue;
            }
            
            // Validate required fields
            if (empty($team['teamId']) || empty($team['teamName']) || empty($team['league'])) {
                error_log('Volleyball Import: Missing required fields for team: ' . json_encode($team));
                continue;
            }

            // Check if team already imported today
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE team_id = %s AND league = %s AND DATE(import_date) = %s",
                $team['teamId'], $team['league'], $today
            ));

            $data = array(
                'team_id' => sanitize_text_field($team['teamId']),
                'team_name' => sanitize_text_field($team['teamName']),
                'league' => sanitize_text_field($team['league']),
                'position' => isset($team['position']) ? intval($team['position']) : 0,
                'ranking_points' => isset($team['rankingPoints']) ? intval($team['rankingPoints']) : 0,
                'logo_url' => isset($team['logoUrl']) ? esc_url_raw($team['logoUrl']) : '',
                'match_stats' => isset($team['matches']) ? wp_json_encode($team['matches']) : '[]',
                'set_stats' => isset($team['sets']) ? wp_json_encode($team['sets']) : '[]',
                'point_stats' => isset($team['points']) ? wp_json_encode($team['points']) : '[]',
                'result_breakdown' => isset($team['resultsBreakdown']) ? wp_json_encode($team['resultsBreakdown']) : '[]',
                'penalty' => isset($team['penalty']) ? sanitize_text_field($team['penalty']) : '',
                'import_date' => current_time('mysql')
            );

            if ($existing) {
                // Update existing record
                $wpdb->update($this->table_name, $data, array('id' => $existing->id));
                $results[] = "Updated: " . $team['teamName'] . ' (' . $team['league'] . ')';
            } else {
                // Insert new record
                $wpdb->insert($this->table_name, $data);
                $results[] = "Inserted: " . $team['teamName'] . ' (' . $team['league'] . ')';
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Teams imported successfully from GitHub',
            'results' => $results,
            'count' => count($results)
        ), 200);
    }

    /**
     * Get teams by league (for Bricks Builder)
     */
    public function get_teams($request) {
        global $wpdb;

        $league = sanitize_text_field($request['league']);

        // Get latest import date
        $latest_date = $wpdb->get_var(
            "SELECT MAX(DATE(import_date)) FROM {$this->table_name}"
        );

        // Get teams for specific league from latest import
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE league = %s AND DATE(import_date) = %s
            ORDER BY position ASC",
            $league, $latest_date
        ));

        // Decode JSON fields for easier use
        foreach ($teams as &$team) {
            $team->match_stats = json_decode($team->match_stats, true);
            $team->set_stats = json_decode($team->set_stats, true);
            $team->point_stats = json_decode($team->point_stats, true);
            $team->result_breakdown = json_decode($team->result_breakdown, true);
        }

        return new WP_REST_Response($teams, 200);
    }

    /**
     * Add admin settings page
     */
    public function add_admin_page() {
        add_options_page(
            'Volleyball Import Settings',
            'Volleyball Import',
            'manage_options',
            'volleyball-import',
            array($this, 'options_page')
        );
    }

    /**
     * Settings init
     */
    public function settings_init() {
        register_setting('volleyball', 'volleyball_github_owner');
        register_setting('volleyball', 'volleyball_github_repo');
        register_setting('volleyball', 'volleyball_github_branch');
        register_setting('volleyball', 'volleyball_github_token');

        add_settings_section(
            'volleyball_section',
            'GitHub Configuration',
            null,
            'volleyball'
        );

        add_settings_field(
            'volleyball_github_owner',
            'Repository Owner',
            array($this, 'owner_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_field(
            'volleyball_github_repo',
            'Repository Name',
            array($this, 'repo_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_field(
            'volleyball_github_branch',
            'Branch',
            array($this, 'branch_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_field(
            'volleyball_github_token',
            'Personal Access Token',
            array($this, 'token_render'),
            'volleyball',
            'volleyball_section'
        );
    }

    /**
     * Render owner field
     */
    public function owner_render() {
        $owner = get_option('volleyball_github_owner', 'cillianbc');
        echo '<input type="text" name="volleyball_github_owner" value="' . esc_attr($owner) . '" />';
    }

    /**
     * Render repo field
     */
    public function repo_render() {
        $repo = get_option('volleyball_github_repo', 'volleyball-league-data');
        echo '<input type="text" name="volleyball_github_repo" value="' . esc_attr($repo) . '" />';
    }

    /**
     * Render branch field
     */
    public function branch_render() {
        $branch = get_option('volleyball_github_branch', 'main');
        echo '<input type="text" name="volleyball_github_branch" value="' . esc_attr($branch) . '" />';
    }

    /**
     * Render token field
     */
    public function token_render() {
        $token = get_option('volleyball_github_token', '');
        echo '<input type="password" name="volleyball_github_token" value="' . esc_attr($token) . '" />';
        echo '<p class="description">Enter your GitHub PAT with Contents: Read-only permission.</p>';
    }

    /**
     * Options page HTML
     */
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Volleyball Import Settings</h1>
            
            <form action="options.php" method="post">
                <h2>GitHub Configuration</h2>
                <?php
                settings_fields('volleyball');
                do_settings_sections('volleyball');
                submit_button();
                ?>
            </form>
            
            <hr style="margin: 30px 0;">
            
            <div class="volleyball-import-section">
                <h2>Manual Import</h2>
                <p>Click the button below to manually import team data from GitHub.</p>
                <button id="volleyball-import-btn" class="button button-primary">Import Teams Now</button>
                <div id="volleyball-import-result" style="margin-top: 20px;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        if ($hook !== 'settings_page_volleyball-import') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $("#volleyball-import-btn").on("click", function() {
                    var $button = $(this);
                    var $result = $("#volleyball-import-result");
                    
                    $button.prop("disabled", true).text("Importing...");
                    $result.html("<p>⏳ Importing data from GitHub...</p>");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "volleyball_manual_import",
                            nonce: "' . wp_create_nonce('volleyball_import_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html("<div style=\"color: green; background: #f0fff0; padding: 10px; border: 1px solid #90ee90; border-radius: 4px;\">" +
                                    "<strong>✅ Import Successful!</strong><br>" +
                                    response.data.message + "<br>" +
                                    "Imported " + response.data.count + " teams<br>" +
                                    "<details><summary>Show details</summary><pre>" + response.data.results.join("\\n") + "</pre></details>" +
                                    "</div>");
                            } else {
                                $result.html("<div style=\"color: red; background: #fff0f0; padding: 10px; border: 1px solid #ffb0b0; border-radius: 4px;\">" +
                                    "<strong>❌ Import Failed:</strong> " + response.data.message +
                                    "</div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            $result.html("<div style=\"color: red; background: #fff0f0; padding: 10px; border: 1px solid #ffb0b0; border-radius: 4px;\">" +
                                "<strong>❌ Error:</strong> " + error +
                                "</div>");
                        },
                        complete: function() {
                            $button.prop("disabled", false).text("Import Teams Now");
                        }
                    });
                });
            });
        ');
    }
    
    /**
     * Handle AJAX manual import
     */
    public function ajax_manual_import() {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'volleyball_import_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Perform the import
        $request = new WP_REST_Request('POST', '/volleyball/v1/import-teams');
        $response = $this->import_teams($request);
        
        // Get response data
        $data = $response->get_data();
        $status = $response->get_status();
        
        if ($status === 200 && $data['success']) {
            wp_send_json_success($data);
        } else {
            wp_send_json_error($data);
        }
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'volleyball_table')) {
            wp_enqueue_style('volleyball-styles', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
        }
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'volleyball_trends')) {
            wp_enqueue_style('volleyball-styles', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
        }
    }

    /**
     * Table shortcode [volleyball_table league="x"]
     */
    public function table_shortcode($atts) {
        $atts = shortcode_atts(array(
            'league' => ''
        ), $atts);

        if (empty($atts['league'])) {
            return '<p>No league specified for table.</p>';
        }

        global $wpdb;
        $league = sanitize_text_field($atts['league']);

        $latest_date = $wpdb->get_var(
            "SELECT MAX(DATE(import_date)) FROM {$this->table_name}"
        );

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            WHERE league = %s AND DATE(import_date) = %s
            ORDER BY position ASC",
            $league, $latest_date
        ));

        if (empty($teams)) {
            return '<p>No data for league: ' . esc_html($league) . '</p>';
        }

        // Decode JSON fields for display if needed
        foreach ($teams as &$team) {
            $team->match_stats = json_decode($team->match_stats, true);
            $team->set_stats = json_decode($team->set_stats, true);
            $team->point_stats = json_decode($team->point_stats, true);
            $team->result_breakdown = json_decode($team->result_breakdown, true);
        }

        ob_start();
        ?>
        <div class="volleyball-table-container">
            <div style="overflow-x: auto;">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr>
                            <th rowspan="2">Pos</th>
                            <th rowspan="2">Team</th>
                            <th rowspan="2">Points</th>
                            <th colspan="3" style="text-align: center;">Matches</th>
                            <th colspan="3" style="text-align: center;">Sets</th>
                            <th colspan="3" style="text-align: center;">Points</th>
                            <th colspan="6" style="text-align: center;">Results</th>
                            <th rowspan="2">Set Ratio</th>
                            <th rowspan="2">Point Ratio</th>
                            <th rowspan="2">Penalty</th>
                        </tr>
                        <tr>
                            <th>Played</th>
                            <th>Won</th>
                            <th>Lost</th>
                            <th>Won</th>
                            <th>Lost</th>
                            <th>Ratio</th>
                            <th>Won</th>
                            <th>Lost</th>
                            <th>Ratio</th>
                            <th>3-0</th>
                            <th>3-1</th>
                            <th>3-2</th>
                            <th>2-3</th>
                            <th>1-3</th>
                            <th>0-3</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $team):
                            // Safely decode JSON fields, handling both string and array data
                            $match_stats = $this->safe_json_decode($team->match_stats);
                            $set_stats = $this->safe_json_decode($team->set_stats);
                            $point_stats = $this->safe_json_decode($team->point_stats);
                            $result_breakdown = $this->safe_json_decode($team->result_breakdown);
                        ?>
                        <tr>
                            <td><?php echo esc_html($team->position); ?></td>
                            <td>
                                <?php if ($team->logo_url): ?>
                                    <img src="<?php echo esc_url($team->logo_url); ?>" alt="<?php echo esc_attr($team->team_name); ?>" style="width:30px;height:30px;margin-right:8px;vertical-align:middle;">
                                <?php endif; ?>
                                <?php echo esc_html($team->team_name); ?>
                            </td>
                            <td><?php echo esc_html($team->ranking_points); ?></td>

                            <!-- Matches -->
                            <td><?php echo esc_html($match_stats['played'] ?? 0); ?></td>
                            <td><?php echo esc_html($match_stats['won'] ?? 0); ?></td>
                            <td><?php echo esc_html($match_stats['lost'] ?? 0); ?></td>

                            <!-- Sets -->
                            <td><?php echo esc_html($set_stats['won'] ?? 0); ?></td>
                            <td><?php echo esc_html($set_stats['lost'] ?? 0); ?></td>
                            <td><?php echo number_format($set_stats['ratio'] ?? 0, 3); ?></td>

                            <!-- Points -->
                            <td><?php echo esc_html($point_stats['won'] ?? 0); ?></td>
                            <td><?php echo esc_html($point_stats['lost'] ?? 0); ?></td>
                            <td><?php echo number_format($point_stats['ratio'] ?? 0, 3); ?></td>

                            <!-- Results Breakdown -->
                            <td><?php echo esc_html($result_breakdown['wins3_0'] ?? 0); ?></td>
                            <td><?php echo esc_html($result_breakdown['wins3_1'] ?? 0); ?></td>
                            <td><?php echo esc_html($result_breakdown['wins3_2'] ?? 0); ?></td>
                            <td><?php echo esc_html($result_breakdown['losses2_3'] ?? 0); ?></td>
                            <td><?php echo esc_html($result_breakdown['losses1_3'] ?? 0); ?></td>
                            <td><?php echo esc_html($result_breakdown['losses0_3'] ?? 0); ?></td>

                            <!-- Ratios -->
                            <td><?php echo number_format($set_stats['ratio'] ?? 0, 3); ?></td>
                            <td><?php echo number_format($point_stats['ratio'] ?? 0, 3); ?></td>

                            <!-- Penalty -->
                            <td><?php echo esc_html($team->penalty ?: '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Trends shortcode [volleyball_trends league="x" type="position" range="all"]
     */
    public function trends_shortcode($atts) {
        $atts = shortcode_atts(array(
            'league' => '',
            'type' => 'position',
            'range' => 'all'
        ), $atts);

        if (empty($atts['league'])) {
            return '<p>No league specified for trends.</p>';
        }

        global $wpdb;
        $league = sanitize_text_field($atts['league']);
        $type = sanitize_text_field($atts['type']);
        $range = sanitize_text_field($atts['range']);

        // Check if historical data exists
        $has_data = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE league = %s AND import_date < CURDATE() - INTERVAL 1 WEEK",
            $league
        ));

        if (empty($has_data)) {
            // Fetch historical if no data
            $this->fetch_historical($league);
        }

        // Query historical data
        $where = "WHERE league = %s";
        $params = array($league);
        if ($range !== 'all') {
            $where .= " AND import_date >= %s";
            $params[] = date('Y-m-d', strtotime($range)) . ' 00:00:00';
        }

        $historical = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(import_date) as week, AVG(CASE WHEN %s = 'position' THEN position ELSE ranking_points END) as value
            FROM {$this->table_name}
            {$where}
            GROUP BY DATE(import_date)
            ORDER BY week ASC",
            $type, ...$params
        ), ARRAY_A);

        if (empty($historical)) {
            return '<p>No historical data for league: ' . esc_html($league) . '</p>';
        }

        $labels = array_column($historical, 'week');
        $data = array_column($historical, 'value');

        $canvas_id = 'volleyball-trends-' . uniqid();

        ob_start();
        ?>
        <div class="volleyball-trends-container">
            <canvas id="<?php echo esc_attr($canvas_id); ?>" width="400" height="200"></canvas>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const ctx = document.getElementById('<?php echo esc_attr($canvas_id); ?>').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo wp_json_encode($labels); ?>,
                        datasets: [{
                            label: '<?php echo esc_js($type); ?> Trend for <?php echo esc_js($league); ?>',
                            data: <?php echo wp_json_encode($data); ?>,
                            borderColor: 'rgb(75, 192, 192)',
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Fetch historical data from GitHub /historical folder
     * @param string $league Optional league to filter
     */
    public function fetch_historical($league = null) {
        global $wpdb;

        // Get config
        $owner = get_option('volleyball_github_owner', 'cillianbc');
        $repo = get_option('volleyball_github_repo', 'volleyball-league-data');
        $branch = get_option('volleyball_github_branch', 'main');
        $token = get_option('volleyball_github_token', '');

        if (empty($token)) {
            error_log('Volleyball Import: No token for historical fetch');
            return;
        }

        // Fetch /historical directory
        $dir_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/historical?ref={$branch}";
        $response = wp_remote_get($dir_url, array(
            'headers' => array(
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress Volleyball Plugin'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Volleyball Import: Failed historical directory fetch: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($data) || !is_array($data)) {
            error_log('Volleyball Import: Invalid historical directory response');
            return;
        }

        foreach ($data as $file) {
            if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            $filename_parts = pathinfo($file['name'], PATHINFO_FILENAME);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename_parts, $matches)) {
                $file_date = $matches[1];
                $file_league = $matches[2];

                if ($league && $file_league !== $league) {
                    continue;
                }

                // Fetch file
                $file_url = $file['download_url'];
                $file_response = wp_remote_get($file_url, array(
                    'headers' => array(
                        'Authorization' => 'token ' . $token,
                        'Accept' => 'application/vnd.github.v3+json',
                        'User-Agent' => 'WordPress Volleyball Plugin'
                    )
                ));

                if (is_wp_error($file_response)) {
                    error_log('Volleyball Import: Failed historical file fetch ' . $file['name']);
                    continue;
                }

                $file_body = wp_remote_retrieve_body($file_response);
                $teams = json_decode($file_body, true);

                if (json_last_error() !== JSON_ERROR_NONE || empty($teams) || !is_array($teams)) {
                    error_log('Volleyball Import: Invalid JSON in historical ' . $file['name']);
                    continue;
                }

                // Add league and date
                foreach ($teams as &$team) {
                    $team['league'] = $file_league;
                }

                // Upsert teams with file_date
                foreach ($teams as $team) {
                    if (empty($team['teamId']) || empty($team['teamName']) || empty($team['league'])) {
                        continue;
                    }

                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$this->table_name}
                        WHERE team_id = %s AND league = %s AND DATE(import_date) = %s",
                        $team['teamId'], $team['league'], $file_date
                    ));

                    $data = array(
                        'team_id' => sanitize_text_field($team['teamId']),
                        'team_name' => sanitize_text_field($team['teamName']),
                        'league' => sanitize_text_field($team['league']),
                        'position' => intval($team['position']),
                        'ranking_points' => intval($team['rankingPoints']),
                        'logo_url' => esc_url_raw($team['logoUrl']),
                        'match_stats' => wp_json_encode($team['matches']),
                        'set_stats' => wp_json_encode($team['sets']),
                        'point_stats' => wp_json_encode($team['points']),
                        'result_breakdown' => wp_json_encode($team['resultsBreakdown']),
                        'penalty' => sanitize_text_field($team['penalty']),
                        'import_date' => $file_date . ' 00:00:00'
                    );

                    if ($existing) {
                        $wpdb->update($this->table_name, $data, array('id' => $existing->id));
                    } else {
                        $wpdb->insert($this->table_name, $data);
                    }
                }
            }
        }
    }

    /**
     * Schedule weekly cron for auto-import
     */
    public function schedule_cron() {
        if (!wp_next_scheduled('volleyball_weekly_import')) {
            wp_schedule_event(time(), 'weekly', 'volleyball_weekly_import');
        }
    }

    /**
     * Perform cron import
     */
    public function perform_cron_import() {
        // Simulate REST request for import_teams
        $request = new WP_REST_Request('POST', '/volleyball/v1/import-teams');
        $this->import_teams($request);
    }

    /**
     * Deactivate cron on plugin deactivation
     */
    public function deactivate_cron() {
        wp_clear_scheduled_hook('volleyball_weekly_import');
    }

    /**
     * Safely decode JSON data that might already be an array
     */
    private function safe_json_decode($data) {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : array();
        }

        return array();
    }

}

// Initialize the plugin
new VolleyballImport();