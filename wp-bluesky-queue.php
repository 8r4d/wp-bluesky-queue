<?php
/**
 * Plugin Name: WP Bluesky Queue
 * Description: Manage and auto-post a queue of social media posts to Bluesky, including blog archive links.
 * Version: 1.1.11
 * Author: Brad & Claude
 * License: GPL v2 or later
 * Text Domain: wp-bluesky-queue
 */

if (!defined('ABSPATH')) exit;

define('WPBQ_VERSION', '1.1.11');
define('WPBQ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPBQ_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include classes
require_once WPBQ_PLUGIN_DIR . 'includes/class-bluesky-api.php';
require_once WPBQ_PLUGIN_DIR . 'includes/class-queue-manager.php';
require_once WPBQ_PLUGIN_DIR . 'includes/class-cron-handler.php';
require_once WPBQ_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once WPBQ_PLUGIN_DIR . 'includes/class-auto-queue.php';

// Hook cron actions immediately — don't wait for plugins_loaded
add_action('wpbq_process_queue', array('WPBQ_Cron_Handler', 'process_scheduled_queue'));
add_action('wpbq_random_post', array('WPBQ_Cron_Handler', 'process_random_post'));

/**
 * Activation: create custom database table and schedule cron
 */
function wpbq_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bluesky_queue';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_text text NOT NULL,
        blog_post_id bigint(20) unsigned DEFAULT 0,
        link_url varchar(2048) DEFAULT '',
        image_url varchar(2048) DEFAULT '',
        status varchar(20) DEFAULT 'queued',
        scheduled_at datetime DEFAULT NULL,
        posted_at datetime DEFAULT NULL,
        bluesky_uri varchar(512) DEFAULT '',
        sort_order int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status),
        KEY scheduled_at (scheduled_at),
        KEY sort_order (sort_order)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Create a log table
    $log_table = $wpdb->prefix . 'bluesky_queue_log';
    $sql_log = "CREATE TABLE $log_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        queue_id bigint(20) unsigned DEFAULT 0,
        action varchar(50) NOT NULL,
        message text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql_log);

    // Schedule cron events
    if (!wp_next_scheduled('wpbq_process_queue')) {
        wp_schedule_event(time(), 'five_minutes', 'wpbq_process_queue');
    }
    if (!wp_next_scheduled('wpbq_random_post')) {
        wp_schedule_event(time(), 'hourly', 'wpbq_random_post');
    }

    update_option('wpbq_db_version', WPBQ_VERSION);
}
register_activation_hook(__FILE__, 'wpbq_activate');

/**
 * Deactivation: clear scheduled cron events
 */
function wpbq_deactivate() {
    wp_clear_scheduled_hook('wpbq_process_queue');
    wp_clear_scheduled_hook('wpbq_random_post');
}
register_deactivation_hook(__FILE__, 'wpbq_deactivate');

/**
 * Add custom cron interval (every 5 minutes)
 */
function wpbq_cron_intervals($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300,
        'display'  => __('Every 5 Minutes', 'wp-bluesky-queue'),
    );
    return $schedules;
}
add_filter('cron_schedules', 'wpbq_cron_intervals');

/**
 * Initialize the plugin
 */
function wpbq_init() {
    add_action('wpbq_process_queue', array('WPBQ_Cron_Handler', 'process_scheduled_queue'));
    add_action('wpbq_random_post', array('WPBQ_Cron_Handler', 'process_random_post'));

    if (!wp_next_scheduled('wpbq_process_queue')) {
        wp_schedule_event(time(), 'five_minutes', 'wpbq_process_queue');
    }
    if (!wp_next_scheduled('wpbq_random_post')) {
        wp_schedule_event(time(), 'hourly', 'wpbq_random_post');
    }

    if (class_exists('WPBQ_Auto_Queue')) {
        new WPBQ_Auto_Queue();
    }

    if (is_admin()) {
        new WPBQ_Admin_Page();
    }
}

add_action('plugins_loaded', 'wpbq_init');

/**
 * On every admin page load, check if there are overdue items and process them
 */
function wpbq_admin_check_queue() {
    if (!get_option('wpbq_queue_enabled', false)) return;
    
    // Only run once per 5 minutes max
    $last_check = get_transient('wpbq_last_admin_check');
    if ($last_check) return;
    set_transient('wpbq_last_admin_check', true, 300);
    
    // Check posting hours
    $hour = intval(current_time('G'));
    $start = intval(get_option('wpbq_posting_start_hour', 8));
    $end = intval(get_option('wpbq_posting_end_hour', 22));
    if ($hour < $start || $hour >= $end) return;

    // Check daily limit
    $daily_limit = intval(get_option('wpbq_daily_limit', 10));
    $today_count = WPBQ_Cron_Handler::get_today_post_count_public();
    if ($today_count >= $daily_limit) return;
    
    // Check for overdue scheduled items first
    global $wpdb;
    $table = $wpdb->prefix . 'bluesky_queue';
    $due_item = $wpdb->get_row(
        "SELECT * FROM $table 
         WHERE status = 'queued' 
         AND scheduled_at IS NOT NULL 
         AND scheduled_at <= UTC_TIMESTAMP() 
         ORDER BY scheduled_at ASC LIMIT 1"
    );
    
    if ($due_item) {
        WPBQ_Cron_Handler::post_queue_item($due_item);
        return;
    }
    
    // Check sequential queue with interval
    $interval_minutes = intval(get_option('wpbq_post_interval', 60));
    $last_posted = get_option('wpbq_last_posted_time', 0);
    
    if ((time() - $last_posted) >= ($interval_minutes * 60)) {
        $next = WPBQ_Queue_Manager::get_next_in_queue();
        if ($next) {
            WPBQ_Cron_Handler::post_queue_item($next);
        }
    }
}
add_action('admin_init', 'wpbq_admin_check_queue');