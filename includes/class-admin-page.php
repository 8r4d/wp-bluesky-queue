<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Admin_Page {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_wpbq_add_queue_item', array($this, 'ajax_add_item'));
        add_action('wp_ajax_wpbq_delete_queue_item', array($this, 'ajax_delete_item'));
        add_action('wp_ajax_wpbq_post_now', array($this, 'ajax_post_now'));
        add_action('wp_ajax_wpbq_import_archives', array($this, 'ajax_import_archives'));
        add_action('wp_ajax_wpbq_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wpbq_requeue_item', array($this, 'ajax_requeue_item'));
        add_action('wp_ajax_wpbq_update_order', array($this, 'ajax_update_order'));
        add_action('wp_ajax_wpbq_debug_image', array($this, 'ajax_debug_image'));
        add_action('wp_ajax_wpbq_test_mastodon', array($this, 'ajax_test_mastodon'));
        
    }

    public function ajax_debug_image() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $url = esc_url_raw($_POST['url']);
        $debug = array('original_url' => $url);

        // Step 1: Try fetching the URL
        $response = wp_remote_get($url, array(
            'timeout'    => 15,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (compatible; WPBlueskyQueue/1.0)',
            'redirection' => 5,
        ));

        if (is_wp_error($response)) {
            $debug['error'] = $response->get_error_message();
            wp_send_json_error($debug);
        }

        $debug['http_code']    = wp_remote_retrieve_response_code($response);
        $debug['content_type'] = wp_remote_retrieve_header($response, 'content-type');
        $debug['body_length']  = strlen(wp_remote_retrieve_body($response));
        $debug['final_url']    = wp_remote_retrieve_header($response, 'location') ?: 'No redirect';

        // Check first bytes
        $body = wp_remote_retrieve_body($response);
        $first_bytes = bin2hex(substr($body, 0, 16));
        $debug['first_bytes_hex'] = $first_bytes;

        // Is it HTML?
        $debug['starts_with_html'] = (stripos(trim($body), '<') === 0 || stripos(trim($body), '<!') === 0);

        // If it looks like HTML, grab the first 200 chars to see what it is
        if ($debug['starts_with_html']) {
            $debug['body_preview'] = substr(trim($body), 0, 200);
        }

        wp_send_json_success($debug);
    }

    public function ajax_update_order() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $order = json_decode(stripslashes($_POST['order']), true);

        if (!is_array($order)) wp_send_json_error('Invalid order data');

        foreach ($order as $item) {
            WPBQ_Queue_Manager::update_item(
                absint($item['id']),
                array('sort_order' => intval($item['position']))
            );
        }

        wp_send_json_success('Order updated');
    }

    public function add_menu() {
        add_menu_page(
            'Bluedon Queue',
            'Bluedon Queue',
            'manage_options',
            'wpbq-queue',
            array($this, 'render_queue_page'),
            'dashicons-share',
            30
        );

        add_submenu_page(
            'wpbq-queue',
            'Queue',
            'Queue',
            'manage_options',
            'wpbq-queue',
            array($this, 'render_queue_page')
        );

        add_submenu_page(
            'wpbq-queue',
            'Import Archives',
            'Import Archives',
            'manage_options',
            'wpbq-import',
            array($this, 'render_import_page')
        );

        add_submenu_page(
            'wpbq-queue',
            'Activity Log',
            'Activity Log',
            'manage_options',
            'wpbq-log',
            array($this, 'render_log_page')
        );

        add_submenu_page(
            'wpbq-queue',
            'Settings',
            'Settings',
            'manage_options',
            'wpbq-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        $group = 'wpbq_settings';

        // Bluesky credentials
        register_setting($group, 'wpbq_bluesky_enabled', 'absint');
        register_setting($group, 'wpbq_bluesky_handle', array(
            'sanitize_callback' => function($value) {
                $value = trim($value);
                $value = ltrim($value, '@');
                return sanitize_text_field($value);
            }
        ));
        register_setting($group, 'wpbq_bluesky_app_password', array(
            'sanitize_callback' => function($value) {
                return trim($value);
            }
        ));
        register_setting($group, 'wpbq_bluesky_pds_host', array(
            'sanitize_callback' => function($value) {
                return rtrim(esc_url_raw($value), '/');
            }
        ));

        // Mastodon
        register_setting($group, 'wpbq_mastodon_enabled', 'absint');
        register_setting($group, 'wpbq_mastodon_instance', array(
            'sanitize_callback' => function($value) {
                return rtrim(esc_url_raw($value), '/');
            }
        ));
        register_setting($group, 'wpbq_mastodon_token', array(
            'sanitize_callback' => function($value) {
                return trim($value);
            }
        ));
        register_setting($group, 'wpbq_mastodon_visibility', 'sanitize_text_field');

        // Queue settings
        register_setting($group, 'wpbq_queue_enabled', 'absint');
        register_setting($group, 'wpbq_random_enabled', 'absint');
        register_setting($group, 'wpbq_post_interval', 'absint');
        register_setting($group, 'wpbq_daily_limit', 'absint');
        register_setting($group, 'wpbq_posting_start_hour', 'absint');
        register_setting($group, 'wpbq_posting_end_hour', 'absint');
        register_setting($group, 'wpbq_random_probability', 'absint');
        register_setting($group, 'wpbq_post_template', 'sanitize_textarea_field');

        // Hashtags
        register_setting($group, 'wpbq_max_hashtags', 'absint');
        register_setting($group, 'wpbq_hashtags_from_categories', 'absint');
        register_setting($group, 'wpbq_always_include_hashtags', 'sanitize_text_field');

        // Auto-queue
        register_setting($group, 'wpbq_auto_queue_enabled', 'absint');
        register_setting($group, 'wpbq_auto_queue_post_types');
        register_setting($group, 'wpbq_auto_queue_delay', 'absint');
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'wpbq') === false) return;

        wp_enqueue_style('wpbq-admin', WPBQ_PLUGIN_URL . 'assets/admin.css', array(), WPBQ_VERSION);
        wp_enqueue_script('wpbq-admin', WPBQ_PLUGIN_URL . 'assets/admin.js', array('jquery', 'jquery-ui-sortable'), WPBQ_VERSION, true);
        wp_localize_script('wpbq-admin', 'wpbq', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wpbq_nonce'),
        ));
    }

    /**
     * QUEUE PAGE
     */
    public function render_queue_page() {
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'queued';
        $items  = WPBQ_Queue_Manager::get_queue($status, 100);
        $counts = array(
            'queued' => WPBQ_Queue_Manager::get_count('queued'),
            'posted' => WPBQ_Queue_Manager::get_count('posted'),
            'failed' => WPBQ_Queue_Manager::get_count('failed'),
        );



        ?>
        <div class="wrap wpbq-wrap">
            <h1>🦋 Bluedon Queue</h1>

            <!-- Stats Bar -->
            <div class="wpbq-stats">
                <a href="?page=wpbq-queue&status=queued" class="wpbq-stat <?php echo $status === 'queued' ? 'active' : ''; ?>">
                    📋 Queued: <strong><?php echo $counts['queued']; ?></strong>
                </a>
                <a href="?page=wpbq-queue&status=posted" class="wpbq-stat <?php echo $status === 'posted' ? 'active' : ''; ?>">
                    ✅ Posted: <strong><?php echo $counts['posted']; ?></strong>
                </a>
                <a href="?page=wpbq-queue&status=failed" class="wpbq-stat <?php echo $status === 'failed' ? 'active' : ''; ?>">
                    ❌ Failed: <strong><?php echo $counts['failed']; ?></strong>
                </a>
            </div>

<form method="post" style="margin-bottom:15px;">
    <?php wp_nonce_field('wpbq_manual_cron'); ?>
    <button type="submit" name="wpbq_run_cron" class="button">
        ⚡ Manually Run Queue Processor
    </button>
</form>
<?php
if (isset($_POST['wpbq_run_cron']) && wp_verify_nonce($_POST['_wpnonce'], 'wpbq_manual_cron')) {
    global $wpdb;
    $table = $wpdb->prefix . 'bluesky_queue';
    
    echo '<div class="notice notice-info" style="padding:15px;">';
    echo '<h3>🔍 Full Debug Report</h3>';
    
    // 1. Time check
    echo '<strong>Times:</strong><br>';
    echo 'UTC: ' . gmdate('Y-m-d H:i:s') . '<br>';
    echo 'Site local: ' . current_time('mysql') . '<br>';
    echo 'UTC_TIMESTAMP (MySQL): ' . $wpdb->get_var("SELECT UTC_TIMESTAMP()") . '<br>';
    echo 'NOW() (MySQL): ' . $wpdb->get_var("SELECT NOW()") . '<br><br>';
    
    // 2. Settings check
    echo '<strong>Settings:</strong><br>';
    echo 'Queue enabled: ' . (get_option('wpbq_queue_enabled') ? '✅ YES' : '❌ NO') . '<br>';
    echo 'Post interval: ' . get_option('wpbq_post_interval', 60) . ' minutes<br>';
    echo 'Daily limit: ' . get_option('wpbq_daily_limit', 10) . '<br>';
    echo 'Posting hours: ' . get_option('wpbq_posting_start_hour', 8) . ' to ' . get_option('wpbq_posting_end_hour', 22) . '<br>';
    echo 'Current hour (site): ' . current_time('G') . '<br>';
    echo 'Last posted time: ' . get_option('wpbq_last_posted_time', 'never') . '<br><br>';
    
    // 3. Show ALL queued items raw from database
    echo '<strong>All queued items in database:</strong><br>';
    $all_items = $wpdb->get_results("SELECT id, status, scheduled_at, sort_order, LEFT(post_text, 50) as preview FROM $table ORDER BY id DESC LIMIT 20");
    if (empty($all_items)) {
        echo '⚠️ NO ITEMS IN DATABASE AT ALL<br>';
    } else {
        echo '<table class="widefat" style="max-width:800px;">';
        echo '<tr><th>ID</th><th>Status</th><th>Scheduled At (raw DB value)</th><th>Sort Order</th><th>Preview</th></tr>';
        foreach ($all_items as $qi) {
            echo '<tr>';
            echo '<td>#' . $qi->id . '</td>';
            echo '<td>' . $qi->status . '</td>';
            echo '<td>' . ($qi->scheduled_at ?: 'NULL') . '</td>';
            echo '<td>' . $qi->sort_order . '</td>';
            echo '<td>' . esc_html($qi->preview) . '</td>';
            echo '</tr>';
        }
        echo '</table><br>';
    }
    
    // 4. Test each query the cron handler uses
    echo '<strong>Query tests:</strong><br>';
    
    $due = $wpdb->get_row("SELECT * FROM $table WHERE status = 'queued' AND scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at ASC LIMIT 1");
    echo 'get_next_due (UTC): ' . ($due ? '✅ Found #' . $due->id : '❌ None found') . '<br>';
    
    $due_local = $wpdb->get_row("SELECT * FROM $table WHERE status = 'queued' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW() ORDER BY scheduled_at ASC LIMIT 1");
    echo 'get_next_due (local NOW()): ' . ($due_local ? '✅ Found #' . $due_local->id : '❌ None found') . '<br>';
    
    $sequential = $wpdb->get_row("SELECT * FROM $table WHERE status = 'queued' AND scheduled_at IS NULL ORDER BY sort_order ASC LIMIT 1");
    echo 'get_next_in_queue (sequential): ' . ($sequential ? '✅ Found #' . $sequential->id : '❌ None found') . '<br>';
    
    $random = $wpdb->get_row("SELECT * FROM $table WHERE status = 'queued' ORDER BY RAND() LIMIT 1");
    echo 'get_random (any queued): ' . ($random ? '✅ Found #' . $random->id : '❌ None found') . '<br><br>';
    
    // 5. Try to actually post something
    echo '<strong>Attempting to post:</strong><br>';
    $item_to_post = $due ?: $due_local ?: $sequential ?: $random;
    
    if ($item_to_post) {
        echo 'Posting item #' . $item_to_post->id . ': "' . esc_html(mb_substr($item_to_post->post_text, 0, 50)) . '..."<br>';
        
        // Test API connection first
        $api = new WPBQ_Bluesky_API();
        $session = $api->test_connection();
        if (is_wp_error($session)) {
            echo '❌ API connection failed: ' . $session->get_error_message() . '<br>';
        } else {
            echo '✅ API connected<br>';
            $result = WPBQ_Cron_Handler::post_queue_item($item_to_post);
            if ($result) {
                echo '✅ <strong>POSTED SUCCESSFULLY!</strong><br>';
            } else {
                echo '❌ Post failed — check Activity Log<br>';
            }
        }
    } else {
        echo '⚠️ No items found to post in any category<br>';
    }
    
    echo '</div>';
    
    // Refresh
    $items = WPBQ_Queue_Manager::get_queue($status, 100);
    $counts = array(
        'queued' => WPBQ_Queue_Manager::get_count('queued'),
        'posted' => WPBQ_Queue_Manager::get_count('posted'),
        'failed' => WPBQ_Queue_Manager::get_count('failed'),
    );
}
?>

            <!-- Add New Post Form -->
            <div class="wpbq-add-form">
                <h2>Add New Post to Queue</h2>
                <form id="wpbq-add-form">
                    <table class="form-table">
                        <tr>
                            <th><label for="post_text">Post Text</label></th>
                            <td>
                                <textarea id="post_text" name="post_text" rows="4" class="large-text" maxlength="300"
                                    placeholder="Write your Bluesky post here... (max 300 chars)"></textarea>
                                <p class="description">Characters: <span id="char-count">0</span>/300</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="link_url">Link URL</label></th>
                            <td>
                                <input type="url" id="link_url" name="link_url" class="regular-text"
                                    placeholder="https://yourblog.com/your-post">
                                <p class="description">Optional: Creates a link card preview on Bluesky</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="blog_post_select">Or Select Blog Post</label></th>
                            <td>
                                <select id="blog_post_select" name="blog_post_id">
                                    <option value="">— Select a blog post —</option>
                                    <?php
                                    $recent = get_posts(array('numberposts' => 50, 'post_status' => 'publish'));
                                    foreach ($recent as $p) {
                                        printf('<option value="%d" data-url="%s" data-title="%s">%s (%s)</option>',
                                            $p->ID,
                                            esc_attr(get_permalink($p->ID)),
                                            esc_attr($p->post_title),
                                            esc_html($p->post_title),
                                            get_the_date('M j, Y', $p->ID)
                                        );
                                    }
                                    ?>
                                </select>
                                <button type="button" id="wpbq-populate-from-post" class="button">Populate from Post</button>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="scheduled_at">Schedule For</label></th>
                            <td>
                                <input type="datetime-local" id="scheduled_at" name="scheduled_at" class="regular-text">
                                <p class="description">Leave empty to add to the sequential queue</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Add to Queue</button>
                    </p>
                </form>
            </div>

            

            <!-- Queue Table -->
            <h2>Queue (<?php echo esc_html(ucfirst($status)); ?>)</h2>
            <table class="wp-list-table widefat fixed striped" id="wpbq-queue-table">
                <thead>
                    <tr>
                        <th class="wpbq-col-drag" width="30"></th>
                        <th>Post Text</th>
                        <th width="200">Link</th>
                        <th width="150">Schedule</th>
                        <th width="120">Status</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody id="wpbq-queue-body">
                    <?php if (empty($items)) : ?>
                        <tr><td colspan="6">No items in queue.</td></tr>
                    <?php else : ?>
                        <?php foreach ($items as $item) : ?>
                            <tr data-id="<?php echo esc_attr($item->id); ?>">
                                <td class="wpbq-drag-handle">☰</td>
                                <td>
                                    <strong><?php echo esc_html(mb_substr($item->post_text, 0, 100)); ?></strong>
                                    <?php if (mb_strlen($item->post_text) > 100) echo '...'; ?>
                                    <?php if ($item->blog_post_id) : ?>
                                        <br><small>📝 Blog Post #<?php echo $item->blog_post_id; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item->link_url) : ?>
                                        <a href="<?php echo esc_url($item->link_url); ?>" target="_blank">
                                            <?php echo esc_html(mb_substr($item->link_url, 0, 40)); ?>...
                                        </a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item->scheduled_at) : ?>
                                        🕐 <?php echo esc_html(get_date_from_gmt($item->scheduled_at, 'M j, g:ia')); ?>
                                    <?php else : ?>
                                        Sequential
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="wpbq-status wpbq-status-<?php echo esc_attr($item->status); ?>">
                                        <?php echo esc_html(ucfirst($item->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($item->status === 'queued') : ?>
                                        <button class="button button-small wpbq-post-now" data-id="<?php echo $item->id; ?>">
                                            🚀 Post Now
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($item->status === 'failed' || $item->status === 'posted') : ?>
                                        <button class="button button-small wpbq-requeue" data-id="<?php echo $item->id; ?>">
                                            🔄 Re-queue
                                        </button>
                                    <?php endif; ?>
                                    <button class="button button-small wpbq-delete" data-id="<?php echo $item->id; ?>">
                                        🗑️ Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php


 $next_scheduled = wp_next_scheduled('wpbq_process_queue');
$next_random    = wp_next_scheduled('wpbq_random_post');
echo '<div class="notice notice-info"><p>';
echo '⏰ <strong>Cron Status:</strong><br>';
echo 'Queue processing next run: ' . ($next_scheduled ? date('M j, g:i:sa', $next_scheduled) . ' UTC (' . human_time_diff($next_scheduled) . ' from now)' : '❌ NOT SCHEDULED') . '<br>';
echo 'Random posting next run: ' . ($next_random ? date('M j, g:i:sa', $next_random) . ' UTC' : '❌ NOT SCHEDULED') . '<br>';
echo 'Current time (UTC): ' . gmdate('M j, g:i:sa') . '<br>';
echo 'Current time (site): ' . current_time('M j, g:i:sa') . '<br>';
echo 'Queue enabled: ' . (get_option('wpbq_queue_enabled') ? '✅' : '❌') . '<br>';
echo 'DISABLE_WP_CRON: ' . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? '⚠️ YES — cron will NOT run on page loads' : 'No (normal)') . '<br>';
echo 'Post interval: ' . get_option('wpbq_post_interval', 60) . ' minutes<br>';
echo 'Daily limit: ' . get_option('wpbq_daily_limit', 10) . '<br>';
echo '</p></div>';
    }

    /**
     * IMPORT ARCHIVES PAGE
     */
    public function render_import_page() {
        ?>
        <div class="wrap wpbq-wrap">
            <h1>📚 Import Blog Archives to Queue</h1>
            <p>Import your published blog posts as Bluedon queue items. Each post will generate a social post with the title, excerpt, and link.</p>

            <form id="wpbq-import-form">
                <table class="form-table">
                    <tr>
                        <th>Post Type</th>
                        <td>
                            <select name="post_type">
                                <option value="post">Posts</option>
                                <option value="page">Pages</option>
                                <?php
                                $custom_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
                                foreach ($custom_types as $type) {
                                    echo '<option value="' . esc_attr($type->name) . '">' . esc_html($type->label) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Category (optional)</th>
                        <td>
                            <?php wp_dropdown_categories(array(
                                'show_option_all' => '— All Categories —',
                                'name'            => 'category',
                                'orderby'         => 'name',
                                'hide_empty'      => true,
                            )); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Date Range (optional)</th>
                        <td>
                            <label>From: <input type="date" name="date_from"></label>
                            <label>To: <input type="date" name="date_to"></label>
                        </td>
                    </tr>
                    <tr>
                        <th>Max Posts to Import</th>
                        <td>
                            <input type="number" name="max_posts" value="50" min="1" max="500">
                            <p class="description">Posts already in the queue will be skipped.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary" id="wpbq-import-btn">
                        📥 Import to Queue
                    </button>
                    <span id="wpbq-import-status"></span>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * ACTIVITY LOG PAGE
     */
    public function render_log_page() {
        $logs = WPBQ_Queue_Manager::get_logs(100);
        ?>
        <div class="wrap wpbq-wrap">
            <h1>📋 Activity Log</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="160">Time</th>
                        <th width="80">Queue ID</th>
                        <th width="120">Action</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr><td colspan="4">No log entries yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, g:ia', strtotime($log->created_at))); ?></td>
                                <td>#<?php echo esc_html($log->queue_id); ?></td>
                                <td>
                                    <span class="wpbq-log-action wpbq-log-<?php echo esc_attr($log->action); ?>">
                                        <?php echo esc_html($log->action); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * SETTINGS PAGE
     */
    public function render_settings_page() {
        ?>
        <div class="wrap wpbq-wrap">
            <h1>⚙️ Queue Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wpbq_settings'); ?>

                    <!-- ====== BLUESKY ACCOUNT ====== -->
                    <h2>🦋 Bluesky</h2>
                    <table class="form-table">
                        <tr>
                            <th>Enable Bluesky</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wpbq_bluesky_enabled" value="1"
                                        <?php checked(get_option('wpbq_bluesky_enabled', 1), 1); ?>>
                                    Post to Bluesky when processing queue
                                </label>
                            </td>
                        </tr>
                    <tr>
                        <th>Bluesky Handle</th>
                        <td>
                            <input type="text" name="wpbq_bluesky_handle" value="<?php echo esc_attr(get_option('wpbq_bluesky_handle')); ?>" class="regular-text" placeholder="yourname.bsky.social">
                        </td>
                    </tr>
                    <tr>
                        <th>App Password</th>
                        <td>
                            <input type="password" name="wpbq_bluesky_app_password" value="<?php echo esc_attr(get_option('wpbq_bluesky_app_password')); ?>" class="regular-text" placeholder="xxxx-xxxx-xxxx-xxxx">
                            <p class="description">Create at: Bluesky → Settings → Privacy and Security → App Passwords</p>
                        </td>
                    </tr>
                    <tr>
                        <th>PDS Host</th>
                        <td>
                            <input type="url" name="wpbq_bluesky_pds_host" value="<?php echo esc_attr(get_option('wpbq_bluesky_pds_host', 'https://bsky.social')); ?>" class="regular-text">
                            <p class="description">Default: https://bsky.social</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Test Connection</th>
                        <td>
                            <button type="button" id="wpbq-test-connection" class="button">🔌 Test Connection</button>
                            <span id="wpbq-test-result"></span>
                        </td>
                    </tr>
                </table>

                <!-- ====== MASTODON ====== -->
                <h2>🐘 Mastodon</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable Mastodon</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpbq_mastodon_enabled" value="1"
                                    <?php checked(get_option('wpbq_mastodon_enabled'), 1); ?>>
                                Also post to Mastodon when processing queue
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Instance URL</th>
                        <td>
                            <input type="url" name="wpbq_mastodon_instance"
                                value="<?php echo esc_attr(get_option('wpbq_mastodon_instance')); ?>"
                                class="regular-text" placeholder="https://mastodon.social">
                            <p class="description">Your Mastodon instance (e.g., https://mastodon.social, https://fosstodon.org)</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Access Token</th>
                        <td>
                            <input type="password" name="wpbq_mastodon_token"
                                value="<?php echo esc_attr(get_option('wpbq_mastodon_token')); ?>"
                                class="regular-text">
                            <p class="description">
                                To get a token:<br>
                                1. Go to your Mastodon instance → Preferences → Development → New Application<br>
                                2. Application name: <code>WP Bluesky Queue</code><br>
                                3. Scopes needed: <code>write:statuses</code> and <code>write:media</code><br>
                                4. Submit, then click your app name and copy the <strong>Access Token</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Post Visibility</th>
                        <td>
                            <select name="wpbq_mastodon_visibility">
                                <?php $vis = get_option('wpbq_mastodon_visibility', 'public'); ?>
                                <option value="public" <?php selected($vis, 'public'); ?>>🌍 Public</option>
                                <option value="unlisted" <?php selected($vis, 'unlisted'); ?>>🔓 Unlisted</option>
                                <option value="private" <?php selected($vis, 'private'); ?>>🔒 Followers Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Test Connection</th>
                        <td>
                            <button type="button" id="wpbq-test-mastodon" class="button">🔌 Test Mastodon Connection</button>
                            <span id="wpbq-test-mastodon-result"></span>
                            <p class="description">⚠️ Make sure to <strong>Save Changes</strong> before testing.</p>
                        </td>
                    </tr>
                </table>
                <h2>#️⃣ Hashtags</h2>
                            <table class="form-table">
                                <tr>
                                    <th>Max Hashtags Per Post</th>
                                    <td>
                                        <input type="number" name="wpbq_max_hashtags" 
                                            value="<?php echo esc_attr(get_option('wpbq_max_hashtags', 5)); ?>" 
                                            min="0" max="15">
                                        <p class="description">Maximum number of hashtags to append (0 to disable)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Hashtag Sources</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="wpbq_hashtags_from_categories" value="1" 
                                                <?php checked(get_option('wpbq_hashtags_from_categories'), 1); ?>>
                                            Include categories as hashtags (in addition to tags)
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Always Include These Hashtags</th>
                                    <td>
                                        <input type="text" name="wpbq_always_include_hashtags" class="large-text"
                                            value="<?php echo esc_attr(get_option('wpbq_always_include_hashtags', '')); ?>"
                                            placeholder="blog, MyBrand, tech">
                                        <p class="description">Comma-separated list. These are added to every post (before tag-based hashtags). 
                                        Don't include the # symbol.</p>
                                    </td>
                                </tr>
                            </table>

                <h2>📅 Scheduling</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable Queue Processing</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpbq_queue_enabled" value="1" <?php checked(get_option('wpbq_queue_enabled'), 1); ?>>
                                Automatically post from queue on schedule
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Post Interval (minutes)</th>
                        <td>
                            <input type="number" name="wpbq_post_interval" value="<?php echo esc_attr(get_option('wpbq_post_interval', 60)); ?>" min="5" max="1440">
                            <p class="description">Minimum minutes between sequential queue posts</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Daily Post Limit</th>
                        <td>
                            <input type="number" name="wpbq_daily_limit" value="<?php echo esc_attr(get_option('wpbq_daily_limit', 10)); ?>" min="1" max="100">
                        </td>
                    </tr>
                    <tr>
                        <th>Posting Hours</th>
                        <td>
                            From <input type="number" name="wpbq_posting_start_hour" value="<?php echo esc_attr(get_option('wpbq_posting_start_hour', 8)); ?>" min="0" max="23" style="width:60px">
                            to <input type="number" name="wpbq_posting_end_hour" value="<?php echo esc_attr(get_option('wpbq_posting_end_hour', 22)); ?>" min="0" max="23" style="width:60px">
                            <p class="description">Posts will only go out during these hours (site timezone)</p>
                        </td>
                    </tr>
                </table>

                <h2>🎲 Random Posting</h2>
                <table class="form-table">
                    <tr>
                        <th>Enable Random Posts</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpbq_random_enabled" value="1" <?php checked(get_option('wpbq_random_enabled'), 1); ?>>
                                Randomly pick from queue (in addition to scheduled posting)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Random Post Probability</th>
                        <td>
                            <input type="number" name="wpbq_random_probability" value="<?php echo esc_attr(get_option('wpbq_random_probability', 30)); ?>" min="1" max="100">%
                            <p class="description">Each hour, this is the chance a random queue item gets posted</p>
                        </td>
                    </tr>
                </table>

                <h2>📝 Post Template</h2>
                <table class="form-table">
                    <tr>
                        <th>Archive Post Template</th>
                        <td>
                            <textarea name="wpbq_post_template" rows="4" class="large-text"><?php
                                echo esc_textarea(get_option('wpbq_post_template', "📝 {title}\n\n{excerpt}\n\n🔗 {url}"));
                            ?></textarea>
                            <p class="description">
                                Available tags: <code>{title}</code>, <code>{excerpt}</code>, <code>{url}</code>
                                <br>Max 300 characters after substitution (Bluesky limit)
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>


            <h2>🔍 Debug Image Fetching</h2>
            <table class="form-table">
                <tr>
                    <th>Test Image URL</th>
                    <td>
                        <input type="url" id="wpbq-debug-image-url" class="regular-text" 
                            placeholder="Paste a featured image URL from your blog">
                        <button type="button" id="wpbq-debug-image-btn" class="button">Test Fetch</button>
                        <pre id="wpbq-debug-image-result" style="margin-top:10px; background:#f1f1f1; padding:10px; display:none;"></pre>
                    </td>
                </tr>
            </table>


        </div>
        <?php
    }

    // =====================
    // AJAX HANDLERS
    // =====================

    public function ajax_add_item() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $data = array(
            'post_text'    => sanitize_textarea_field(wp_unslash($_POST['post_text'])),
            'blog_post_id' => absint($_POST['blog_post_id'] ?? 0),
            'link_url'     => esc_url_raw(wp_unslash($_POST['link_url'] ?? '')),
            'scheduled_at' => !empty($_POST['scheduled_at']) 
                ? get_gmt_from_date(sanitize_text_field(wp_unslash($_POST['scheduled_at']))) 
                : null,
        );

        if (empty($data['post_text'])) {
            wp_send_json_error('Post text is required');
        }

        $id = WPBQ_Queue_Manager::add_to_queue($data);

        if ($id) {
            WPBQ_Queue_Manager::log($id, 'added', 'Item added to queue');
            wp_send_json_success(array('id' => $id, 'message' => 'Added to queue!'));
        } else {
            wp_send_json_error('Failed to add item');
        }
    }

    public function ajax_delete_item() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $id = absint($_POST['id']);
        WPBQ_Queue_Manager::delete_item($id);
        WPBQ_Queue_Manager::log($id, 'deleted', 'Item removed from queue');
        wp_send_json_success('Deleted');
    }

    public function ajax_post_now() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $id   = absint($_POST['id']);
        $item = WPBQ_Queue_Manager::get_item($id);

        if (!$item) wp_send_json_error('Item not found');

        $result = WPBQ_Cron_Handler::post_queue_item($item);

        if ($result) {
            wp_send_json_success('Posted to Bluesky!');
        } else {
            wp_send_json_error('Failed to post. Check the Activity Log for details.');
        }
    }

    public function ajax_requeue_item() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $id = absint($_POST['id']);
        WPBQ_Queue_Manager::update_item($id, array(
            'status'    => 'queued',
            'posted_at' => null,
        ));
        WPBQ_Queue_Manager::log($id, 'requeued', 'Item returned to queue');
        wp_send_json_success('Re-queued!');
    }

    public function ajax_import_archives() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $args = array(
            'post_type'      => sanitize_text_field($_POST['post_type'] ?? 'post'),
            'posts_per_page' => absint($_POST['max_posts'] ?? 50),
        );

        if (!empty($_POST['category']) && $_POST['category'] > 0) {
            $args['cat'] = absint($_POST['category']);
        }

        if (!empty($_POST['date_from']) || !empty($_POST['date_to'])) {
            $args['date_query'] = array();
            if (!empty($_POST['date_from'])) {
                $args['date_query']['after'] = sanitize_text_field($_POST['date_from']);
            }
            if (!empty($_POST['date_to'])) {
                $args['date_query']['before'] = sanitize_text_field($_POST['date_to']);
            }
        }

        $imported = WPBQ_Queue_Manager::import_blog_archives($args);
        WPBQ_Queue_Manager::log(0, 'import', "Imported $imported posts from archives");

        wp_send_json_success(array(
            'imported' => $imported,
            'message'  => "$imported posts imported to queue!",
        ));
    }

    public function ajax_test_connection() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $api    = new WPBQ_Bluesky_API();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error('Connection failed: ' . $result->get_error_message());
        }

        wp_send_json_success('✅ Connected to Bluesky successfully!');
    }


    public function ajax_test_mastodon() {
        check_ajax_referer('wpbq_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $api    = new WPBQ_Mastodon_API();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error('Connection failed: ' . $result->get_error_message());
        }

        $name = isset($result['display_name']) ? $result['display_name'] : $result['username'];
        wp_send_json_success('✅ Connected as ' . $name . ' (@' . $result['username'] . ')');
    }
}