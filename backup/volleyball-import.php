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

        // Try different hooks for Bricks compatibility
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 20);
        add_action('wp_head', array($this, 'enqueue_scripts_fallback'), 20);
        add_action('wp_footer', array($this, 'enqueue_scripts_fallback'), 5);
        
        // Additional hooks for Bricks compatibility
        add_action('bricks_content_end', array($this, 'enqueue_scripts_fallback'), 10);
        add_action('bricks_after_site_wrapper', array($this, 'enqueue_scripts_fallback'), 10);
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
            subleague varchar(50),
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
            KEY team_league (team_id, league),
            KEY subleague (subleague)
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

        // GET endpoint to retrieve teams by league (and optional subleague)
        register_rest_route('volleyball/v1', '/teams/(?P<league>[^/]+)(/(?P<subleague>[^/]+))?', array(
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
                // Use the flat teams array which should already have subleague field
                $teams = $file_data['teams'];
                error_log('Volleyball Import: Using flat teams array from ' . $file['name']);
            } elseif (isset($file_data['subLeagues']) && is_array($file_data['subLeagues'])) {
                // Extract teams from subLeagues structure
                error_log('Volleyball Import: Using subLeagues structure from ' . $file['name']);
                foreach ($file_data['subLeagues'] as $subLeague) {
                    if (isset($subLeague['teams']) && is_array($subLeague['teams'])) {
                        foreach ($subLeague['teams'] as $team) {
                            // Add subleague info to each team
                            $team['subleague'] = $subLeague['name'];
                            $teams[] = $team;
                        }
                    }
                }
            } elseif (is_array($file_data) && isset($file_data[0]['teamId'])) {
                // Direct array of teams
                $teams = $file_data;
                error_log('Volleyball Import: Using direct teams array from ' . $file['name']);
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
        
                    // Handle subleague for nested leagues
                    if (isset($team['subLeague'])) {
                        $team['subleague'] = sanitize_text_field($team['subLeague']);
                    } elseif (isset($file_data['subLeagues'])) {
                        // If subLeagues exist in file, find matching team
                        foreach ($file_data['subLeagues'] as $subLeagueData) {
                            if (isset($subLeagueData['name']) && isset($subLeagueData['teams'])) {
                                foreach ($subLeagueData['teams'] as $subTeam) {
                                    if (isset($subTeam['teamId']) && $subTeam['teamId'] == $team['teamId']) {
                                        $team['subleague'] = sanitize_text_field($subLeagueData['name']);
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
        
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
            $where_clause = "WHERE team_id = %s AND league = %s AND DATE(import_date) = %s";
            $params = array($team['teamId'], $team['league'], $today);
            if (isset($team['subleague'])) {
                $where_clause .= " AND subleague = %s";
                $params[] = $team['subleague'];
            }
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                {$where_clause}",
                ...$params
            ));

            $data = array(
                'team_id' => sanitize_text_field($team['teamId']),
                'team_name' => sanitize_text_field($team['teamName']),
                'league' => sanitize_text_field($team['league']),
                'subleague' => isset($team['subleague']) ? sanitize_text_field($team['subleague']) : null,
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

        // Properly decode URL-encoded parameters
        $raw_league = $request['league'];
        $decoded_league = urldecode($raw_league);
        $league = stripslashes(sanitize_text_field($decoded_league));
        
        $raw_subleague = isset($request['subleague']) ? $request['subleague'] : '';
        $decoded_subleague = urldecode($raw_subleague);
        $subleague = stripslashes(sanitize_text_field($decoded_subleague));
        
        error_log('Volleyball Debug REST API: get_teams called');
        error_log('Volleyball Debug REST API: Raw league: ' . $raw_league);
        error_log('Volleyball Debug REST API: Decoded league: ' . $decoded_league);
        error_log('Volleyball Debug REST API: Sanitized league: ' . $league);
        error_log('Volleyball Debug REST API: Raw subleague: ' . $raw_subleague);
        error_log('Volleyball Debug REST API: Decoded subleague: ' . $decoded_subleague);
        error_log('Volleyball Debug REST API: Sanitized subleague: ' . $subleague);

        // Get latest import date
        $latest_date = $wpdb->get_var(
            "SELECT MAX(DATE(import_date)) FROM {$this->table_name}"
        );
        
        error_log('Volleyball Debug REST API: Latest import date: ' . ($latest_date ? $latest_date : 'NULL'));
        
        // Check total teams in database
        $total_teams = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        error_log('Volleyball Debug REST API: Total teams in database: ' . $total_teams);
        
        // Check teams for this specific league
        $league_teams = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE league = %s",
            $league
        ));
        error_log('Volleyball Debug REST API: Teams for league "' . $league . '": ' . $league_teams);
        
        // Try a LIKE search to see if there are similar league names
        $similar_leagues = $wpdb->get_results($wpdb->prepare(
            "SELECT league, COUNT(*) as count FROM {$this->table_name}
             WHERE league LIKE %s GROUP BY league",
            '%' . $league . '%'
        ));
        error_log('Volleyball Debug REST API: Similar leagues: ' . print_r($similar_leagues, true));
        
        // Get actual league names that contain the word we're looking for
        $league_parts = explode(' ', $league);
        $main_word = end($league_parts); // Get last word (Division, Premier, etc.)
        $word_matches = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT league FROM {$this->table_name} WHERE league LIKE %s",
            '%' . $main_word . '%'
        ));
        error_log('Volleyball Debug REST API: Leagues containing "' . $main_word . '": ' . print_r($word_matches, true));
        
        // Character-level debugging - compare the exact characters
        if (!empty($similar_leagues)) {
            $db_league = $similar_leagues[0]->league;
            error_log('Volleyball Debug REST API: Character comparison:');
            error_log('  Search league: "' . $league . '" (length: ' . strlen($league) . ')');
            error_log('  Database league: "' . $db_league . '" (length: ' . strlen($db_league) . ')');
            error_log('  Search league bytes: ' . bin2hex($league));
            error_log('  Database league bytes: ' . bin2hex($db_league));
            error_log('  Are they equal?: ' . ($league === $db_league ? 'YES' : 'NO'));
        }
        
        // Check distinct leagues in database
        $distinct_leagues = $wpdb->get_results("SELECT DISTINCT league, COUNT(*) as count FROM {$this->table_name} GROUP BY league");
        error_log('Volleyball Debug REST API: Distinct leagues in database: ' . print_r($distinct_leagues, true));
        
        // Check distinct subleagues for this league
        if (!empty($league)) {
            $distinct_subleagues = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT subleague, COUNT(*) as count FROM {$this->table_name} WHERE league = %s GROUP BY subleague",
                $league
            ));
            error_log('Volleyball Debug REST API: Distinct subleagues for "' . $league . '": ' . print_r($distinct_subleagues, true));
        }

        // Get teams for specific league (and subleague if specified) from latest import
        $where_clause = "WHERE league = %s AND DATE(import_date) = %s";
        $params = array($league, $latest_date);
        
        if (!empty($subleague)) {
            $where_clause .= " AND subleague = %s";
            $params[] = $subleague;
        }
        
        $final_query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
            {$where_clause}
            ORDER BY position ASC",
            ...$params
        );
        
        error_log('Volleyball Debug REST API: Final query: ' . $final_query);
        
        $teams = $wpdb->get_results($final_query);
        
        error_log('Volleyball Debug REST API: Teams found: ' . count($teams));
        
        if (empty($teams) && !empty($subleague)) {
            // Try without the import date filter
            $fallback_query = $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE league = %s AND subleague = %s
                ORDER BY import_date DESC, position ASC",
                $league, $subleague
            );
            
            error_log('Volleyball Debug REST API: Trying fallback query: ' . $fallback_query);
            $teams = $wpdb->get_results($fallback_query);
            error_log('Volleyball Debug REST API: Fallback teams found: ' . count($teams));
        }

        // Decode JSON fields for easier use
        foreach ($teams as &$team) {
            $team->match_stats = json_decode($team->match_stats, true);
            $team->set_stats = json_decode($team->set_stats, true);
            $team->point_stats = json_decode($team->point_stats, true);
            $team->result_breakdown = json_decode($team->result_breakdown, true);
        }

        error_log('Volleyball Debug REST API: Returning ' . count($teams) . ' teams');
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
        register_setting('volleyball', 'volleyball_primary_color');
        register_setting('volleyball', 'volleyball_secondary_color');

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

        add_settings_section(
            'volleyball_colors_section',
            'Styling Configuration',
            null,
            'volleyball'
        );

        add_settings_field(
            'volleyball_primary_color',
            'Primary Color',
            array($this, 'primary_color_render'),
            'volleyball',
            'volleyball_colors_section'
        );

        add_settings_field(
            'volleyball_secondary_color',
            'Secondary Color',
            array($this, 'secondary_color_render'),
            'volleyball',
            'volleyball_colors_section'
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
     * Render primary color field
     */
    public function primary_color_render() {
        $primary_color = get_option('volleyball_primary_color', '#007cba');
        echo '<input type="color" name="volleyball_primary_color" value="' . esc_attr($primary_color) . '" />';
        echo '<p class="description">Choose the primary color for tables (default: WordPress blue).</p>';
    }

    /**
     * Render secondary color field
     */
    public function secondary_color_render() {
        $secondary_color = get_option('volleyball_secondary_color', '#6c757d');
        echo '<input type="color" name="volleyball_secondary_color" value="' . esc_attr($secondary_color) . '" />';
        echo '<p class="description">Choose the secondary color for accents (default: gray).</p>';
    }

    /**
     * Options page HTML
     */
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Volleyball Import Settings</h1>

            <form action="options.php" method="post">
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

            <hr style="margin: 30px 0;">

            <div class="volleyball-shortcodes-section">
                <h2>Available Shortcodes</h2>
                <p>Use these shortcodes to display volleyball league data on your pages and posts:</p>

                <h3>League Tables</h3>
                <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0;">
                    <code style="background: #fff; padding: 2px 4px; border: 1px solid #ccc; border-radius: 3px;">[volleyball_table league="Men's Premier Division"]</code>
                    <p><strong>Description:</strong> Displays a league table for the specified league.</p>
                    <p><strong>Parameters:</strong></p>
                    <ul style="margin-left: 20px;">
                        <li><code>league</code> (required): The league name (e.g., "Men's Premier Division", "Women's Division 2")</li>
                        <li><code>subleague</code> (optional): For nested leagues, specify a sub-league (e.g., "D2M-A")</li>
                    </ul>
                    <p><strong>Note:</strong> For Division 2 & 3 leagues (men & women), an interactive accordion will be displayed to select sub-leagues.</p>
                </div>

                <h3>Historical Trends</h3>
                <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0;">
                    <code style="background: #fff; padding: 2px 4px; border: 1px solid #ccc; border-radius: 3px;">[volleyball_trends league="Men's Premier Division" type="position" range="all"]</code>
                    <p><strong>Description:</strong> Displays a Chart.js line chart showing historical trends for the league.</p>
                    <p><strong>Parameters:</strong></p>
                    <ul style="margin-left: 20px;">
                        <li><code>league</code> (required): The league name</li>
                        <li><code>type</code> (optional): "position" (default) or "ranking_points"</li>
                        <li><code>range</code> (optional): "all" (default), "1month", "3months", "6months"</li>
                    </ul>
                </div>

                <h3>Available Leagues</h3>
                <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin: 10px 0;">
                    <p>The following league names are supported (use exact names):</p>
                    <ul style="margin-left: 20px;">
                        <li>Men's Premier Division</li>
                        <li>Women's Premier Division</li>
                        <li>Men's Division 1</li>
                        <li>Women's Division 1</li>
                        <li><strong>Men's Division 2</strong> (with sub-leagues - accordion selector)</li>
                        <li><strong>Men's Division 3</strong> (with sub-leagues - accordion selector)</li>
                        <li><strong>Women's Division 2</strong> (with sub-leagues - accordion selector)</li>
                        <li><strong>Women's Division 3</strong> (with sub-leagues - accordion selector)</li>
                    </ul>
                    <p><strong>Note:</strong> Division 2 & 3 leagues will automatically display an interactive accordion for selecting sub-leagues.</p>
                    <p><strong>Tip:</strong> Check your database for exact league names if you encounter issues.</p>
                </div>
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
     * Fallback enqueue function for Bricks compatibility
     */
    public function enqueue_scripts_fallback() {
        global $post;

        error_log('Volleyball Debug: enqueue_scripts_fallback called');
        
        // Check if we're on a singular page
        if (!is_singular()) {
            error_log('Volleyball Debug: Not a singular page, exiting fallback');
            return;
        }
        
        // Check if $post is a valid WP_Post object
        if (!is_a($post, 'WP_Post')) {
            error_log('Volleyball Debug: No valid post object, exiting fallback');
            return;
        }
        
        // Log post content for debugging
        error_log('Volleyball Debug: Post ID: ' . $post->ID);
        error_log('Volleyball Debug: Post content preview: ' . substr($post->post_content, 0, 100));
        
        // Check for shortcode in post content
        $has_shortcode = has_shortcode($post->post_content, 'volleyball_table');
        error_log('Volleyball Debug: has_shortcode result: ' . ($has_shortcode ? 'true' : 'false'));
        
        // Check for Bricks content
        $is_bricks = false;
        if (function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main')) {
            $is_bricks = true;
            error_log('Volleyball Debug: Bricks detected');
        }
        
        // Force enqueue for Bricks or if shortcode is detected
        if (!$has_shortcode && !$is_bricks) {
            // Try to detect shortcode in a different way for Bricks
            if (strpos($post->post_content, '[volleyball_table') !== false ||
                strpos($post->post_content, 'volleyball_table') !== false) {
                error_log('Volleyball Debug: Shortcode text found in content but not detected by has_shortcode');
                $has_shortcode = true;
            } else {
                error_log('Volleyball Debug: No shortcode detected, exiting fallback');
                return;
            }
        }

        // Check if our script is already enqueued
        if (wp_script_is('volleyball-ajax', 'enqueued')) {
            error_log('Volleyball Debug: Script already enqueued, exiting fallback');
            return;
        }

        error_log('Volleyball Debug: Using fallback enqueue method');

        // Manually enqueue the script
        $js_file_url = plugin_dir_url(__FILE__) . 'volleyball-ajax.js';
        error_log('Volleyball Debug: Script URL: ' . $js_file_url);
        echo '<script src="' . esc_url($js_file_url) . '" id="volleyball-ajax-js"></script>';

        // Add the localized data
        $localized_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('volleyball/v1/teams/'),
            'nonce' => wp_create_nonce('volleyball_subleague_nonce')
        );
        
        error_log('Volleyball Debug: Localizing with data: ' . json_encode($localized_data));
        echo '<script>var volleyball_ajax = ' . wp_json_encode($localized_data) . ';</script>';
        error_log('Volleyball Debug: Fallback enqueue completed');
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        global $post;

        // Debug: Log that enqueue function is called
        error_log('Volleyball Debug: enqueue_scripts called');

        $primary_color = get_option('volleyball_primary_color', '#007cba');
        $secondary_color = get_option('volleyball_secondary_color', '#6c757d');

        // Debug: Check conditions
        error_log('Volleyball Debug: is_singular() = ' . (is_singular() ? 'true' : 'false'));
        if (is_a($post, 'WP_Post')) {
            error_log('Volleyball Debug: Post ID: ' . $post->ID);
            error_log('Volleyball Debug: has_shortcode volleyball_table = ' . (has_shortcode($post->post_content, 'volleyball_table') ? 'true' : 'false'));
            error_log('Volleyball Debug: post content preview = ' . substr($post->post_content, 0, 100));
            
            // Check for Bricks content
            if (function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main')) {
                error_log('Volleyball Debug: Bricks detected in main enqueue');
            }
            
            // Try to detect shortcode in a different way for Bricks
            if (strpos($post->post_content, '[volleyball_table') !== false ||
                strpos($post->post_content, 'volleyball_table') !== false) {
                error_log('Volleyball Debug: Shortcode text found in content');
            }
        } else {
            error_log('Volleyball Debug: No valid post object in main enqueue');
        }

        // Always enqueue styles and scripts if we're on a singular post/page
        if (is_singular()) {
            error_log('Volleyball Debug: Enqueueing styles');
            wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css', array(), '5.1.3');
            wp_enqueue_style('volleyball-styles', plugin_dir_url(__FILE__) . 'volleyball-styles.css', array('bootstrap-css'), '1.0.0');

            // Set CSS variables from settings
            $custom_css = "
                :root {
                    --volleyball-primary-color: {$primary_color};
                    --volleyball-secondary-color: {$secondary_color};
                }
            ";
            wp_add_inline_style('volleyball-styles', $custom_css);

            // Enqueue jQuery for AJAX
            wp_enqueue_script('jquery');

            // Check for shortcode in a more flexible way for Bricks compatibility
            $should_enqueue = false;
            
            if (is_a($post, 'WP_Post')) {
                if (has_shortcode($post->post_content, 'volleyball_table')) {
                    $should_enqueue = true;
                    error_log('Volleyball Debug: Shortcode detected via has_shortcode');
                }
                // Additional check for Bricks or other page builders
                elseif (strpos($post->post_content, '[volleyball_table') !== false ||
                       strpos($post->post_content, 'volleyball_table') !== false) {
                    $should_enqueue = true;
                    error_log('Volleyball Debug: Shortcode detected via string search');
                }
                // Check for Bricks specifically
                elseif (function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main')) {
                    $should_enqueue = true;
                    error_log('Volleyball Debug: Enqueueing for Bricks compatibility');
                }
            }
            
            // Force enqueue on all singular pages for testing
            $force_enqueue = true; // Set to false after testing
            if ($force_enqueue) {
                $should_enqueue = true;
                error_log('Volleyball Debug: Force enqueueing for testing');
            }

            if ($should_enqueue) {
                error_log('Volleyball Debug: Enqueueing AJAX script');
                wp_enqueue_script('volleyball-ajax', plugin_dir_url(__FILE__) . 'volleyball-ajax.js', array('jquery'), '1.0.0', true);

                // Localize script with AJAX URL and nonce
                wp_localize_script('volleyball-ajax', 'volleyball_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'rest_url' => rest_url('volleyball/v1/teams/'),
                    'nonce' => wp_create_nonce('volleyball_subleague_nonce')
                ));
                error_log('Volleyball Debug: Localized script with: ' . json_encode(array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'rest_url' => rest_url('volleyball/v1/teams/'),
                    'nonce' => wp_create_nonce('volleyball_subleague_nonce')
                )));
            } else {
                error_log('Volleyball Debug: Not enqueueing AJAX script - conditions not met');
            }

            // Enqueue Chart.js for trends shortcode
            if (is_a($post, 'WP_Post') &&
                (has_shortcode($post->post_content, 'volleyball_trends') ||
                 strpos($post->post_content, '[volleyball_trends') !== false)) {
                wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
                error_log('Volleyball Debug: Enqueueing Chart.js for trends');
            }
        } else {
            error_log('Volleyball Debug: Not a singular page, skipping enqueue');
        }
    }

    /**
     * Table shortcode [volleyball_table league="x"]
     */
    public function table_shortcode($atts) {
        $atts = shortcode_atts(array(
            'league' => '',
            'subleague' => ''
        ), $atts);

        if (empty($atts['league'])) {
            return '<p>No league specified for table.</p>';
        }

        global $wpdb;
        $league = sanitize_text_field($atts['league']);
        $subleague = sanitize_text_field($atts['subleague']);

        // Check if this is a nested league (Division 2 or 3 for men/women)
        $nested_leagues = array(
            "Men's Division 2", "Men's Division 3",
            "Women's Division 2", "Women's Division 3"
        );
        $is_nested = in_array($league, $nested_leagues);

        $latest_date = $wpdb->get_var(
            "SELECT MAX(DATE(import_date)) FROM {$this->table_name}"
        );

        if ($is_nested && empty($subleague)) {
            // Render dynamic accordion for nested league
            $subleagues = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT subleague
                 FROM {$this->table_name}
                 WHERE league = %s AND subleague IS NOT NULL AND DATE(import_date) = %s
                 ORDER BY subleague ASC",
                $league, $latest_date
            ));

            if (empty($subleagues)) {
                return '<p>No sub-league data available for ' . esc_html($league) . '</p>';
            }

            $accordion_id = 'volleyball-accordion-' . uniqid();
            ob_start();
            ?>
            <div class="volleyball-accordion" id="<?php echo esc_attr($accordion_id); ?>" data-league="<?php echo esc_attr($league); ?>">
                <h3><?php echo esc_html($league); ?> - Select Sub-League</h3>
                <div class="accordion-headers">
                    <?php foreach ($subleagues as $sub):
                        $current_subleague = $sub->subleague;
                    ?>
                    <button class="accordion-header" data-subleague="<?php echo esc_attr($current_subleague); ?>">
                        <?php echo esc_html($current_subleague); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="accordion-content">
                    <div class="volleyball-loading">Select a sub-league to view teams...</div>
                    <div id="subleague-table-container"></div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        } else {
            // Regular league or specific subleague
            $where_clause = "WHERE league = %s AND DATE(import_date) = %s";
            $params = array($league, $latest_date);
            
            if (!empty($subleague)) {
                $where_clause .= " AND subleague = %s";
                $params[] = $subleague;
            }
            
            $teams = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                {$where_clause}
                ORDER BY position ASC",
                ...$params
            ));

            if (empty($teams)) {
                return '<p>No data for league: ' . esc_html($league) . (empty($subleague) ? '' : ' / subleague: ' . esc_html($subleague)) . '</p>';
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
                <h3><?php echo esc_html($league); ?><?php if (!empty($subleague)) echo ' - ' . esc_html($subleague); ?></h3>
                <?php $this->render_team_table($teams); ?>
            </div>
            <?php
            return ob_get_clean();
        }
    }

    /**
     * Render team table HTML
     */
    private function render_team_table($teams) {
        ?>
        <div class="table-responsive">
            <table class="volleyball-league-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="position-col">Pos</th>
                        <th rowspan="2" class="team-col">Team</th>
                        <th rowspan="2" class="points-col">Points</th>
                        <th colspan="3" class="header-group">Matches</th>
                        <th colspan="3" class="header-group">Sets</th>
                        <th colspan="3" class="header-group">Points</th>
                        <th colspan="6" class="header-group">Results</th>
                        <th rowspan="2" class="ratio-col">Set Ratio</th>
                        <th rowspan="2" class="ratio-col">Point Ratio</th>
                        <th rowspan="2" class="penalty-col">Penalty</th>
                    </tr>
                    <tr>
                        <th class="stats-col">Played</th>
                        <th class="stats-col">Won</th>
                        <th class="stats-col">Lost</th>
                        <th class="stats-col">Won</th>
                        <th class="stats-col">Lost</th>
                        <th class="stats-col">Ratio</th>
                        <th class="stats-col">Won</th>
                        <th class="stats-col">Lost</th>
                        <th class="stats-col">Ratio</th>
                        <th class="stats-col">3-0</th>
                        <th class="stats-col">3-1</th>
                        <th class="stats-col">3-2</th>
                        <th class="stats-col">2-3</th>
                        <th class="stats-col">1-3</th>
                        <th class="stats-col">0-3</th>
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
                        <td class="position-col"><?php echo esc_html($team->position); ?></td>
                        <td class="team-name">
                            <?php if ($team->logo_url): ?>
                                <img src="<?php echo esc_url($team->logo_url); ?>" alt="<?php echo esc_attr($team->team_name); ?>" class="team-logo">
                            <?php endif; ?>
                            <?php echo esc_html($team->team_name); ?>
                        </td>
                        <td class="points-col"><?php echo esc_html($team->ranking_points); ?></td>

                        <!-- Matches -->
                        <td class="stats-col"><?php echo esc_html($match_stats['played'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($match_stats['won'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($match_stats['lost'] ?? 0); ?></td>

                        <!-- Sets -->
                        <td class="stats-col"><?php echo esc_html($set_stats['won'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($set_stats['lost'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo number_format($set_stats['ratio'] ?? 0, 3); ?></td>

                        <!-- Points -->
                        <td class="stats-col"><?php echo esc_html($point_stats['won'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($point_stats['lost'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo number_format($point_stats['ratio'] ?? 0, 3); ?></td>

                        <!-- Results Breakdown -->
                        <td class="stats-col"><?php echo esc_html($result_breakdown['wins3_0'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($result_breakdown['wins3_1'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($result_breakdown['wins3_2'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($result_breakdown['losses2_3'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($result_breakdown['losses1_3'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($result_breakdown['losses0_3'] ?? 0); ?></td>

                        <!-- Ratios -->
                        <td class="ratio-col"><?php echo number_format($set_stats['ratio'] ?? 0, 3); ?></td>
                        <td class="ratio-col"><?php echo number_format($point_stats['ratio'] ?? 0, 3); ?></td>

                        <!-- Penalty -->
                        <td class="penalty-col"><?php echo esc_html($team->penalty ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
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