<?php
/**
 * Volleyball League Shortcode Handler
 *
 * Handles shortcode processing for the volleyball league plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class VolleyballShortcode {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'volleyball_teams';
    }

    /**
     * Table shortcode [volleyball_table league="x"]
     */
    public function table_shortcode($atts) {
        $atts = shortcode_atts(array(
            'league' => '',
            'subleague' => '',
            'view' => 'full'
        ), $atts);

        if (empty($atts['league'])) {
            return '<p>No league specified for table.</p>';
        }

        global $wpdb;
        $league = sanitize_text_field($atts['league']);
        $subleague = sanitize_text_field($atts['subleague']);
        $view = sanitize_text_field($atts['view']);
        
        // Validate view parameter
        if (!in_array($view, array('full', 'condensed'))) {
            $view = 'full';
        }

        // Check if this is a nested league using VolleyballLeagues class
        $is_nested = VolleyballLeagues::has_subleagues($league);

        $latest_date = $wpdb->get_var(
            "SELECT MAX(DATE(import_date)) FROM {$this->table_name}"
        );

        if ($is_nested && empty($subleague)) {
            // Get first available sub-league for auto-loading
            $first_subleague = VolleyballLeagues::get_first_subleague($league, $this->table_name);

            if (!$first_subleague) {
                return '<p>No sub-league data available for ' . esc_html($league) . '</p>';
            }

            // Get all sub-leagues for the accordion
            $subleagues = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT subleague
                 FROM {$this->table_name}
                 WHERE league = %s AND subleague IS NOT NULL AND DATE(import_date) = %s
                 ORDER BY subleague ASC",
                $league, $latest_date
            ));

            // Get teams for the first sub-league to display immediately
            $first_subleague_teams = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE league = %s AND subleague = %s AND DATE(import_date) = %s
                 ORDER BY position ASC",
                $league, $first_subleague, $latest_date
            ));

            // Decode JSON fields for the first sub-league teams
            foreach ($first_subleague_teams as &$team) {
                $team->match_stats = json_decode($team->match_stats, true);
                $team->set_stats = json_decode($team->set_stats, true);
                $team->point_stats = json_decode($team->point_stats, true);
                $team->result_breakdown = json_decode($team->result_breakdown, true);
            }

            $accordion_id = 'volleyball-accordion-' . uniqid();
            ob_start();
            ?>
            <div class="volleyball-accordion" id="<?php echo esc_attr($accordion_id); ?>" data-league="<?php echo esc_attr($league); ?>" data-view="<?php echo esc_attr($view); ?>">
                <h3><?php echo esc_html($league); ?> - Select Sub-League</h3>
                <div class="accordion-headers">
                    <?php foreach ($subleagues as $index => $sub):
                        $current_subleague = $sub->subleague;
                        $is_first = ($current_subleague === $first_subleague);
                    ?>
                    <button class="accordion-header<?php if ($is_first) echo ' active'; ?>" data-subleague="<?php echo esc_attr($current_subleague); ?>"<?php if ($is_first) echo ' data-autoload="true"'; ?>>
                        <?php echo esc_html($current_subleague); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="accordion-content">
                    <div class="volleyball-loading" style="display: none;">Loading...</div>
                    <div id="subleague-table-container">
                        <?php if (!empty($first_subleague_teams)): ?>
                        <div class="volleyball-table-container">
                            <h3><?php echo esc_html($league); ?> - <?php echo esc_html($first_subleague); ?></h3>
                            <?php
                            if ($view === 'condensed') {
                                $this->render_condensed_table($first_subleague_teams);
                            } else {
                                $this->render_team_table($first_subleague_teams);
                            }
                            ?>
                        </div>
                        <?php else: ?>
                        <div class="volleyball-error">No teams found for <?php echo esc_html($first_subleague); ?></div>
                        <?php endif; ?>
                    </div>
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
                <?php
                if ($view === 'condensed') {
                    $this->render_condensed_table($teams);
                } else {
                    $this->render_team_table($teams);
                }
                ?>
            </div>
            <?php
            return ob_get_clean();
        }
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
    private function fetch_historical($league = null) {
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
     * Render condensed team table HTML (6 columns)
     */
    private function render_condensed_table($teams) {
        ?>
        <div class="table-responsive">
            <table class="volleyball-league-table volleyball-table-condensed">
                <thead>
                    <tr>
                        <th class="position-col">Pos</th>
                        <th class="team-col">Team</th>
                        <th class="stats-col">Games Played</th>
                        <th class="stats-col">Wins</th>
                        <th class="stats-col">Losses</th>
                        <th class="points-col">Ranking Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $team):
                        // Safely decode JSON fields, handling both string and array data
                        $match_stats = $this->safe_json_decode($team->match_stats);
                    ?>
                    <tr>
                        <td class="position-col"><?php echo esc_html($team->position); ?></td>
                        <td class="team-name">
                            <?php if ($team->logo_url): ?>
                                <img src="<?php echo esc_url($team->logo_url); ?>" alt="<?php echo esc_attr($team->team_name); ?>" class="team-logo">
                            <?php endif; ?>
                            <?php echo esc_html($team->team_name); ?>
                        </td>
                        <td class="stats-col"><?php echo esc_html($match_stats['played'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($match_stats['won'] ?? 0); ?></td>
                        <td class="stats-col"><?php echo esc_html($match_stats['lost'] ?? 0); ?></td>
                        <td class="points-col"><?php echo esc_html($team->ranking_points); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
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