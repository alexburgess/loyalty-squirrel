<?php
if (!defined('ABSPATH')) {
    exit;
}

class Square_Loyalty_Points_Square_API {
    /**
     * @var array
     */
    private $settings;

    public function __construct($settings) {
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_configured() {
        return $this->get_access_token() !== '';
    }

    public function get_environment() {
        $environment = isset($this->settings['square_environment']) ? sanitize_key($this->settings['square_environment']) : 'production';
        return $environment === 'sandbox' ? 'sandbox' : 'production';
    }

    public function get_api_version() {
        $version = isset($this->settings['square_api_version']) ? sanitize_text_field((string) $this->settings['square_api_version']) : '';
        return $version !== '' ? $version : '2026-01-22';
    }

    public function get_access_token() {
        return isset($this->settings['square_access_token']) ? trim((string) $this->settings['square_access_token']) : '';
    }

    public function retrieve_program() {
        $response = $this->request('GET', '/v2/loyalty/programs/main');
        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['program']) && is_array($response['program']) ? $response['program'] : array();
    }

    public function create_loyalty_account($program_id, $customer_id, $phone_number, $idempotency_key = '') {
        $program_id = $this->sanitize_square_id($program_id);
        $customer_id = $this->sanitize_square_id($customer_id);
        $phone_number = $this->sanitize_phone_number($phone_number);

        if ($program_id === '') {
            return new WP_Error('square_loyalty_missing_program', __('Missing Square loyalty program ID.', 'square-loyalty-points'));
        }

        if ($customer_id === '') {
            return new WP_Error('square_loyalty_missing_customer_id', __('Missing Square customer ID.', 'square-loyalty-points'));
        }

        if ($phone_number === '') {
            return new WP_Error('square_loyalty_missing_phone', __('A phone number is required to enroll this customer in Square Loyalty.', 'square-loyalty-points'));
        }

        $response = $this->request(
            'POST',
            '/v2/loyalty/accounts',
            array(
                'loyalty_account' => array(
                    'program_id' => $program_id,
                    'customer_id' => $customer_id,
                    'mapping' => array(
                        'phone_number' => $phone_number,
                    ),
                ),
                'idempotency_key' => $idempotency_key !== '' ? sanitize_text_field($idempotency_key) : wp_generate_uuid4(),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['loyalty_account']) && is_array($response['loyalty_account']) ? $response['loyalty_account'] : array();
    }

    public function retrieve_loyalty_account($account_id) {
        $account_id = trim((string) $account_id);
        if ($account_id === '') {
            return new WP_Error('square_loyalty_missing_account', __('Missing Square loyalty account ID.', 'square-loyalty-points'));
        }

        $response = $this->request('GET', '/v2/loyalty/accounts/' . rawurlencode($account_id));
        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['loyalty_account']) && is_array($response['loyalty_account']) ? $response['loyalty_account'] : array();
    }

    public function get_loyalty_account_by_customer_id($customer_id) {
        $customer_id = $this->sanitize_square_id($customer_id);
        if ($customer_id === '') {
            return new WP_Error('square_loyalty_missing_customer_id', __('Missing Square customer ID.', 'square-loyalty-points'));
        }

        $accounts = $this->search_loyalty_accounts_by_customer_ids(array($customer_id));
        if (is_wp_error($accounts)) {
            return $accounts;
        }

        return isset($accounts[$customer_id]) ? $accounts[$customer_id] : null;
    }

    public function search_loyalty_accounts_by_customer_ids($customer_ids) {
        $customer_ids = array_values(array_unique(array_filter(array_map(array($this, 'sanitize_square_id'), (array) $customer_ids))));
        if (empty($customer_ids)) {
            return array();
        }

        $accounts_by_customer = array();
        $chunks = array_chunk($customer_ids, 30);

        foreach ($chunks as $chunk) {
            $response = $this->request(
                'POST',
                '/v2/loyalty/accounts/search',
                array(
                    'query' => array(
                        'customer_ids' => array_values($chunk),
                    ),
                    'limit' => 200,
                )
            );

            if (is_wp_error($response)) {
                return $response;
            }

            $accounts = isset($response['loyalty_accounts']) && is_array($response['loyalty_accounts'])
                ? $response['loyalty_accounts']
                : array();

            foreach ($accounts as $account) {
                if (!is_array($account) || empty($account['customer_id'])) {
                    continue;
                }

                $accounts_by_customer[(string) $account['customer_id']] = $account;
            }
        }

        return $accounts_by_customer;
    }

