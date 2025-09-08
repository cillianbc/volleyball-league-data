# Product Context: Volleyball League Import Plugin

## Why This Project Exists
The project addresses the need for a portable solution to import and display volleyball league table data in WordPress sites. Previously, data was logged directly to WP database via n8n, limiting portability across sites. Now, using GitHub as an intermediary API endpoint (storing JSON files) allows any WP site with the plugin to fetch and display data without custom n8n integrations.

## Problems Solved
- **Portability**: Plugin fetches data from public/shared GitHub repo, enabling easy deployment on any WP instance.
- **Data Persistence**: "Current" folder for latest weekly league tables (one JSON per league); "historical" for timestamped archives to enable trend analysis.
- **Display Flexibility**: Universal shortcodes for embedding tables and Chart.js-based trend lines in posts/pages.
- **Separation of Concerns**: n8n handles data collection and pushes to GitHub; plugin handles WP-specific import and rendering.

## How It Should Work
1. n8n scrapes/collects volleyball data weekly and pushes JSON files to GitHub repo: /current/{league}.json for latest, /historical/{date}-{league}.json for archives.
2. WP plugin's import endpoint (triggered manually or via cron) fetches files from GitHub, parses JSON (array of teams with fields like teamId, teamName, league, position, rankingPoints, etc.), inserts/updates DB table.
3. Shortcodes query DB for current/historical data: e.g., [volleyball_table league="premier"] displays table; [volleyball_trends league="premier"] shows Chart.js line chart of weekly positions/rankings.
4. Data flow ensures idempotency (daily/weekly updates) and supports trends by aggregating historical fetches.

## User Experience Goals
- Simple shortcode usage for non-technical users to embed league tables/trends.
- Responsive, clean displays using Chart.js for interactive trends (e.g., position over time).
- Admin can trigger imports via API or schedule; view logs in WP admin.
- Minimal setup: Configure GitHub token in WP options for auth.