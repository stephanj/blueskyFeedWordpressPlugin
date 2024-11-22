<?php
/**
 * Plugin Name: BlueSky Feed Scroller
 * Description: Display BlueSky posts
 * Version: 1.0
 * Author: Stephan Janssen
 */

ini_set('display_errors', 1);
ini_set('error_reporting', E_ALL);
// Force logging to a specific file in your plugin directory
ini_set('error_log', dirname(__FILE__) . '/debug.log');
// Use this for debugging
error_log("BlueSky Feed Plugin: Initializing...");

if (!defined('ABSPATH')) exit;

class BlueSkyFeedScroller
{
    private $options;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
        add_action('admin_head', array($this, 'add_menu_icon_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_ajax_test_bluesky_connection', array($this, 'handle_test_connection'));
        add_action('wp_ajax_load_more_bluesky_posts', array($this, 'ajax_load_more_posts'));
        add_action('wp_ajax_nopriv_load_more_bluesky_posts', array($this, 'ajax_load_more_posts'));
        add_action('wp_ajax_clear_bluesky_cache', array($this, 'handle_clear_cache'));
        add_shortcode('bluesky_feed', array($this, 'render_feed_shortcode'));
    }

    private function debug_log($message, $data = null) {
        $log_file = dirname(__FILE__) . '/debug.log';
        $timestamp = date('Y-m-d H:i:s');

        if ($data !== null) {
            $message .= ' Data: ' . print_r($data, true);
        }

        file_put_contents(
            $log_file,
            "[$timestamp] $message\n",
            FILE_APPEND
        );
    }

    public function add_plugin_page() {
        add_menu_page(
            'BlueSky Feed Settings',
            'BlueSky Feed',
            'manage_options',
            'bluesky-feed-settings',
            array($this, 'create_admin_page'),
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" fill="#a7aaad" viewBox="0 0 512 512"><path d="M111.8 62.2C170.2 105.9 233 194.7 256 242.4c23-47.6 85.8-136.4 144.2-180.2c42.1-31.6 110.3-56 110.3 21.8c0 15.5-8.9 130.5-14.1 149.2C478.2 298 412 314.6 353.1 304.5c102.9 17.5 129.1 75.5 72.5 133.5c-107.4 110.2-154.3-27.6-166.3-62.9l0 0c-1.7-4.9-2.6-7.8-3.3-7.8s-1.6 3-3.3 7.8l0 0c-12 35.3-59 173.1-166.3 62.9c-56.5-58-30.4-116 72.5-133.5C100 314.6 33.8 298 15.7 233.1C10.4 214.4 1.5 99.4 1.5 83.9c0-77.8 68.2-53.4 110.3-21.8z"/></svg>')
        );
    }

    public function add_menu_icon_styles() {
        ?>
        <style>
            /* Style the menu icon */
            .toplevel_page_bluesky-feed-settings .wp-menu-image img {
                width: 20px;
                height: 20px;
                padding: 7px 0;
                opacity: 0.6;
            }

            /* Style for hover and active states */
            .toplevel_page_bluesky-feed-settings:hover .wp-menu-image img,
            .toplevel_page_bluesky-feed-settings.current .wp-menu-image img {
                opacity: 1;
            }

            /* Fix for dark mode */
            body.admin-color-light .toplevel_page_bluesky-feed-settings .wp-menu-image img path {
                fill: #1d2327;
            }
            body.admin-color-dark .toplevel_page_bluesky-feed-settings .wp-menu-image img path {
                fill: #ffffff;
            }

            /* Handle other admin color schemes */
            .wp-admin .toplevel_page_bluesky-feed-settings .wp-menu-image img path {
                fill: currentColor;
            }
        </style>
        <?php
    }

    public function ajax_load_more_posts()
    {
        check_ajax_referer('bluesky_feed_nonce', 'nonce');

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $options = get_option('bluesky_feed_options');
        $accounts = isset($options['accounts']) ? explode("\n", $options['accounts']) : array();
        $hashtags = isset($options['hashtags']) ? explode("\n", $options['hashtags']) : array();

        // Clean up arrays
        $accounts = array_map('trim', $accounts);
        $hashtags = array_map('trim', $hashtags);

        // Fetch posts with pagination
        $posts = $this->fetch_bluesky_posts($accounts, $hashtags, $page);

        wp_send_json_success(array(
            'posts' => $posts,
            'hasMore' => count($posts) > 0
        ));
    }

