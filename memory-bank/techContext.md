# Tech Context: Volleyball League Import Plugin

## Technologies Used
- **Core**: WordPress (PHP 7.4+), custom plugin with class-based structure.
- **Database**: WordPress $wpdb for MySQL interactions; custom table with JSON fields for stats.
- **API**: WordPress REST API (v2) for import/retrieve endpoints; GitHub REST API (v3) for fetching JSON files (endpoints like /repos/{owner}/{repo}/contents/{path}).
- **Frontend Display**: PHP shortcodes for embedding; HTML/CSS for tables; Chart.js (v4+) for trend line charts (line graphs for positions/rankings over time).
- **External**: n8n for data collection and GitHub pushes (assumed external workflow, not in plugin scope).

## Development Setup
- **Environment**: Local WordPress site in VSCode workspace (/Users/cillianbrackenconway/Local Sites/DVC - league tables); macOS with zsh shell.
- **Tools**: VSCode for editing; WP-CLI if needed for testing; Git for version control (repo integration via API, not local clone).
- **Testing**: Manual API calls (Postman/curl), shortcode testing in WP posts/pages; WP debug mode for errors.

## Technical Constraints
- **WordPress**: Plugin must be portable (no server-specific configs); avoid direct file writes, use WP functions (sanitize, esc_*).
- **GitHub API**: Rate limits (5k req/hour for auth, 60 unauth); file size limits (1MB per file); requires personal access token (PAT) with repo contents read scope.
- **Performance**: Limit fetches to necessary leagues/files; cache DB queries with transients; Chart.js loads asynchronously.
- **Security**: Store GitHub token in WP options (update_option('volleyball_github_token')); validate JSON structure to prevent injection.
- **Compatibility**: WP 6.0+, PHP 8.0+ preferred; Chart.js via CDN to avoid bloat.

## Dependencies
- **WordPress Core**: $wpdb, WP_REST_Server, wp_remote_get/post, dbDelta, shortcode API.
- **External Libraries**: Chart.js (enqueue from CDN: https://cdn.jsdelivr.net/npm/chart.js); no Composer (keep simple for WP plugins).
- **n8n**: External dependency for populating GitHub; assumes JSON output matches {teamId, teamName, league, position, rankingPoints, logoUrl, matches[], sets[], points[], resultsBreakdown[], penalty}.

## Tool Usage Patterns
- **HTTP Requests**: wp_remote_get for GitHub (headers with Authorization: token {token}); handle responses/errors.
- **JSON Handling**: json_decode/encode for stats; validate array structure.
- **Shortcodes**: add_shortcode('volleyball_table', [handler]); params for league; output HTML table from DB query.
- **Cron/Scheduling**: wp_schedule_event for weekly imports if automated.
- **Admin Config**: Optional settings page to input repo details/token (add_menu_page).