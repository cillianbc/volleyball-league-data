# System Patterns: Volleyball League Import Plugin

## System Architecture - Modular Design
The plugin follows WordPress best practices with a modular architecture for maintainability and extensibility. Core is separated into specialized classes:

- **VolleyballImport** (main): Plugin initialization, WordPress hook management, database table creation
- **VolleyballAdmin**: Settings page with hardcoded shortcode display, GitHub configuration, color settings
- **VolleyballShortcode**: Shortcode handlers with server-side first sub-league rendering and AJAX switching
- **VolleyballAjax**: REST API endpoints for data import/retrieval, GitHub integration
- **VolleyballUtils**: Utility functions, frontend asset enqueuing with fallback mechanisms
- **VolleyballLeagues**: League definitions, sub-league logic, hardcoded shortcode options

## Key Technical Decisions
- **Modular Architecture**: Separate files in `/includes/` directory with single responsibility classes to improve maintainability and debugging
- **Deferred Initialization**: WordPress hooks registered in constructor, actual initialization deferred until WordPress is fully loaded via `init` action
- **Data Storage**: Custom WP DB table with JSON-encoded stats for flexibility; subLeague field for nested leagues; indexes on league/date/team/subLeague for query efficiency
- **API Integration**: WordPress HTTP API (`wp_remote_get`) to fetch GitHub contents with auth token stored in `wp_options`. Parse JSON responses to handle nested "subLeagues" and flat "teams" with subLeague field
- **Import Logic**: Idempotent upserts based on team_id/league/import_date/subLeague to avoid duplicates; triggered via POST REST or WP cron
- **Display Strategy**: Server-side rendering of first sub-league for immediate display, JavaScript/AJAX for dynamic switching between sub-leagues
- **Hardcoded Shortcodes**: Fixed set of 8 league shortcode options to prevent copy-paste errors and ensure consistency
- **Asset Enqueuing**: Intelligent loading with multiple fallback mechanisms for page builders like Bricks; CSS variables for customization

## Design Patterns
- **Modular Class Structure**: Each class handles specific functionality with clear separation of concerns
- **Singleton-like Initialization**: Main class instantiated once with proper WordPress hook integration
- **Hook-Based Architecture**: Actions/filters for REST init, activation hooks, enqueuing with proper timing
- **Repository Pattern**: DB operations centralized in class methods with proper sanitization and error handling
- **Template Rendering**: Shortcodes use output buffering for HTML/JS generation with immediate table display for nested leagues
- **Fallback Strategy Pattern**: Multiple mechanisms for asset loading to handle various WordPress environments
- **Utility Class Pattern**: Shared functionality centralized in VolleyballUtils for code reuse

## Component Relationships
- **VolleyballImport** → Initializes all components → Manages WordPress lifecycle
- **VolleyballAdmin** → WP Settings API → Hardcoded shortcode grid → GitHub config → Color settings
- **VolleyballShortcode** → VolleyballLeagues (league detection) → DB queries → Server-side first sub-league rendering → AJAX switching
- **VolleyballAjax** → REST routes → GitHub API integration → Data import/retrieval → JSON processing
- **VolleyballUtils** → Asset enqueuing → Fallback mechanisms → Utility functions → Data formatting
- **VolleyballLeagues** → Hardcoded league definitions → Sub-league logic → First sub-league detection

## Critical Implementation Paths
1. **Plugin Loading**: File inclusion → Class instantiation → WordPress init hook → Component initialization → Hook registration
2. **Import Path**: Manual/cron trigger → GitHub API calls → JSON parsing → Data validation → Database upserts → Response
3. **Display Path**: Shortcode call → League detection → First sub-league query → Server-side table rendering → AJAX setup for switching
4. **Sub-League Switching**: User click → AJAX request → REST endpoint → Database query → JSON response → Dynamic table update
5. **Asset Loading**: WordPress enqueue hooks → Shortcode detection → Asset registration → Fallback mechanisms → Page builder compatibility
6. **Admin Interface**: Settings page load → Hardcoded shortcode grid → Copy functionality → GitHub configuration → Color management

## Error Handling and Resilience
- **WordPress Function Availability**: Checks for function existence before calling WordPress APIs
- **Class Loading**: Sequential file inclusion with path resolution using `__DIR__`
- **Database Operations**: Prepared statements, error checking, graceful degradation
- **Asset Enqueuing**: Multiple fallback mechanisms for different WordPress environments
- **AJAX Handling**: Error responses, timeout handling, fallback data loading strategies
- **GitHub Integration**: Network error handling, JSON parsing validation, authentication failure responses

## Security Patterns
- **Input Sanitization**: All user inputs processed through WordPress sanitization functions
- **Output Escaping**: All output properly escaped using esc_html, esc_attr, esc_url functions
- **Capability Checks**: Proper permission verification for admin operations and data imports
- **SQL Injection Prevention**: Prepared statements for all database operations
- **XSS Prevention**: Proper escaping in both PHP and JavaScript output
- **CSRF Protection**: Nonces for AJAX requests and form submissions