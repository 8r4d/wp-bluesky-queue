<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Auto_Queue {

    public function __construct() {
        add_action('transition_post_status', array($this, 'on_post_publish'), 10, 3);
        add_action('admin_notices', array($this, 'show_queued_notice'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save_meta_box'));
    }

    /**
     * When a post transitions to 'publish', auto-add to Bluesky queue
     */
    public function on_post_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish') return;
        if ($old_status === 'publish') return;

        if (!get_option('wpbq_auto_queue_enabled', false)) return;

        $allowed_types = get_option('wpbq_auto_queue_post_types', array('post'));
        if (!is_array($allowed_types)) $allowed_types = array('post');
        if (!in_array($post->post_type, $allowed_types, true)) return;

        $opt_out = get_post_meta($post->ID, '_wpbq_skip_auto_queue', true);
        if ($opt_out) return;

        global $wpdb;
        $table = $wpdb->prefix . 'bluesky_queue';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE blog_post_id = %d AND status IN ('queued', 'posted')",
            $post->ID
        ));
        if ($exists) return;

        $title = $post->post_title;
        $url   = get_permalink($post->ID);

        if (has_excerpt($post->ID)) {
            $excerpt = get_the_excerpt($post->ID);
        } else {
            $clean_content = strip_shortcodes($post->post_content);
            $clean_content = preg_replace('/$$[^$$]+\]/', '', $clean_content);
            $clean_content = wp_strip_all_tags($clean_content);
            $excerpt = wp_trim_words($clean_content, 20, '...');
        }

        $template = get_option('wpbq_post_template', "📝 {title}\n\n{excerpt}\n\n🔗 {url}");
        $text = str_replace(
            array('{title}', '{excerpt}', '{url}'),
            array($title, $excerpt, $url),
            $template
        );

        // Add hashtags
        $hashtags = WPBQ_Queue_Manager::generate_hashtags($post->ID);
        if (!empty($hashtags)) {
            $full_text = $text . "\n\n" . $hashtags;
            if (mb_strlen($full_text) <= 300) {
                $text = $full_text;
            } else {
                $text = WPBQ_Queue_Manager::fit_text_with_hashtags(
                    $template, $title, $excerpt, $url, $post->ID
                );
            }
        }

        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 297) . '...';
        }

        $thumb_id = get_post_thumbnail_id($post->ID);
        $image_url = '';
        if ($thumb_id) {
            $image_url = wp_get_attachment_image_url($thumb_id, 'medium_large');
            if (!$image_url) {
                $image_url = wp_get_attachment_image_url($thumb_id, 'large');
            }
        }

        $delay_minutes = intval(get_option('wpbq_auto_queue_delay', 0));
        $scheduled_at = null;
        if ($delay_minutes > 0) {
            $scheduled_at = gmdate('Y-m-d H:i:s', time() + ($delay_minutes * 60));
        }

        $queue_id = WPBQ_Queue_Manager::add_to_queue(array(
            'post_text'    => $text,
            'blog_post_id' => $post->ID,
            'link_url'     => $url,
            'image_url'    => $image_url,
            'status'       => 'queued',
            'scheduled_at' => $scheduled_at,
        ));

        if ($queue_id) {
            WPBQ_Queue_Manager::log(
                $queue_id,
                'auto_queued',
                'Auto-queued from post publish: "' . $title . '" (Post #' . $post->ID . ')'
            );

            if (is_admin()) {
                set_transient('wpbq_just_queued_' . get_current_user_id(), array(
                    'post_title' => $title,
                    'queue_id'   => $queue_id,
                    'delay'      => $delay_minutes,
                ), 60);
            }
        }
    }

    /**
     * Show admin notice after a post was auto-queued
     */
    public function show_queued_notice() {
        $notice = get_transient('wpbq_just_queued_' . get_current_user_id());
        if (!$notice) return;

        delete_transient('wpbq_just_queued_' . get_current_user_id());

        $message = sprintf(
            '🦋 "<strong>%s</strong>" was automatically added to your Bluesky queue.',
            esc_html($notice['post_title'])
        );

        if ($notice['delay'] > 0) {
            $message .= sprintf(' It will be posted in %d minutes.', $notice['delay']);
        }

        $message .= sprintf(
            ' <a href="%s">View Queue</a>',
            admin_url('admin.php?page=wpbq-queue')
        );

        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', $message);
    }

    /**
     * Add meta box to post editor
     */
    public function add_meta_box() {
        if (!get_option('wpbq_auto_queue_enabled', false)) return;

        $allowed_types = get_option('wpbq_auto_queue_post_types', array('post'));
        if (!is_array($allowed_types)) $allowed_types = array('post');

        add_meta_box(
            'wpbq_auto_queue',
            '🦋 Bluesky Auto-Queue',
            array($this, 'render_meta_box'),
            $allowed_types,
            'side',
            'default'
        );
    }

    /**
     * Render meta box content
     */
    public function render_meta_box($post) {
        wp_nonce_field('wpbq_meta_box', 'wpbq_meta_box_nonce');

        $skip = get_post_meta($post->ID, '_wpbq_skip_auto_queue', true);

        global $wpdb;
        $table = $wpdb->prefix . 'bluesky_queue';
        $queued = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM $table WHERE blog_post_id = %d ORDER BY id DESC LIMIT 1",
            $post->ID
        ));
        ?>

        <?php if ($queued) : ?>
            <p>
                <?php if ($queued->status === 'posted') : ?>
                    ✅ Already posted to Bluesky
                <?php elseif ($queued->status === 'queued') : ?>
                    📋 In Bluesky queue (#<?php echo $queued->id; ?>)
                    — <a href="<?php echo admin_url('admin.php?page=wpbq-queue'); ?>">View Queue</a>
                <?php elseif ($queued->status === 'failed') : ?>
                    ❌ Failed to post — <a href="<?php echo admin_url('admin.php?page=wpbq-log'); ?>">View Log</a>
                <?php endif; ?>
            </p>
            <hr>
        <?php endif; ?>

        <label>
            <input type="checkbox" name="wpbq_skip_auto_queue" value="1" <?php checked($skip, 1); ?>>
            <strong>Skip</strong> auto-queue for this post
        </label>
        <p class="description" style="margin-top:8px;">
            If checked, this post won't be automatically added to the Bluesky queue when published.
        </p>
        <?php
    }

    /**
     * Save meta box data
     */
    public function save_meta_box($post_id) {
        if (!isset($_POST['wpbq_meta_box_nonce'])) return;
        if (!wp_verify_nonce($_POST['wpbq_meta_box_nonce'], 'wpbq_meta_box')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['wpbq_skip_auto_queue'])) {
            update_post_meta($post_id, '_wpbq_skip_auto_queue', 1);
        } else {
            delete_post_meta($post_id, '_wpbq_skip_auto_queue');
        }
    }
}