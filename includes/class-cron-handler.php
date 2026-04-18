<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Cron_Handler {

    /**
     * Public accessor for today's post count
     */
    public static function get_today_post_count_public() {
        global $wpdb;
        $table = $wpdb->prefix . 'bluesky_queue';

        return intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $table
            WHERE status = 'posted'
            AND DATE(posted_at) = UTC_DATE()"
        ));
    }

    /**
     * Process scheduled queue items (runs every 5 minutes)
     */
    public static function process_scheduled_queue() {
        error_log('[WPBQ Cron] === process_scheduled_queue STARTED ===');
        
        $enabled = get_option('wpbq_queue_enabled', false);
        if (!$enabled) {
            error_log('[WPBQ Cron] STOPPED: Queue not enabled');
            return;
        }

        if (!self::is_within_posting_hours()) {
            error_log('[WPBQ Cron] STOPPED: Outside posting hours. Hour: ' . current_time('G'));
            return;
        }

        $daily_limit = intval(get_option('wpbq_daily_limit', 10));
        $today_count = self::get_today_post_count();
        if ($today_count >= $daily_limit) {
            error_log('[WPBQ Cron] STOPPED: Daily limit reached. Count: ' . $today_count . ' / ' . $daily_limit);
            return;
        }

        error_log('[WPBQ Cron] Passed all checks. Looking for due items...');

        // Check for specifically scheduled items
        $due_item = WPBQ_Queue_Manager::get_next_due();
        if ($due_item) {
            error_log('[WPBQ Cron] Found due item #' . $due_item->id . ' scheduled_at: ' . $due_item->scheduled_at);
            self::post_queue_item($due_item);
            return;
        }

        error_log('[WPBQ Cron] No scheduled items due. Checking sequential queue...');

        // Check sequential queue
        $interval_minutes = intval(get_option('wpbq_post_interval', 60));
        $last_posted = get_option('wpbq_last_posted_time', 0);
        $elapsed = time() - $last_posted;
        $needed = $interval_minutes * 60;

        error_log('[WPBQ Cron] Interval: ' . $interval_minutes . 'min. Elapsed: ' . $elapsed . 's. Needed: ' . $needed . 's.');

        if ($elapsed >= $needed) {
            $next = WPBQ_Queue_Manager::get_next_in_queue();
            if ($next) {
                error_log('[WPBQ Cron] Found sequential item #' . $next->id . ' — posting');
                self::post_queue_item($next);
            } else {
                error_log('[WPBQ Cron] No sequential items in queue');
            }
        } else {
            error_log('[WPBQ Cron] STOPPED: Interval not reached. ' . ($needed - $elapsed) . 's remaining');
        }

        error_log('[WPBQ Cron] === process_scheduled_queue ENDED ===');
    }

    /**
     * Process a random post from the queue (runs hourly or custom)
     */
    public static function process_random_post() {
        $random_enabled = get_option('wpbq_random_enabled', false);
        if (!$random_enabled) return;

        if (!self::is_within_posting_hours()) return;

        // Random chance (configurable probability)
        $probability = intval(get_option('wpbq_random_probability', 30)); // 30% default
        if (wp_rand(1, 100) > $probability) return;

        $daily_limit = intval(get_option('wpbq_daily_limit', 10));
        $today_count = self::get_today_post_count();
        if ($today_count >= $daily_limit) return;

        $item = WPBQ_Queue_Manager::get_random_item();
        if ($item) {
            self::post_queue_item($item);
        }
    }

    /**
     * Post a specific queue item to Bluesky
     */
    public static function post_queue_item($item) {
        $api = new WPBQ_Bluesky_API();

        $result = $api->create_post(
            $item->post_text,
            $item->link_url,
            $item->image_url
        );

        if (is_wp_error($result)) {
            WPBQ_Queue_Manager::update_item($item->id, array('status' => 'failed'));
            WPBQ_Queue_Manager::log($item->id, 'post_failed', $result->get_error_message());
            return false;
        }

        // Success! Update the queue item
        WPBQ_Queue_Manager::update_item($item->id, array(
            'status'      => 'posted',
            'posted_at'   => current_time('mysql', true),
            'bluesky_uri' => isset($result['uri']) ? $result['uri'] : '',
        ));

        update_option('wpbq_last_posted_time', time());

        WPBQ_Queue_Manager::log(
            $item->id,
            'posted',
            'Successfully posted to Bluesky. URI: ' . (isset($result['uri']) ? $result['uri'] : 'N/A')
        );

        return true;
    }

    /**
     * Check if current time is within configured posting hours
     */
    private static function is_within_posting_hours() {
        $start = intval(get_option('wpbq_posting_start_hour', 8));  // 8 AM
        $end   = intval(get_option('wpbq_posting_end_hour', 22));   // 10 PM

        $current_hour = intval(current_time('G')); // 0-23, site timezone

        return ($current_hour >= $start && $current_hour < $end);
    }

    /**
     * Count how many posts were made today
     */
    private static function get_today_post_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'bluesky_queue';

        return intval($wpdb->get_var(
            "SELECT COUNT(*) FROM $table
             WHERE status = 'posted'
             AND DATE(posted_at) = UTC_DATE()"
        ));
    }
}