    public function handle_clear_cache()
    {
        check_ajax_referer('clear_bluesky_cache', 'security');
        delete_transient('bluesky_auth_token');
        wp_send_json_success();
    }

    public function create_admin_page() {
        $this->options = get_option('bluesky_feed_options');
        ?>
        <div class="wrap">
            <h1><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="#000000" viewBox="0 0 512 512"><path d="M111.8 62.2C170.2 105.9 233 194.7 256 242.4c23-47.6 85.8-136.4 144.2-180.2c42.1-31.6 110.3-56 110.3 21.8c0 15.5-8.9 130.5-14.1 149.2C478.2 298 412 314.6 353.1 304.5c102.9 17.5 129.1 75.5 72.5 133.5c-107.4 110.2-154.3-27.6-166.3-62.9l0 0c-1.7-4.9-2.6-7.8-3.3-7.8s-1.6 3-3.3 7.8l0 0c-12 35.3-59 173.1-166.3 62.9c-56.5-58-30.4-116 72.5-133.5C100 314.6 33.8 298 15.7 233.1C10.4 214.4 1.5 99.4 1.5 83.9c0-77.8 68.2-53.4 110.3-21.8z"/></svg> BlueSky Feed Settings</h1>

            <!-- How To Section -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><span class="dashicons dashicons-info-outline" style="font-size: 24px; margin-right: 10px;"></span>Quick Start Guide</h2>
                <p>Follow these steps to set up your BlueSky Feed:</p>
                <ol style="list-style-type: decimal; margin-left: 20px;">
                    <li>Enter your BlueSky credentials (email/handle and password)</li>
                    <li>Add hashtags you want to track (without the # symbol)</li>
                    <li>Click "Test Connection" to verify your credentials</li>
                    <li>Choose your preferred scroll direction (horizontal or vertical)</li>
                    <li>Use the shortcode <code>[bluesky_feed]</code> to display the feed on any page</li>
                </ol>
            </div>

            <div class="card" style="max-width: 800px;">
                <form method="post" action="options.php">
                    <?php settings_fields('bluesky_feed_option_group'); ?>

                    <!-- Authentication Section -->
                    <div class="form-section">
                        <h2><span class="dashicons dashicons-lock" style="font-size: 24px; margin-right: 10px;"></span>Authentication</h2>
                        <div class="form-table">
                            <div class="form-field" style="margin-bottom: 20px;">
                                <label for="bluesky_identifier" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                    BlueSky Identifier (email/handle)
                                </label>
                                <input
                                    type="text"
                                    id="bluesky_identifier"
                                    name="bluesky_feed_options[bluesky_identifier]"
                                    value="<?php echo esc_attr(isset($this->options['bluesky_identifier']) ? $this->options['bluesky_identifier'] : ''); ?>"
                                    class="regular-text"
                                    style="margin-right: 10px;"
                                >
                                <p class="description">Enter your BlueSky email or handle (e.g., user.bsky.social)</p>
                            </div>

                            <div class="form-field" style="margin-bottom: 20px;">
                                <label for="bluesky_password" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                    BlueSky Password
                                </label>
                                <input
                                    type="password"
                                    id="bluesky_password"
                                    name="bluesky_feed_options[bluesky_password]"
                                    value="<?php echo esc_attr(isset($this->options['bluesky_password']) ? $this->options['bluesky_password'] : ''); ?>"
                                    class="regular-text"
                                    style="margin-right: 10px;"
                                >
                                <p class="description">Enter your BlueSky password</p>
                                <button type="button" id="test-bluesky-connection" class="button button-secondary">
                                    <span class="dashicons dashicons-superhero" style="margin-top: 4px;"></span>
                                    Test Connection
                                </button>
                                <span id="connection-result" style="margin-left: 10px; display: inline-block;"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Feed Configuration Section -->
                    <div class="form-section" style="margin-top: 30px;">
                        <h2><span class="dashicons dashicons-tag" style="font-size: 24px; margin-right: 10px;"></span>Feed Configuration</h2>
                        <div class="form-table">
                            <div class="form-field" style="margin-bottom: 20px;">
                                <label for="bluesky_hashtags" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                    Hashtags to Track
                                </label>
                                <textarea
                                    id="bluesky_hashtags"
                                    name="bluesky_feed_options[hashtags]"
                                    rows="4"
                                    class="large-text code"
                                    placeholder="Enter one hashtag per line (without #)"
                                ><?php echo esc_textarea(isset($this->options['hashtags']) ? $this->options['hashtags'] : ''); ?></textarea>
                                <p class="description">Enter hashtags to track, one per line, without the # symbol (e.g., devoxx)</p>
                            </div>
                        </div>
                    </div>

                    <!-- Layout Settings Section -->
                    <div class="form-section" style="margin-top: 30px;">
                        <h2><span class="dashicons dashicons-layout" style="font-size: 24px; margin-right: 10px;"></span>Layout Settings</h2>
                        <div class="form-table">
                            <div class="form-field">
                                <label for="scroll_direction" style="display: block; margin-bottom: 5px; font-weight: bold;">
                                    Scroll Direction
                                </label>
                                <select
                                    id="scroll_direction"
                                    name="bluesky_feed_options[scroll_direction]"
                                    class="regular-text"
                                >
                                    <option value="horizontal" <?php selected(isset($this->options['scroll_direction']) ? $this->options['scroll_direction'] : '', 'horizontal'); ?>>
                                        Horizontal Scroll
                                    </option>
                                    <option value="vertical" <?php selected(isset($this->options['scroll_direction']) ? $this->options['scroll_direction'] : '', 'vertical'); ?>>
                                        Vertical Scroll
                                    </option>
                                </select>
                                <p class="description">Choose how posts will be displayed and scrolled</p>
                            </div>
                        </div>
                    </div>

                    <?php submit_button('Save Settings'); ?>
                </form>
            </div>

            <!-- Cache Control Section -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><span class="dashicons dashicons-performance" style="font-size: 24px; margin-right: 10px;"></span>Cache Management</h2>
                <p>Clear the BlueSky cache if you're experiencing issues with the feed or after changing settings:</p>
                <button type="button" id="clear-bluesky-cache" class="button button-secondary">
                    <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
                    Clear Cache
                </button>
                <span id="cache-clear-result" style="margin-left: 10px;"></span>
            </div>

            <!-- Shortcode Help Section -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><span class="dashicons dashicons-shortcode" style="font-size: 24px; margin-right: 10px;"></span>Using the Shortcode</h2>
                <p>To display the BlueSky feed on any page or post, use this shortcode:</p>
                <code style="background: #f0f0f1; padding: 10px; display: inline-block; margin: 10px 0;">[bluesky_feed]</code>
            </div>

            <!-- GitHub Repository Section -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><span class="dashicons dashicons-github" style="font-size: 24px; margin-right: 10px;"></span>Contributing & Support</h2>
                <p>This plugin is open source! Find the code, report issues, or contribute on GitHub:</p>
                <p><a href="https://github.com/stephanj/blueskyFeedWordpressPlugin" target="_blank" class="button button-secondary">
                        <span class="dashicons dashicons-external" style="margin-top: 4px;"></span>
                        View on GitHub
                    </a></p>
            </div>
        </div>

        <style>
            .card {
                background: white;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .form-section {
                border-bottom: 1px solid #eee;
                padding-bottom: 20px;
            }
            .form-section:last-child {
                border-bottom: none;
            }
            .form-section h2 {
                display: flex;
                align-items: center;
                color: #1d2327;
                font-size: 1.3em;
                margin-bottom: 1em;
            }
            .description {
                color: #646970;
                font-style: italic;
                margin-top: 4px;
            }
        </style>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Test Connection Handler
                $('#test-bluesky-connection').on('click', function () {
                    const button = $(this);
                    const resultSpan = $('#connection-result');

                    button.prop('disabled', true);
                    resultSpan.html('<span style="color:#666">Testing connection...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_bluesky_connection',
                            security: '<?php echo wp_create_nonce('bluesky_test_connection'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                resultSpan.html('<span style="color:green">✓ Connection successful!</span>');
                            } else {
                                resultSpan.html('<span style="color:red">✗ Connection failed: ' +
                                    (response.data ? response.data : 'Unknown error') + '</span>');
                            }
                        },
                        error: function (xhr, status, error) {
                            resultSpan.html('<span style="color:red">✗ Request failed: ' + error + '</span>');
                        },
                        complete: function () {
                            button.prop('disabled', false);
                        }
                    });
                });

