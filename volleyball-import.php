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
    private $admin;
    private $shortcode;
    private $ajax;

    public function __construct() {
        // Set table name immediately
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'volleyball_teams';
        
        // Include modular classes first (before any WordPress hooks)
        $this->include_classes();
        
        // Hook into WordPress init to ensure all functions are available
        add_action('init', array($this, 'init'));
        
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'create_table'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
    }

    /**
     * Initialize the plugin after WordPress is fully loaded
     */
    public function init() {
        // Initialize modular classes
        $this->admin = new VolleyballAdmin();
        $this->shortcode = new VolleyballShortcode();
        $this->ajax = new VolleyballAjax();

        // Initialize components
        $this->init_hooks();
    }

    /**
     * Include all required class files using __DIR__ to avoid WordPress function dependency
     */
    private function include_classes() {
        require_once __DIR__ . '/includes/class-volleyball-leagues.php';
        require_once __DIR__ . '/includes/class-volleyball-admin.php';
        require_once __DIR__ . '/includes/class-volleyball-shortcode.php';
        require_once __DIR__ . '/includes/class-volleyball-ajax.php';
        require_once __DIR__ . '/includes/class-volleyball-utils.php';
    }

    /**
     * Initialize WordPress hooks and components
     */
    private function init_hooks() {
        // Initialize admin interface
        $this->admin->init();

        // Initialize frontend enqueue functionality (check if method exists first)
        if (method_exists('VolleyballUtils', 'init_enqueue')) {
            VolleyballUtils::init_enqueue();
        } else {
            // Fallback: enqueue manually
            add_action('wp_enqueue_scripts', array($this, 'fallback_enqueue_scripts'), 20);
        }

        // Hook into WordPress
        add_action('rest_api_init', array($this->ajax, 'register_routes'));
        add_action('wp_ajax_volleyball_manual_import', array($this->ajax, 'ajax_manual_import'));
        add_action('wp', array($this->ajax, 'schedule_cron'));
        add_action('volleyball_weekly_import', array($this->ajax, 'perform_cron_import'));
        add_shortcode('volleyball_table', array($this->shortcode, 'table_shortcode'));
        add_shortcode('volleyball_trends', array($this->shortcode, 'trends_shortcode'));
    }

    /**
     * Fallback enqueue method if VolleyballUtils isn't loaded
     */
    public function fallback_enqueue_scripts() {
        if (is_singular()) {
            wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css', array(), '5.1.3');
            wp_enqueue_style('volleyball-styles', plugin_dir_url(__FILE__) . 'volleyball-styles.css', array('bootstrap-css'), '1.0.0');
            wp_enqueue_script('jquery');
            wp_enqueue_script('volleyball-ajax', plugin_dir_url(__FILE__) . 'volleyball-ajax.js', array('jquery'), '1.0.0', true);
            wp_localize_script('volleyball-ajax', 'volleyball_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => rest_url('volleyball/v1/teams/'),
                'nonce' => wp_create_nonce('volleyball_subleague_nonce')
            ));
        }
    }

    /**
     * Plugin deactivation cleanup
     */
    public function deactivate_plugin() {
        if (isset($this->ajax)) {
            $this->ajax->deactivate_cron();
        }
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
}

// Initialize the plugin
new VolleyballImport();