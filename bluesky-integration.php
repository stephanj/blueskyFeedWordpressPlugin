<?php
class BlueSkyAPI {
    private $base_url = 'https://bsky.social/xrpc/';
    private $access_jwt = null;
    private $refresh_jwt = null;
    private $token_expiry = null;

    public function __construct() {
        $this->loadStoredToken();
    }

    private function loadStoredToken() {
        $stored_token = get_transient('bluesky_auth_token');
        if ($stored_token) {
            $this->access_jwt = $stored_token['access_jwt'];
            $this->refresh_jwt = $stored_token['refresh_jwt'];
            $this->token_expiry = $stored_token['expiry'];
            return true;         }
        return false;
    }

    private function storeToken($access_jwt, $refresh_jwt) {
        $token_data = array(
            'access_jwt' => $access_jwt,
            'refresh_jwt' => $refresh_jwt,
            'expiry' => time() + 3600 // Store for 1 hour
        );
        set_transient('bluesky_auth_token', $token_data, 3600);
        $this->access_jwt = $access_jwt;
        $this->refresh_jwt = $refresh_jwt;
        $this->token_expiry = $token_data['expiry'];
    }

    public function get_user_posts($handle) {
        try {
            $did = $this->resolve_handle($handle);
            if (!$did) {
                return array();
            }

            $response = wp_remote_get($this->base_url . 'app.bsky.feed.getAuthorFeed', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_jwt,
                ),
                'body' => array(
                    'actor' => $did,
                    'limit' => 20,
                ),
            ));

            if (is_wp_error($response)) {
                return array();
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            return $this->format_posts($body['feed']);

        } catch (Exception $e) {
            return array();
        }
    }

    public function search_posts($hashtag) {
        try {
            $response = wp_remote_get($this->base_url . 'app.bsky.feed.searchPosts', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_jwt,
                ),
                'body' => array(
                    'q' => '#' . $hashtag,
                    'limit' => 20,
                ),
            ));

            if (is_wp_error($response)) {
                return array();
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $this->format_posts($body['posts']);
        } catch (Exception $e) {
            throw $e;
        }
    }

    private function resolve_handle($handle) {
        $response = wp_remote_get($this->base_url . 'com.atproto.identity.resolveHandle', array(
            'body' => array(
                'handle' => $handle,
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['did']) ? $body['did'] : null;
    }

    private function extract_images($post) {
        $images = array();

        // Check for embedded images in the post record
        if (isset($post['embed'])) {
            $embed = $post['embed'];

            // Case 1: Direct images in embed
            if (isset($embed['images'])) {
                foreach ($embed['images'] as $img) {
                    if (isset($img['fullsize'])) {
                        $images[] = $img['fullsize'];
                    }
                }
            }

            // Case 2: Images in embed.media
            if (isset($embed['media']) && isset($embed['media']['images'])) {
                foreach ($embed['media']['images'] as $img) {
                    if (isset($img['fullsize'])) {
                        $images[] = $img['fullsize'];
                    }
                }
            }

            // Case 3: External media with images
            if (isset($embed['external']) && isset($embed['external']['thumb'])) {
                $images[] = $embed['external']['thumb'];
            }

            // Case 4: Record with media (newer API format)
            if (isset($embed['$type']) && $embed['$type'] === 'app.bsky.embed.images') {
                if (isset($embed['images'])) {
                    foreach ($embed['images'] as $img) {
                        if (isset($img['fullsize'])) {
                            $images[] = $img['fullsize'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    private function format_posts($posts) {
        $formatted = array();

        foreach ($posts as $post) {
            // Get images from the post
            $images = $this->extract_images($post);
            $formatted_post = array(
                'text' => isset($post['record']['text']) ?
                    html_entity_decode($post['record']['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                'uri' => isset($post['uri']) ? $post['uri'] : '',
                'createdAt' => $post['record']['createdAt'],
                'author' => array(
                    'displayName' => isset($post['author']['displayName']) ?
                        html_entity_decode($post['author']['displayName'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
                    'handle' => $post['author']['handle'],
                    'avatar' => $post['author']['avatar']
                ),
                'images' => $images
            );

            $formatted[] = $formatted_post;
        }

        return $formatted;
    }

    public function authenticate($force = false) {
        try {
            // If we have a valid token and not forcing refresh, return true
            if (!$force && $this->access_jwt && $this->token_expiry > time()) {
                return true;
            }

            $options = get_option('bluesky_feed_options');

            if (empty($options['bluesky_identifier']) || empty($options['bluesky_password'])) {
                return false;
            }

            $response = wp_remote_post($this->base_url . 'com.atproto.server.createSession', array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'identifier' => $options['bluesky_identifier'],
                    'password' => $options['bluesky_password'],
                )),
                'timeout' => 15,
            ));

            if (is_wp_error($response)) {
                return false;
            }

	        $status = wp_remote_retrieve_response_code($response);
	        if ($status !== 200) {
		        return false;
	        }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!isset($body['accessJwt']) || !isset($body['refreshJwt'])) {
                return false;
            }

            // Store the new tokens
            $this->storeToken($body['accessJwt'], $body['refreshJwt']);
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    private function ensureValidToken() {
        if (!$this->access_jwt || $this->token_expiry <= time()) {
            if (!$this->authenticate()) {
                throw new Exception('Failed to authenticate with Bluesky');
            }
        }
    }
}