                // Cache Clear Handler
                $('#clear-bluesky-cache').on('click', function () {
                    const resultSpan = $('#cache-clear-result');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'clear_bluesky_cache',
                            security: '<?php echo wp_create_nonce('clear_bluesky_cache'); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                resultSpan.html('<span style="color:green">✓ Cache cleared successfully!</span>');
                                setTimeout(() => {
                                    resultSpan.html('');
                                }, 3000);
                            }
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function handle_test_connection()
    {
        error_log('BlueSky Test Connection Started');

        if (!check_ajax_referer('bluesky_test_connection', 'security', false)) {
            error_log('BlueSky Test: Nonce verification failed');
            wp_send_json_error('Invalid security token');
            return;
        }

        $options = get_option('bluesky_feed_options');
        error_log('BlueSky Test: Options retrieved: ' . print_r($options, true));

        // Verify nonce
        if (!check_ajax_referer('bluesky_test_connection', 'security', false)) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
            return;
        }

        // Get options
        $options = get_option('bluesky_feed_options');

        // Check if credentials are set
        if (empty($options['bluesky_identifier']) || empty($options['bluesky_password'])) {
            wp_send_json_error('BlueSky credentials are not configured');
            return;
        }

        try {
            // Test connection
            require_once plugin_dir_path(__FILE__) . 'bluesky-integration.php';
            $api = new BlueSkyAPI();

            if ($api->authenticate()) {
                wp_send_json_success('Connection successful');
            } else {
                wp_send_json_error('Authentication failed. Please check your credentials.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function page_init() {
        register_setting(
            'bluesky_feed_option_group',
            'bluesky_feed_options',
            array($this, 'sanitize')
        );

        // Authentication fields remain the same
        add_settings_section(
            'bluesky_auth_section',
            'BlueSky Authentication',
            array($this, 'auth_section_info'),
            'bluesky-feed-settings'
        );

        add_settings_field(
            'bluesky_identifier',
            'BlueSky Identifier',
            array($this, 'identifier_callback'),
            'bluesky-feed-settings',
            'bluesky_auth_section'
        );

        add_settings_field(
            'bluesky_password',
            'BlueSky Password',
            array($this, 'password_callback'),
            'bluesky-feed-settings',
            'bluesky_auth_section'
        );

        // Hashtags section (removed accounts section)
        add_settings_section(
            'bluesky_feed_setting_section',
            'Feed Settings',
            array($this, 'section_info'),
            'bluesky-feed-settings'
        );

        add_settings_field(
            'hashtags',
            'Hashtags',
            array($this, 'hashtags_callback'),
            'bluesky-feed-settings',
            'bluesky_feed_setting_section'
        );

        // Layout settings remain the same
        add_settings_section(
            'bluesky_layout_section',
            'Layout Settings',
            array($this, 'layout_section_info'),
            'bluesky-feed-settings'
        );

        add_settings_field(
            'scroll_direction',
            'Scroll Direction',
            array($this, 'scroll_direction_callback'),
            'bluesky-feed-settings',
            'bluesky_layout_section'
        );
    }

    public function layout_section_info() {
        echo 'Configure the layout of your BlueSky feed:';
    }

    public function scroll_direction_callback() {
        $value = isset($this->options['scroll_direction']) ?
            esc_attr($this->options['scroll_direction']) : 'horizontal';
        ?>
        <select name="bluesky_feed_options[scroll_direction]" class="regular-text">
            <option value="horizontal" <?php selected($value, 'horizontal'); ?>>Horizontal Scroll</option>
            <option value="vertical" <?php selected($value, 'vertical'); ?>>Vertical Scroll</option>
        </select>
        <p class="description">Choose how posts will be displayed and scrolled</p>
        <?php
    }

    public function auth_section_info()
    {
        echo 'Enter your BlueSky authentication credentials:';
    }

    public function identifier_callback()
    {
        $value = isset($this->options['bluesky_identifier']) ?
            esc_attr($this->options['bluesky_identifier']) : '';
        ?>
        <input type="text"
               name="bluesky_feed_options[bluesky_identifier]"
               value="<?php echo $value; ?>"
               class="regular-text">
        <p class="description">Enter your BlueSky email or handle</p>
        <?php
    }

    public function password_callback()
    {
        $value = isset($this->options['bluesky_password']) ?
            esc_attr($this->options['bluesky_password']) : '';
        ?>
        <input type="password"
               name="bluesky_feed_options[bluesky_password]"
               value="<?php echo $value; ?>"
               class="regular-text">
        <p class="description">Enter your BlueSky password</p>
        <?php
    }

    public function sanitize($input) {
        $new_input = array();

        if (isset($input['bluesky_identifier']))
            $new_input['bluesky_identifier'] = sanitize_text_field($input['bluesky_identifier']);

        if (isset($input['bluesky_password']))
            $new_input['bluesky_password'] = sanitize_text_field($input['bluesky_password']);

        if (isset($input['hashtags']))
            $new_input['hashtags'] = sanitize_textarea_field($input['hashtags']);

        if (isset($input['scroll_direction']))
            $new_input['scroll_direction'] = sanitize_text_field($input['scroll_direction']);

        return $new_input;
    }

    public function section_info()
    {
        echo 'Enter your BlueSky feed settings below:';
    }

    public function accounts_callback()
    {
        $value = isset($this->options['accounts']) ? esc_attr($this->options['accounts']) : '';
        ?>
        <textarea name="bluesky_feed_options[accounts]" rows="5" cols="50"><?php echo $value; ?></textarea>
        <p class="description">Enter BlueSky accounts (one per line)</p>
        <?php
    }

    public function hashtags_callback()
    {
        $value = isset($this->options['hashtags']) ? esc_attr($this->options['hashtags']) : '';
        ?>
        <textarea name="bluesky_feed_options[hashtags]" rows="5" cols="50"><?php echo $value; ?></textarea>
        <p class="description">Enter hashtags to track (one per line, without #)</p>
        <?php
    }

    public function enqueue_frontend_assets()
    {
        wp_enqueue_style(
            'bluesky-feed-style',
            plugins_url('css/bluesky-feed-v2.css', __FILE__),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'bluesky-feed-script',
            plugins_url('js/bluesky-feed-v3.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );
    }

    private function fetch_bluesky_posts($accounts, $hashtags)
    {
        $this->debug_log('Starting fetch_bluesky_posts');
        $this->debug_log('Accounts:', $accounts);
        $this->debug_log('Hashtags:', $hashtags);

        try {
            error_log('BlueSky Feed: Fetching posts started');

            require_once plugin_dir_path(__FILE__) . 'bluesky-integration.php';
            $api = new BlueSkyAPI();

            if (!$api->authenticate()) {
                error_log('BlueSky Feed: Authentication failed');
                throw new Exception('Failed to authenticate with BlueSky');
            }

            $posts = array();

            // Fetch posts for each account
            foreach ($accounts as $account) {
                if (!empty($account)) {
                    error_log('BlueSky Feed: Fetching posts for account: ' . $account);
                    $account_posts = $api->get_user_posts($account);
                    $posts = array_merge($posts, $account_posts);
                }
            }

            // Fetch posts for each hashtag
            foreach ($hashtags as $hashtag) {
                if (!empty($hashtag)) {
                    error_log('BlueSky Feed: Fetching posts for hashtag: ' . $hashtag);
                    $hashtag_posts = $api->search_posts($hashtag);
                    $posts = array_merge($posts, $hashtag_posts);
                }
            }

            // Sort posts by date (latest first)
            usort($posts, function($a, $b) {
                $date_a = strtotime($a['createdAt']);
                $date_b = strtotime($b['createdAt']);
                return $date_b - $date_a; // Reverse chronological order
            });

            error_log('BlueSky Feed: Total posts fetched: ' . count($posts));
            return $posts;

        } catch (Exception $e) {
            error_log('BlueSky Feed Error in fetch_bluesky_posts: ' . $e->getMessage());
            throw $e;
        }
    }

    private function render_post($post) {
        try {
            if (!isset($post['author']) || !isset($post['text'])) {
                error_log('BlueSky Feed: Invalid post data: ' . print_r($post, true));
                return '';
            }

            // Get scroll direction from options
            $scroll_direction = isset($this->options['scroll_direction']) ?
                $this->options['scroll_direction'] : 'horizontal';

            // Generate post-specific URL
            $post_url = $this->generate_post_url($post);

            // Post container with conditional classes
            $post_class = 'bluesky-post';
            if ($scroll_direction === 'horizontal') {
                $post_class .= ' bluesky-post-horizontal';
            }

            // Start post container with link wrapper
            $output = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" class="%s">',
                esc_url($post_url),
                esc_attr($post_class)
            );

            // Post Header
            $output .= '<div class="bluesky-post-header">';
            if (isset($post['author']['avatar'])) {
                $output .= sprintf(
                    '<img src="%s" class="bluesky-avatar lazy" data-src="%s" alt="%s\'s avatar" loading="lazy" />',
                    esc_url($post['author']['avatar']),
                    esc_url($post['author']['avatar']),
                    esc_attr($post['author']['displayName'])
                );
            }

            $output .= '<div class="bluesky-author-info">';
            $output .= sprintf(
                '<span class="bluesky-display-name">%s</span>',
                esc_html($post['author']['displayName'])
            );
            $output .= sprintf(
                '<span class="bluesky-handle">@%s</span>',
                esc_html($post['author']['handle'])
            );
            $output .= '</div>'; // Close author-info
            $output .= '</div>'; // Close post-header

            // Process and add the text content
            $processed_text = $this->format_post_content($post['text']);
            $output .= sprintf('<div class="bluesky-post-content">%s</div>', $processed_text);

            // Handle Images
            if (!empty($post['images'])) {
                // Filter out any invalid image URLs
                $valid_images = array_filter($post['images'], function($url) {
                    return filter_var($url, FILTER_VALIDATE_URL) !== false;
                });

                if (!empty($valid_images)) {
                    $image_grid_class = count($valid_images) > 1 ? 'bluesky-post-images-grid' : '';

                    $output .= sprintf(
                        '<div class="bluesky-post-images %s">',
                        esc_attr($image_grid_class)
                    );

                    foreach ($valid_images as $index => $image_url) {
                        $output .= sprintf(
                            '<div class="bluesky-image-wrapper" data-index="%d">
                            <img
                                src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                class="lazy bluesky-post-image"
                                data-src="%s"
                                alt=""
                                loading="lazy"
                                onerror="this.parentElement.style.display=\'none\'"
                            />
                        </div>',
                            $index,
                            esc_url($image_url)
                        );
                    }

                    $output .= '</div>'; // Close post-images
                }
            }

            // Post Footer with timestamp
            $output .= '<div class="bluesky-post-footer">';
            if (isset($post['createdAt'])) {
                $timestamp = strtotime($post['createdAt']);
                $output .= sprintf(
                    '<span class="bluesky-timestamp">%s</span>',
                    esc_html($this->format_timestamp($timestamp))
                );
            }
            $output .= '</div>'; // Close post-footer

            $output .= '</a>'; // Close post link wrapper

            return $output;

        } catch (Exception $e) {
            error_log('BlueSky Feed Error in render_post: ' . $e->getMessage());
            return '';
        }
    }

    private function format_post_content($text) {
        // First decode any HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = wp_specialchars_decode($text, ENT_QUOTES);

        // Fix the &#039; that appears as literal text (not as an entity)
        $text = preg_replace('/\&\#039;/', "'", $text);  // Handle proper HTML entity
        $text = preg_replace('/\#039;/', "'", $text);    // Handle the broken version
        $text = preg_replace('/\&\#39;/', "'", $text);   // Handle another common variant

        // Now escape HTML, but preserve our formatted elements
        $text = esc_html($text);

        // Convert URLs to clickable links
        $text = preg_replace(
            '/(https?:\/\/[^\s<>"\']+)/',
            '<span class="bluesky-link">$1</span>',
            $text
        );

        // Convert mentions to blue text
        $text = preg_replace(
            '/(@[\w.-]+)/',
            '<span class="bluesky-mention">$1</span>',
            $text
        );

        // Convert hashtags to blue text (but ignore the #039 code)
        $text = preg_replace(
            '/(#(?!039;)(?:[0-9]+|[\w-]+))/',
            '<span class="bluesky-hashtag">$1</span>',
            $text
        );

        return $text;
    }

    private function format_timestamp($timestamp) {
        $now = current_time('timestamp');
        $diff = $now - $timestamp;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . 'm';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . 'd';
        } else {
            return date('M j', $timestamp);
        }
    }

    private function generate_post_url($post) {
        // Base Bluesky URL
        $base_url = 'https://bsky.app/profile/';

        // If we have both handle and post URI, generate specific post URL
        if (isset($post['author']['handle']) && isset($post['uri'])) {
            // Extract post ID from URI
            // URI format is typically: at://did:plc:XXX/app.bsky.feed.post/POSTID
            if (preg_match('/\/app\.bsky\.feed\.post\/([^\/]+)$/', $post['uri'], $matches)) {
                $post_id = $matches[1];
                // Return full URL to specific post
                return $base_url . urlencode($post['author']['handle']) . '/post/' . urlencode($post_id);
            }
        }

        // Fallback to profile URL if we can't construct post URL
        return isset($post['author']['handle']) ?
            $base_url . urlencode($post['author']['handle']) :
            'https://bsky.app';
    }

    public function render_feed_shortcode($atts) {
        try {
            error_log('BlueSky Feed: Shortcode execution started');

            // Get settings
            $options = get_option('bluesky_feed_options');

            if (empty($options['bluesky_identifier']) || empty($options['bluesky_password'])) {
                return '<div class="bluesky-feed-error">BlueSky credentials not configured</div>';
            }

            $accounts = isset($options['accounts']) ? explode("\n", $options['accounts']) : array();
            $hashtags = isset($options['hashtags']) ? explode("\n", $options['hashtags']) : array();

            // Clean up arrays
            $accounts = array_map('trim', $accounts);
            $hashtags = array_map('trim', $hashtags);

            // Try to fetch posts with one retry
            $posts = array();
            try {
                $posts = $this->fetch_bluesky_posts($accounts, $hashtags);
            } catch (Exception $e) {
                error_log('BlueSky Feed: First attempt failed, trying again with force authentication');
                // Force new authentication and try again
                require_once plugin_dir_path(__FILE__) . 'bluesky-integration.php';
                $api = new BlueSkyAPI();
                if ($api->authenticate(true)) {
                    $posts = $this->fetch_bluesky_posts($accounts, $hashtags);
                }
            }

            if (empty($posts)) {
                return '<div class="bluesky-feed-error">No posts found</div>';
            }

            $scroll_direction = isset($options['scroll_direction']) ?
                $options['scroll_direction'] : 'horizontal';

            // Build output
            $output = sprintf(
                '<div class="bluesky-feed-container" data-scroll-direction="%s">',
                esc_attr($scroll_direction)
            );

            $output .= '<div class="bluesky-feed-scroller">';

            foreach ($posts as $post) {
                $output .= $this->render_post($post);
            }

            $output .= '</div>'; // Close feed-scroller
            $output .= '</div>'; // Close feed-container

            return $output;

        } catch (Exception $e) {
            error_log('BlueSky Feed Error: ' . $e->getMessage());
            return '<div class="bluesky-feed-error">Error loading feed: ' . esc_html($e->getMessage()) . '</div>';
        }
    }
}

// Initialize the plugin
if (class_exists('BlueSkyFeedScroller')) {
    $blueSkyFeedScroller = new BlueSkyFeedScroller();
}
