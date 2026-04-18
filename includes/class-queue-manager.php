<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Queue_Manager {

    private static $table_name;
    private static $log_table;

    public static function init() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'bluesky_queue';
        self::$log_table  = $wpdb->prefix . 'bluesky_queue_log';



    }

    /**
     * Add an item to the queue
     */
    public static function add_to_queue($data) {
        global $wpdb;
        self::init();

        $defaults = array(
            'post_text'    => '',
            'blog_post_id' => 0,
            'link_url'     => '',
            'image_url'    => '',
            'status'       => 'queued',
            'scheduled_at' => null,
            'sort_order'   => 0,
        );

        $data = wp_parse_args($data, $defaults);

        // Auto-set sort_order to end of queue
        if (empty($data['sort_order'])) {
            $max = $wpdb->get_var("SELECT MAX(sort_order) FROM " . self::$table_name);
            $data['sort_order'] = ($max !== null) ? $max + 1 : 0;
        }

        $wpdb->insert(self::$table_name, array(
            'post_text'    => wp_unslash(sanitize_textarea_field($data['post_text'])),
            'blog_post_id' => absint($data['blog_post_id']),
            'link_url'     => esc_url_raw($data['link_url']),
            'image_url'    => esc_url_raw($data['image_url']),
            'status'       => sanitize_text_field($data['status']),
            'scheduled_at' => $data['scheduled_at'],
            'sort_order'   => intval($data['sort_order']),
            'created_at'   => current_time('mysql', true),
        ));

        return $wpdb->insert_id;
    }

    /**
     * Update a queue item
     */
    public static function update_item($id, $data) {
        global $wpdb;
        self::init();

        $update = array();
        if (isset($data['post_text']))    $update['post_text']    = sanitize_textarea_field($data['post_text']);
        if (isset($data['link_url']))     $update['link_url']     = esc_url_raw($data['link_url']);
        if (isset($data['image_url']))    $update['image_url']    = esc_url_raw($data['image_url']);
        if (isset($data['status']))       $update['status']       = sanitize_text_field($data['status']);
        if (isset($data['scheduled_at'])) $update['scheduled_at'] = $data['scheduled_at'];
        if (isset($data['sort_order']))   $update['sort_order']   = intval($data['sort_order']);
        if (isset($data['posted_at']))    $update['posted_at']    = $data['posted_at'];
        if (isset($data['bluesky_uri']))  $update['bluesky_uri']  = sanitize_text_field($data['bluesky_uri']);

        return $wpdb->update(self::$table_name, $update, array('id' => absint($id)));
    }

    /**
     * Delete a queue item
     */
    public static function delete_item($id) {
        global $wpdb;
        self::init();
        return $wpdb->delete(self::$table_name, array('id' => absint($id)));
    }

    /**
     * Get a single queue item
     */
    public static function get_item($id) {
        global $wpdb;
        self::init();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " WHERE id = %d", $id
        ));
    }

    /**
     * Get all queued items (optionally filtered by status)
     */
    public static function get_queue($status = 'queued', $limit = 50, $offset = 0) {
        global $wpdb;
        self::init();

        if ($status === 'all') {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::$table_name . " ORDER BY sort_order ASC, scheduled_at ASC LIMIT %d OFFSET %d",
                $limit, $offset
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . " WHERE status = %s ORDER BY sort_order ASC, scheduled_at ASC LIMIT %d OFFSET %d",
            $status, $limit, $offset
        ));
    }

    /**
     * Get the next scheduled item that's due to be posted
     */
    public static function get_next_due() {
        global $wpdb;
        self::init();

        return $wpdb->get_row(
            "SELECT * FROM " . self::$table_name . "
             WHERE status = 'queued'
             AND (scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP())
             ORDER BY scheduled_at ASC
             LIMIT 1"
        );
    }

    /**
     * Get the next item in the sequential queue (no specific schedule)
     */
    public static function get_next_in_queue() {
        global $wpdb;
        self::init();

        return $wpdb->get_row(
            "SELECT * FROM " . self::$table_name . "
             WHERE status = 'queued'
             AND scheduled_at IS NULL
             ORDER BY sort_order ASC
             LIMIT 1"
        );
    }

    /**
     * Get a random queued item
     */
    public static function get_random_item() {
        global $wpdb;
        self::init();

        return $wpdb->get_row(
            "SELECT * FROM " . self::$table_name . "
             WHERE status = 'queued'
             ORDER BY RAND()
             LIMIT 1"
        );
    }

    /**
     * Get queue count by status
     */
    public static function get_count($status = 'queued') {
        global $wpdb;
        self::init();

        if ($status === 'all') {
            return $wpdb->get_var("SELECT COUNT(*) FROM " . self::$table_name);
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . " WHERE status = %s", $status
        ));
    }

    /**
     * Import blog archive posts into the queue
     */
    public static function import_blog_archives($args = array()) {
        $defaults = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'category'       => '',
            'tag'            => '',
            'date_query'     => array(),
        );

        $args = wp_parse_args($args, $defaults);
        $posts = get_posts($args);
        $imported = 0;

        foreach ($posts as $post) {
            // Check if already in queue
            global $wpdb;
            self::init();
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::$table_name . " WHERE blog_post_id = %d AND status IN ('queued', 'scheduled')",
                $post->ID
            ));

            if ($exists) continue;

            // Build the post text
            $title   = $post->post_title;
            $url     = get_permalink($post->ID);

            if (has_excerpt($post->ID)) {
                $excerpt = get_the_excerpt($post->ID);
            } else {
                // Strip shortcodes BEFORE trimming words
                $clean_content = strip_shortcodes($post->post_content);
                $clean_content = wp_strip_all_tags($clean_content);
                $excerpt = wp_trim_words($clean_content, 20, '...');
            }

            // Also strip any shortcodes that might have snuck into the excerpt
            $excerpt = strip_shortcodes($excerpt);

            // Get template from settings or use default
            $template = get_option('wpbq_post_template', "📝 {title}\n\n{excerpt}\n\n🔗 {url}");
            $text = str_replace(
                array('{title}', '{excerpt}', '{url}'),
                array($title, $excerpt, $url),
                $template
            );

            // Generate hashtags from WordPress tags
            $hashtags = self::generate_hashtags($post->ID);
            if (!empty($hashtags)) {
                $text .= "\n\n" . $hashtags;
            }

            // Truncate to 300 characters (Bluesky limit)
            if (mb_strlen($text) > 300) {
                // Try to fit by removing hashtags one at a time from the end
                $text = self::fit_text_with_hashtags($template, $title, $excerpt, $url, $post->ID);
            }

            // Get featured image
           $thumb_id = get_post_thumbnail_id($post->ID);
            if ($thumb_id) {
                $image_path = get_attached_file($thumb_id);
                $image_url  = wp_get_attachment_image_url($thumb_id, 'medium_large');
            } else {
                $image_url = '';
            }

            self::add_to_queue(array(
                'post_text'    => $text,
                'blog_post_id' => $post->ID,
                'link_url'     => $url,
                'image_url'    => $image_url ?: '',
                'status'       => 'queued',
            ));

            $imported++;
        }

        return $imported;
    }

    /**
     * Log an action
     */
    public static function log($queue_id, $action, $message = '') {
        global $wpdb;
        self::init();

        $wpdb->insert(self::$log_table, array(
            'queue_id'   => absint($queue_id),
            'action'     => sanitize_text_field($action),
            'message'    => sanitize_textarea_field($message),
            'created_at' => current_time('mysql', true),
        ));
    }

    /**
     * Get recent logs
     */
    public static function get_logs($limit = 50) {
        global $wpdb;
        self::init();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::$log_table . " ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }


    /**
     * Generate hashtag string from a post's WordPress tags and categories
     */
    public static function generate_hashtags($post_id) {
        $hashtags = array();
        $max_tags = intval(get_option('wpbq_max_hashtags', 5));

        // Get tags
        $tags = get_the_tags($post_id);
        if ($tags && !is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $hashtags[] = self::format_hashtag($tag->name);
            }
        }

        // Optionally include categories
        $include_categories = get_option('wpbq_hashtags_from_categories', false);
        if ($include_categories) {
            $categories = get_the_category($post_id);
            if ($categories && !is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    // Skip "Uncategorized"
                    if (strtolower($cat->name) === 'uncategorized') continue;
                    $hashtag = self::format_hashtag($cat->name);
                    if (!in_array($hashtag, $hashtags, true)) {
                        $hashtags[] = $hashtag;
                    }
                }
            }
        }

        // Add any custom always-include hashtags
        $always_include = get_option('wpbq_always_include_hashtags', '');
        if (!empty($always_include)) {
            $custom = array_map('trim', explode(',', $always_include));
            foreach ($custom as $tag) {
                if (empty($tag)) continue;
                $formatted = self::format_hashtag($tag);
                if (!in_array($formatted, $hashtags, true)) {
                    // Prepend custom tags so they always appear
                    array_unshift($hashtags, $formatted);
                }
            }
        }

        // Limit to max
        $hashtags = array_slice($hashtags, 0, $max_tags);

        return implode(' ', $hashtags);
    }

    /**
     * Format a string as a valid Bluesky hashtag
     * Bluesky hashtags: no spaces, no special chars, no leading #
     * (we add the # prefix)
     */
    private static function format_hashtag($text) {
        // Remove special characters, keep letters, numbers, underscores
        $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $text);

        // Convert spaces/hyphens to CamelCase
        // e.g., "web development" -> "WebDevelopment"
        $words = preg_split('/[\s\-_]+/', $text);
        $tag = '';
        foreach ($words as $word) {
            $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (!empty($clean)) {
                $tag .= mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
            }
        }

        if (empty($tag)) return '';

        return '#' . $tag;
    }

    /**
     * Fit post text + hashtags within 300 chars, removing hashtags as needed
     */
    private static function fit_text_with_hashtags($template, $title, $excerpt, $url, $post_id) {
        $base_text = str_replace(
            array('{title}', '{excerpt}', '{url}'),
            array($title, $excerpt, $url),
            $template
        );

        // Strip shortcodes from base text
        $base_text = strip_shortcodes($base_text);
        $base_text = preg_replace('/$$[^$$]+\]/', '', $base_text);

        $hashtags = array();
        $max_tags = intval(get_option('wpbq_max_hashtags', 5));

        // Build ordered hashtag list
        $always_include = get_option('wpbq_always_include_hashtags', '');
        if (!empty($always_include)) {
            $custom = array_map('trim', explode(',', $always_include));
            foreach ($custom as $tag) {
                if (!empty($tag)) $hashtags[] = self::format_hashtag($tag);
            }
        }

        $tags = get_the_tags($post_id);
        if ($tags && !is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $formatted = self::format_hashtag($tag->name);
                if (!in_array($formatted, $hashtags, true)) {
                    $hashtags[] = $formatted;
                }
            }
        }

        $include_categories = get_option('wpbq_hashtags_from_categories', false);
        if ($include_categories) {
            $categories = get_the_category($post_id);
            if ($categories && !is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    if (strtolower($cat->name) === 'uncategorized') continue;
                    $formatted = self::format_hashtag($cat->name);
                    if (!in_array($formatted, $hashtags, true)) {
                        $hashtags[] = $formatted;
                    }
                }
            }
        }

        $hashtags = array_slice($hashtags, 0, $max_tags);

        // Try fitting with all hashtags, then remove from the end until it fits
        while (!empty($hashtags)) {
            $hashtag_str = implode(' ', $hashtags);
            $full_text = $base_text . "\n\n" . $hashtag_str;

            if (mb_strlen($full_text) <= 300) {
                return $full_text;
            }

            // Remove last hashtag and try again
            array_pop($hashtags);
        }

        // No room for any hashtags — truncate base text
        if (mb_strlen($base_text) > 300) {
            $base_text = mb_substr($base_text, 0, 297) . '...';
        }

        return $base_text;
    }



}