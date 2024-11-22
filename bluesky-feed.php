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

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_ajax_test_bluesky_connection', array($this, 'handle_test_connection'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
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

    public function add_plugin_page()
    {
        add_menu_page(
            'BlueSky Feed Settings',
            'BlueSky Feed',
            'manage_options',
            'bluesky-feed-settings',
            array($this, 'create_admin_page'),
            'dashicons-share'
        );
    }

    public function handle_clear_cache()
    {
        check_ajax_referer('clear_bluesky_cache', 'security');
        delete_transient('bluesky_auth_token');
        wp_send_json_success();
    }

    public function create_admin_page()
    {
        $this->options = get_option('bluesky_feed_options');
        ?>
        <div class="wrap">
            <h1>BlueSky Feed Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('bluesky_feed_option_group');
                do_settings_sections('bluesky-feed-settings');
                submit_button();
                ?>
            </form>

            <!-- Test Connection Section -->
            <div class="test-connection"
                 style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccc;">
                <h2>Connection Test</h2>
                <button type="button" id="test-bluesky-connection" class="button button-primary">
                    Test BlueSky Connection
                </button>
                <span id="connection-result" style="margin-left: 10px; display: inline-block;"></span>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $('#test-bluesky-connection').on('click', function () {
                    const button = $(this);
                    const resultSpan = $('#connection-result');

                    // Disable button and show loading
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
            });
        </script>

        <div class="clear-cache" style="margin-top: 20px;">
            <button type="button" id="clear-bluesky-cache" class="button button-secondary">Clear BlueSky Cache</button>
            <span id="cache-clear-result"></span>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                $('#clear-bluesky-cache').on('click', function () {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'clear_bluesky_cache',
                            security: '<?php echo wp_create_nonce('clear_bluesky_cache'); ?>'
                        },
                        success: function (_response) {
                            $('#cache-clear-result').html('<span style="color:green">Cache cleared!</span>');
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

    public function page_init()
    {
        register_setting(
            'bluesky_feed_option_group',
            'bluesky_feed_options',
            array($this, 'sanitize')
        );

        // Add Authentication Section
        add_settings_section(
            'bluesky_auth_section',
            'BlueSky Authentication',
            array($this, 'auth_section_info'),
            'bluesky-feed-settings'
        );

        // Add Authentication Fields
        add_settings_field(
            'bluesky_identifier',
            'BlueSky Identifier (email/handle)',
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

        // Existing settings section
        add_settings_section(
            'bluesky_feed_setting_section',
            'Feed Settings',
            array($this, 'section_info'),
            'bluesky-feed-settings'
        );

        register_setting(
            'bluesky_feed_option_group',
            'bluesky_feed_options',
            array($this, 'sanitize')
        );

        add_settings_field(
            'accounts',
            'BlueSky Accounts',
            array($this, 'accounts_callback'),
            'bluesky-feed-settings',
            'bluesky_feed_setting_section'
        );

        add_settings_field(
            'hashtags',
            'Hashtags',
            array($this, 'hashtags_callback'),
            'bluesky-feed-settings',
            'bluesky_feed_setting_section'
        );

        // Add Layout Section
        add_settings_section(
            'bluesky_layout_section',
            'Layout Settings',
            array($this, 'layout_section_info'),
            'bluesky-feed-settings'
        );

        // Add Scroll Direction Field
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

    public function sanitize($input)
    {
        $new_input = array();

        if (isset($input['bluesky_identifier']))
            $new_input['bluesky_identifier'] = sanitize_text_field($input['bluesky_identifier']);

        if (isset($input['bluesky_password']))
            $new_input['bluesky_password'] = sanitize_text_field($input['bluesky_password']);

        if (isset($input['accounts']))
            $new_input['accounts'] = sanitize_textarea_field($input['accounts']);

        if (isset($input['hashtags']))
            $new_input['hashtags'] = sanitize_textarea_field($input['hashtags']);

        if (isset($input['scroll_direction'])) {
            $new_input['scroll_direction'] = sanitize_text_field($input['scroll_direction']);
        }
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
            plugins_url('js/bluesky-feed-v2.js', __FILE__),
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
        // First decode ALL HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Also try a second pass with a different decode function
        $text = wp_specialchars_decode($text, ENT_QUOTES);

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

        // Convert hashtags to blue text
        $text = preg_replace(
            '/(#[0-9]+|#[\w-]+)/',
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
