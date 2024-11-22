# BlueSky Feed Plugin for WordPress

A WordPress plugin that allows you to display BlueSky posts in Wordpress based on hashtags in a beautiful, responsive feed with horizontal or vertical scrolling capabilities.

## 🌟 Features

- Display BlueSky posts filtered by hashtags
- Horizontal or vertical scrolling options
- Lazy loading of images for better performance
- Responsive design that works on all devices
- Built-in caching system for improved performance
- Simple shortcode integration
- Touch-friendly navigation
- Automatic post updates
- Clean, modern UI

## ⚙️ Admin screen

![admin-1](https://github.com/user-attachments/assets/1e6f6cf4-3667-470e-9589-9a353ef3bc7a)
![admin-2](https://github.com/user-attachments/assets/f2bdd753-2a57-4b5d-8220-91c7386a7133)


## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- jQuery (included with WordPress)
- BlueSky account credentials

## 🚀 Installation

1. Upload the `blueskyFeedPlugin` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the BlueSky Feed settings page in your WordPress admin panel
4. Enter your BlueSky credentials and configure your feed settings

## ⚙️ Configuration

### Admin Settings

1. **Authentication Settings**
   - BlueSky Identifier (email/handle)
   - BlueSky Password
   - Test Connection button to verify credentials

2. **Feed Configuration**
   - Add hashtags to track (without # symbol)
   - One hashtag per line

3. **Layout Settings**
   - Choose between horizontal or vertical scrolling
   - Configure display options

### Shortcode Usage

Add the feed to any page or post using the shortcode:

```
[bluesky_feed]
```

## 🔧 Developer Information

### File Structure

```
blueskyFeedPlugin/
├── js/
│   └── bluesky-feed-v3.js      # Frontend JavaScript
├── css/
│   └── bluesky-feed-v2.css     # Styling
├── bluesky-feed.php            # Main plugin file
├── bluesky-integration.php     # BlueSky API integration
└── README.md
```

### Key Components

1. **BlueSkyFeedScroller Class**
   - Main plugin class handling WordPress integration
   - Manages admin interface and settings
   - Handles shortcode rendering

2. **BlueSkyAPI Class**
   - Handles all BlueSky API interactions
   - Manages authentication
   - Fetches and formats posts

3. **Frontend JavaScript**
   - Handles scrolling behavior
   - Manages lazy loading
   - Handles touch interactions
   - Controls post loading and updates

### Hooks and Filters

The plugin uses the following WordPress hooks:

```php
// Actions
add_action('admin_menu', ...)
add_action('admin_init', ...)
add_action('wp_enqueue_scripts', ...)
add_action('wp_ajax_test_bluesky_connection', ...)
add_action('wp_ajax_load_more_bluesky_posts', ...)
add_action('wp_ajax_clear_bluesky_cache', ...)

// Filters
add_filter('bluesky_post_content', ...)
```

### CSS Classes

Key CSS classes for styling:

- `.bluesky-feed-container`: Main container
- `.bluesky-feed-scroller`: Scroll container
- `.bluesky-post`: Individual post
- `.bluesky-post-horizontal`: Horizontal layout specific
- `.bluesky-post-content`: Post content
- `.bluesky-image-wrapper`: Image container
- `.bluesky-nav-buttons`: Navigation buttons

### JavaScript API

The `BlueSkyFeed` class provides methods for:

```javascript
// Initialize feed
new BlueSkyFeed(container);

// Available methods
scrollPosts(direction)    // Scroll to next/prev posts
loadMoreContent()        // Load additional posts
updateNavigationState()  // Update nav buttons state
initLazyLoading()       // Initialize lazy loading
```

### Caching

The plugin implements a caching system for API responses:

- Cache duration: 1 hour
- Cached data: Authentication tokens and post data
- Clear cache: Available through admin interface

## 🔒 Security

- All user inputs are sanitized
- WordPress nonces used for AJAX requests
- Credentials stored securely using WordPress options API
- API requests use proper authentication headers

## 🐛 Debugging

Enable debug logging by adding to wp-config.php:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Logs are stored in:
- Plugin directory: `/debug.log`
- WordPress debug log: `/wp-content/debug.log`

## 📝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT - see the [LICENSE](LICENSE) file for details.

## 🤝 Support

For support:
1. Check the [issues page](https://github.com/stephanj/blueskyFeedPlugin/issues)
2. Create a new issue with detailed information about your problem
3. Include WordPress version and PHP version in bug reports
