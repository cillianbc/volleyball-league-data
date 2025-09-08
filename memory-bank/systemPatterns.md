# System Patterns: Volleyball League Import Plugin

## System Architecture
The plugin follows WordPress best practices for extensibility and security. Core is a PHP class `VolleyballImport` that initializes on plugin load, creating a custom DB table and registering REST API routes. Data flows from external n8n to GitHub repo (JSON files in /current and /historical folders), fetched by WP via GitHub API, stored in DB, and rendered via shortcodes or REST GET.

## Key Technical Decisions
- **Data Storage**: Custom WP DB table with JSON-encoded stats for flexibility; indexes on league/date/team for query efficiency.
- **API Integration**: Use WordPress HTTP API (`wp_remote_get`) to fetch GitHub contents (requires auth token stored in `wp_options`). Parse JSON responses to match existing team structure.
- **Import Logic**: Idempotent upserts based on team_id/league/import_date to avoid duplicates; triggered via POST REST or WP cron.
- **Display**: Shortcodes for universal embedding: `[volleyball_table league="x"]` for current tables (HTML table), `[volleyball_trends league="x"]` for historical trends (Chart.js canvas with line charts for position/rankings over time).
- **Security**: Sanitize inputs, capability checks for imports, public GET for display; GitHub token stored encrypted if possible (via WP options).
- **Enqueuing Assets**: wp_enqueue_script/style for Chart.js on shortcode pages only.

## Design Patterns
- **Singleton-like Initialization**: Class instantiated once via `new VolleyballImport()` at plugin bottom.
- **Hook-Based**: Actions/filters for REST init, activation hooks for table creation.
- **Repository Pattern**: DB operations centralized in class methods (e.g., import_teams, get_teams).
- **Template Rendering**: Shortcodes use output buffering or direct echo for HTML/JS generation.
- **Error Handling**: WP_Error for failures, JSON responses for API.

## Component Relationships
- **VolleyballImport Class** → DB Table (create/update/query) → REST Routes (POST import, GET teams).
- **Shortcodes** → Query DB → Render HTML/JS (enqueues Chart.js for trends).
- **GitHub Fetch** → Integrated into import_teams method: loop over leagues/files, fetch/parse/insert.
- **n8n External** → GitHub API (create/update files in /current/{league}.json and /historical/{date}-{league}.json).
- **Chart.js** → Historical data aggregation (fetch multiple timestamps, plot lines).

## Critical Implementation Paths
1. **Import Path**: Trigger → Fetch GitHub files (current/historical) → Parse JSON array → Validate/sanitize → Upsert to DB → Return results.
2. **Display Path**: Shortcode call → Query latest DB data → Generate HTML table or Chart.js config → Output.
3. **Trend Path**: Shortcode → Query historical DB entries by league/date range → Aggregate (e.g., avg position per week) → Chart.js data array → Render canvas.
4. **Setup Path**: Plugin activation → Create table; Admin config → Set GitHub token/repo details in options.