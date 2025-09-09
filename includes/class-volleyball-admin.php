<?php
/**
 * Volleyball Admin Interface
 * Handles admin settings page and shortcode display
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class VolleyballAdmin {

    private $plugin_name;

    public function __construct($plugin_name = 'volleyball-import') {
        $this->plugin_name = $plugin_name;
    }

    /**
     * Initialize admin hooks
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_page'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    /**
     * Add admin settings page
     */
    public function add_admin_page() {
        add_options_page(
            'Volleyball Import Settings',
            'Volleyball Import',
            'manage_options',
            'volleyball-import',
            array($this, 'options_page')
        );
    }

    /**
     * Settings init
     */
    public function settings_init() {
        register_setting('volleyball', 'volleyball_github_owner');
        register_setting('volleyball', 'volleyball_github_repo');
        register_setting('volleyball', 'volleyball_github_branch');
        register_setting('volleyball', 'volleyball_github_token');
        register_setting('volleyball', 'volleyball_primary_color');
        register_setting('volleyball', 'volleyball_secondary_color');

        add_settings_section(
            'volleyball_section',
            'GitHub Configuration',
            null,
            'volleyball'
        );

        add_settings_field(
            'volleyball_github_owner',
            'Repository Owner',
            array($this, 'owner_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_field(
            'volleyball_github_repo',
            'Repository Name',
            array($this, 'repo_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_field(
            'volleyball_github_branch',
            'Branch',
            array($this, 'branch_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_field(
            'volleyball_github_token',
            'Personal Access Token',
            array($this, 'token_render'),
            'volleyball',
            'volleyball_section'
        );

        add_settings_section(
            'volleyball_colors_section',
            'Styling Configuration',
            null,
            'volleyball'
        );

        add_settings_field(
            'volleyball_primary_color',
            'Primary Color',
            array($this, 'primary_color_render'),
            'volleyball',
            'volleyball_colors_section'
        );

        add_settings_field(
            'volleyball_secondary_color',
            'Secondary Color',
            array($this, 'secondary_color_render'),
            'volleyball',
            'volleyball_colors_section'
        );
    }

    /**
     * Render owner field
     */
    public function owner_render() {
        $owner = get_option('volleyball_github_owner', 'cillianbc');
        echo '<input type="text" name="volleyball_github_owner" value="' . esc_attr($owner) . '" />';
    }

    /**
     * Render repo field
     */
    public function repo_render() {
        $repo = get_option('volleyball_github_repo', 'volleyball-league-data');
        echo '<input type="text" name="volleyball_github_repo" value="' . esc_attr($repo) . '" />';
    }

    /**
     * Render branch field
     */
    public function branch_render() {
        $branch = get_option('volleyball_github_branch', 'main');
        echo '<input type="text" name="volleyball_github_branch" value="' . esc_attr($branch) . '" />';
    }

    /**
     * Render token field
     */
    public function token_render() {
        $token = get_option('volleyball_github_token', '');
        echo '<input type="password" name="volleyball_github_token" value="' . esc_attr($token) . '" />';
        echo '<p class="description">Enter your GitHub PAT with Contents: Read-only permission.</p>';
    }

    /**
     * Render primary color field
     */
    public function primary_color_render() {
        $primary_color = get_option('volleyball_primary_color', '#007cba');
        echo '<input type="color" name="volleyball_primary_color" value="' . esc_attr($primary_color) . '" />';
        echo '<p class="description">Choose the primary color for tables (default: WordPress blue).</p>';
    }

    /**
     * Render secondary color field
     */
    public function secondary_color_render() {
        $secondary_color = get_option('volleyball_secondary_color', '#6c757d');
        echo '<input type="color" name="volleyball_secondary_color" value="' . esc_attr($secondary_color) . '" />';
        echo '<p class="description">Choose the secondary color for accents (default: gray).</p>';
    }

    /**
     * Options page HTML
     */
    public function options_page() {
        ?>
        <div class="wrap">
            <h1>Volleyball Import Settings</h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('volleyball');
                do_settings_sections('volleyball');
                submit_button();
                ?>
            </form>

            <hr style="margin: 30px 0;">

            <div class="volleyball-import-section">
                <h2>Manual Import</h2>
                <p>Click the button below to manually import team data from GitHub.</p>
                <button id="volleyball-import-btn" class="button button-primary">Import Teams Now</button>
                <div id="volleyball-import-result" style="margin-top: 20px;"></div>
            </div>

            <hr style="margin: 30px 0;">

            <?php $this->render_shortcodes_section(); ?>
        </div>
        <?php
    }

    /**
     * Render the shortcodes section with all 8 options
     */
    private function render_shortcodes_section() {
        $shortcode_options = VolleyballLeagues::get_shortcode_options();

        ?>
        <div class="volleyball-shortcodes-section">
            <h2>League Table Shortcodes</h2>
            <p>Copy and paste these shortcodes into your pages or posts:</p>

            <div class="volleyball-shortcodes-grid">
                <?php foreach ($shortcode_options as $option): ?>
                    <div class="shortcode-card <?php echo $option['has_subleagues'] ? 'has-subleagues' : ''; ?>">
                        <h4><?php echo esc_html($option['label']); ?></h4>
                        <div class="shortcode-display">
                            <code><?php echo esc_html($option['shortcode']); ?></code>
                            <button class="copy-btn button button-small"
                                    data-shortcode="<?php echo esc_attr($option['shortcode']); ?>"
                                    title="Copy to clipboard">
                                Copy
                            </button>
                        </div>
                        <?php if (isset($option['note'])): ?>
                            <p class="shortcode-note"><?php echo esc_html($option['note']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="shortcode-info">
                <h3>How to Use</h3>
                <ul>
                    <li><strong>Regular leagues</strong> (Premier & Division 1): Display a single table</li>
                    <li><strong>Nested leagues</strong> (Division 2 & 3): Display an accordion with sub-leagues</li>
                    <li><strong>Default behavior</strong>: First sub-league loads automatically for nested leagues</li>
                </ul>
            </div>
        </div>

        <style>
            .volleyball-shortcodes-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .shortcode-card {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                position: relative;
            }

            .shortcode-card.has-subleagues {
                border-left: 4px solid #007cba;
            }

            .shortcode-card h4 {
                margin: 0 0 15px 0;
                color: #23282d;
                font-size: 16px;
            }

            .shortcode-display {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }

            .shortcode-display code {
                background: #fff;
                border: 1px solid #ccc;
                border-radius: 4px;
                padding: 8px 12px;
                font-family: monospace;
                font-size: 13px;
                flex: 1;
                word-break: break-all;
            }

            .copy-btn {
                white-space: nowrap;
                background: #007cba !important;
                color: white !important;
                border: none !important;
                cursor: pointer;
                transition: background-color 0.2s;
            }

            .copy-btn:hover {
                background: #005a87 !important;
            }

            .shortcode-note {
                font-size: 12px;
                color: #666;
                margin: 10px 0 0 0;
                font-style: italic;
            }

            .shortcode-info {
                background: #f0f8ff;
                border: 1px solid #b8daff;
                border-radius: 4px;
                padding: 15px;
                margin-top: 20px;
            }

            .shortcode-info h3 {
                margin-top: 0;
                color: #004085;
            }

            .shortcode-info ul {
                margin: 10px 0 0 20px;
            }

            .shortcode-info li {
                margin-bottom: 5px;
            }
        </style>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Copy to clipboard functionality
                document.querySelectorAll('.copy-btn').forEach(function(button) {
                    button.addEventListener('click', function() {
                        var shortcode = this.getAttribute('data-shortcode');
                        navigator.clipboard.writeText(shortcode).then(function() {
                            // Visual feedback
                            var originalText = button.textContent;
                            button.textContent = 'Copied!';
                            button.style.background = '#28a745';
                            setTimeout(function() {
                                button.textContent = originalText;
                                button.style.background = '';
                            }, 2000);
                        });
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        if ($hook !== 'settings_page_volleyball-import') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $("#volleyball-import-btn").on("click", function() {
                    var $button = $(this);
                    var $result = $("#volleyball-import-result");

                    $button.prop("disabled", true).text("Importing...");
                    $result.html("<p>⏳ Importing data from GitHub...</p>");

                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "volleyball_manual_import",
                            nonce: "' . wp_create_nonce('volleyball_import_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html("<div style=\"color: green; background: #f0fff0; padding: 10px; border: 1px solid #90ee90; border-radius: 4px;\">" +
                                    "<strong>✅ Import Successful!</strong><br>" +
                                    response.data.message + "<br>" +
                                    "Imported " + response.data.count + " teams<br>" +
                                    "<details><summary>Show details</summary><pre>" + response.data.results.join("\\n") + "</pre></details>" +
                                    "</div>");
                            } else {
                                $result.html("<div style=\"color: red; background: #fff0f0; padding: 10px; border: 1px solid #ffb0b0; border-radius: 4px;\">" +
                                    "<strong>❌ Import Failed:</strong> " + response.data.message +
                                    "</div>");
                            }
                        },
                        error: function(xhr, status, error) {
                            $result.html("<div style=\"color: red; background: #fff0f0; padding: 10px; border: 1px solid #ffb0b0; border-radius: 4px;\">" +
                                "<strong>❌ Error:</strong> " + error +
                                "</div>");
                        },
                        complete: function() {
                            $button.prop("disabled", false).text("Import Teams Now");
                        }
                    });
                });
            });
        ');
    }
}