<?php
/**
 * Volleyball League Utilities
 *
 * Utility functions and helpers for the volleyball league plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class VolleyballUtils {

    /**
     * Safely decode JSON data that might already be an array
     */
    public static function safe_json_decode($data) {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : array();
        }

        return array();
    }

    /**
     * Get primary color from settings
     */
    public static function get_primary_color() {
        return get_option('volleyball_primary_color', '#007cba');
    }

    /**
     * Get secondary color from settings
     */
    public static function get_secondary_color() {
        return get_option('volleyball_secondary_color', '#6c757d');
    }

    /**
     * Generate CSS variables for custom colors
     */
    public static function get_custom_css_variables() {
        $primary_color = self::get_primary_color();
        $secondary_color = self::get_secondary_color();

        return "
            :root {
                --volleyball-primary-color: {$primary_color};
                --volleyball-secondary-color: {$secondary_color};
            }
        ";
    }

    /**
     * Sanitize and validate league parameter
     */
    public static function sanitize_league_param($league) {
        if (empty($league)) {
            return '';
        }

        // Decode URL-encoded parameters
        $decoded_league = urldecode($league);
        return stripslashes(sanitize_text_field($decoded_league));
    }

    /**
     * Sanitize and validate subleague parameter
     */
    public static function sanitize_subleague_param($subleague) {
        if (empty($subleague)) {
            return '';
        }

        // Decode URL-encoded parameters
        $decoded_subleague = urldecode($subleague);
        return stripslashes(sanitize_text_field($decoded_subleague));
    }

    /**
     * Format team data for display
     */
    public static function format_team_data($team) {
        if (!is_object($team) && !is_array($team)) {
            return null;
        }

        // Convert to object if array
        if (is_array($team)) {
            $team = (object) $team;
        }

        // Decode JSON fields
        $team->match_stats = self::safe_json_decode($team->match_stats);
        $team->set_stats = self::safe_json_decode($team->set_stats);
        $team->point_stats = self::safe_json_decode($team->point_stats);
        $team->result_breakdown = self::safe_json_decode($team->result_breakdown);

        return $team;
    }

    /**
     * Generate unique ID for elements
     */
    public static function generate_unique_id($prefix = 'volleyball') {
        return $prefix . '-' . uniqid();
    }

    /**
     * Check if current user has required capabilities
     */
    public static function check_user_capability($capability = 'edit_posts') {
        return current_user_can($capability);
    }

    /**
     * Get plugin directory URL
     */
    public static function get_plugin_url() {
        return plugin_dir_url(dirname(__FILE__));
    }

    /**
     * Get plugin directory path
     */
    public static function get_plugin_path() {
        return plugin_dir_path(dirname(__FILE__));
    }

    /**
     * Log debug information
     */
    public static function debug_log($message, $data = null) {
        if (WP_DEBUG) {
            $log_message = '[Volleyball Plugin] ' . $message;
            if ($data !== null) {
                $log_message .= ' - Data: ' . print_r($data, true);
            }
            error_log($log_message);
        }
    }

    /**
     * Validate shortcode attributes
     */
    public static function validate_shortcode_atts($atts, $required = array(), $defaults = array()) {
        // Set defaults
        $atts = shortcode_atts($defaults, $atts);

        // Check required fields
        foreach ($required as $field) {
            if (empty($atts[$field])) {
                return new WP_Error('missing_required_field', "Required field '{$field}' is missing");
            }
        }

        return $atts;
    }

    /**
     * Format number with specified decimal places
     */
    public static function format_number($number, $decimals = 3) {
        if (!is_numeric($number)) {
            return '0.' . str_repeat('0', $decimals);
        }

        return number_format((float) $number, $decimals);
    }

    /**
     * Escape HTML content safely
     */
    public static function escape_html($content) {
        if ($content === null || $content === false) {
            return '';
        }

        return esc_html($content);
    }

    /**
     * Check if we're in a Bricks Builder context
     */
    public static function is_bricks_context() {
        return function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main');
    }

    /**
     * Get current timestamp in MySQL format
     */
    public static function get_current_mysql_timestamp() {
        return current_time('mysql');
    }

    /**
     * Get current date in Y-m-d format
     */
    public static function get_current_date() {
        return current_time('Y-m-d');
    }

    /**
     * Initialize frontend enqueue functionality
     */
    public static function init_enqueue() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'), 20);
        add_action('wp_head', array(__CLASS__, 'enqueue_scripts_fallback'), 20);
        add_action('wp_footer', array(__CLASS__, 'enqueue_scripts_fallback'), 5);

        // Additional hooks for Bricks compatibility
        add_action('bricks_content_end', array(__CLASS__, 'enqueue_scripts_fallback'), 10);
        add_action('bricks_after_site_wrapper', array(__CLASS__, 'enqueue_scripts_fallback'), 10);
    }

    /**
     * Enqueue scripts and styles
     */
    public static function enqueue_scripts() {
        global $post;

        // Debug: Log that enqueue function is called
        self::debug_log('enqueue_scripts called');

        $primary_color = self::get_primary_color();
        $secondary_color = self::get_secondary_color();

        // Debug: Check conditions
        self::debug_log('is_singular() = ' . (is_singular() ? 'true' : 'false'));
        if (is_a($post, 'WP_Post')) {
            self::debug_log('Post ID: ' . $post->ID);
            self::debug_log('has_shortcode volleyball_table = ' . (has_shortcode($post->post_content, 'volleyball_table') ? 'true' : 'false'));
            self::debug_log('post content preview = ' . substr($post->post_content, 0, 100));

            // Check for Bricks content
            if (function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main')) {
                self::debug_log('Bricks detected in main enqueue');
            }

            // Try to detect shortcode in a different way for Bricks
            if (strpos($post->post_content, '[volleyball_table') !== false ||
                strpos($post->post_content, 'volleyball_table') !== false) {
                self::debug_log('Shortcode text found in content');
            }
        } else {
            self::debug_log('No valid post object in main enqueue');
        }

        // Always enqueue styles and scripts if we're on a singular post/page
        if (is_singular()) {
            self::debug_log('Enqueueing styles');
            wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css', array(), '5.1.3');
            wp_enqueue_style('volleyball-styles', self::get_plugin_url() . 'volleyball-styles.css', array('bootstrap-css'), '1.0.0');

            // Set CSS variables from settings
            $custom_css = self::get_custom_css_variables();
            wp_add_inline_style('volleyball-styles', $custom_css);

            // Enqueue jQuery for AJAX
            wp_enqueue_script('jquery');

            // Check for shortcode in a more flexible way for Bricks compatibility
            $should_enqueue = false;

            if (is_a($post, 'WP_Post')) {
                if (has_shortcode($post->post_content, 'volleyball_table')) {
                    $should_enqueue = true;
                    self::debug_log('Shortcode detected via has_shortcode');
                }
                // Additional check for Bricks or other page builders
                elseif (strpos($post->post_content, '[volleyball_table') !== false ||
                       strpos($post->post_content, 'volleyball_table') !== false) {
                    $should_enqueue = true;
                    self::debug_log('Shortcode detected via string search');
                }
                // Check for Bricks specifically
                elseif (function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main')) {
                    $should_enqueue = true;
                    self::debug_log('Enqueueing for Bricks compatibility');
                }
            }

            // Force enqueue on all singular pages for testing
            $force_enqueue = true; // Set to false after testing
            if ($force_enqueue) {
                $should_enqueue = true;
                self::debug_log('Force enqueueing for testing');
            }

            if ($should_enqueue) {
                self::debug_log('Enqueueing AJAX script');
                wp_enqueue_script('volleyball-ajax', self::get_plugin_url() . 'volleyball-ajax.js', array('jquery'), '1.0.0', true);

                // Localize script with AJAX URL and nonce
                wp_localize_script('volleyball-ajax', 'volleyball_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'rest_url' => rest_url('volleyball/v1/teams/'),
                    'nonce' => wp_create_nonce('volleyball_subleague_nonce')
                ));
                self::debug_log('Localized script with: ' . json_encode(array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'rest_url' => rest_url('volleyball/v1/teams/'),
                    'nonce' => wp_create_nonce('volleyball_subleague_nonce')
                )));
            } else {
                self::debug_log('Not enqueueing AJAX script - conditions not met');
            }

            // Enqueue Chart.js for trends shortcode
            if (is_a($post, 'WP_Post') &&
                (has_shortcode($post->post_content, 'volleyball_trends') ||
                 strpos($post->post_content, '[volleyball_trends') !== false)) {
                wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true);
                self::debug_log('Enqueueing Chart.js for trends');
            }
        } else {
            self::debug_log('Not a singular page, skipping enqueue');
        }
    }

    /**
     * Fallback enqueue function for Bricks compatibility
     */
    public static function enqueue_scripts_fallback() {
        global $post;

        self::debug_log('enqueue_scripts_fallback called');

        // Check if we're on a singular page
        if (!is_singular()) {
            self::debug_log('Not a singular page, exiting fallback');
            return;
        }

        // Check if $post is a valid WP_Post object
        if (!is_a($post, 'WP_Post')) {
            self::debug_log('No valid post object, exiting fallback');
            return;
        }

        // Log post content for debugging
        self::debug_log('Post ID: ' . $post->ID);
        self::debug_log('Post content preview: ' . substr($post->post_content, 0, 100));

        // Check for shortcode in post content
        $has_shortcode = has_shortcode($post->post_content, 'volleyball_table');
        self::debug_log('has_shortcode result: ' . ($has_shortcode ? 'true' : 'false'));

        // Check for Bricks content
        $is_bricks = false;
        if (function_exists('bricks_is_builder_call') || function_exists('bricks_is_builder_main')) {
            $is_bricks = true;
            self::debug_log('Bricks detected');
        }

        // Force enqueue for Bricks or if shortcode is detected
        if (!$has_shortcode && !$is_bricks) {
            // Try to detect shortcode in a different way for Bricks
            if (strpos($post->post_content, '[volleyball_table') !== false ||
                strpos($post->post_content, 'volleyball_table') !== false) {
                self::debug_log('Shortcode text found in content but not detected by has_shortcode');
                $has_shortcode = true;
            } else {
                self::debug_log('No shortcode detected, exiting fallback');
                return;
            }
        }

        // Check if our script is already enqueued
        if (wp_script_is('volleyball-ajax', 'enqueued')) {
            self::debug_log('Script already enqueued, exiting fallback');
            return;
        }

        self::debug_log('Using fallback enqueue method');

        // Manually enqueue the script
        $js_file_url = self::get_plugin_url() . 'volleyball-ajax.js';
        self::debug_log('Script URL: ' . $js_file_url);
        echo '<script src="' . esc_url($js_file_url) . '" id="volleyball-ajax-js"></script>';

        // Add the localized data
        $localized_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('volleyball/v1/teams/'),
            'nonce' => wp_create_nonce('volleyball_subleague_nonce')
        );

        self::debug_log('Localizing with data: ' . json_encode($localized_data));
        echo '<script>var volleyball_ajax = ' . wp_json_encode($localized_data) . ';</script>';
        self::debug_log('Fallback enqueue completed');
    }
}