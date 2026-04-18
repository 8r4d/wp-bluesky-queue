<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Bluesky_API {

    private $handle;
    private $app_password;
    private $pds_host;
    private $access_jwt;
    private $did;

    public function __construct() {
        $this->handle       = get_option('wpbq_bluesky_handle', '');
        $this->app_password = get_option('wpbq_bluesky_app_password', '');
        $this->pds_host     = get_option('wpbq_bluesky_pds_host', 'https://bsky.social');
    }

    /**
     * Create an authenticated session
     */
    public function create_session() {
        $url = $this->pds_host . '/xrpc/com.atproto.server.createSession';

        // DEBUG: Log what we're sending (remove after debugging)
        error_log('[WPBQ Debug] Attempting session creation...');
        error_log('[WPBQ Debug] URL: ' . $url);
        error_log('[WPBQ Debug] Handle: ' . $this->handle);
        error_log('[WPBQ Debug] Password length: ' . strlen($this->app_password));
        error_log('[WPBQ Debug] Password format check: ' . (preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', $this->app_password) ? 'Valid app password format' : 'NOT app password format'));
        // Log the full record for debugging
        error_log('[WPBQ Debug] Full record being sent: ' . wp_json_encode($args, JSON_PRETTY_PRINT));

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'identifier' => $this->handle,
                'password'   => $this->app_password,
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('[WPBQ Debug] WP_Error: ' . $response->get_error_message());
            return new WP_Error('session_failed', $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        // DEBUG: Log the response
        error_log('[WPBQ Debug] Response code: ' . $code);
        error_log('[WPBQ Debug] Response body: ' . wp_remote_retrieve_body($response));

        if ($code !== 200 || empty($body['accessJwt'])) {
            $error_msg = isset($body['message']) ? $body['message'] : 'Unknown error';
            return new WP_Error('auth_failed', $error_msg);
        }

        $this->access_jwt = $body['accessJwt'];
        $this->did         = $body['did'];

        set_transient('wpbq_refresh_jwt', $body['refreshJwt'], HOUR_IN_SECONDS);

        return true;
    }

    /**
     * Create a post on Bluesky
     *
     * @param string $text     The post text (max 300 chars)
     * @param string $link_url Optional URL to create a link card
     * @param string $image_url Optional image URL for the embed
     * @return array|WP_Error
     */
    public function create_post($text, $link_url = '', $image_url = '') {

        // Clean any WordPress shortcodes from the text
        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text);

        // Collapse multiple whitespace/newlines left behind
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Ensure we have a session
        if (empty($this->access_jwt)) {
            $session = $this->create_session();
            if (is_wp_error($session)) {
                return $session;
            }
        }

        // Build the record
        $record = array(
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'langs'     => array('en'),
        );

        // Parse and add facets (links, mentions, hashtags)
        $facets = $this->parse_facets($text);
        if (!empty($facets)) {
            $record['facets'] = $facets;
        }

        // Add link card embed if URL provided
        if (!empty($link_url)) {
            $card = $this->fetch_link_card($link_url, $image_url);
            if (!is_wp_error($card)) {
                $record['embed'] = array(
                    '$type'    => 'app.bsky.embed.external',
                    'external' => $card,
                );
            }
        }

        // Send to Bluesky
        $url  = $this->pds_host . '/xrpc/com.atproto.repo.createRecord';
        $args = array(
            'repo'       => $this->did,
            'collection' => 'app.bsky.feed.post',
            'record'     => $record,
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_jwt,
            ),
            'body'    => wp_json_encode($args),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $error_msg = isset($body['message']) ? $body['message'] : 'Post creation failed';
            return new WP_Error('post_failed', $error_msg);
        }

        return $body; // Contains 'uri' and 'cid'
    }

    /**
     * Parse text for links, mentions, and hashtags and return facets array
     */
    private function parse_facets($text) {
        $facets = array();

        // Parse URLs
        preg_match_all('/https?:\/\/[^\s\)]+/', $text, $url_matches, PREG_OFFSET_CAPTURE);
        foreach ($url_matches[0] as $match) {
            $url       = $match[0];
            $byteStart = strlen(mb_strcut($text, 0, $match[1]));
            $byteEnd   = $byteStart + strlen($url);

            $facets[] = array(
                'index'    => array(
                    'byteStart' => $byteStart,
                    'byteEnd'   => $byteEnd,
                ),
                'features' => array(
                    array(
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri'   => $url,
                    ),
                ),
            );
        }

        // Parse hashtags
        preg_match_all('/#(\w+)/', $text, $tag_matches, PREG_OFFSET_CAPTURE);
        foreach ($tag_matches[0] as $i => $match) {
            $full_tag  = $match[0];
            $tag_name  = $tag_matches[1][$i][0];
            $byteStart = strlen(mb_strcut($text, 0, $match[1]));
            $byteEnd   = $byteStart + strlen($full_tag);

            $facets[] = array(
                'index'    => array(
                    'byteStart' => $byteStart,
                    'byteEnd'   => $byteEnd,
                ),
                'features' => array(
                    array(
                        '$type' => 'app.bsky.richtext.facet#tag',
                        'tag'   => $tag_name,
                    ),
                ),
            );
        }

        // Parse mentions (@handle.bsky.social)
        preg_match_all('/@([\w.-]+\.[\w.-]+)/', $text, $mention_matches, PREG_OFFSET_CAPTURE);
        foreach ($mention_matches[0] as $i => $match) {
            $full_mention = $match[0];
            $handle       = $mention_matches[1][$i][0];
            $did          = $this->resolve_handle($handle);

            if (!is_wp_error($did)) {
                $byteStart = strlen(mb_strcut($text, 0, $match[1]));
                $byteEnd   = $byteStart + strlen($full_mention);

                $facets[] = array(
                    'index'    => array(
                        'byteStart' => $byteStart,
                        'byteEnd'   => $byteEnd,
                    ),
                    'features' => array(
                        array(
                            '$type' => 'app.bsky.richtext.facet#mention',
                            'did'   => $did,
                        ),
                    ),
                );
            }
        }

        return $facets;
    }

    /**
     * Resolve a handle to a DID
     */
    private function resolve_handle($handle) {
        $url = $this->pds_host . '/xrpc/com.atproto.identity.resolveHandle?handle=' . urlencode($handle);

        $response = wp_remote_get($url, array('timeout' => 10));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['did']) ? $body['did'] : new WP_Error('resolve_failed', 'Could not resolve handle');
    }

    /**
     * Fetch OpenGraph data for a link card
     */
    private function fetch_link_card($url, $fallback_image = '') {
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $html = wp_remote_retrieve_body($response);

        $title       = $this->extract_og_tag($html, 'og:title') ?: wp_parse_url($url, PHP_URL_HOST);
        $description = $this->extract_og_tag($html, 'og:description') ?: '';

        // PRIORITIZE the fallback_image (our stored featured image URL)
        // over the og:image tag, since og:image may point to a 
        // dynamic script that fails when fetched server-side
        $image = $fallback_image;

        if (empty($image)) {
            $og_image = $this->extract_og_tag($html, 'og:image') ?: '';

            // Only use the OG image if it looks like an actual image file,
            // not a PHP script that generates images
            if (!empty($og_image) && !preg_match('/\.php/', $og_image)) {
                // Fix relative URLs
                if (strpos($og_image, 'http') !== 0) {
                    $parsed = wp_parse_url($url);
                    $og_image = $parsed['scheme'] . '://' . $parsed['host'] . '/' . ltrim($og_image, '/');
                }
                $image = $og_image;
            }
        }

        error_log('[WPBQ Debug] Link card - Title: ' . $title);
        error_log('[WPBQ Debug] Link card - Image URL being used: ' . $image);

        $card = array(
            'uri'         => $url,
            'title'       => mb_substr($title, 0, 300),
            'description' => mb_substr($description, 0, 1000),
        );

        if (!empty($image)) {
            $thumb = $this->upload_image($image);

            if (!is_wp_error($thumb) && is_array($thumb)) {
                $formatted = $this->format_blob($thumb);
                if ($formatted !== null) {
                    $card['thumb'] = $formatted;
                } else {
                    error_log('[WPBQ Debug] Thumbnail skipped — blob format invalid');
                }
            } else {
                error_log('[WPBQ Debug] Thumbnail skipped — upload failed');
            }
        }

        return $card;
    }

    /**
     * Ensure a blob reference has the exact structure Bluesky requires:
     * {
     *   "$type": "blob",
     *   "ref": { "$link": "bafkrei..." },
     *   "mimeType": "image/jpeg",
     *   "size": 12345
     * }
     */
    private function format_blob($blob) {
        // If it already has the right structure, return it
        if (isset($blob['$type']) && $blob['$type'] === 'blob'
            && isset($blob['ref']['$link'])
            && isset($blob['mimeType'])
            && isset($blob['size'])) {
            return $blob;
        }

        // Some responses nest it differently — try to extract
        $formatted = array(
            '$type'    => 'blob',
            'ref'      => array(),
            'mimeType' => '',
            'size'     => 0,
        );

        // Extract the $link reference
        if (isset($blob['ref']['$link'])) {
            $formatted['ref']['$link'] = $blob['ref']['$link'];
        } elseif (isset($blob['ref']) && is_string($blob['ref'])) {
            $formatted['ref']['$link'] = $blob['ref'];
        } elseif (isset($blob['cid'])) {
            // Some API versions return 'cid' instead of 'ref.$link'
            $formatted['ref']['$link'] = $blob['cid'];
        }

        // Extract mimeType
        if (isset($blob['mimeType'])) {
            $formatted['mimeType'] = $blob['mimeType'];
        }

        // Extract size
        if (isset($blob['size'])) {
            $formatted['size'] = intval($blob['size']);
        }

        error_log('[WPBQ Debug] format_blob result: ' . wp_json_encode($formatted));

        // Validate we have the minimum required fields
        if (empty($formatted['ref']['$link'])) {
            error_log('[WPBQ Debug] format_blob FAILED — no ref.$link found in: ' . wp_json_encode($blob));
            return null;
        }

        return $formatted;
    }


    /**
     * Extract an OpenGraph meta tag value from HTML
     */
    private function extract_og_tag($html, $property) {
        $pattern = '/<meta\s+(?:property|name)=["\']' . preg_quote($property, '/') . '["\']\s+content=["\']([^"\']+)["\']/i';
        if (preg_match($pattern, $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }
        // Also try reverse order (content before property)
        $pattern2 = '/<meta\s+content=["\']([^"\']+)["\']\s+(?:property|name)=["\']' . preg_quote($property, '/') . '["\']/i';
        if (preg_match($pattern2, $html, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    /**
     * Upload an image to Bluesky and return the blob reference
     */
    private function upload_image($image_url) {
        $image_data = wp_remote_get($image_url, array(
            'timeout'    => 15,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (compatible; WPBlueskyQueue/1.0)',
        ));

        if (is_wp_error($image_data)) {
            return $image_data;
        }

        $http_code    = wp_remote_retrieve_response_code($image_data);
        $body         = wp_remote_retrieve_body($image_data);
        $content_type = wp_remote_retrieve_header($image_data, 'content-type');

        if ($content_type) {
            $content_type = strtolower(trim(explode(';', $content_type)[0]));
        }

        if ($http_code !== 200) {
            return new WP_Error('image_fetch_failed', 'Image URL returned HTTP ' . $http_code);
        }

        $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        if (!in_array($content_type, $allowed_types, true)) {
            return new WP_Error('not_an_image', 'URL returned "' . $content_type . '" instead of an image');
        }

        // Magic bytes check
        $is_real_image = false;
        $first_bytes = substr($body, 0, 16);
        if (substr($first_bytes, 0, 2) === "\xFF\xD8")               $is_real_image = true;
        if (substr($first_bytes, 0, 8) === "\x89PNG\r\n\x1A\n")      $is_real_image = true;
        if (substr($first_bytes, 0, 4) === "GIF8")                    $is_real_image = true;
        if (substr($first_bytes, 0, 4) === "RIFF" &&
            substr($body, 8, 4) === "WEBP")                           $is_real_image = true;

        if (!$is_real_image) {
            return new WP_Error('not_an_image', 'Downloaded content is not a valid image');
        }

        error_log('[WPBQ Debug] Image size before compression: ' . strlen($body) . ' bytes');

        // ALWAYS run through compression/resize to be safe
        // This ensures we're under 1MB and in a format Bluesky likes
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($body);

            if ($img) {
                $width  = imagesx($img);
                $height = imagesy($img);

                error_log('[WPBQ Debug] Image dimensions: ' . $width . 'x' . $height);

                // Scale down if wider/taller than 1200px OR if file is over 1MB
                $max_dimension = 1200;
                $needs_resize = ($width > $max_dimension || $height > $max_dimension || strlen($body) > 1000000);

                if ($needs_resize) {
                    $scale = min($max_dimension / $width, $max_dimension / $height, 1);
                    $new_w = intval($width * $scale);
                    $new_h = intval($height * $scale);

                    $resized = imagecreatetruecolor($new_w, $new_h);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $width, $height);
                    imagedestroy($img);
                    $img = $resized;

                    error_log('[WPBQ Debug] Resized to: ' . $new_w . 'x' . $new_h);
                }

                // Encode as JPEG — try decreasing quality until under 1MB
                $quality = 85;
                do {
                    ob_start();
                    imagejpeg($img, null, $quality);
                    $body = ob_get_clean();
                    $quality -= 10;
                    error_log('[WPBQ Debug] Compressed at quality ' . ($quality + 10) . ': ' . strlen($body) . ' bytes');
                } while (strlen($body) > 1000000 && $quality >= 30);

                imagedestroy($img);
                $content_type = 'image/jpeg'; // We converted to JPEG

                if (strlen($body) > 1000000) {
                    error_log('[WPBQ Debug] Image still too large after compression');
                    return new WP_Error('image_too_large', 'Image exceeds 1MB even after compression');
                }

                error_log('[WPBQ Debug] Final image size: ' . strlen($body) . ' bytes');
            } else {
                error_log('[WPBQ Debug] imagecreatefromstring() failed');
                if (strlen($body) > 1000000) {
                    return new WP_Error('image_too_large', 'Image exceeds 1MB and GD could not process it');
                }
            }
        } else {
            error_log('[WPBQ Debug] GD library not available');
            if (strlen($body) > 1000000) {
                return new WP_Error('image_too_large', 'Image exceeds 1MB and GD is not available for compression');
            }
        }

        // Upload to Bluesky
        $url = $this->pds_host . '/xrpc/com.atproto.repo.uploadBlob';

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => $content_type,
                'Authorization' => 'Bearer ' . $this->access_jwt,
            ),
            'body'    => $body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        error_log('[WPBQ Debug] Upload response: ' . wp_remote_retrieve_body($response));

        return isset($result['blob']) ? $result['blob'] : new WP_Error('upload_failed', 'Blob upload failed');
    }

    /**
     * Compress an image to fit under Bluesky's 1MB limit
     */
    private function compress_image($image_data, $content_type) {
        $img = @imagecreatefromstring($image_data);

        if (!$img) {
            return new WP_Error('image_too_large', 'Image exceeds 1MB and could not be compressed');
        }

        // Scale down
        $width  = imagesx($img);
        $height = imagesy($img);
        $scale  = min(1200 / $width, 1200 / $height, 1);

        if ($scale < 1) {
            $new_w   = intval($width * $scale);
            $new_h   = intval($height * $scale);
            $resized = imagecreatetruecolor($new_w, $new_h);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $width, $height);
            imagedestroy($img);
            $img = $resized;
        }

        // Output as JPEG at 80% quality
        ob_start();
        imagejpeg($img, null, 80);
        $compressed = ob_get_clean();
        imagedestroy($img);

        if (strlen($compressed) > 1000000) {
            return new WP_Error('image_too_large', 'Image still exceeds 1MB after compression');
        }

        return $compressed;
    }

    /**
     * Test the connection
     */
    public function test_connection() {
        return $this->create_session();
    }
}