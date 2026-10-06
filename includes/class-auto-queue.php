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
        $data  = WPBQ_Queue_Manager::build_post_data_from_post($post);

        $delay_minutes = intval(get_option('wpbq_auto_queue_delay', 0));
        $scheduled_at = null;
        if ($delay_minutes > 0) {
            $scheduled_at = gmdate('Y-m-d H:i:s', time() + ($delay_minutes * 60));
        }

        $queue_id = WPBQ_Queue_Manager::add_to_queue(array(
            'post_text'    => $data['text'],
            'blog_post_id' => $post->ID,
            'link_url'     => $data['url'],
            'image_url'    => $data['image_url'],
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
            '🦋 "<strong>%s</strong>" was automatically added to your Bluedon queue.',
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
        // Shown even with auto-queue off — the social blurb is also used
        // by archive imports and revivals.
        $allowed_types = get_option('wpbq_auto_queue_post_types', array('post'));
        if (!is_array($allowed_types)) $allowed_types = array('post');
        $revival_types = get_option('wpbq_revival_post_types', array('post'));
        if (!is_array($revival_types)) $revival_types = array('post');
        $allowed_types = array_unique(array_merge(array('post'), $allowed_types, $revival_types));

        add_meta_box(
            'wpbq_auto_queue',
            '🦋 Bluedon',
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

        $skip  = get_post_meta($post->ID, '_wpbq_skip_auto_queue', true);
        $skip_revival = get_post_meta($post->ID, '_wpbq_skip_revival', true);
        $blurb = get_post_meta($post->ID, '_wpbq_social_blurb', true);

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
                    ✅ Already posted
                <?php elseif ($queued->status === 'queued') : ?>
                    📋 In Bluedon queue (#<?php echo $queued->id; ?>)
                    — <a href="<?php echo admin_url('admin.php?page=wpbq-queue'); ?>">View Queue</a>
                <?php elseif ($queued->status === 'failed') : ?>
                    ❌ Failed to post — <a href="<?php echo admin_url('admin.php?page=wpbq-log'); ?>">View Log</a>
                <?php endif; ?>
            </p>
            <hr>
        <?php endif; ?>

        <p style="margin-bottom:4px;">
            <label for="wpbq_social_blurb"><strong>Social blurb</strong></label>
        </p>
        <textarea id="wpbq_social_blurb" name="wpbq_social_blurb" rows="4" style="width:100%;"><?php echo esc_textarea($blurb); ?></textarea>
        <p class="description" id="wpbq-blurb-count"></p>
        <p class="description">
            Used in place of the excerpt for <code>{excerpt}</code> and <code>{blurb}</code>.
            Put one blurb per line to have one picked at random each time it's shared.
        </p>
        <script>
        (function() {
            var box = document.getElementById('wpbq_social_blurb');
            var out = document.getElementById('wpbq-blurb-count');
            function update() {
                var lines = box.value.split(/\r?\n/).filter(function(l) { return l.trim() !== ''; });
                var longest = lines.reduce(function(max, l) { return Math.max(max, Array.from(l.trim()).length); }, 0);
                out.textContent = lines.length
                    ? lines.length + (lines.length === 1 ? ' blurb' : ' blurbs') + ', longest ' + longest + ' chars (Bluesky posts max out at 300 including title, link and hashtags)'
                    : '';
            }
            box.addEventListener('input', update);
            update();
        })();
        </script>

        <hr>
        <label>
            <input type="checkbox" name="wpbq_skip_revival" value="1" <?php checked($skip_revival, 1); ?>>
            <strong>Never revive</strong> this post
        </label>
        <p class="description" style="margin-top:8px;">
            If checked, this post won't be picked when old posts are randomly re-shared from the archive.
        </p>

        <?php if (get_option('wpbq_auto_queue_enabled', false)) : ?>
            <hr>
            <label>
                <input type="checkbox" name="wpbq_skip_auto_queue" value="1" <?php checked($skip, 1); ?>>
                <strong>Skip</strong> auto-queue for this post
            </label>
            <p class="description" style="margin-top:8px;">
                If checked, this post won't be automatically added to the Bluedon queue when published.
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Rebuild the post text of queued (not yet posted) items for a post
     */
    private function refresh_queued_text($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bluesky_queue';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE blog_post_id = %d AND status = 'queued'",
            $post_id
        ));
        if (empty($ids)) return;

        $data = WPBQ_Queue_Manager::build_post_data_from_post(get_post($post_id));
        foreach ($ids as $id) {
            WPBQ_Queue_Manager::update_item($id, array('post_text' => $data['text']));
            WPBQ_Queue_Manager::log($id, 'blurb_updated', 'Post text rebuilt after social blurb changed (Post #' . $post_id . ')');
        }
    }

    /**
     * Save meta box data
     */
    public function save_meta_box($post_id) {
        if (!isset($_POST['wpbq_meta_box_nonce'])) return;
        if (!wp_verify_nonce($_POST['wpbq_meta_box_nonce'], 'wpbq_meta_box')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $old_blurb = (string) get_post_meta($post_id, '_wpbq_social_blurb', true);
        $blurb = isset($_POST['wpbq_social_blurb'])
            ? trim(sanitize_textarea_field(wp_unslash($_POST['wpbq_social_blurb'])))
            : '';
        if ($blurb !== '') {
            update_post_meta($post_id, '_wpbq_social_blurb', $blurb);
        } else {
            delete_post_meta($post_id, '_wpbq_social_blurb');
        }

        // Auto-queue runs on publish, before meta boxes are saved (the block
        // editor saves them in a separate request afterwards), so a blurb
        // typed right before publishing would be missed. Rebuild the text of
        // any still-queued item for this post when the blurb changes.
        if ($blurb !== $old_blurb) {
            $this->refresh_queued_text($post_id);
        }

        if (isset($_POST['wpbq_skip_revival'])) {
            update_post_meta($post_id, '_wpbq_skip_revival', 1);
        } else {
            delete_post_meta($post_id, '_wpbq_skip_revival');
        }

        // The checkbox is only rendered while auto-queue is enabled; don't
        // clear a saved opt-out just because it wasn't on the form.
        if (!get_option('wpbq_auto_queue_enabled', false)) return;

        if (isset($_POST['wpbq_skip_auto_queue'])) {
            update_post_meta($post_id, '_wpbq_skip_auto_queue', 1);
        } else {
            delete_post_meta($post_id, '_wpbq_skip_auto_queue');
        }
    }
}