    public function adjust_points($account_id, $points, $reason, $allow_negative_balance = false, $idempotency_key = '') {
        $account_id = trim((string) $account_id);
        $points = (int) $points;
        $reason = trim(sanitize_text_field((string) $reason));

        if ($account_id === '') {
            return new WP_Error('square_loyalty_missing_account', __('Missing Square loyalty account ID.', 'square-loyalty-points'));
        }

        if ($points === 0) {
            return new WP_Error('square_loyalty_zero_points', __('Point adjustment cannot be zero.', 'square-loyalty-points'));
        }

        if ($reason === '') {
            return new WP_Error('square_loyalty_missing_reason', __('Adjustment reason is required.', 'square-loyalty-points'));
        }

        $body = array(
            'adjust_points' => array(
                'points' => $points,
                'reason' => $reason,
            ),
            'idempotency_key' => $idempotency_key !== '' ? sanitize_text_field($idempotency_key) : wp_generate_uuid4(),
        );

        if ($points < 0 && $allow_negative_balance) {
            $body['allow_negative_balance'] = true;
        }

        $response = $this->request('POST', '/v2/loyalty/accounts/' . rawurlencode($account_id) . '/adjust', $body);
        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['event']) && is_array($response['event']) ? $response['event'] : array();
    }

    public function search_loyalty_events($args = array()) {
        $defaults = array(
            'loyalty_account_id' => '',
            'types' => array(),
            'limit' => 30,
            'cursor' => '',
        );
        $args = wp_parse_args($args, $defaults);

        $body = array(
            'limit' => min(30, max(1, absint($args['limit']))),
        );

        $filter = array();
        $account_id = trim((string) $args['loyalty_account_id']);
        if ($account_id !== '') {
            $filter['loyalty_account_filter'] = array(
                'loyalty_account_id' => $account_id,
            );
        }

        $types = array_values(array_filter(array_map('sanitize_key', (array) $args['types'])));
        if (!empty($types)) {
            $filter['type_filter'] = array(
                'types' => array_map('strtoupper', $types),
            );
        }

        if (!empty($filter)) {
            $body['query'] = array(
                'filter' => $filter,
            );
        }

        $cursor = trim((string) $args['cursor']);
        if ($cursor !== '') {
            $body['cursor'] = $cursor;
        }

        return $this->request('POST', '/v2/loyalty/events/search', $body);
    }

    private function request($method, $path, $body = null) {
        if (!$this->is_configured()) {
            return new WP_Error('square_loyalty_not_configured', __('Square access token is not configured.', 'square-loyalty-points'));
        }

        $url = $this->get_base_url() . $path;
        $args = array(
            'method' => strtoupper($method),
            'timeout' => 25,
            'headers' => array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_access_token(),
                'Content-Type' => 'application/json',
                'Square-Version' => $this->get_api_version(),
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : array();
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if ($code < 200 || $code >= 300 || !empty($decoded['errors'])) {
            return new WP_Error(
                'square_loyalty_api_error',
                $this->format_square_errors($decoded, $code),
                array(
                    'status' => $code,
                    'response' => $decoded,
                )
            );
        }

        return $decoded;
    }

    private function get_base_url() {
        return $this->get_environment() === 'sandbox'
            ? 'https://connect.squareupsandbox.com'
            : 'https://connect.squareup.com';
    }

    private function format_square_errors($decoded, $status_code) {
        $messages = array();

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $detail = isset($error['detail']) ? trim((string) $error['detail']) : '';
                $code = isset($error['code']) ? trim((string) $error['code']) : '';
                if ($detail !== '' && $code !== '') {
                    $messages[] = $code . ': ' . $detail;
                } elseif ($detail !== '') {
                    $messages[] = $detail;
                } elseif ($code !== '') {
                    $messages[] = $code;
                }
            }
        }

        if (empty($messages)) {
            $messages[] = sprintf(__('Square API request failed with HTTP %d.', 'square-loyalty-points'), (int) $status_code);
        }

        return implode(' ', $messages);
    }

    private function sanitize_square_id($value) {
        $value = trim((string) $value);
        return preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }

    private function sanitize_phone_number($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^\d+]/', '', $value);
        if ($value === null || $value === '+') {
            return '';
        }

        if (strpos($value, '+') !== 0) {
            $digits = preg_replace('/\D/', '', $value);
            if ($digits !== null && strlen($digits) === 10) {
                return '+1' . $digits;
            }
            if ($digits !== null && strlen($digits) === 11 && strpos($digits, '1') === 0) {
                return '+' . $digits;
            }
        }

        return $value;
    }
}
