<?php
if (!defined('ABSPATH')) {
    exit;
}

class Square_Loyalty_Points_Manager {
    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var string
     */
    private $activity_table;

    /**
     * @var string
     */
    private $role_runs_table;

    public function __construct($wpdb_object = null) {
        global $wpdb;
        $this->wpdb = $wpdb_object ?: $wpdb;
        $this->activity_table = $this->wpdb->prefix . 'square_loyalty_points_activity';
        $this->role_runs_table = $this->wpdb->prefix . 'square_loyalty_points_role_runs';
    }

    public static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $activity_table = $wpdb->prefix . 'square_loyalty_points_activity';
        $role_runs_table = $wpdb->prefix . 'square_loyalty_points_role_runs';

        $activity_sql = "CREATE TABLE {$activity_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            square_customer_id VARCHAR(191) NULL,
            loyalty_account_id VARCHAR(191) NULL,
            square_event_id VARCHAR(191) NULL,
            action VARCHAR(50) NOT NULL,
            points INT NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'success',
            error_message TEXT NULL,
            role_run_id BIGINT UNSIGNED NULL,
            admin_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY square_customer_id (square_customer_id),
            KEY loyalty_account_id (loyalty_account_id),
            KEY square_event_id (square_event_id),
            KEY action (action),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $role_runs_sql = "CREATE TABLE {$role_runs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_key VARCHAR(100) NOT NULL,
            role_label VARCHAR(191) NULL,
            operation VARCHAR(30) NOT NULL,
            points INT NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            applied_count INT UNSIGNED NOT NULL DEFAULT 0,
            excluded_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            applied_participants LONGTEXT NULL,
            excluded_participants LONGTEXT NULL,
            skipped_participants LONGTEXT NULL,
            failed_participants LONGTEXT NULL,
            admin_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY role_key (role_key),
            KEY operation (operation)
        ) {$charset_collate};";

        dbDelta($activity_sql);
        dbDelta($role_runs_sql);
    }

    public function get_activity_table_name() {
        return $this->activity_table;
    }

    public function get_role_runs_table_name() {
        return $this->role_runs_table;
    }

    public function log_activity($args = array()) {
        $defaults = array(
            'user_id' => 0,
            'square_customer_id' => '',
            'loyalty_account_id' => '',
            'square_event_id' => '',
            'action' => '',
            'points' => 0,
            'note' => '',
            'status' => 'success',
            'error_message' => '',
            'role_run_id' => null,
            'admin_user_id' => null,
        );

        $args = wp_parse_args($args, $defaults);
        $action = sanitize_key((string) $args['action']);
        if ($action === '') {
            $action = 'unknown';
        }

        $status = sanitize_key((string) $args['status']);
        if (!in_array($status, array('success', 'skipped', 'failed', 'no_change'), true)) {
            $status = 'success';
        }

        $inserted = $this->wpdb->insert(
            $this->activity_table,
            array(
                'user_id' => absint($args['user_id']),
                'square_customer_id' => sanitize_text_field((string) $args['square_customer_id']),
                'loyalty_account_id' => sanitize_text_field((string) $args['loyalty_account_id']),
                'square_event_id' => sanitize_text_field((string) $args['square_event_id']),
                'action' => $action,
                'points' => (int) $args['points'],
                'note' => sanitize_text_field((string) $args['note']),
                'status' => $status,
                'error_message' => sanitize_textarea_field((string) $args['error_message']),
                'role_run_id' => !empty($args['role_run_id']) ? absint($args['role_run_id']) : null,
                'admin_user_id' => !empty($args['admin_user_id']) ? absint($args['admin_user_id']) : null,
                'created_at' => $this->now_gmt(),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s')
        );

        return $inserted ? (int) $this->wpdb->insert_id : false;
    }

    public function get_recent_activity($limit = 100) {
        $limit = max(1, absint($limit));

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT *
                FROM {$this->activity_table}
                ORDER BY created_at DESC, id DESC
                LIMIT %d",
                $limit
            )
        );
    }

    public function get_user_activity($user_id, $limit = 100) {
        $user_id = absint($user_id);
        $limit = max(1, absint($limit));
        if ($user_id <= 0) {
            return array();
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT *
                FROM {$this->activity_table}
                WHERE user_id = %d
                ORDER BY created_at DESC, id DESC
                LIMIT %d",
                $user_id,
                $limit
            )
        );
    }

    public function log_role_run($args = array()) {
        $defaults = array(
            'role_key' => '',
            'role_label' => '',
            'operation' => '',
            'points' => 0,
            'note' => '',
            'applied_participants' => array(),
            'excluded_participants' => array(),
            'skipped_participants' => array(),
            'failed_participants' => array(),
            'admin_user_id' => null,
        );

        $args = wp_parse_args($args, $defaults);
        $role_key = sanitize_key((string) $args['role_key']);
        $operation = sanitize_key((string) $args['operation']);
        if ($role_key === '' || $operation === '') {
            return false;
        }

        $applied = $this->sanitize_role_run_participants($args['applied_participants']);
        $excluded = $this->sanitize_role_run_participants($args['excluded_participants']);
        $skipped = $this->sanitize_role_run_participants($args['skipped_participants']);
        $failed = $this->sanitize_role_run_participants($args['failed_participants']);

        $inserted = $this->wpdb->insert(
            $this->role_runs_table,
            array(
                'role_key' => $role_key,
                'role_label' => sanitize_text_field((string) $args['role_label']),
                'operation' => $operation,
                'points' => (int) $args['points'],
                'note' => sanitize_text_field((string) $args['note']),
                'applied_count' => count($applied),
                'excluded_count' => count($excluded),
                'skipped_count' => count($skipped),
                'failed_count' => count($failed),
                'applied_participants' => wp_json_encode($applied),
                'excluded_participants' => wp_json_encode($excluded),
                'skipped_participants' => wp_json_encode($skipped),
                'failed_participants' => wp_json_encode($failed),
                'admin_user_id' => !empty($args['admin_user_id']) ? absint($args['admin_user_id']) : null,
                'created_at' => $this->now_gmt(),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
        );

        return $inserted ? (int) $this->wpdb->insert_id : false;
    }

    public function count_role_runs() {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->role_runs_table}");
    }

    public function get_role_runs($limit = 50, $offset = 0) {
        $limit = max(1, absint($limit));
        $offset = max(0, absint($offset));

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT *
                FROM {$this->role_runs_table}
                ORDER BY created_at DESC, id DESC
                LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
    }

    public function get_role_run($run_id) {
        $run_id = absint($run_id);
        if ($run_id <= 0) {
            return null;
        }

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT *
                FROM {$this->role_runs_table}
                WHERE id = %d",
                $run_id
            )
        );
    }

    public function decode_role_run_participants($json) {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return array();
        }

        return $this->sanitize_role_run_participants($decoded);
    }

    public function update_role_run_participants($run_id, $applied_participants, $excluded_participants) {
        $run_id = absint($run_id);
        if ($run_id <= 0) {
            return false;
        }

        $applied = $this->sanitize_role_run_participants($applied_participants);
        $excluded = $this->sanitize_role_run_participants($excluded_participants);

        $updated = $this->wpdb->update(
            $this->role_runs_table,
            array(
                'applied_count' => count($applied),
                'excluded_count' => count($excluded),
                'applied_participants' => wp_json_encode($applied),
                'excluded_participants' => wp_json_encode($excluded),
            ),
            array('id' => $run_id),
            array('%d', '%d', '%s', '%s'),
            array('%d')
        );

        return $updated !== false;
    }

    public function get_point_balance_timeseries($days = 90) {
        $days = max(7, absint($days));
        $tz = wp_timezone();
        $local_end = new DateTimeImmutable('today', $tz);
        $local_start = $local_end->modify('-' . ($days - 1) . ' days');
        $local_start_ymd = $local_start->format('Y-m-d');
        $local_end_ymd = $local_end->format('Y-m-d');
        $start_gmt = $local_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end_gmt = $local_end->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $prior_total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(SUM(points), 0)
                FROM {$this->activity_table}
                WHERE status = 'success'
                    AND created_at < %s",
                $start_gmt
            )
        );

        $entries = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT created_at, points
                FROM {$this->activity_table}
                WHERE status = 'success'
                    AND created_at >= %s
                    AND created_at < %s
                ORDER BY created_at ASC, id ASC",
                $start_gmt,
                $end_gmt
            )
        );

        $entries_by_date = array();
        foreach ($entries as $entry) {
            $entry_created_at = isset($entry->created_at) ? (string) $entry->created_at : '';
            if ($entry_created_at === '') {
                continue;
            }

            $local_date = $this->gmt_datetime_to_local_date_key($entry_created_at);
            if ($local_date === '' || $local_date < $local_start_ymd || $local_date > $local_end_ymd) {
                continue;
            }

            if (!isset($entries_by_date[$local_date])) {
                $entries_by_date[$local_date] = array();
            }

            $entries_by_date[$local_date][] = (int) $entry->points;
        }

        $series = array();
        $running_total = $prior_total;

        for ($i = 0; $i < $days; $i++) {
            $date = $local_start->modify('+' . $i . ' days')->format('Y-m-d');
            $day_low = $running_total;
            $day_high = $running_total;

            if (isset($entries_by_date[$date])) {
                foreach ($entries_by_date[$date] as $points) {
                    $running_total += (int) $points;
                    if ($running_total < $day_low) {
                        $day_low = $running_total;
                    }
                    if ($running_total > $day_high) {
                        $day_high = $running_total;
                    }
                }
            }

            $series[] = array(
                'date' => $date,
                'total' => (int) $running_total,
                'low' => (int) $day_low,
                'high' => (int) $day_high,
            );
        }

        return $series;
    }

    public function get_movement_totals($days = 90) {
        $days = max(7, absint($days));
        $tz = wp_timezone();
        $local_end = new DateTimeImmutable('today', $tz);
        $local_start = $local_end->modify('-' . ($days - 1) . ' days');
        $start_gmt = $local_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end_gmt = $local_end->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS added_total,
                    COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END), 0) AS spent_total
                FROM {$this->activity_table}
                WHERE status = 'success'
                    AND created_at >= %s
                    AND created_at < %s",
                $start_gmt,
                $end_gmt
            )
        );

        $added = isset($row->added_total) ? (int) $row->added_total : 0;
        $spent = isset($row->spent_total) ? (int) $row->spent_total : 0;

        return array(
            'added' => $added,
            'spent' => $spent,
            'net' => $added - $spent,
        );
    }

    public function get_role_add_markers($days = 90) {
        $days = max(7, absint($days));
        $tz = wp_timezone();
        $local_end = new DateTimeImmutable('today', $tz);
        $local_start = $local_end->modify('-' . ($days - 1) . ' days');
        $local_start_ymd = $local_start->format('Y-m-d');
        $local_end_ymd = $local_end->format('Y-m-d');
        $start_gmt = $local_start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT created_at, note, operation, points
                FROM {$this->role_runs_table}
                WHERE created_at >= %s
                    AND note IS NOT NULL
                    AND note <> ''
                ORDER BY created_at ASC, id ASC",
                $start_gmt
            )
        );

        $markers = array();
        foreach ($rows as $row) {
            $event_at = isset($row->created_at) ? (string) $row->created_at : '';
            $note = isset($row->note) ? sanitize_text_field((string) $row->note) : '';
            if ($event_at === '' || $note === '') {
                continue;
            }

            $local_date = $this->gmt_datetime_to_local_date_key($event_at);
            if ($local_date === '' || $local_date < $local_start_ymd || $local_date > $local_end_ymd) {
                continue;
            }

            $points = isset($row->points) ? (int) $row->points : 0;
            $operation = isset($row->operation) ? sanitize_key((string) $row->operation) : '';
            if (in_array($operation, array('deduct', 'set_decrease'), true)) {
                $points = -abs($points);
            }

            $markers[] = array(
                'date' => $local_date,
                'note' => $note,
                'points' => $points,
            );
        }

        return $markers;
    }

    public function count_linked_users($meta_key, $search = '', $role = '') {
        $meta_key = sanitize_key((string) $meta_key);
        if ($meta_key === '') {
            return 0;
        }

        $sql = $this->build_linked_users_sql($meta_key, $search, $role, true);
        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql['query'], $sql['params']));
    }

    public function get_linked_users($meta_key, $limit = 50, $offset = 0, $search = '', $role = '') {
        $meta_key = sanitize_key((string) $meta_key);
        if ($meta_key === '') {
            return array();
        }

        $limit = max(1, absint($limit));
        $offset = max(0, absint($offset));
        $sql = $this->build_linked_users_sql($meta_key, $search, $role, false);
        $sql['query'] .= " ORDER BY u.display_name ASC, u.user_email ASC LIMIT %d OFFSET %d";
        $sql['params'][] = $limit;
        $sql['params'][] = $offset;

        return $this->wpdb->get_results($this->wpdb->prepare($sql['query'], $sql['params']));
    }

    public function get_all_linked_customer_ids($meta_key, $limit = 500, $search = '', $role = '') {
        $rows = $this->get_linked_users($meta_key, $limit, 0, $search, $role);
        $ids = array();

        foreach ($rows as $row) {
            if (!empty($row->square_customer_id)) {
                $ids[] = (string) $row->square_customer_id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function get_user_by_square_customer_id($customer_id, $meta_key) {
        $customer_id = trim((string) $customer_id);
        $meta_key = sanitize_key((string) $meta_key);
        if ($customer_id === '' || $meta_key === '') {
            return null;
        }

        $users = get_users(
            array(
                'meta_key' => $meta_key,
                'meta_value' => $customer_id,
                'number' => 1,
                'fields' => 'all',
            )
        );

        return !empty($users) ? $users[0] : null;
    }

    public function get_candidate_square_customer_meta_keys($limit = 20) {
        $limit = max(1, absint($limit));
        $like_square = '%' . $this->wpdb->esc_like('square') . '%';
        $like_customer = '%' . $this->wpdb->esc_like('customer') . '%';

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT meta_key, COUNT(DISTINCT user_id) as user_count, MAX(meta_value) as sample_value
                FROM {$this->wpdb->usermeta}
                WHERE meta_key LIKE %s
                    AND meta_key LIKE %s
                    AND meta_value <> ''
                GROUP BY meta_key
                ORDER BY user_count DESC, meta_key ASC
                LIMIT %d",
                $like_square,
                $like_customer,
                $limit
            )
        );
    }

    private function build_linked_users_sql($meta_key, $search, $role, $count_only) {
        $search = trim((string) $search);
        $role = sanitize_key((string) $role);
        $users_table = $this->wpdb->users;
        $usermeta_table = $this->wpdb->usermeta;
        $capabilities_key = $this->wpdb->get_blog_prefix() . 'capabilities';
        $params = array($meta_key);

        $select = $count_only
            ? 'SELECT COUNT(DISTINCT u.ID)'
            : 'SELECT DISTINCT u.ID as user_id, u.display_name, u.user_login, u.user_email, sqm.meta_value as square_customer_id';

        $sql = "{$select}
            FROM {$users_table} u
            INNER JOIN {$usermeta_table} sqm ON sqm.user_id = u.ID AND sqm.meta_key = %s AND sqm.meta_value <> ''";

        if ($role !== '') {
            $sql .= " INNER JOIN {$usermeta_table} caps ON caps.user_id = u.ID AND caps.meta_key = %s";
            $params[] = $capabilities_key;
        }

        $sql .= " WHERE 1=1";

        if ($search !== '') {
            $like = '%' . $this->wpdb->esc_like($search) . '%';
            $sql .= " AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR sqm.meta_value LIKE %s)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($role !== '') {
            $role_like = '%"' . $this->wpdb->esc_like($role) . '"%';
            $sql .= " AND caps.meta_value LIKE %s";
            $params[] = $role_like;
        }

        return array(
            'query' => $sql,
            'params' => $params,
        );
    }

    private function sanitize_role_run_participants($participants) {
        $clean = array();

        foreach ((array) $participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $clean[] = array(
                'id' => isset($participant['id']) ? absint($participant['id']) : 0,
                'name' => isset($participant['name']) ? sanitize_text_field((string) $participant['name']) : '',
                'email' => isset($participant['email']) ? sanitize_email((string) $participant['email']) : '',
                'square_customer_id' => isset($participant['square_customer_id']) ? sanitize_text_field((string) $participant['square_customer_id']) : '',
                'loyalty_account_id' => isset($participant['loyalty_account_id']) ? sanitize_text_field((string) $participant['loyalty_account_id']) : '',
                'square_event_id' => isset($participant['square_event_id']) ? sanitize_text_field((string) $participant['square_event_id']) : '',
                'status' => isset($participant['status']) ? sanitize_key((string) $participant['status']) : '',
                'message' => isset($participant['message']) ? sanitize_text_field((string) $participant['message']) : '',
            );
        }

        return $clean;
    }

    private function gmt_datetime_to_local_date_key($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $date->setTimezone(wp_timezone())->format('Y-m-d');
        } catch (Exception $e) {
            return '';
        }
    }

    private function now_gmt() {
        return gmdate('Y-m-d H:i:s');
    }
}
