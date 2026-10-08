<?php
if (!defined('ABSPATH')) exit;

/**
 * Bulk import queue items from an external CSV or JSON source.
 *
 * Each row/object may have: post_text, link_url, image_url, scheduled_at,
 * blog_post_id. A row needs either post_text or a published blog_post_id
 * (in which case the text is built from the post templates, just like
 * archive imports).
 */
class WPBQ_Bulk_Import {

    const MAX_ROWS = 1000;
    const MAX_TEXT = 300;

    /**
     * Header aliases => canonical field name. Headers are lowercased and
     * spaces/hyphens turned into underscores before lookup.
     */
    private static $aliases = array(
        'post_text'    => 'post_text',
        'text'         => 'post_text',
        'post'         => 'post_text',
        'message'      => 'post_text',
        'content'      => 'post_text',
        'link_url'     => 'link_url',
        'link'         => 'link_url',
        'url'          => 'link_url',
        'image_url'    => 'image_url',
        'image'        => 'image_url',
        'scheduled_at' => 'scheduled_at',
        'schedule'     => 'scheduled_at',
        'scheduled'    => 'scheduled_at',
        'date'         => 'scheduled_at',
        'datetime'     => 'scheduled_at',
        'publish_at'   => 'scheduled_at',
        'blog_post_id' => 'blog_post_id',
        'post_id'      => 'blog_post_id',
    );

    /**
     * Parse raw CSV or JSON into a list of rows keyed by canonical field.
     *
     * @return array|WP_Error array('rows' => [[row => n, fields...]], 'warnings' => [])
     */
    public static function parse($raw) {
        // Strip a UTF-8 BOM (Excel adds one to "CSV UTF-8" exports)
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
        $trimmed = ltrim($raw);

        if ($trimmed === '') {
            return new WP_Error('wpbq_empty', 'Nothing to import — the file or pasted text is empty.');
        }

        $first = $trimmed[0];
        $records = ($first === '[' || $first === '{') ? self::parse_json($trimmed) : self::parse_csv($raw);
        if (is_wp_error($records)) return $records;

        if (empty($records['rows'])) {
            return new WP_Error('wpbq_empty', 'No rows found to import.');
        }
        if (count($records['rows']) > self::MAX_ROWS) {
            return new WP_Error('wpbq_too_many', sprintf('Too many rows (%d). Import at most %d at a time.', count($records['rows']), self::MAX_ROWS));
        }

        return $records;
    }

    private static function parse_json($raw) {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new WP_Error('wpbq_bad_json', 'Invalid JSON: ' . json_last_error_msg());
        }
        if (empty($data)) {
            return array('rows' => array(), 'warnings' => array());
        }

        // Accept a bare object, an array of objects, or {"posts": [...]}
        if (isset($data['posts']) && is_array($data['posts'])) {
            $data = $data['posts'];
        } elseif (array_keys($data) !== range(0, count($data) - 1)) {
            $data = array($data);
        }

        $rows     = array();
        $unknown  = array();
        foreach ($data as $i => $obj) {
            if (!is_array($obj)) {
                return new WP_Error('wpbq_bad_json', sprintf('Item %d is not an object.', $i + 1));
            }
            $row = array('row' => $i + 1);
            foreach ($obj as $key => $value) {
                $field = self::canonical_field($key);
                if ($field === null) {
                    $unknown[$key] = true;
                    continue;
                }
                $row[$field] = is_scalar($value) ? trim((string) $value) : '';
            }
            $rows[] = $row;
        }

