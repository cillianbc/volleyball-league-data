# Active Context: Volleyball League Import Plugin

## Current Work Focus
Updating the VolleyballImport plugin to fetch team data from a GitHub repository instead of direct n8n input. Focus on integrating GitHub API calls into the import process, adding shortcodes for displaying current league tables and historical trend lines using Chart.js. Ensuring the solution is portable via simple plugin installation and shortcode usage.

## Recent Changes
- Initialized Memory Bank with core files: projectbrief.md, productContext.md, systemPatterns.md, techContext.md.
- Provided plugin file (volleyball-import.php) with existing DB table, REST API for import/get, but no GitHub integration yet.
- User specified GitHub structure: /current folder with one JSON per league for current week; /historical with timestamped versions (e.g., {date}-{league}.json) for weekly trends.

## Next Steps
- Clarify missing GitHub details: exact repo (owner/repo/branch), authentication (token storage), data format confirmation, import trigger (manual POST or cron).
- Update plugin: Modify import_teams to fetch from GitHub /current files, parse/insert to DB; add method to fetch historical for trends if needed.
- Implement shortcodes: [volleyball_table league="x"] for HTML table from DB; [volleyball_trends league="x"] for Chart.js line chart of weekly data.
- Test integration, document in progress.md.
- Switch to code mode for implementation after plan approval.

## Active Decisions and Considerations
- **GitHub File Handling**: Fetch all /current/*.json or specific league file; assume JSON array of teams matching {teamId, teamName, position, rankingPoints, logoUrl, matches[], etc.}.
- **Trends Implementation**: Shortcode queries DB for historical entries by league/date, aggregates data (e.g., position per week), feeds to Chart.js config (line chart with dates on x-axis, position/rankings on y).
- **Trigger**: Default to manual POST to /import-teams; optional WP cron for auto-fetch weekly.
- **Auth**: Store token in WP options; use in wp_remote_get headers. Consider public repo if no auth needed, but prefer token for private.
- **Shortcode Params**: league (required), type (table/trends default table), date_range for historical.

## Important Patterns and Preferences
- Keep plugin lightweight: Enqueue Chart.js only when trends shortcode used.
- Error resilience: Fallback to empty display if fetch fails; log errors via WP debug.
- Responsive design: Use CSS classes for tables; Chart.js responsive: true.

## Learnings and Project Insights
- Portability achieved by decoupling data source (GitHub) from WP DB, allowing multi-site use.
- Historical data enables value-add features like trends, differentiating from simple tables.
- WP shortcodes provide universal access without custom themes/pages.
- Potential: Expand to admin dashboard for import button, league selection.