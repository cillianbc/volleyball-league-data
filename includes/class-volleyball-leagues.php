<?php
/**
 * Volleyball League Definitions
 * Contains all league mappings and shortcode definitions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class VolleyballLeagues {

    /**
     * Get all available league shortcode options
     * Returns array of league definitions for admin display
     */
    public static function get_shortcode_options() {
        return array(
            array(
                'label' => "Men's Premier Division",
                'shortcode' => '[volleyball_table league="Men\'s Premier Division"]',
                'has_subleagues' => false
            ),
            array(
                'label' => "Women's Premier Division",
                'shortcode' => '[volleyball_table league="Women\'s Premier Division"]',
                'has_subleagues' => false
            ),
            array(
                'label' => "Men's Division 1",
                'shortcode' => '[volleyball_table league="Men\'s Division 1"]',
                'has_subleagues' => false
            ),
            array(
                'label' => "Women's Division 1",
                'shortcode' => '[volleyball_table league="Women\'s Division 1"]',
                'has_subleagues' => false
            ),
            array(
                'label' => "Men's Division 2 (with sub-leagues)",
                'shortcode' => '[volleyball_table league="Men\'s Division 2"]',
                'has_subleagues' => true,
                'note' => 'Shows accordion with first sub-league loaded by default'
            ),
            array(
                'label' => "Men's Division 3 (with sub-leagues)",
                'shortcode' => '[volleyball_table league="Men\'s Division 3"]',
                'has_subleagues' => true,
                'note' => 'Shows accordion with first sub-league loaded by default'
            ),
            array(
                'label' => "Women's Division 2 (with sub-leagues)",
                'shortcode' => '[volleyball_table league="Women\'s Division 2"]',
                'has_subleagues' => true,
                'note' => 'Shows accordion with first sub-league loaded by default'
            ),
            array(
                'label' => "Women's Division 3 (with sub-leagues)",
                'shortcode' => '[volleyball_table league="Women\'s Division 3"]',
                'has_subleagues' => true,
                'note' => 'Shows accordion with first sub-league loaded by default'
            )
        );
    }

    /**
     * Get league name mapping for file processing
     * Maps filename to proper league name
     */
    public static function get_league_name_mapping() {
        return array(
            'mens-premier-division' => "Men's Premier Division",
            'womens-premier-division' => "Women's Premier Division",
            'mens-division-1' => "Men's Division 1",
            'womens-division-1' => "Women's Division 1",
            'division-2-men' => "Men's Division 2",
            'division-3-men' => "Men's Division 3",
            'wo-division-2-women' => "Women's Division 2",
            'wo-division-3-women' => "Women's Division 3"
        );
    }

    /**
     * Get nested leagues that have sub-leagues
     */
    public static function get_nested_leagues() {
        return array(
            "Men's Division 2", "Men's Division 3",
            "Women's Division 2", "Women's Division 3"
        );
    }

    /**
     * Check if a league has sub-leagues
     */
    public static function has_subleagues($league_name) {
        $nested_leagues = self::get_nested_leagues();
        return in_array($league_name, $nested_leagues);
    }

    /**
     * Get the first available sub-league for a nested league
     */
    public static function get_first_subleague($league_name, $table_name) {
        global $wpdb;

        $latest_date = $wpdb->get_var(
            "SELECT MAX(DATE(import_date)) FROM {$table_name}"
        );

        if (!$latest_date) {
            return null;
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT subleague FROM {$table_name}
             WHERE league = %s AND subleague IS NOT NULL AND DATE(import_date) = %s
             ORDER BY subleague ASC LIMIT 1",
            $league_name, $latest_date
        ));
    }
}