        return array('rows' => $rows, 'warnings' => self::unknown_warning(array_keys($unknown)));
    }

    private static function parse_csv($raw) {
        $delimiter = self::detect_delimiter($raw);

        // fgetcsv handles quoted fields containing commas and line breaks
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);

        $header = fgetcsv($fh, 0, $delimiter, '"', '');
        if (!$header) {
            fclose($fh);
            return new WP_Error('wpbq_bad_csv', 'Could not read a header row from the CSV.');
        }

        $map     = array();
        $unknown = array();
        foreach ($header as $index => $name) {
            $field = self::canonical_field($name);
            if ($field === null) {
                if (trim((string) $name) !== '') $unknown[] = $name;
                continue;
            }
            $map[$index] = $field;
        }

        if (!in_array('post_text', $map, true) && !in_array('blog_post_id', $map, true)) {
            fclose($fh);
            return new WP_Error('wpbq_bad_csv', 'The first row must be a header with at least a "post_text" (or "blog_post_id") column. Found: ' . implode(', ', array_map('strval', $header)));
        }

        $rows   = array();
        $row_no = 1; // header is row 1, matching spreadsheet row numbers
        while (($cells = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $row_no++;
            // Skip blank lines
            if ($cells === array(null) || implode('', array_map('trim', $cells)) === '') continue;

            $row = array('row' => $row_no);
            foreach ($map as $index => $field) {
                $row[$field] = isset($cells[$index]) ? trim($cells[$index]) : '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return array('rows' => $rows, 'warnings' => self::unknown_warning($unknown));
    }

    /**
     * Pick comma, semicolon or tab — whichever appears most in the header
     * line (Excel uses semicolons in some locales; TSV pastes from sheets).
     */
    private static function detect_delimiter($raw) {
        $line = strtok($raw, "\r\n");
        $best = ',';
        $best_count = 0;
        foreach (array(',', ';', "\t") as $candidate) {
            $count = substr_count((string) $line, $candidate);
            if ($count > $best_count) {
                $best = $candidate;
                $best_count = $count;
            }
        }
        return $best;
    }

    private static function canonical_field($name) {
        $key = strtolower(trim((string) $name));
        $key = preg_replace('/[\s\-]+/', '_', $key);
        return isset(self::$aliases[$key]) ? self::$aliases[$key] : null;
    }

    private static function unknown_warning($columns) {
        if (empty($columns)) return array();
        return array('Ignored unrecognized column(s): ' . implode(', ', $columns));
    }

    /**
     * Validate parsed rows and, unless $dry_run, add the valid ones to the
     * queue in file order.
     *
     * @return array('rows' => [...], 'counts' => [...])
     */
    public static function import($rows, $dry_run = true, $skip_duplicates = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'bluesky_queue';

        // What's already waiting in the queue, for duplicate detection
        $queued_texts = array_flip($wpdb->get_col("SELECT post_text FROM $table WHERE status = 'queued'"));
        $queued_posts = array_flip(array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT blog_post_id FROM $table WHERE status = 'queued' AND blog_post_id > 0"
        )));

        $results = array();
        $counts  = array('ok' => 0, 'skipped' => 0, 'error' => 0);
        $now     = time();

        foreach ($rows as $row) {
            $result = self::prepare_row($row, $now);

            if ($result['status'] === 'ok' && $skip_duplicates) {
                if ($result['generated'] && isset($queued_posts[$result['blog_post_id']])) {
                    $result['status']  = 'skipped';
                    $result['message'] = 'Blog post #' . $result['blog_post_id'] . ' is already in the queue';
                } elseif (!$result['generated'] && isset($queued_texts[$result['post_text']])) {
                    $result['status']  = 'skipped';
                    $result['message'] = 'Identical post text is already in the queue';
                }
            }

            if ($result['status'] === 'ok') {
                // Later rows in the same file count as duplicates of this one
                $queued_texts[$result['post_text']] = true;
                if ($result['blog_post_id']) $queued_posts[$result['blog_post_id']] = true;

                if (!$dry_run) {
                    $id = WPBQ_Queue_Manager::add_to_queue(array(
                        'post_text'    => $result['post_text'],
                        'blog_post_id' => $result['blog_post_id'],
                        'link_url'     => $result['link_url'],
                        'image_url'    => $result['image_url'],
                        'scheduled_at' => $result['scheduled_at'],
                    ));
                    if (!$id) {
                        $result['status']  = 'error';
                        $result['message'] = 'Database insert failed';
                    } else {
                        $result['id'] = $id;
                    }
                }
            }

            $counts[$result['status']]++;
            $results[] = $result;
        }

        return array('rows' => $results, 'counts' => $counts);
    }

    /**
     * Validate a single row and fill in generated text for blog-post rows.
     */
    private static function prepare_row($row, $now) {
        $text    = isset($row['post_text']) ? sanitize_textarea_field($row['post_text']) : '';
        $link    = isset($row['link_url']) ? $row['link_url'] : '';
        $image   = isset($row['image_url']) ? $row['image_url'] : '';
        $when    = isset($row['scheduled_at']) ? $row['scheduled_at'] : '';
        $post_id = isset($row['blog_post_id']) ? absint($row['blog_post_id']) : 0;

        $result = array(
            'row'          => $row['row'],
            'status'       => 'ok',
            'message'      => '',
            'post_text'    => $text,
            'link_url'     => '',
            'image_url'    => '',
            'scheduled_at' => null,
            'schedule_display' => 'Sequential',
            'blog_post_id' => 0,
            'generated'    => false,
        );

        $error = function($message) use (&$result) {
            $result['status']  = 'error';
            $result['message'] = $message;
            return $result;
        };

        if ($post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                return $error('Blog post #' . $post_id . ' not found or not published');
            }
            $result['blog_post_id'] = $post_id;

            if ($text === '') {
                $data = WPBQ_Queue_Manager::build_post_data_from_post($post);
                $result['post_text'] = $data['text'];
                $result['generated'] = true;
                if ($link === '')  $link  = $data['url'];
                if ($image === '') $image = $data['image_url'];
            }
        }

        if ($result['post_text'] === '') {
            return $error('Missing post text (or a blog_post_id to generate it from)');
        }
        if (mb_strlen($result['post_text']) > self::MAX_TEXT) {
            return $error(sprintf('Post text is %d characters (max %d)', mb_strlen($result['post_text']), self::MAX_TEXT));
        }

        foreach (array('link_url' => $link, 'image_url' => $image) as $field => $url) {
            if ($url === '') continue;
            $clean = esc_url_raw($url, array('http', 'https'));
            if ($clean === '' || !filter_var($clean, FILTER_VALIDATE_URL)) {
                return $error('Invalid ' . str_replace('_', ' ', $field) . ': ' . $url);
            }
            $result[$field] = $clean;
        }

        if ($when !== '') {
            // Times without an explicit offset are read in the site's timezone
            try {
                $date = new DateTimeImmutable($when, wp_timezone());
            } catch (Exception $e) {
                return $error('Unrecognized date/time: ' . $when);
            }
            if ($date->getTimestamp() <= $now) {
                return $error('Scheduled time is in the past: ' . $when);
            }
            $result['scheduled_at']     = gmdate('Y-m-d H:i:s', $date->getTimestamp());
            $result['schedule_display'] = wp_date('M j, Y g:ia', $date->getTimestamp());
        }

        if ($result['generated']) {
            $result['message'] = 'Text generated from a post template';
        }

        return $result;
    }
}
