<?php
/**
 * Volleyball League AJAX Handler
 *
 * Handles AJAX requests and REST API endpoints for the volleyball league plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class VolleyballAjax {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'volleyball_teams';
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

            // Map filename to proper league name using VolleyballLeagues class
            $league_name_map = VolleyballLeagues::get_league_name_mapping();
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
}