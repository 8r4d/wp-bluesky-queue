<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Mastodon_API {

    private $instance_url;
    private $access_token;

    public function __construct() {
        $this->instance_url  = rtrim(get_option('wpbq_mastodon_instance', ''), '/');
        $this->access_token  = get_option('wpbq_mastodon_token', '');
    }

    /**
     * Post a status to Mastodon
     */
    public function create_post($text, $link_url = '', $image_url = '') {
        if (empty($this->instance_url) || empty($this->access_token)) {
            return new WP_Error('not_configured', 'Mastodon credentials not configured');
        }

        // Mastodon auto-generates link cards from URLs in the text
        // Just make sure the URL is in the post text
        if (!empty($link_url) && strpos($text, $link_url) === false) {
            $remaining = 500 - mb_strlen($text) - 2; // 500 char limit
            if ($remaining >= mb_strlen($link_url)) {
                $text .= "\n\n" . $link_url;
            }
        }

        // Mastodon limit is 500 chars
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497) . '...';
        }

        $params = array(
            'status'     => $text,
            'visibility' => get_option('wpbq_mastodon_visibility', 'public'),
            'language'   => 'en',
        );

        // Upload image if provided
        if (!empty($image_url)) {
            $media_id = $this->upload_media($image_url);
            if (!is_wp_error($media_id) && $media_id) {
                $params['media_ids'] = array($media_id);
            }
        }

        $response = wp_remote_post($this->instance_url . '/api/v1/statuses', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($params),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error = isset($body['error']) ? $body['error'] : 'HTTP ' . $code;
            return new WP_Error('mastodon_post_failed', $error);
        }

        return $body; // Contains 'id', 'url', etc.
    }

    /**
     * Upload media to Mastodon
     */
    private function upload_media($image_url) {
        // Download the image
        $image_data = wp_remote_get($image_url, array(
            'timeout'   => 15,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (compatible; WPBlueskyQueue/1.0)',
        ));

        if (is_wp_error($image_data)) {
            return $image_data;
        }

        $http_code    = wp_remote_retrieve_response_code($image_data);
        $body         = wp_remote_retrieve_body($image_data);
        $content_type = wp_remote_retrieve_header($image_data, 'content-type');

        if ($http_code !== 200) {
            return new WP_Error('image_fetch_failed', 'Image returned HTTP ' . $http_code);
        }

        if ($content_type) {
            $content_type = strtolower(trim(explode(';', $content_type)[0]));
        }

        $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        if (!in_array($content_type, $allowed_types, true)) {
            return new WP_Error('not_an_image', 'Not a valid image type: ' . $content_type);
        }

        // Mastodon accepts up to 16MB for images, but let's be reasonable
        // Compress if over 2MB
        if (strlen($body) > 2000000 && function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($body);
            if ($img) {
                $width  = imagesx($img);
                $height = imagesy($img);
                $scale  = min(1600 / $width, 1600 / $height, 1);

                if ($scale < 1) {
                    $new_w   = intval($width * $scale);
                    $new_h   = intval($height * $scale);
                    $resized = imagecreatetruecolor($new_w, $new_h);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $width, $height);
                    imagedestroy($img);
                    $img = $resized;
                }

                ob_start();
                imagejpeg($img, null, 80);
                $body = ob_get_clean();
                imagedestroy($img);
                $content_type = 'image/jpeg';
            }
        }

        // Mastodon uses multipart form upload
        $boundary = wp_generate_password(24, false);

        $payload = '';
        $payload .= '--' . $boundary . "\r\n";
        $payload .= 'Content-Disposition: form-data; name="file"; filename="image.' . str_replace('image/', '', $content_type) . '"' . "\r\n";
        $payload .= 'Content-Type: ' . $content_type . "\r\n\r\n";
        $payload .= $body . "\r\n";
        $payload .= '--' . $boundary . '--' . "\r\n";

        $response = wp_remote_post($this->instance_url . '/api/v2/media', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body'    => $payload,
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code   = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        // v2/media returns 202 for async processing, 200 for immediate
        if ($code !== 200 && $code !== 202) {
            $error = isset($result['error']) ? $result['error'] : 'Media upload failed (HTTP ' . $code . ')';
            return new WP_Error('media_upload_failed', $error);
        }

        return isset($result['id']) ? $result['id'] : null;
    }

    /**
     * Test the connection
     */
    public function test_connection() {
        if (empty($this->instance_url) || empty($this->access_token)) {
            return new WP_Error('not_configured', 'Instance URL and access token are required');
        }

        $response = wp_remote_get($this->instance_url . '/api/v1/accounts/verify_credentials', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error = isset($body['error']) ? $body['error'] : 'HTTP ' . $code;
            return new WP_Error('auth_failed', $error);
        }

        return $body; // Contains 'username', 'display_name', etc.
    }
}