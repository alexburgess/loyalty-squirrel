<?php
if (!defined('ABSPATH')) {
    exit;
}

class Square_Loyalty_Points_Plugin {
    /**
     * @var Square_Loyalty_Points_Plugin|null
     */
    private static $instance = null;

    /**
     * @var Square_Loyalty_Points_Manager
     */
    private $manager;

    /**
     * @var Square_Loyalty_Points_Square_API
     */
    private $square_api;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var array
     */
    private $loyalty_account_cache = array();

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate() {
        if (!self::is_woocommerce_available_for_activation()) {
            if (!function_exists('deactivate_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            deactivate_plugins(plugin_basename(SQUARE_LOYALTY_POINTS_PLUGIN_FILE));
            wp_die(
                esc_html__('Loyalty Squirrel requires WooCommerce to be installed and active before activation.', 'square-loyalty-points'),
                esc_html__('Plugin dependency check failed', 'square-loyalty-points'),
                array('back_link' => true)
            );
        }

        Square_Loyalty_Points_Manager::install_tables();
        update_option('square_loyalty_points_db_version', defined('SQUARE_LOYALTY_POINTS_DB_VERSION') ? SQUARE_LOYALTY_POINTS_DB_VERSION : '1');

        $defaults = self::default_settings();
        $existing = get_option('square_loyalty_points_settings', array());
        $settings = wp_parse_args($existing, $defaults);
        $settings['endpoint_slug'] = sanitize_title((string) $settings['endpoint_slug']);
        if ($settings['endpoint_slug'] === '') {
            $settings['endpoint_slug'] = $defaults['endpoint_slug'];
        }
        update_option('square_loyalty_points_settings', $settings);

        add_rewrite_endpoint($settings['endpoint_slug'], EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
        update_option('square_loyalty_points_rewrite_endpoint_slug', $settings['endpoint_slug']);
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function default_settings() {
        return array(
            'label_singular' => 'Loyalty Point',
            'label_plural' => 'Loyalty Points',
            'sidebar_use_plural_label' => 1,
            'endpoint_slug' => 'loyalty-points',
            'square_environment' => 'production',
            'square_api_version' => '2026-01-22',
            'square_access_token' => '',
            'square_customer_meta_key' => 'square_customer_id',
            'auto_detect_customer_meta_key' => 1,
            'auto_enroll_missing_accounts' => 1,
            'loyalty_phone_meta_key' => 'billing_phone',
            'account_description_text' => '',
            'allow_negative_balance' => 0,
            'role_dropdown_role_keys' => array(),
        );
    }

    private function __construct() {
        $this->settings = wp_parse_args(get_option('square_loyalty_points_settings', array()), self::default_settings());
        $this->manager = new Square_Loyalty_Points_Manager();
        $this->square_api = new Square_Loyalty_Points_Square_API($this->settings);
        $this->maybe_upgrade_database();

        add_action('init', array($this, 'register_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_account_menu_item'));
        add_action('woocommerce_account_' . $this->get_endpoint_slug() . '_endpoint', array($this, 'render_account_endpoint'));

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'maybe_render_admin_notice'));
        add_filter('admin_footer_text', array($this, 'filter_admin_footer_text'), 20, 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

        add_action('admin_post_square_loyalty_points_manage_user', array($this, 'handle_manage_user'));
        add_action('admin_post_square_loyalty_points_enroll_user', array($this, 'handle_enroll_user'));
        add_action('admin_post_square_loyalty_points_manage_role', array($this, 'handle_manage_role'));
        add_action('admin_post_square_loyalty_points_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_square_loyalty_points_download_role_run_csv', array($this, 'handle_download_role_run_csv'));
        add_action('admin_post_square_loyalty_points_apply_role_excluded', array($this, 'handle_apply_role_excluded'));

        add_action('wp_ajax_square_loyalty_points_user_search', array($this, 'ajax_user_search'));
        add_action('wp_ajax_square_loyalty_points_role_member_search', array($this, 'ajax_role_member_search'));
    }

    private static function is_woocommerce_available_for_activation() {
        if (class_exists('WooCommerce')) {
            return true;
        }

        $woocommerce_plugin = 'woocommerce/woocommerce.php';
        $active_plugins = (array) get_option('active_plugins', array());
        if (in_array($woocommerce_plugin, $active_plugins, true)) {
            return true;
        }

        if (!is_multisite()) {
            return false;
        }

        $network_active_plugins = array_keys((array) get_site_option('active_sitewide_plugins', array()));
        return in_array($woocommerce_plugin, $network_active_plugins, true);
    }

    private function maybe_upgrade_database() {
        $target_version = defined('SQUARE_LOYALTY_POINTS_DB_VERSION') ? (string) SQUARE_LOYALTY_POINTS_DB_VERSION : '1';
        $installed_version = (string) get_option('square_loyalty_points_db_version', '');

        if ($installed_version === $target_version) {
            return;
        }

        Square_Loyalty_Points_Manager::install_tables();
        update_option('square_loyalty_points_db_version', $target_version);
    }

    public function get_label_singular() {
        return sanitize_text_field((string) $this->settings['label_singular']);
    }

    public function get_label_plural() {
        return sanitize_text_field((string) $this->settings['label_plural']);
    }

    public function get_endpoint_slug() {
        $slug = sanitize_title((string) $this->settings['endpoint_slug']);
        return $slug ?: 'loyalty-points';
    }

    public function get_plugin_display_name() {
        return __('Loyalty Squirrel', 'square-loyalty-points');
    }

    public function should_use_plural_in_sidebar() {
        return !empty($this->settings['sidebar_use_plural_label']);
    }

    public function get_square_customer_meta_key() {
        $key = isset($this->settings['square_customer_meta_key']) ? sanitize_key((string) $this->settings['square_customer_meta_key']) : '';
        return $key !== '' ? $key : 'square_customer_id';
    }

    public function get_loyalty_phone_meta_key() {
        $key = isset($this->settings['loyalty_phone_meta_key']) ? sanitize_key((string) $this->settings['loyalty_phone_meta_key']) : '';
        return $key !== '' ? $key : 'billing_phone';
    }

    public function register_endpoint() {
        $endpoint_slug = $this->get_endpoint_slug();
        add_rewrite_endpoint($endpoint_slug, EP_ROOT | EP_PAGES);

        $flushed_endpoint_slug = sanitize_title((string) get_option('square_loyalty_points_rewrite_endpoint_slug', ''));
        if ($flushed_endpoint_slug !== $endpoint_slug) {
            flush_rewrite_rules(false);
            update_option('square_loyalty_points_rewrite_endpoint_slug', $endpoint_slug);
        }
    }

    public function add_account_menu_item($items) {
        $user_id = get_current_user_id();
        if ($user_id <= 0 || !$this->user_has_square_loyalty_account($user_id)) {
            return $items;
        }

        $endpoint = $this->get_endpoint_slug();
        $label = $this->get_label_plural();
        $updated = array();

        foreach ($items as $key => $value) {
            if ('customer-logout' === $key) {
                $updated[$endpoint] = $label;
            }
            $updated[$key] = $value;
        }

        if (!isset($updated[$endpoint])) {
            $updated[$endpoint] = $label;
        }

        return $updated;
    }

    public function render_account_endpoint() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $customer_id = $this->get_user_square_customer_id($user_id);

        echo '<h2>' . esc_html($this->get_label_plural()) . '</h2>';

        if ($customer_id === '') {
            echo '<p>' . esc_html__('Your WordPress account is not linked to a Square customer profile yet.', 'square-loyalty-points') . '</p>';
            return;
        }

        if (!$this->square_api->is_configured()) {
            echo '<p>' . esc_html__('Loyalty details are temporarily unavailable.', 'square-loyalty-points') . '</p>';
            return;
        }

        $account = $this->square_api->get_loyalty_account_by_customer_id($customer_id);
        if (is_wp_error($account)) {
            echo '<p>' . esc_html($account->get_error_message()) . '</p>';
            return;
        }

        if (empty($account)) {
            echo '<p>' . esc_html__('Loyalty details are not available for this account yet.', 'square-loyalty-points') . '</p>';
            return;
        }

        $balance = isset($account['balance']) ? (int) $account['balance'] : 0;
        printf(
            '<p><strong>%s:</strong> %s</p>',
            esc_html(sprintf(__('Available %s', 'square-loyalty-points'), strtolower($this->get_label_plural()))),
            esc_html($this->format_points($balance))
        );

        $account_description = isset($this->settings['account_description_text']) ? trim((string) $this->settings['account_description_text']) : '';
        if ($account_description !== '') {
            echo '<p class="square-loyalty-account-description">' . nl2br(esc_html($account_description)) . '</p>';
        }

        echo '<h3>' . esc_html__('History', 'square-loyalty-points') . '</h3>';
        $events = $this->get_events_for_loyalty_account(isset($account['id']) ? (string) $account['id'] : '', 20);
        $this->render_square_events_table($events, false, $account);
    }

    public function enqueue_frontend_assets() {
        if (is_admin() || !function_exists('is_account_page') || !is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'square-loyalty-points-frontend',
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            SQUARE_LOYALTY_POINTS_VERSION
        );
    }

    public function register_admin_menu() {
        $menu_title = $this->should_use_plural_in_sidebar() ? $this->get_label_plural() : $this->get_plugin_display_name();

        add_menu_page(
            $this->get_plugin_display_name(),
            $menu_title,
            'manage_options',
            'square-loyalty-points',
            array($this, 'render_admin_page'),
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/icon.svg',
            57
        );

        add_submenu_page('square-loyalty-points', __('Overview', 'square-loyalty-points'), __('Overview', 'square-loyalty-points'), 'manage_options', 'square-loyalty-points', array($this, 'render_admin_page'));
        add_submenu_page('square-loyalty-points', __('Manage Customer Points', 'square-loyalty-points'), __('Manage Customer', 'square-loyalty-points'), 'manage_options', 'square-loyalty-points-customer', array($this, 'render_admin_page'));
        add_submenu_page('square-loyalty-points', __('Apply Points by Role', 'square-loyalty-points'), __('Apply by Role', 'square-loyalty-points'), 'manage_options', 'square-loyalty-points-role', array($this, 'render_admin_page'));
        add_submenu_page('square-loyalty-points', __('Loyalty Activity', 'square-loyalty-points'), __('Activity', 'square-loyalty-points'), 'manage_options', 'square-loyalty-points-activity', array($this, 'render_admin_page'));
        add_submenu_page('square-loyalty-points', __('Loyalty Squirrel Settings', 'square-loyalty-points'), __('Settings', 'square-loyalty-points'), 'manage_options', 'square-loyalty-points-settings', array($this, 'render_admin_page'));
        add_submenu_page('square-loyalty-points', __('About Loyalty Squirrel', 'square-loyalty-points'), __('About', 'square-loyalty-points'), 'manage_options', 'square-loyalty-points-about', array($this, 'render_admin_page'));
    }

    public function enqueue_admin_assets($hook_suffix) {
        wp_enqueue_style(
            'square-loyalty-points-menu-icon',
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/css/menu-icon.css',
            array(),
            SQUARE_LOYALTY_POINTS_VERSION
        );

        if (strpos($hook_suffix, 'square-loyalty-points') === false) {
            return;
        }

        wp_enqueue_style(
            'square-loyalty-points-fontawesome',
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/fontawesome/css/all.min.css',
            array(),
            SQUARE_LOYALTY_POINTS_VERSION
        );

        wp_enqueue_style(
            'square-loyalty-points-admin',
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/css/admin.css',
            array('square-loyalty-points-fontawesome'),
            SQUARE_LOYALTY_POINTS_VERSION
        );

        wp_enqueue_style(
            'square-loyalty-points-admin-extra',
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/css/square-loyalty-points.css',
            array('square-loyalty-points-admin'),
            SQUARE_LOYALTY_POINTS_VERSION
        );

        wp_enqueue_script(
            'square-loyalty-points-admin',
            SQUARE_LOYALTY_POINTS_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            SQUARE_LOYALTY_POINTS_VERSION,
            true
        );

        wp_localize_script(
            'square-loyalty-points-admin',
            'loyaltyPointsAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'pageSlug' => 'square-loyalty-points',
                'searchAction' => 'square_loyalty_points_user_search',
                'roleMemberSearchAction' => 'square_loyalty_points_role_member_search',
                'searchNonce' => wp_create_nonce('square_loyalty_points_user_search'),
                'roleMemberSearchNonce' => wp_create_nonce('square_loyalty_points_role_member_search'),
                'labelSingular' => $this->get_label_singular(),
                'labelPlural' => $this->get_label_plural(),
                'previewSelectOperation' => __('Select an operation to see the projected balance.', 'square-loyalty-points'),
                'previewEnterAmount' => __('Enter a point amount to preview the new balance.', 'square-loyalty-points'),
                'previewPrefix' => __('New balance will be:', 'square-loyalty-points'),
                'previewInsufficient' => __('Removal exceeds current balance unless negative balances are enabled in settings.', 'square-loyalty-points'),
                'amountLabel' => __('Points', 'square-loyalty-points'),
                'newBalanceAmountLabel' => __('New balance', 'square-loyalty-points'),
                'notePlaceholderDefault' => __('Required reason for this Square adjustment', 'square-loyalty-points'),
                'notePlaceholderAdd' => __('Reason for adding points', 'square-loyalty-points'),
                'notePlaceholderDeduct' => __('Reason for removing points', 'square-loyalty-points'),
                'notePlaceholderSet' => __('Reason for setting the balance', 'square-loyalty-points'),
                'roleAmountLabel' => __('Points per user', 'square-loyalty-points'),
                'roleNewBalanceLabel' => __('Set balance per user', 'square-loyalty-points'),
                'roleNotePlaceholderDefault' => __('Required reason for this bulk Square adjustment', 'square-loyalty-points'),
                'roleNotePlaceholderAdd' => __('Reason for adding points by role', 'square-loyalty-points'),
                'roleNotePlaceholderDeduct' => __('Reason for removing points by role', 'square-loyalty-points'),
                'roleNotePlaceholderSet' => __('Reason for setting balances by role', 'square-loyalty-points'),
                'roleMemberSearchPlaceholder' => __('Search role members to exclude', 'square-loyalty-points'),
                'roleMemberSearchNoResults' => __('No matching users in this role.', 'square-loyalty-points'),
            )
        );
    }

    public function maybe_render_admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'square-loyalty-points') !== 0) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('WooCommerce is not active. The My Account loyalty endpoint will not be available until WooCommerce is active.', 'square-loyalty-points') . '</p></div>';
        }

        if (!$this->square_api->is_configured()) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Square access token is not configured yet. Add it in Loyalty Squirrel > Settings before applying points.', 'square-loyalty-points') . '</p></div>';
        }
    }

    public function filter_admin_footer_text($footer_text) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'square-loyalty-points') !== 0) {
            return $footer_text;
        }

        return '';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = $this->get_current_admin_tab();

        echo '<div class="wrap credits-wrap">';
        echo '<h1><span class="credits-heading-squirrel" aria-hidden="true"></span>' . esc_html($this->get_plugin_display_name()) . '</h1>';

        $this->render_admin_result_notice();
        $this->render_admin_tabs($tab);

        switch ($tab) {
            case 'manage':
                $this->render_manage_tab();
                break;
            case 'role':
                $this->render_role_tab();
                break;
            case 'activity':
                $this->render_activity_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
            case 'about':
                $this->render_about_tab();
                break;
            case 'overview':
            default:
                $this->render_overview_tab();
                break;
        }

        echo '</div>';
    }

    private function get_current_admin_tab() {
        $tabs = $this->get_admin_tabs();
        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($requested_tab !== '' && isset($tabs[$requested_tab])) {
            return $requested_tab;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'square-loyalty-points';
        $page_map = array(
            'square-loyalty-points-customer' => 'manage',
            'square-loyalty-points-role' => 'role',
            'square-loyalty-points-activity' => 'activity',
            'square-loyalty-points-settings' => 'settings',
            'square-loyalty-points-about' => 'about',
            'square-loyalty-points' => 'overview',
        );

        return isset($page_map[$page]) ? $page_map[$page] : 'overview';
    }

    private function get_admin_tabs() {
        return array(
            'overview' => array('label' => __('Overview', 'square-loyalty-points'), 'icon' => 'fa-chart-line'),
            'manage' => array('label' => __('Manage Customer', 'square-loyalty-points'), 'icon' => 'fa-user-gear'),
            'role' => array('label' => __('Apply by Role', 'square-loyalty-points'), 'icon' => 'fa-people-group'),
            'activity' => array('label' => __('Activity', 'square-loyalty-points'), 'icon' => 'fa-clock-rotate-left'),
            'settings' => array('label' => __('Settings', 'square-loyalty-points'), 'icon' => 'fa-gear'),
            'about' => array('label' => __('About', 'square-loyalty-points'), 'icon' => 'fa-circle-info'),
        );
    }

    private function render_admin_tabs($active_tab) {
        echo '<nav class="nav-tab-wrapper credits-nav-tabs">';

        foreach ($this->get_admin_tabs() as $tab_key => $tab) {
            $url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => $tab_key), admin_url('admin.php'));
            $classes = array('nav-tab');
            if ($tab_key === $active_tab) {
                $classes[] = 'nav-tab-active';
            }

            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">';
            echo '<i class="fa-duotone ' . esc_attr($tab['icon']) . '" aria-hidden="true"></i> ';
            echo esc_html($tab['label']);
            echo '</a>';
        }

        echo '</nav>';
    }

    private function render_overview_tab() {
        global $wp_roles;
        $roles = $wp_roles ? $wp_roles->roles : array();
        $count_data = count_users();
        $role_counts = isset($count_data['avail_roles']) ? $count_data['avail_roles'] : array();
        $meta_key = $this->get_square_customer_meta_key();
        $search = isset($_GET['overview_search']) ? sanitize_text_field(wp_unslash($_GET['overview_search'])) : '';
        $role_filter = isset($_GET['overview_role']) ? sanitize_key(wp_unslash($_GET['overview_role'])) : '';
        if ($role_filter !== '' && !isset($roles[$role_filter])) {
            $role_filter = '';
        }

        $range_options = array(
            30 => __('Last 30 days', 'square-loyalty-points'),
            90 => __('Last 90 days', 'square-loyalty-points'),
            180 => __('Last 180 days', 'square-loyalty-points'),
            365 => __('Last 12 months', 'square-loyalty-points'),
        );
        $range_days = isset($_GET['overview_range']) ? absint($_GET['overview_range']) : 90;
        if (!isset($range_options[$range_days])) {
            $range_days = 90;
        }
        $show_marker_labels = isset($_GET['overview_marker_labels']) ? absint(wp_unslash($_GET['overview_marker_labels'])) === 1 : true;

        $paged = isset($_GET['overview_paged']) ? max(1, absint($_GET['overview_paged'])) : 1;
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;
        $linked_count = $this->manager->count_linked_users($meta_key, $search, $role_filter);
        $total_linked_count = ($search === '' && $role_filter === '') ? $linked_count : $this->manager->count_linked_users($meta_key);
        $total_pages = max(1, (int) ceil(max(1, $linked_count) / $per_page));
        if ($paged > $total_pages) {
            $paged = $total_pages;
            $offset = ($paged - 1) * $per_page;
        }

        $linked_users = $this->manager->get_linked_users($meta_key, $per_page, $offset, $search, $role_filter);
        $linked_users_for_phone_audit = $this->manager->get_linked_users($meta_key, max(1, $total_linked_count), 0);
        $linked_missing_phone_users = $this->filter_linked_users_missing_phone($linked_users_for_phone_audit);
        $timeseries = $this->manager->get_point_balance_timeseries($range_days);
        $role_add_markers = $this->manager->get_role_add_markers($range_days);
        $movement_totals = $this->manager->get_movement_totals($range_days);
        $accounts_by_customer = array();
        $overview_error = null;
        $linked_customer_ids = $this->manager->get_all_linked_customer_ids($meta_key, 500, $search, $role_filter);

        if ($this->square_api->is_configured() && !empty($linked_customer_ids)) {
            $accounts_by_customer = $this->square_api->search_loyalty_accounts_by_customer_ids($linked_customer_ids);
            if (is_wp_error($accounts_by_customer)) {
                $overview_error = $accounts_by_customer;
                $accounts_by_customer = array();
            }
        }

        if ($this->square_api->is_configured() && !$overview_error && !empty($linked_users)) {
            $page_customer_ids = array();
            foreach ($linked_users as $row) {
                if (!empty($row->square_customer_id) && !isset($accounts_by_customer[(string) $row->square_customer_id])) {
                    $page_customer_ids[] = (string) $row->square_customer_id;
                }
            }

            if (!empty($page_customer_ids)) {
                $page_accounts = $this->square_api->search_loyalty_accounts_by_customer_ids($page_customer_ids);
                if (is_wp_error($page_accounts)) {
                    $overview_error = $page_accounts;
                } else {
                    $accounts_by_customer = array_merge($accounts_by_customer, $page_accounts);
                }
            }
        }

        $total_points = 0;
        $accounts_found = 0;
        foreach ($accounts_by_customer as $account) {
            $accounts_found++;
            $total_points += isset($account['balance']) ? (int) $account['balance'] : 0;
        }

        echo '<div class="credits-grid credits-grid-overview">';
        echo '<div class="credits-card credits-metric">';
        echo '<h2><i class="fa-duotone fa-award" aria-hidden="true"></i> ' . esc_html__('Total Available Points', 'square-loyalty-points') . '</h2>';
        echo '<p class="credits-metric-value">' . esc_html($this->format_points($total_points)) . '</p>';
        echo '<p class="description">' . esc_html__('Live Square balance total for linked WordPress accounts loaded on this overview.', 'square-loyalty-points') . '</p>';
        echo '</div>';

        echo '<div class="credits-card credits-metric">';
        echo '<h2><i class="fa-duotone fa-users" aria-hidden="true"></i> ' . esc_html__('Linked Customers', 'square-loyalty-points') . '</h2>';
        echo '<p class="credits-metric-value">' . esc_html(number_format_i18n($linked_count)) . '</p>';
        echo '<p class="description">' . esc_html(sprintf(__('Users with a value in %s.', 'square-loyalty-points'), $meta_key)) . '</p>';
        echo '</div>';

        echo '<div class="credits-card credits-metric">';
        echo '<h2><i class="fa-duotone fa-link" aria-hidden="true"></i> ' . esc_html__('Square Loyalty Accounts', 'square-loyalty-points') . '</h2>';
        echo '<p class="credits-metric-value">' . esc_html(number_format_i18n($accounts_found)) . '</p>';
        echo '<p class="description">' . esc_html__('Linked customers that currently resolve to a Square Loyalty account.', 'square-loyalty-points') . '</p>';
        echo '</div>';

        if (!empty($linked_missing_phone_users)) {
            echo '<div class="credits-card credits-metric square-loyalty-missing-phone-metric">';
            echo '<h2><i class="fa-duotone fa-phone-slash" aria-hidden="true"></i> ' . esc_html__('Missing Phone Numbers', 'square-loyalty-points') . '</h2>';
            echo '<p class="credits-metric-value">' . esc_html(number_format_i18n(count($linked_missing_phone_users))) . '</p>';
            echo '<p class="description">' . esc_html__('Linked customers that cannot be auto-enrolled in Square Loyalty until a phone number is added.', 'square-loyalty-points') . '</p>';
            echo '</div>';
        }
        echo '</div>';

        if (!empty($linked_missing_phone_users)) {
            $this->render_missing_phone_overview_panel($linked_missing_phone_users);
        }

        echo '<div class="credits-card">';
        echo '<form method="get" action="" class="credits-chart-toolbar">';
        echo '<input type="hidden" name="page" value="square-loyalty-points" />';
        echo '<input type="hidden" name="tab" value="overview" />';
        if ($search !== '') {
            echo '<input type="hidden" name="overview_search" value="' . esc_attr($search) . '" />';
        }
        if ($role_filter !== '') {
            echo '<input type="hidden" name="overview_role" value="' . esc_attr($role_filter) . '" />';
        }
        echo '<h2><i class="fa-duotone fa-chart-line-up-down" aria-hidden="true"></i> ' . esc_html__('Tracked Points Over Time', 'square-loyalty-points') . '</h2>';
        echo '<div class="credits-chart-toolbar-controls">';
        echo '<div class="credits-chart-toolbar-toggle">';
        echo '<input type="hidden" name="overview_marker_labels" value="0" />';
        echo '<label for="square_loyalty_overview_marker_labels">';
        echo '<input type="checkbox" id="square_loyalty_overview_marker_labels" name="overview_marker_labels" value="1" ' . checked($show_marker_labels, true, false) . ' onchange="this.form.submit()" />';
        echo esc_html__('Show role apply notes', 'square-loyalty-points');
        echo '</label>';
        echo '</div>';
        echo '<div class="credits-chart-toolbar-range">';
        echo '<label for="square_loyalty_overview_range" class="screen-reader-text">' . esc_html__('Time range', 'square-loyalty-points') . '</label>';
        echo '<select id="square_loyalty_overview_range" name="overview_range" onchange="this.form.submit()">';
        foreach ($range_options as $days => $label) {
            echo '<option value="' . esc_attr((string) $days) . '" ' . selected($range_days, $days, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        $this->render_total_points_chart($timeseries, $role_add_markers, $show_marker_labels, $movement_totals);
        echo '<p class="description">' . esc_html__('This chart uses successful point adjustments recorded by Loyalty Squirrel. Live Square spending or expiry that happened outside this plugin can still appear in customer history without changing this local trend line.', 'square-loyalty-points') . '</p>';
        echo '</div>';

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-list-ul" aria-hidden="true"></i> ' . esc_html__('Customer Loyalty Balances', 'square-loyalty-points') . '</h2>';

        if ($overview_error) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($overview_error->get_error_message()) . '</p></div>';
        } elseif (!$this->square_api->is_configured()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Configure your Square access token in Settings to load live balances.', 'square-loyalty-points') . '</p></div>';
        }

        echo '<form method="get" action="" class="credits-inline-form credits-inline-form-spaced">';
        echo '<input type="hidden" name="page" value="square-loyalty-points" />';
        echo '<input type="hidden" name="tab" value="overview" />';
        echo '<input type="hidden" name="overview_range" value="' . esc_attr((string) $range_days) . '" />';
        echo '<input type="hidden" name="overview_marker_labels" value="' . esc_attr($show_marker_labels ? '1' : '0') . '" />';
        echo '<span class="credits-input-decor credits-input-wide credits-tone-search">';
        echo '<span class="credits-input-icon"><i class="fa-duotone fa-magnifying-glass" aria-hidden="true"></i></span>';
        echo '<input type="search" name="overview_search" class="regular-text" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Filter by name, email, username, or Square ID', 'square-loyalty-points') . '" />';
        echo '</span>';
        echo '<span class="credits-input-decor credits-input-medium credits-tone-link">';
        echo '<span class="credits-input-icon"><i class="fa-duotone fa-users" aria-hidden="true"></i></span>';
        echo '<select name="overview_role">';
        echo '<option value="">' . esc_html__('All roles', 'square-loyalty-points') . '</option>';
        foreach ($roles as $role_key => $role_data) {
            $count = isset($role_counts[$role_key]) ? (int) $role_counts[$role_key] : 0;
            echo '<option value="' . esc_attr($role_key) . '" ' . selected($role_filter, $role_key, false) . '>' . esc_html(sprintf('%s (%d)', translate_user_role($role_data['name']), $count)) . '</option>';
        }
        echo '</select>';
        echo '</span>';
        echo '<button type="submit" class="button button-secondary"><i class="fa-duotone fa-filter" aria-hidden="true"></i> ' . esc_html__('Filter', 'square-loyalty-points') . '</button>';

        if ($search !== '' || $role_filter !== '') {
            echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'overview', 'overview_range' => $range_days, 'overview_marker_labels' => $show_marker_labels ? 1 : 0), admin_url('admin.php'))) . '"><i class="fa-duotone fa-xmark" aria-hidden="true"></i> ' . esc_html__('Clear', 'square-loyalty-points') . '</a>';
        }

        echo '</form>';

        if (empty($linked_users)) {
            echo '<p>' . esc_html__('No linked customers match this filter.', 'square-loyalty-points') . '</p>';
        } else {
            echo '<table class="widefat striped credits-overview-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Customer', 'square-loyalty-points') . '</th>';
            echo '<th>' . esc_html__('Email', 'square-loyalty-points') . '</th>';
            echo '<th>' . esc_html__('Role(s)', 'square-loyalty-points') . '</th>';
            echo '<th>' . esc_html__('Square Customer ID', 'square-loyalty-points') . '</th>';
            echo '<th>' . esc_html__('Balance', 'square-loyalty-points') . '</th>';
            echo '<th>' . esc_html__('Lifetime', 'square-loyalty-points') . '</th>';
            echo '<th>' . esc_html__('Action', 'square-loyalty-points') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($linked_users as $row) {
                $customer_id = (string) $row->square_customer_id;
                $account = isset($accounts_by_customer[$customer_id]) ? $accounts_by_customer[$customer_id] : null;
                $manage_url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'manage', 'user_id' => (int) $row->user_id), admin_url('admin.php'));
                $balance = is_array($account) && isset($account['balance']) ? $this->format_points((int) $account['balance']) : __('Not found', 'square-loyalty-points');
                $lifetime = is_array($account) && isset($account['lifetime_points']) ? $this->format_points((int) $account['lifetime_points']) : '-';

                echo '<tr>';
                echo '<td>' . esc_html($row->display_name . ' (' . $row->user_login . ')') . '</td>';
                echo '<td>' . esc_html($row->user_email) . '</td>';
                echo '<td>' . esc_html($this->get_user_roles_label((int) $row->user_id)) . '</td>';
                echo '<td><code>' . esc_html($customer_id) . '</code></td>';
                echo '<td><strong>' . esc_html($balance) . '</strong></td>';
                echo '<td>' . esc_html($lifetime) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($manage_url) . '"><i class="fa-duotone fa-user-gear" aria-hidden="true"></i> ' . esc_html__('Manage', 'square-loyalty-points') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            $this->render_pagination($paged, $total_pages, array('page' => 'square-loyalty-points', 'tab' => 'overview', 'overview_search' => $search, 'overview_role' => $role_filter, 'overview_range' => $range_days, 'overview_marker_labels' => $show_marker_labels ? 1 : 0), 'overview_paged');
        }

        echo '</div>';
    }

    private function render_total_points_chart($series, $role_add_markers = array(), $show_marker_labels = true, $movement_totals = array()) {
        if (empty($series)) {
            echo '<p>' . esc_html__('Not enough data yet to render the chart.', 'square-loyalty-points') . '</p>';
            return;
        }

        $totals = array();
        $range_values = array();
        foreach ($series as $row) {
            $total = isset($row['total']) ? (int) $row['total'] : 0;
            $low = isset($row['low']) ? (int) $row['low'] : $total;
            $high = isset($row['high']) ? (int) $row['high'] : $total;
            $totals[] = $total;
            $range_values[] = $low;
            $range_values[] = $high;
        }

        $min = min($range_values);
        $max = max($range_values);
        $start_total = (int) reset($totals);
        $end_total = (int) end($totals);
        $period_change = $end_total - $start_total;
        $added_in_period = 0;
        $spent_in_period = 0;
        if (is_array($movement_totals) && !empty($movement_totals)) {
            $added_in_period = isset($movement_totals['added']) ? (int) $movement_totals['added'] : 0;
            $spent_in_period = isset($movement_totals['spent']) ? (int) $movement_totals['spent'] : 0;
            $period_change = isset($movement_totals['net']) ? (int) $movement_totals['net'] : ($added_in_period - $spent_in_period);
        }
        reset($totals);
        if ($max === $min) {
            $max = $min + 1;
        }

        $width = 920;
        $height = 320;
        $pad_left = 96;
        $pad_right = 24;
        $pad_top = 22;
        $pad_bottom = 68;
        $plot_width = $width - $pad_left - $pad_right;
        $plot_height = $height - $pad_top - $pad_bottom;
        $plot_left = $pad_left;
        $plot_right = $pad_left + $plot_width;
        $plot_top = $pad_top;
        $plot_bottom = $pad_top + $plot_height;
        $points = array();
        $point_data = array();
        $index_by_date = array();

        $count = count($series);
        foreach ($series as $index => $row) {
            $x_ratio = $count > 1 ? ($index / ($count - 1)) : 0;
            $y_ratio = (((int) $row['total']) - $min) / ($max - $min);

            $x = $plot_left + ($plot_width * $x_ratio);
            $y = $plot_bottom - ($plot_height * $y_ratio);
            $points[] = round($x, 2) . ',' . round($y, 2);
            $point_data[$index] = array(
                'x' => $x,
                'y' => $y,
            );
            if (!empty($row['date'])) {
                $index_by_date[(string) $row['date']] = $index;
            }
        }

        $line_points = implode(' ', $points);
        $area_points = $plot_left . ',' . $plot_bottom . ' ' . $line_points . ' ' . $plot_right . ',' . $plot_bottom;

        echo '<div class="credits-chart">';
        echo '<svg viewBox="0 0 ' . esc_attr((string) $width) . ' ' . esc_attr((string) $height) . '" role="img" aria-label="' . esc_attr__('Tracked loyalty points over time', 'square-loyalty-points') . '">';
        echo '<line x1="' . esc_attr((string) $plot_left) . '" y1="' . esc_attr((string) $plot_top) . '" x2="' . esc_attr((string) $plot_left) . '" y2="' . esc_attr((string) $plot_bottom) . '" class="credits-chart-axis"></line>';
        echo '<line x1="' . esc_attr((string) $plot_left) . '" y1="' . esc_attr((string) $plot_bottom) . '" x2="' . esc_attr((string) $plot_right) . '" y2="' . esc_attr((string) $plot_bottom) . '" class="credits-chart-axis"></line>';

        for ($i = 0; $i <= 4; $i++) {
            $y = $plot_top + (($plot_height / 4) * $i);
            $axis_value = $max - ((($max - $min) / 4) * $i);
            $axis_label = number_format_i18n((int) round($axis_value));
            echo '<line x1="' . esc_attr((string) $plot_left) . '" y1="' . esc_attr((string) $y) . '" x2="' . esc_attr((string) $plot_right) . '" y2="' . esc_attr((string) $y) . '" class="credits-chart-grid"></line>';
            echo '<text x="' . esc_attr((string) ($plot_left - 10)) . '" y="' . esc_attr((string) ($y + 4)) . '" text-anchor="end" class="credits-chart-label-y">' . esc_html($axis_label) . '</text>';
        }

        $tick_indexes = array(0, (int) round(($count - 1) * 0.25), (int) round(($count - 1) * 0.5), (int) round(($count - 1) * 0.75), $count - 1);
        $tick_indexes = array_values(array_unique($tick_indexes));
        sort($tick_indexes);

        foreach ($tick_indexes as $tick_index) {
            if (!isset($series[$tick_index])) {
                continue;
            }

            $x_ratio = $count > 1 ? ($tick_index / ($count - 1)) : 0;
            $x = $plot_left + ($plot_width * $x_ratio);
            $tick_date = $this->format_local_date_short($series[$tick_index]['date']);
            echo '<line x1="' . esc_attr((string) $x) . '" y1="' . esc_attr((string) $plot_bottom) . '" x2="' . esc_attr((string) $x) . '" y2="' . esc_attr((string) ($plot_bottom + 6)) . '" class="credits-chart-axis-tick"></line>';
            echo '<text x="' . esc_attr((string) $x) . '" y="' . esc_attr((string) ($plot_bottom + 22)) . '" text-anchor="middle" class="credits-chart-label-x">' . esc_html($tick_date) . '</text>';
        }

        echo '<polygon points="' . esc_attr($area_points) . '" class="credits-chart-area"></polygon>';
        foreach ($series as $index => $row) {
            if (!isset($point_data[$index])) {
                continue;
            }

            $low = isset($row['low']) ? (int) $row['low'] : (isset($row['total']) ? (int) $row['total'] : 0);
            $high = isset($row['high']) ? (int) $row['high'] : (isset($row['total']) ? (int) $row['total'] : 0);
            if ($high === $low) {
                continue;
            }

            $x = (float) $point_data[$index]['x'];
            $low_ratio = ($low - $min) / ($max - $min);
            $high_ratio = ($high - $min) / ($max - $min);
            $y_low = $plot_bottom - ($plot_height * $low_ratio);
            $y_high = $plot_bottom - ($plot_height * $high_ratio);
            echo '<line x1="' . esc_attr((string) $x) . '" y1="' . esc_attr((string) $y_high) . '" x2="' . esc_attr((string) $x) . '" y2="' . esc_attr((string) $y_low) . '" class="credits-chart-day-range"></line>';
        }
        echo '<polyline points="' . esc_attr($line_points) . '" class="credits-chart-line"></polyline>';

        if (!empty($role_add_markers)) {
            $marker_i = 0;
            foreach ($role_add_markers as $marker) {
                if (!is_array($marker) || empty($marker['date']) || !isset($index_by_date[$marker['date']])) {
                    continue;
                }

                $marker_index = (int) $index_by_date[$marker['date']];
                $x_ratio = $count > 1 ? ($marker_index / ($count - 1)) : 0;
                $x = $plot_left + ($plot_width * $x_ratio);
                $note = isset($marker['note']) ? sanitize_text_field((string) $marker['note']) : '';
                if ($note === '') {
                    continue;
                }

                echo '<line x1="' . esc_attr((string) $x) . '" y1="' . esc_attr((string) $plot_top) . '" x2="' . esc_attr((string) $x) . '" y2="' . esc_attr((string) $plot_bottom) . '" class="credits-chart-marker-line"></line>';

                if ($show_marker_labels) {
                    $label = wp_html_excerpt($note, 44, '...');
                    $row_offset = $marker_i % 4;
                    $bubble_height = 18;
                    $bubble_y = $plot_top + 4 + ($row_offset * ($bubble_height + 4));
                    $text_length = function_exists('mb_strwidth') ? mb_strwidth($label, 'UTF-8') : (function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label));
                    $bubble_padding_x = 6;
                    $bubble_width = max(32, min(220, (int) round(((float) $text_length * 5.5) + ($bubble_padding_x * 2))));
                    $bubble_x = $x + 8;
                    if (($bubble_x + $bubble_width) > ($plot_right - 2)) {
                        $bubble_x = $x - 8 - $bubble_width;
                    }
                    if ($bubble_x < ($plot_left + 2)) {
                        $bubble_x = $plot_left + 2;
                    }
                    $connector_y = $bubble_y + ($bubble_height / 2);
                    $connector_start_x = $x >= ($bubble_x + ($bubble_width / 2)) ? ($bubble_x + $bubble_width) : $bubble_x;

                    echo '<rect x="' . esc_attr((string) $bubble_x) . '" y="' . esc_attr((string) $bubble_y) . '" width="' . esc_attr((string) $bubble_width) . '" height="' . esc_attr((string) $bubble_height) . '" rx="8" ry="8" class="credits-chart-marker-bubble"></rect>';
                    echo '<line x1="' . esc_attr((string) $connector_start_x) . '" y1="' . esc_attr((string) $connector_y) . '" x2="' . esc_attr((string) $x) . '" y2="' . esc_attr((string) $connector_y) . '" class="credits-chart-marker-connector"></line>';
                    echo '<text x="' . esc_attr((string) ($bubble_x + $bubble_padding_x)) . '" y="' . esc_attr((string) ($bubble_y + 12)) . '" text-anchor="start" class="credits-chart-marker-label">' . esc_html($label) . '</text>';
                }
                $marker_i++;
            }
        }

        echo '</svg>';

        $first_date = isset($series[0]['date']) ? $this->format_local_date_only($series[0]['date']) : '';
        $last_date = isset($series[$count - 1]['date']) ? $this->format_local_date_only($series[$count - 1]['date']) : '';
        $period_class = 'credits-chart-period-change';
        if ($period_change > 0) {
            $period_class .= ' credits-chart-period-positive';
        } elseif ($period_change < 0) {
            $period_class .= ' credits-chart-period-negative';
        }

        echo '<div class="credits-chart-meta">';
        echo '<span><strong>' . esc_html__('Start:', 'square-loyalty-points') . '</strong> ' . esc_html($first_date) . '</span>';
        echo '<span><strong>' . esc_html__('End:', 'square-loyalty-points') . '</strong> ' . esc_html($last_date) . '</span>';
        echo '<span class="' . esc_attr($period_class) . '"><strong>' . esc_html__('Net Change:', 'square-loyalty-points') . '</strong> ' . esc_html($this->format_signed_points($period_change)) . '</span>';
        echo '<span><strong>' . esc_html__('Added:', 'square-loyalty-points') . '</strong> ' . esc_html($this->format_signed_points($added_in_period)) . '</span>';
        echo '<span><strong>' . esc_html__('Removed:', 'square-loyalty-points') . '</strong> ' . esc_html($this->format_signed_points(-$spent_in_period)) . '</span>';
        echo '<span><strong>' . esc_html__('Min:', 'square-loyalty-points') . '</strong> ' . esc_html($this->format_points($min)) . '</span>';
        echo '<span><strong>' . esc_html__('Max:', 'square-loyalty-points') . '</strong> ' . esc_html($this->format_points($max)) . '</span>';
        echo '</div>';
        echo '</div>';
    }

    private function render_manage_tab() {
        $search_term = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $selected_user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $results = $search_term !== '' ? $this->search_users_for_manage($search_term, 20) : array();
        $selected_user = $selected_user_id ? get_user_by('id', $selected_user_id) : null;

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-magnifying-glass" aria-hidden="true"></i> ' . esc_html__('Find Customer', 'square-loyalty-points') . '</h2>';
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="square-loyalty-points" />';
        echo '<input type="hidden" name="tab" value="manage" />';
        echo '<div class="credits-inline-form">';
        echo '<div class="credits-search-wrap credits-input-decor credits-input-wide credits-tone-search">';
        echo '<span class="credits-input-icon"><i class="fa-duotone fa-magnifying-glass" aria-hidden="true"></i></span>';
        echo '<input type="search" id="credits-live-search" name="s" class="regular-text" autocomplete="off" placeholder="' . esc_attr__('Name, username, or email', 'square-loyalty-points') . '" value="' . esc_attr($search_term) . '" />';
        echo '<div id="credits-live-results" class="credits-live-results" aria-live="polite"></div>';
        echo '</div>';
        echo '<button type="submit" class="button button-secondary"><i class="fa-duotone fa-magnifying-glass" aria-hidden="true"></i> ' . esc_html__('Search', 'square-loyalty-points') . '</button>';
        echo '</div>';
        echo '</form>';

        if (!empty($results)) {
            echo '<ul class="credits-user-results">';
            foreach ($results as $user) {
                $url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'manage', 'user_id' => $user->ID, 's' => $search_term), admin_url('admin.php'));
                echo '<li><a href="' . esc_url($url) . '"><i class="fa-duotone fa-user" aria-hidden="true"></i> ' . esc_html($this->get_customer_display_name($user)) . '</a> <span class="credits-user-meta">(' . esc_html($user->user_email) . ')</span></li>';
            }
            echo '</ul>';
        } elseif ($search_term !== '') {
            echo '<p>' . esc_html__('No users found for that search.', 'square-loyalty-points') . '</p>';
        }
        echo '</div>';

        if (!$selected_user) {
            return;
        }

        $customer_id = $this->get_user_square_customer_id((int) $selected_user->ID);
        $account = null;
        $account_error = null;
        if ($customer_id !== '' && $this->square_api->is_configured()) {
            $account = $this->square_api->get_loyalty_account_by_customer_id($customer_id);
            if (is_wp_error($account)) {
                $account_error = $account;
                $account = null;
            }
        }

        $balance = is_array($account) && isset($account['balance']) ? (int) $account['balance'] : 0;
        $selected_user_name = $this->get_customer_display_name($selected_user);

        echo '<div class="credits-manage-layout">';
        echo '<div class="credits-card credits-manage-summary">';
        echo '<h2><i class="fa-duotone fa-address-card" aria-hidden="true"></i> ' . esc_html__('Customer Summary', 'square-loyalty-points') . '</h2>';
        echo '<p class="credits-manage-customer-name">' . esc_html($selected_user_name) . '</p>';
        echo '<p class="credits-manage-customer-meta">' . esc_html($selected_user->user_email) . '</p>';
        echo '<p class="credits-manage-customer-meta">@' . esc_html($selected_user->user_login) . '</p>';
        echo '<p class="credits-manage-customer-meta"><strong>' . esc_html__('Square Customer ID:', 'square-loyalty-points') . '</strong> ' . ($customer_id !== '' ? '<code>' . esc_html($customer_id) . '</code>' : esc_html__('Not found', 'square-loyalty-points')) . '</p>';
        $selected_user_phone = $customer_id !== '' ? $this->get_user_loyalty_phone_number((int) $selected_user->ID) : '';
        if ($customer_id !== '' && $selected_user_phone === '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('This linked customer does not have a usable phone number for Square Loyalty enrollment.', 'square-loyalty-points') . '</p></div>';
            echo '<p class="square-loyalty-profile-links">' . wp_kses($this->format_customer_profile_links((int) $selected_user->ID, $customer_id), $this->get_allowed_link_html()) . '</p>';
        }

        if ($account_error) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($account_error->get_error_message()) . '</p></div>';
        } elseif (!$this->square_api->is_configured()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Configure Square settings before loading customer loyalty data.', 'square-loyalty-points') . '</p></div>';
        } elseif ($customer_id === '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('This customer has no Square customer ID using the configured meta key.', 'square-loyalty-points') . '</p></div>';
        } elseif (empty($account)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('No Square Loyalty account was found for this Square customer ID.', 'square-loyalty-points') . '</p></div>';
            echo '<p class="credits-manage-customer-meta"><strong>' . esc_html__('Enrollment phone:', 'square-loyalty-points') . '</strong> ' . ($selected_user_phone !== '' ? esc_html($selected_user_phone) : esc_html__('Not found', 'square-loyalty-points')) . '</p>';
        }

        echo '<p class="credits-manage-balance-label">' . esc_html__('Current Square balance', 'square-loyalty-points') . '</p>';
        echo '<p class="credits-manage-balance-value">' . esc_html($this->format_points($balance)) . '</p>';
        if (is_array($account) && isset($account['lifetime_points'])) {
            echo '<p class="credits-manage-customer-meta"><strong>' . esc_html__('Lifetime:', 'square-loyalty-points') . '</strong> ' . esc_html($this->format_points((int) $account['lifetime_points'])) . '</p>';
        }
        $this->render_expiring_deadlines(is_array($account) ? $account : array(), true);

        echo '<h3 class="credits-manage-history-heading"><i class="fa-duotone fa-clock-rotate-left" aria-hidden="true"></i> ' . esc_html__('Square Loyalty History', 'square-loyalty-points') . '</h3>';
        $events = is_array($account) && !empty($account['id']) ? $this->get_events_for_loyalty_account((string) $account['id'], 20) : array();
        $this->render_square_events_table($events, false);
        echo '</div>';

        echo '<div class="credits-card credits-manage-tools">';
        echo '<h2><i class="fa-duotone fa-user-gear" aria-hidden="true"></i> ' . esc_html(sprintf(__('Manage %s', 'square-loyalty-points'), $selected_user_name)) . '</h2>';

        if (!is_array($account) || empty($account['id'])) {
            if ($customer_id !== '' && $this->square_api->is_configured()) {
                $phone_number = $selected_user_phone;
                echo '<p>' . esc_html__('This customer can be enrolled in Square Loyalty before points are applied.', 'square-loyalty-points') . '</p>';
                if ($phone_number === '') {
                    echo '<div class="notice notice-warning inline"><p>' . esc_html__('A phone number is required for Square Loyalty enrollment. Add one to the customer billing profile or change the phone meta key in Settings.', 'square-loyalty-points') . '</p></div>';
                } else {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    wp_nonce_field('square_loyalty_points_enroll_user_action', 'square_loyalty_points_nonce');
                    echo '<input type="hidden" name="action" value="square_loyalty_points_enroll_user" />';
                    echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $selected_user->ID) . '" />';
                    echo '<p class="submit"><button type="submit" class="button button-primary"><i class="fa-duotone fa-user-plus" aria-hidden="true"></i> ' . esc_html__('Enroll in Square Loyalty', 'square-loyalty-points') . '</button></p>';
                    echo '</form>';
                }
            } else {
                echo '<p>' . esc_html__('Point adjustments are available after this customer resolves to a Square Loyalty account.', 'square-loyalty-points') . '</p>';
            }
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="credits-manage-form" data-current-balance="' . esc_attr((string) $balance) . '">';
            wp_nonce_field('square_loyalty_points_manage_user_action', 'square_loyalty_points_nonce');
            echo '<input type="hidden" name="action" value="square_loyalty_points_manage_user" />';
            echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $selected_user->ID) . '" />';
            $this->render_operation_fields('customer');
            echo '<p class="submit"><button type="submit" id="credits_apply_change" class="button button-primary" disabled><i class="fa-duotone fa-floppy-disk" aria-hidden="true"></i> ' . esc_html__('Apply Change', 'square-loyalty-points') . '</button></p>';
            echo '</form>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_role_tab() {
        $roles = $this->get_configured_role_dropdown_roles();
        $count_data = count_users();
        $role_counts = isset($count_data['avail_roles']) ? $count_data['avail_roles'] : array();
        $selected_role = isset($_GET['selected_role']) ? sanitize_key(wp_unslash($_GET['selected_role'])) : '';
        if ($selected_role !== '' && !isset($roles[$selected_role])) {
            $selected_role = '';
        }

        $role_users_page = isset($_GET['role_users_page']) ? max(1, absint($_GET['role_users_page'])) : 1;
        $exclude_users_enabled = isset($_GET['exclude_users']) ? absint(wp_unslash($_GET['exclude_users'])) === 1 : false;
        $excluded_user_ids = $this->sanitize_user_id_list(isset($_GET['excluded_user_ids']) ? wp_unslash($_GET['excluded_user_ids']) : '');
        $role_users = array();
        $excluded_people = array();
        $role_users_pages = 1;
        $role_users_per_page = 20;
        $selected_role_count = isset($role_counts[$selected_role]) ? (int) $role_counts[$selected_role] : 0;

        if ($selected_role !== '') {
            $role_users_pages = max(1, (int) ceil(max(1, $selected_role_count) / $role_users_per_page));
            if ($role_users_page > $role_users_pages) {
                $role_users_page = $role_users_pages;
            }
            $role_users = get_users(array('role' => $selected_role, 'number' => $role_users_per_page, 'offset' => ($role_users_page - 1) * $role_users_per_page, 'orderby' => 'display_name', 'order' => 'ASC'));

            if (!empty($excluded_user_ids)) {
                $excluded_users = get_users(array('include' => $excluded_user_ids, 'orderby' => 'display_name', 'order' => 'ASC'));
                foreach ($excluded_users as $excluded_user) {
                    if (!in_array($selected_role, (array) $excluded_user->roles, true)) {
                        continue;
                    }
                    $excluded_people[$excluded_user->ID] = $this->build_user_participant($excluded_user);
                }
            }
        } else {
            $exclude_users_enabled = false;
        }

        if (!$exclude_users_enabled) {
            $excluded_user_ids = array();
            $excluded_people = array();
        } else {
            $excluded_user_ids = array_map('intval', array_keys($excluded_people));
        }
        $excluded_ids_csv = implode(',', $excluded_user_ids);

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-people-group" aria-hidden="true"></i> ' . esc_html__('Apply Points by Role', 'square-loyalty-points') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="credits-role-form">';
        wp_nonce_field('square_loyalty_points_manage_role_action', 'square_loyalty_points_nonce');
        echo '<input type="hidden" name="action" value="square_loyalty_points_manage_role" />';
        echo '<input type="hidden" name="excluded_user_ids" id="credits_role_excluded_user_ids" value="' . esc_attr($excluded_ids_csv) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="credits_role">' . esc_html__('Role', 'square-loyalty-points') . '</label></th><td><select required id="credits_role" name="role">';
        echo '<option value="">' . esc_html__('Select a role', 'square-loyalty-points') . '</option>';
        foreach ($roles as $role_key => $role_data) {
            $count = isset($role_counts[$role_key]) ? (int) $role_counts[$role_key] : 0;
            echo '<option value="' . esc_attr($role_key) . '" data-count="' . esc_attr((string) $count) . '" ' . selected($selected_role, $role_key, false) . '>' . esc_html(sprintf('%s (%d)', translate_user_role($role_data['name']), $count)) . '</option>';
        }
        echo '</select><p id="credits-role-count" class="description">';
        echo $selected_role !== '' ? esc_html(sprintf(__('Selected role currently has %d account(s).', 'square-loyalty-points'), $selected_role_count)) : esc_html__('Select a role to see account count.', 'square-loyalty-points');
        echo '</p></td></tr>';

        if ($selected_role !== '') {
            $this->render_role_exclusions($selected_role, $role_users, $excluded_people, $excluded_user_ids, $exclude_users_enabled, $role_users_page, $role_users_pages);
        }

        echo '</tbody></table>';
        $this->render_operation_fields('role');
        echo '<p class="submit"><button type="submit" id="credits_apply_role" class="button button-primary" disabled><i class="fa-duotone fa-people-group" aria-hidden="true"></i> ' . esc_html__('Apply to Role', 'square-loyalty-points') . '</button></p>';
        echo '</form>';
        echo '</div>';

        $this->render_role_activity_panel();
    }

    private function filter_linked_users_missing_phone($linked_users) {
        $missing = array();

        foreach ((array) $linked_users as $row) {
            $user_id = isset($row->user_id) ? absint($row->user_id) : 0;
            if ($user_id <= 0) {
                continue;
            }

            if ($this->get_user_loyalty_phone_number($user_id) === '') {
                $missing[] = $row;
            }
        }

        return $missing;
    }

    private function render_missing_phone_overview_panel($linked_missing_phone_users) {
        echo '<div class="credits-card square-loyalty-missing-phone-panel">';
        echo '<h2><i class="fa-duotone fa-phone-slash" aria-hidden="true"></i> ' . esc_html__('Linked Customers Missing Phone Numbers', 'square-loyalty-points') . '</h2>';
        echo '<p class="description">' . esc_html__('These customers have a Square Customer ID in WordPress but no usable phone number for Square Loyalty enrollment.', 'square-loyalty-points') . '</p>';
        echo '<table class="widefat striped square-loyalty-missing-phone-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Customer', 'square-loyalty-points') . '</th>';
        echo '<th>' . esc_html__('Email', 'square-loyalty-points') . '</th>';
        echo '<th>' . esc_html__('Square Customer ID', 'square-loyalty-points') . '</th>';
        echo '<th>' . esc_html__('Fix', 'square-loyalty-points') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($linked_missing_phone_users as $row) {
            $user_id = isset($row->user_id) ? absint($row->user_id) : 0;
            $customer_id = isset($row->square_customer_id) ? (string) $row->square_customer_id : '';
            echo '<tr>';
            echo '<td>' . esc_html((string) $row->display_name . ' (' . (string) $row->user_login . ')') . '</td>';
            echo '<td>' . esc_html((string) $row->user_email) . '</td>';
            echo '<td><code>' . esc_html($customer_id) . '</code></td>';
            echo '<td>' . wp_kses($this->format_customer_profile_links($user_id, $customer_id), $this->get_allowed_link_html()) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function format_customer_profile_links($user_id, $square_customer_id) {
        $links = array();
        $square_url = $this->get_square_customer_profile_url($square_customer_id);
        if ($square_url !== '') {
            $links[] = '<a href="' . esc_url($square_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open Square profile', 'square-loyalty-points') . '</a>';
        }

        $wp_url = $this->get_wordpress_user_profile_url($user_id);
        if ($wp_url !== '') {
            $links[] = '<a href="' . esc_url($wp_url) . '">' . esc_html__('Edit WordPress profile', 'square-loyalty-points') . '</a>';
        }

        return implode(' <span aria-hidden="true">|</span> ', $links);
    }

    private function get_square_customer_profile_url($square_customer_id) {
        $square_customer_id = $this->sanitize_square_customer_id($square_customer_id);
        if ($square_customer_id === '') {
            return '';
        }

        return 'https://app.squareup.com/dashboard/customers/directory/customer/' . rawurlencode($square_customer_id);
    }

    private function get_wordpress_user_profile_url($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return '';
        }

        return admin_url('user-edit.php?user_id=' . $user_id);
    }

    private function get_allowed_link_html() {
        return array(
            'a' => array(
                'href' => array(),
                'target' => array(),
                'rel' => array(),
            ),
            'span' => array(
                'aria-hidden' => array(),
            ),
        );
    }

    private function render_role_exclusions($selected_role, $role_users, $excluded_people, $excluded_user_ids, $exclude_users_enabled, $role_users_page, $role_users_pages) {
        echo '<tr class="credits-role-row credits-role-row-enable-exclusions"><th scope="row">' . esc_html__('Exclusions', 'square-loyalty-points') . '</th><td>';
        echo '<label class="credits-role-enable-exclusions"><input type="checkbox" id="credits_role_enable_exclusions" name="exclude_users" value="1" ' . checked($exclude_users_enabled, true, false) . ' /> ' . esc_html__('Exclude users from this role action', 'square-loyalty-points') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, choose specific users to skip before applying the role update.', 'square-loyalty-points') . '</p>';
        echo '</td></tr>';

        echo '<tr class="credits-role-row credits-role-row-exclusions"><th scope="row">' . esc_html__('Role Members', 'square-loyalty-points') . '</th><td>';
        echo '<div id="credits-role-exclusions-section" class="credits-role-exclusions-section"' . ($exclude_users_enabled ? '' : ' hidden') . '>';
        echo '<label for="credits-role-member-search" class="credits-role-member-search-label">' . esc_html__('Search to exclude', 'square-loyalty-points') . '</label>';
        echo '<span class="credits-input-decor credits-input-wide credits-tone-search credits-role-member-search-input-wrap"><span class="credits-input-icon"><i class="fa-duotone fa-magnifying-glass" aria-hidden="true"></i></span><input type="search" id="credits-role-member-search" class="regular-text" placeholder="' . esc_attr__('Type name, username, or email', 'square-loyalty-points') . '" autocomplete="off" /></span>';
        echo '<div id="credits-role-member-search-results" class="credits-role-member-search-results"></div>';
        echo '<p class="description">' . esc_html__('Excluded users will be skipped when this role update runs.', 'square-loyalty-points') . '</p>';
        echo '<div id="credits-role-excluded-list" class="credits-role-excluded-list" data-empty-label="' . esc_attr__('No excluded accounts.', 'square-loyalty-points') . '">';
        if (!empty($excluded_people)) {
            foreach ($excluded_people as $person) {
                echo '<span class="credits-excluded-pill" data-user-id="' . esc_attr((string) $person['id']) . '" data-user-name="' . esc_attr($person['name']) . '" data-user-email="' . esc_attr($person['email']) . '">';
                echo '<span class="credits-excluded-pill-label">' . esc_html($person['name'] . ' (' . $person['email'] . ')') . '</span>';
                echo '<button type="button" class="credits-excluded-pill-remove" data-user-id="' . esc_attr((string) $person['id']) . '" aria-label="' . esc_attr__('Remove exclusion', 'square-loyalty-points') . '">&times;</button>';
                echo '</span>';
            }
        } else {
            echo '<span class="credits-role-excluded-empty">' . esc_html__('No excluded accounts.', 'square-loyalty-points') . '</span>';
        }
        echo '</div>';

        if (empty($role_users)) {
            echo '<p>' . esc_html__('No users found in this role.', 'square-loyalty-points') . '</p>';
        } else {
            echo '<div class="credits-role-users-wrap"><table class="widefat striped credits-role-users-table"><thead><tr>';
            echo '<th>' . esc_html__('Name', 'square-loyalty-points') . '</th><th>' . esc_html__('Email', 'square-loyalty-points') . '</th><th>' . esc_html__('Exclude', 'square-loyalty-points') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($role_users as $role_user) {
                $user_id = (int) $role_user->ID;
                $name = $this->get_customer_display_name($role_user);
                $email = (string) $role_user->user_email;
                $is_excluded = in_array($user_id, $excluded_user_ids, true);
                $button_classes = 'button credits-role-exclude-btn' . ($is_excluded ? ' is-excluded' : '');
                echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($email) . '</td><td>';
                echo '<button type="button" class="' . esc_attr($button_classes) . '" data-user-id="' . esc_attr((string) $user_id) . '" data-user-name="' . esc_attr($name) . '" data-user-email="' . esc_attr($email) . '" aria-pressed="' . esc_attr($is_excluded ? 'true' : 'false') . '">';
                echo '<i class="fa-duotone fa-user-minus" aria-hidden="true"></i> <span>' . esc_html($is_excluded ? __('Excluded', 'square-loyalty-points') : __('Exclude', 'square-loyalty-points')) . '</span>';
                echo '</button></td></tr>';
            }
            echo '</tbody></table></div>';
            $this->render_pagination($role_users_page, $role_users_pages, array('page' => 'square-loyalty-points', 'tab' => 'role', 'selected_role' => $selected_role, 'exclude_users' => $exclude_users_enabled ? 1 : null, 'excluded_user_ids' => implode(',', $excluded_user_ids)), 'role_users_page');
        }

        echo '</div></td></tr>';
    }

    private function render_operation_fields($context) {
        $is_role = $context === 'role';
        $amount_id = $is_role ? 'credits_role_amount' : 'credits_amount';
        $amount_label_id = $is_role ? 'credits_role_amount_label' : 'credits_amount_label';
        $note_id = $is_role ? 'credits_role_note' : 'credits_note';
        $amount_wrap_class = $is_role ? 'credits-role-amount-wrap' : 'credits-manage-amount-wrap';
        $row_prefix = $is_role ? 'credits-role-row' : 'credits-manage-row';
        $operation_label = $is_role ? __('Role point operation', 'square-loyalty-points') : __('Point operation', 'square-loyalty-points');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Operation', 'square-loyalty-points') . '</th><td>';
        echo '<div class="credits-operation-group" role="group" aria-label="' . esc_attr($operation_label) . '">';
        echo '<label class="credits-operation-option credits-operation-add"><input type="radio" name="operation" value="add" required /><span class="credits-operation-button"><i class="fa-duotone fa-circle-plus" aria-hidden="true"></i> ' . esc_html__('Add', 'square-loyalty-points') . '</span></label>';
        echo '<label class="credits-operation-option credits-operation-deduct"><input type="radio" name="operation" value="deduct" required /><span class="credits-operation-button"><i class="fa-duotone fa-circle-minus" aria-hidden="true"></i> ' . esc_html__('Remove', 'square-loyalty-points') . '</span></label>';
        echo '<label class="credits-operation-option credits-operation-set"><input type="radio" name="operation" value="set" required /><span class="credits-operation-button"><i class="fa-duotone fa-sliders" aria-hidden="true"></i> ' . esc_html__('Set', 'square-loyalty-points') . '</span></label>';
        echo '</div><p class="description">' . esc_html__('Choose an operation to continue.', 'square-loyalty-points') . '</p></td></tr>';

        echo '<tr class="' . esc_attr($row_prefix . ' ' . $row_prefix . '-amount') . '"><th scope="row"><label id="' . esc_attr($amount_label_id) . '" for="' . esc_attr($amount_id) . '">' . esc_html($is_role ? __('Points per user', 'square-loyalty-points') : __('Points', 'square-loyalty-points')) . '</label></th><td>';
        echo '<span class="credits-input-decor credits-input-medium credits-tone-money ' . esc_attr($amount_wrap_class) . '"><span class="credits-input-icon"><i class="fa-duotone fa-award" aria-hidden="true"></i></span>';
        echo '<input required type="number" min="0" step="1" name="points" id="' . esc_attr($amount_id) . '" class="regular-text" disabled /></span>';
        if (!$is_role) {
            echo '<p id="credits-balance-preview" class="credits-balance-preview description">' . esc_html__('Select an operation to see the projected balance.', 'square-loyalty-points') . '</p>';
        }
        echo '</td></tr>';

        echo '<tr class="' . esc_attr($row_prefix . ' ' . $row_prefix . '-note') . '"><th scope="row"><label for="' . esc_attr($note_id) . '">' . esc_html($is_role ? __('Bulk reason', 'square-loyalty-points') : __('Reason', 'square-loyalty-points')) . '</label></th><td>';
        echo '<span class="credits-input-decor credits-input-wide credits-tone-note"><span class="credits-input-icon"><i class="fa-duotone fa-pen-to-square" aria-hidden="true"></i></span>';
        echo '<input required type="text" name="note" id="' . esc_attr($note_id) . '" class="regular-text" placeholder="' . esc_attr__('Required reason for this Square adjustment', 'square-loyalty-points') . '" disabled /></span>';
        echo '<p class="description">' . esc_html__('Square records this as the manual adjustment reason.', 'square-loyalty-points') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
    }

    private function render_activity_tab() {
        $activity_type = isset($_GET['activity_type']) ? sanitize_key(wp_unslash($_GET['activity_type'])) : 'all';
        $activity_search = isset($_GET['activity_search']) ? sanitize_text_field(wp_unslash($_GET['activity_search'])) : '';
        $type_map = array(
            'all' => array(),
            'earned' => array('ACCUMULATE_POINTS', 'ACCUMULATE_PROMOTION_POINTS'),
            'spent' => array('CREATE_REWARD', 'REDEEM_REWARD'),
            'expired' => array('EXPIRE_POINTS'),
            'adjusted' => array('ADJUST_POINTS', 'OTHER'),
            'returned' => array('DELETE_REWARD'),
        );
        if (!isset($type_map[$activity_type])) {
            $activity_type = 'all';
        }

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-clock-rotate-left" aria-hidden="true"></i> ' . esc_html__('Square Loyalty Activity', 'square-loyalty-points') . '</h2>';
        echo '<div class="credits-activity-filter-toolbar"><form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="credits-activity-filter-form">';
        echo '<input type="hidden" name="page" value="square-loyalty-points" /><input type="hidden" name="tab" value="activity" /><input type="hidden" name="activity_type" value="' . esc_attr($activity_type) . '" />';
        echo '<span class="credits-input-decor credits-input-medium credits-tone-search"><span class="credits-input-icon"><i class="fa-duotone fa-magnifying-glass" aria-hidden="true"></i></span><input type="search" name="activity_search" value="' . esc_attr($activity_search) . '" placeholder="' . esc_attr__('Filter visible customers', 'square-loyalty-points') . '" /></span>';
        echo '<button type="submit" class="button credits-activity-filter-submit"><i class="fa-duotone fa-filter" aria-hidden="true"></i> ' . esc_html__('Filter', 'square-loyalty-points') . '</button>';
        if ($activity_search !== '' || $activity_type !== 'all') {
            echo '<a class="button credits-activity-filter-clear" href="' . esc_url(add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'activity'), admin_url('admin.php'))) . '"><i class="fa-duotone fa-rotate-left" aria-hidden="true"></i> ' . esc_html__('Clear', 'square-loyalty-points') . '</a>';
        }
        echo '</form><div class="credits-activity-quick-filters">';
        $quick_filters = array('all' => __('All', 'square-loyalty-points'), 'earned' => __('Earned', 'square-loyalty-points'), 'spent' => __('Spent', 'square-loyalty-points'), 'expired' => __('Expired', 'square-loyalty-points'), 'adjusted' => __('Adjusted', 'square-loyalty-points'), 'returned' => __('Returned', 'square-loyalty-points'));
        foreach ($quick_filters as $key => $label) {
            $classes = array('button', 'credits-activity-quick-filter');
            if ($key === $activity_type) {
                $classes[] = 'button-primary';
                $classes[] = 'is-active';
            }
            $url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'activity', 'activity_type' => $key, 'activity_search' => $activity_search), admin_url('admin.php'));
            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</div></div>';

        if (!$this->square_api->is_configured()) {
            echo '<p>' . esc_html__('Configure Square settings to load earned, spent, and expired loyalty events.', 'square-loyalty-points') . '</p>';
        } else {
            $response = $this->square_api->search_loyalty_events(array('types' => $type_map[$activity_type], 'limit' => 30));
            if (is_wp_error($response)) {
                echo '<div class="notice notice-error inline"><p>' . esc_html($response->get_error_message()) . '</p></div>';
            } else {
                $events = isset($response['events']) && is_array($response['events']) ? $response['events'] : array();
                $events = $this->filter_events_by_customer_search($events, $activity_search);
                $this->render_square_events_table($events, true);
            }
        }

        echo '</div>';
    }

    private function render_role_activity_panel() {
        $runs_per_page = 20;
        $runs_page = isset($_GET['role_activity_page']) ? max(1, absint($_GET['role_activity_page'])) : 1;
        $runs_total = $this->manager->count_role_runs();
        $runs_pages = max(1, (int) ceil(max(1, $runs_total) / $runs_per_page));
        if ($runs_page > $runs_pages) {
            $runs_page = $runs_pages;
        }
        $runs = $runs_total > 0 ? $this->manager->get_role_runs($runs_per_page, ($runs_page - 1) * $runs_per_page) : array();
        $view_run_id = isset($_GET['view_run']) ? absint($_GET['view_run']) : 0;

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-list-check" aria-hidden="true"></i> ' . esc_html__('Role Apply Activity', 'square-loyalty-points') . '</h2>';
        if (empty($runs)) {
            echo '<p>' . esc_html__('No role apply executions logged yet.', 'square-loyalty-points') . '</p>';
        } else {
            echo '<div class="credits-activity-table-wrap"><table class="widefat striped credits-activity-table"><thead><tr>';
            echo '<th>' . esc_html__('Date', 'square-loyalty-points') . '</th><th>' . esc_html__('Role', 'square-loyalty-points') . '</th><th>' . esc_html__('Operation', 'square-loyalty-points') . '</th><th>' . esc_html__('Applied', 'square-loyalty-points') . '</th><th>' . esc_html__('Skipped', 'square-loyalty-points') . '</th><th>' . esc_html__('Failed', 'square-loyalty-points') . '</th><th>' . esc_html__('Excluded', 'square-loyalty-points') . '</th><th>' . esc_html__('Note', 'square-loyalty-points') . '</th><th>' . esc_html__('Actions', 'square-loyalty-points') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($runs as $run) {
                $run_id = isset($run->id) ? (int) $run->id : 0;
                $excluded_count = isset($run->excluded_count) ? (int) $run->excluded_count : 0;
                $csv_url = wp_nonce_url(add_query_arg(array('action' => 'square_loyalty_points_download_role_run_csv', 'run_id' => $run_id), admin_url('admin-post.php')), 'square_loyalty_points_download_role_run_csv_' . $run_id);
                $view_url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'role', 'role_activity_page' => $runs_page, 'view_run' => $run_id), admin_url('admin.php'));
                $excluded_display = (string) $excluded_count;
                if ($excluded_count > 0) {
                    $excluded_display = '<a class="credits-role-run-view-excluded" href="' . esc_url($view_url) . '">' . esc_html((string) $excluded_count) . '</a>';
                }

                echo '<tr>';
                echo '<td>' . esc_html($this->format_gmt_date((string) $run->created_at)) . '</td>';
                echo '<td>' . esc_html($this->get_role_run_display_label($run)) . '</td>';
                echo '<td>' . esc_html(sprintf('%1$s %2$s', $this->get_operation_label((string) $run->operation), $this->format_points((int) $run->points))) . '</td>';
                echo '<td>' . esc_html((string) (int) $run->applied_count) . '</td>';
                echo '<td>' . esc_html((string) (int) $run->skipped_count) . '</td>';
                echo '<td>' . esc_html((string) (int) $run->failed_count) . '</td>';
                echo '<td>' . wp_kses($excluded_display, array('a' => array('href' => array(), 'class' => array()))) . '</td>';
                echo '<td>' . esc_html((string) $run->note) . '</td>';
                echo '<td><span class="credits-role-run-actions">';
                echo '<a class="button button-small credits-role-run-csv-btn credits-role-run-icon-btn" href="' . esc_url($csv_url) . '" title="' . esc_attr__('Download CSV', 'square-loyalty-points') . '" aria-label="' . esc_attr__('Download CSV', 'square-loyalty-points') . '"><i class="fa-duotone fa-file-csv" aria-hidden="true"></i></a>';
                if ($excluded_count > 0) {
                    echo '<a class="button button-small credits-role-run-readd-btn" href="' . esc_url($view_url) . '" title="' . esc_attr__('Re-add excluded customers', 'square-loyalty-points') . '" aria-label="' . esc_attr__('Re-add excluded customers', 'square-loyalty-points') . '"><i class="fa-duotone fa-user-plus" aria-hidden="true"></i></a>';
                }
                echo '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            $this->render_pagination($runs_page, $runs_pages, array('page' => 'square-loyalty-points', 'tab' => 'role'), 'role_activity_page');
        }

        if ($view_run_id > 0) {
            $run = $this->manager->get_role_run($view_run_id);
            if ($run) {
                $this->render_role_run_excluded_details($run, $runs_page);
            } else {
                echo '<p class="credits-role-run-detail-empty">' . esc_html__('That role apply entry could not be found.', 'square-loyalty-points') . '</p>';
            }
        }
        echo '</div>';
    }

    private function render_role_run_excluded_details($run, $runs_page) {
        $run_id = isset($run->id) ? absint($run->id) : 0;
        if ($run_id <= 0) {
            return;
        }

        $excluded = $this->manager->decode_role_run_participants(isset($run->excluded_participants) ? $run->excluded_participants : '');
        $run_note = isset($run->note) ? trim((string) $run->note) : '';
        if ($run_note === '') {
            $run_note = __('No note', 'square-loyalty-points');
        }

        echo '<div class="credits-role-run-detail-wrap">';
        echo '<h3><i class="fa-duotone fa-users-slash" aria-hidden="true"></i> ';
        echo esc_html__('Excluded Customers', 'square-loyalty-points') . ' &bull; <em>' . esc_html($run_note) . '</em>';
        echo '</h3>';
        echo '<p class="description">';
        echo esc_html(
            sprintf(
                __('%1$s on %2$s. Operation: %3$s %4$s.', 'square-loyalty-points'),
                $this->get_role_run_display_label($run),
                $this->format_gmt_date((string) $run->created_at),
                $this->get_operation_label(isset($run->operation) ? (string) $run->operation : ''),
                $this->format_points((int) $run->points)
            )
        );
        echo '</p>';

        if (empty($excluded)) {
            echo '<p class="credits-role-run-detail-empty">' . esc_html__('No excluded customers remain for this run.', 'square-loyalty-points') . '</p>';
            echo '</div>';
            return;
        }

        echo '<div class="credits-role-run-detail-table-wrap">';
        echo '<table class="widefat striped credits-role-run-detail-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'square-loyalty-points') . '</th>';
        echo '<th>' . esc_html__('Email', 'square-loyalty-points') . '</th>';
        echo '<th>' . esc_html__('Square Customer ID', 'square-loyalty-points') . '</th>';
        echo '<th>' . esc_html__('Action', 'square-loyalty-points') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($excluded as $participant) {
            $user_id = isset($participant['id']) ? absint($participant['id']) : 0;
            if ($user_id <= 0) {
                continue;
            }

            $name = isset($participant['name']) && $participant['name'] !== '' ? (string) $participant['name'] : sprintf(__('User #%d', 'square-loyalty-points'), $user_id);
            $email = isset($participant['email']) ? (string) $participant['email'] : '';
            $customer_id = $this->get_user_square_customer_id($user_id);

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html($email) . '</td>';
            echo '<td>' . ($customer_id !== '' ? '<code>' . esc_html($customer_id) . '</code>' : esc_html__('Not linked', 'square-loyalty-points')) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="credits-role-run-apply-form">';
            wp_nonce_field('square_loyalty_points_apply_role_excluded_' . $run_id . '_' . $user_id);
            echo '<input type="hidden" name="action" value="square_loyalty_points_apply_role_excluded" />';
            echo '<input type="hidden" name="run_id" value="' . esc_attr((string) $run_id) . '" />';
            echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $user_id) . '" />';
            echo '<input type="hidden" name="role_activity_page" value="' . esc_attr((string) $runs_page) . '" />';
            echo '<button type="submit" class="button button-secondary credits-role-reinclude-btn"><i class="fa-duotone fa-user-check" aria-hidden="true"></i> ' . esc_html__('Apply now', 'square-loyalty-points') . '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private function render_settings_tab() {
        global $wp_roles;
        $available_roles = $wp_roles ? $wp_roles->roles : array();
        $count_data = count_users();
        $role_counts = isset($count_data['avail_roles']) ? $count_data['avail_roles'] : array();
        $configured_role_keys = $this->get_role_dropdown_role_keys();
        $candidate_keys = $this->manager->get_candidate_square_customer_meta_keys();

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-gear" aria-hidden="true"></i> ' . esc_html__('Loyalty Squirrel Settings', 'square-loyalty-points') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('square_loyalty_points_save_settings_action', 'square_loyalty_points_nonce');
        echo '<input type="hidden" name="action" value="square_loyalty_points_save_settings" />';
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->render_text_setting_row('label_singular', __('Singular label', 'square-loyalty-points'), $this->get_label_singular(), 'fa-tag', 'credits-tone-label');
        $this->render_text_setting_row('label_plural', __('Plural label', 'square-loyalty-points'), $this->get_label_plural(), 'fa-tags', 'credits-tone-label');
        echo '<tr><th scope="row">' . esc_html__('Dashboard sidebar', 'square-loyalty-points') . '</th><td><label><input type="checkbox" name="sidebar_use_plural_label" value="1" ' . checked($this->should_use_plural_in_sidebar(), true, false) . ' /> ' . esc_html__('Use the plural label in the admin menu', 'square-loyalty-points') . '</label><p class="description">' . esc_html__('When disabled, the admin sidebar uses Loyalty Squirrel.', 'square-loyalty-points') . '</p></td></tr>';
        $this->render_text_setting_row('endpoint_slug', __('My Account endpoint slug', 'square-loyalty-points'), $this->get_endpoint_slug(), 'fa-link', 'credits-tone-link', __('Used in My Account URL. Example: /my-account/loyalty-points/', 'square-loyalty-points'));
        $this->render_textarea_setting_row('account_description_text', __('My Account description', 'square-loyalty-points'), isset($this->settings['account_description_text']) ? (string) $this->settings['account_description_text'] : '', __('Shown underneath the available coupons line on the customer My Account page.', 'square-loyalty-points'));

        echo '<tr><th scope="row"><label for="square_loyalty_points_environment">' . esc_html__('Square environment', 'square-loyalty-points') . '</label></th><td>';
        echo '<select id="square_loyalty_points_environment" name="square_environment"><option value="production" ' . selected($this->settings['square_environment'], 'production', false) . '>' . esc_html__('Production', 'square-loyalty-points') . '</option><option value="sandbox" ' . selected($this->settings['square_environment'], 'sandbox', false) . '>' . esc_html__('Sandbox', 'square-loyalty-points') . '</option></select>';
        echo '</td></tr>';
        $this->render_text_setting_row('square_api_version', __('Square API version', 'square-loyalty-points'), $this->square_api->get_api_version(), 'fa-code', 'credits-tone-link', __('Current Square docs use 2026-01-22. Change only if you intentionally pin another Square version.', 'square-loyalty-points'));

        echo '<tr><th scope="row"><label for="square_loyalty_points_access_token">' . esc_html__('Square access token', 'square-loyalty-points') . '</label></th><td>';
        echo '<span class="credits-input-decor credits-input-wide credits-tone-danger"><span class="credits-input-icon"><i class="fa-duotone fa-key" aria-hidden="true"></i></span><input type="password" name="square_access_token" id="square_loyalty_points_access_token" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr($this->square_api->is_configured() ? __('Token saved; leave blank to keep it', 'square-loyalty-points') : __('Paste Square access token', 'square-loyalty-points')) . '" /></span>';
        echo '<p><label><input type="checkbox" name="clear_square_access_token" value="1" /> ' . esc_html__('Clear saved token', 'square-loyalty-points') . '</label></p>';
        echo '<p class="description">' . esc_html__('The token needs Square Loyalty read/write permissions.', 'square-loyalty-points') . '</p></td></tr>';

        $this->render_text_setting_row('square_customer_meta_key', __('Square customer ID meta key', 'square-loyalty-points'), $this->get_square_customer_meta_key(), 'fa-address-card', 'credits-tone-link', __('This should match the user meta key used by the plugin that shows Square Customer ID on user profiles.', 'square-loyalty-points'));
        echo '<tr><th scope="row">' . esc_html__('Auto detection', 'square-loyalty-points') . '</th><td><label><input type="checkbox" name="auto_detect_customer_meta_key" value="1" ' . checked(!empty($this->settings['auto_detect_customer_meta_key']), true, false) . ' /> ' . esc_html__('If the configured key is empty for a user, scan that user for Square/customer-like meta keys', 'square-loyalty-points') . '</label></td></tr>';
        $this->render_text_setting_row('loyalty_phone_meta_key', __('Loyalty enrollment phone meta key', 'square-loyalty-points'), $this->get_loyalty_phone_meta_key(), 'fa-phone', 'credits-tone-link', __('Square Loyalty account creation requires a phone number mapping. WooCommerce usually stores this as billing_phone.', 'square-loyalty-points'));
        echo '<tr><th scope="row">' . esc_html__('Missing loyalty accounts', 'square-loyalty-points') . '</th><td><label><input type="checkbox" name="auto_enroll_missing_accounts" value="1" ' . checked(!empty($this->settings['auto_enroll_missing_accounts']), true, false) . ' /> ' . esc_html__('Automatically enroll linked customers in Square Loyalty before applying manual or role points', 'square-loyalty-points') . '</label><p class="description">' . esc_html__('Bulk actions will skip only customers that cannot be enrolled, such as accounts without a phone number.', 'square-loyalty-points') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Detected candidates', 'square-loyalty-points') . '</th><td>';
        if (empty($candidate_keys)) {
            echo '<p class="description">' . esc_html__('No user meta keys containing both "square" and "customer" were found yet.', 'square-loyalty-points') . '</p>';
        } else {
            echo '<table class="widefat striped square-loyalty-candidates"><thead><tr><th>' . esc_html__('Meta key', 'square-loyalty-points') . '</th><th>' . esc_html__('Users', 'square-loyalty-points') . '</th><th>' . esc_html__('Sample value', 'square-loyalty-points') . '</th></tr></thead><tbody>';
            foreach ($candidate_keys as $candidate) {
                echo '<tr><td><code>' . esc_html((string) $candidate->meta_key) . '</code></td><td>' . esc_html(number_format_i18n((int) $candidate->user_count)) . '</td><td><code>' . esc_html(wp_html_excerpt((string) $candidate->sample_value, 36, '...')) . '</code></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="description">' . esc_html__('You can also inspect the Square Customer ID field on a user profile and use the input name as this meta key.', 'square-loyalty-points') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Negative balances', 'square-loyalty-points') . '</th><td><label><input type="checkbox" name="allow_negative_balance" value="1" ' . checked(!empty($this->settings['allow_negative_balance']), true, false) . ' /> ' . esc_html__('Allow remove operations to create negative Square loyalty balances', 'square-loyalty-points') . '</label></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Role dropdown options', 'square-loyalty-points') . '</th><td><input type="hidden" name="role_dropdown_roles_present" value="1" />';
        if (empty($available_roles)) {
            echo '<p class="description">' . esc_html__('No WordPress roles were found.', 'square-loyalty-points') . '</p>';
        } else {
            echo '<select id="credits_role_dropdown_roles" name="role_dropdown_role_keys[]" class="credits-category-multiselect" multiple size="8">';
            foreach ($available_roles as $role_key => $role_data) {
                $role_key = sanitize_key((string) $role_key);
                $count = isset($role_counts[$role_key]) ? (int) $role_counts[$role_key] : 0;
                $selected = empty($configured_role_keys) || in_array($role_key, $configured_role_keys, true) ? ' selected="selected"' : '';
                echo '<option value="' . esc_attr($role_key) . '"' . $selected . '>' . esc_html(sprintf('%s (%d)', translate_user_role($role_data['name']), $count)) . '</option>';
            }
            echo '</select><p class="description">' . esc_html__('Choose which roles appear in Apply by Role. If none are selected, all roles will be shown.', 'square-loyalty-points') . '</p>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary"><i class="fa-duotone fa-floppy-disk" aria-hidden="true"></i> ' . esc_html__('Save Settings', 'square-loyalty-points') . '</button></p>';
        echo '</form></div>';
    }

    private function render_about_tab() {
        $wp_version = get_bloginfo('version');
        $wc_version = class_exists('WooCommerce') && defined('WC_VERSION') ? WC_VERSION : __('Not installed', 'square-loyalty-points');
        $current_year = wp_date('Y');

        echo '<div class="credits-card">';
        echo '<h2><i class="fa-duotone fa-circle-info" aria-hidden="true"></i> ' . esc_html__('About Loyalty Squirrel', 'square-loyalty-points') . '</h2>';
        echo '<table class="widefat striped credits-about-table"><tbody>';
        echo '<tr><th>' . esc_html__('Plugin version', 'square-loyalty-points') . '</th><td><code>' . esc_html(SQUARE_LOYALTY_POINTS_VERSION) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('WordPress version', 'square-loyalty-points') . '</th><td><code>' . esc_html($wp_version) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('WooCommerce version', 'square-loyalty-points') . '</th><td><code>' . esc_html($wc_version) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Square environment', 'square-loyalty-points') . '</th><td><code>' . esc_html($this->square_api->get_environment()) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Square API version', 'square-loyalty-points') . '</th><td><code>' . esc_html($this->square_api->get_api_version()) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__('Square token', 'square-loyalty-points') . '</th><td>' . ($this->square_api->is_configured() ? '<span class="credits-status-pill credits-status-active">' . esc_html__('Configured', 'square-loyalty-points') . '</span>' : '<span class="credits-status-pill credits-status-inactive">' . esc_html__('Missing', 'square-loyalty-points') . '</span>') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Customer meta key', 'square-loyalty-points') . '</th><td><code>' . esc_html($this->get_square_customer_meta_key()) . '</code></td></tr>';
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('This plugin adjusts Square Loyalty balances through Square\'s Loyalty API and keeps a local WordPress audit trail for admin actions.', 'square-loyalty-points') . '</p>';
        echo '<p class="credits-about-credit">' . esc_html__('Built by', 'square-loyalty-points') . ' <strong>' . esc_html__('Alex Burgess', 'square-loyalty-points') . '</strong> &copy; ' . esc_html($current_year) . '</p>';
        echo '</div>';
    }

    private function render_text_setting_row($name, $label, $value, $icon, $tone_class, $description = '') {
        $id = 'square_loyalty_points_' . sanitize_key($name);
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<span class="credits-input-decor credits-input-wide ' . esc_attr($tone_class) . '"><span class="credits-input-icon"><i class="fa-duotone ' . esc_attr($icon) . '" aria-hidden="true"></i></span>';
        echo '<input type="text" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="regular-text" value="' . esc_attr($value) . '" /></span>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function render_textarea_setting_row($name, $label, $value, $description = '') {
        $id = 'square_loyalty_points_' . sanitize_key($name);
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="large-text" rows="4">' . esc_textarea($value) . '</textarea>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    public function handle_manage_user() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'square-loyalty-points'));
        }

        check_admin_referer('square_loyalty_points_manage_user_action', 'square_loyalty_points_nonce');
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $points = isset($_POST['points']) ? absint($_POST['points']) : 0;
        $note = isset($_POST['note']) ? trim(sanitize_text_field(wp_unslash($_POST['note']))) : '';
        $redirect_url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'manage', 'user_id' => $user_id), admin_url('admin.php'));

        $result = $this->perform_points_operation($user_id, $operation, $points, $note, 'admin', null);
        $this->redirect_with_result($redirect_url, $result, __('Customer loyalty points updated.', 'square-loyalty-points'));
    }

    public function handle_enroll_user() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'square-loyalty-points'));
        }

        check_admin_referer('square_loyalty_points_enroll_user_action', 'square_loyalty_points_nonce');
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $redirect_url = add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'manage', 'user_id' => $user_id), admin_url('admin.php'));

        $result = $this->ensure_user_loyalty_account($user_id, true);
        $this->redirect_with_result($redirect_url, $result, __('Customer enrolled in Square Loyalty.', 'square-loyalty-points'));
    }

    public function handle_manage_role() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'square-loyalty-points'));
        }

        check_admin_referer('square_loyalty_points_manage_role_action', 'square_loyalty_points_nonce');
        $role = isset($_POST['role']) ? sanitize_key(wp_unslash($_POST['role'])) : '';
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $points = isset($_POST['points']) ? absint($_POST['points']) : 0;
        $note = isset($_POST['note']) ? trim(sanitize_text_field(wp_unslash($_POST['note']))) : '';
        $exclude_users_enabled = isset($_POST['exclude_users']) && absint(wp_unslash($_POST['exclude_users'])) === 1;
        $excluded_user_ids = $exclude_users_enabled ? $this->sanitize_user_id_list(isset($_POST['excluded_user_ids']) ? wp_unslash($_POST['excluded_user_ids']) : '') : array();
        $redirect_args = array('page' => 'square-loyalty-points', 'tab' => 'role', 'selected_role' => $role);
        if ($exclude_users_enabled) {
            $redirect_args['exclude_users'] = 1;
        }
        if (!empty($excluded_user_ids)) {
            $redirect_args['excluded_user_ids'] = implode(',', $excluded_user_ids);
        }
        $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));

        $validation = $this->validate_operation_request($operation, $points, $note);
        if (is_wp_error($validation)) {
            $this->redirect_with_result($redirect_url, $validation, '');
        }

        $roles = $this->get_configured_role_dropdown_roles();
        if ($role === '' || !isset($roles[$role])) {
            $this->redirect_with_result($redirect_url, new WP_Error('square_loyalty_invalid_role', __('Please choose a valid role.', 'square-loyalty-points')), '');
        }

        $users = get_users(array('role' => $role));
        $excluded_lookup = array_fill_keys($excluded_user_ids, true);
        $applied = array();
        $excluded = array();
        $skipped = array();
        $failed = array();

        foreach ($users as $user) {
            $participant = $this->build_user_participant($user);
            if (isset($excluded_lookup[(int) $user->ID])) {
                $participant['status'] = 'excluded';
                $participant['message'] = __('Excluded before apply.', 'square-loyalty-points');
                $excluded[] = $participant;
                continue;
            }

            $result = $this->perform_points_operation((int) $user->ID, $operation, $points, $note, 'role', null);
            if (is_wp_error($result)) {
                $participant['message'] = $result->get_error_message();
                if (in_array($result->get_error_code(), array('square_loyalty_missing_customer_id', 'square_loyalty_missing_phone', 'square_loyalty_no_account', 'square_loyalty_no_change'), true)) {
                    $participant['status'] = 'skipped';
                    $skipped[] = $participant;
                } else {
                    $participant['status'] = 'failed';
                    $failed[] = $participant;
                }
                continue;
            }

            $participant['status'] = !empty($result['status']) ? sanitize_key($result['status']) : 'success';
            $participant['square_customer_id'] = isset($result['square_customer_id']) ? (string) $result['square_customer_id'] : '';
            $participant['loyalty_account_id'] = isset($result['loyalty_account_id']) ? (string) $result['loyalty_account_id'] : '';
            $participant['square_event_id'] = isset($result['square_event_id']) ? (string) $result['square_event_id'] : '';
            $participant['message'] = isset($result['message']) ? (string) $result['message'] : '';
            if ($participant['status'] === 'no_change') {
                $skipped[] = $participant;
            } else {
                $applied[] = $participant;
            }
        }

        $this->manager->log_role_run(
            array(
                'role_key' => $role,
                'role_label' => $this->get_role_label($role),
                'operation' => $operation,
                'points' => $points,
                'note' => $note,
                'applied_participants' => $applied,
                'excluded_participants' => $excluded,
                'skipped_participants' => $skipped,
                'failed_participants' => $failed,
                'admin_user_id' => get_current_user_id(),
            )
        );

        $this->redirect_with_result(
            $redirect_url,
            true,
            sprintf(
                __('Applied to %1$d user(s). Skipped %2$d, failed %3$d, excluded %4$d.', 'square-loyalty-points'),
                count($applied),
                count($skipped),
                count($failed),
                count($excluded)
            )
        );
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'square-loyalty-points'));
        }

        check_admin_referer('square_loyalty_points_save_settings_action', 'square_loyalty_points_nonce');
        $current = wp_parse_args(get_option('square_loyalty_points_settings', array()), self::default_settings());
        $role_dropdown_role_keys = isset($current['role_dropdown_role_keys']) ? (array) $current['role_dropdown_role_keys'] : array();

        if (isset($_POST['role_dropdown_roles_present'])) {
            $role_dropdown_role_keys = isset($_POST['role_dropdown_role_keys']) && is_array($_POST['role_dropdown_role_keys'])
                ? $this->sanitize_role_keys(wp_unslash($_POST['role_dropdown_role_keys']))
                : array();
        }

        $token = isset($current['square_access_token']) ? (string) $current['square_access_token'] : '';
        if (!empty($_POST['clear_square_access_token'])) {
            $token = '';
        } elseif (isset($_POST['square_access_token']) && trim((string) wp_unslash($_POST['square_access_token'])) !== '') {
            $token = trim(sanitize_text_field(wp_unslash($_POST['square_access_token'])));
        }

        $updated = array(
            'label_singular' => isset($_POST['label_singular']) ? sanitize_text_field(wp_unslash($_POST['label_singular'])) : $current['label_singular'],
            'label_plural' => isset($_POST['label_plural']) ? sanitize_text_field(wp_unslash($_POST['label_plural'])) : $current['label_plural'],
            'sidebar_use_plural_label' => isset($_POST['sidebar_use_plural_label']) ? 1 : 0,
            'endpoint_slug' => isset($_POST['endpoint_slug']) ? sanitize_title(wp_unslash($_POST['endpoint_slug'])) : $current['endpoint_slug'],
            'account_description_text' => isset($_POST['account_description_text']) ? sanitize_textarea_field(wp_unslash($_POST['account_description_text'])) : $current['account_description_text'],
            'square_environment' => isset($_POST['square_environment']) && sanitize_key(wp_unslash($_POST['square_environment'])) === 'sandbox' ? 'sandbox' : 'production',
            'square_api_version' => isset($_POST['square_api_version']) ? sanitize_text_field(wp_unslash($_POST['square_api_version'])) : $current['square_api_version'],
            'square_access_token' => $token,
            'square_customer_meta_key' => isset($_POST['square_customer_meta_key']) ? sanitize_key(wp_unslash($_POST['square_customer_meta_key'])) : $current['square_customer_meta_key'],
            'auto_detect_customer_meta_key' => isset($_POST['auto_detect_customer_meta_key']) ? 1 : 0,
            'auto_enroll_missing_accounts' => isset($_POST['auto_enroll_missing_accounts']) ? 1 : 0,
            'loyalty_phone_meta_key' => isset($_POST['loyalty_phone_meta_key']) ? sanitize_key(wp_unslash($_POST['loyalty_phone_meta_key'])) : $current['loyalty_phone_meta_key'],
            'allow_negative_balance' => isset($_POST['allow_negative_balance']) ? 1 : 0,
            'role_dropdown_role_keys' => $role_dropdown_role_keys,
        );

        if ($updated['label_singular'] === '') {
            $updated['label_singular'] = 'Loyalty Point';
        }
        if ($updated['label_plural'] === '') {
            $updated['label_plural'] = 'Loyalty Points';
        }
        if ($updated['endpoint_slug'] === '') {
            $updated['endpoint_slug'] = 'loyalty-points';
        }
        if ($updated['square_api_version'] === '') {
            $updated['square_api_version'] = '2026-01-22';
        }
        if ($updated['square_customer_meta_key'] === '') {
            $updated['square_customer_meta_key'] = 'square_customer_id';
        }
        if ($updated['loyalty_phone_meta_key'] === '') {
            $updated['loyalty_phone_meta_key'] = 'billing_phone';
        }

        update_option('square_loyalty_points_settings', $updated);
        $old_endpoint = isset($current['endpoint_slug']) ? sanitize_title((string) $current['endpoint_slug']) : '';
        $this->settings = $updated;
        $this->square_api = new Square_Loyalty_Points_Square_API($updated);

        if ($updated['endpoint_slug'] !== $old_endpoint) {
            add_rewrite_endpoint($updated['endpoint_slug'], EP_ROOT | EP_PAGES);
            flush_rewrite_rules();
            update_option('square_loyalty_points_rewrite_endpoint_slug', $updated['endpoint_slug']);
        }

        $this->redirect_with_result(add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'settings'), admin_url('admin.php')), true, __('Settings saved.', 'square-loyalty-points'));
    }

    public function handle_download_role_run_csv() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'square-loyalty-points'));
        }

        $run_id = isset($_GET['run_id']) ? absint($_GET['run_id']) : 0;
        if ($run_id <= 0) {
            wp_die(esc_html__('Invalid role activity request.', 'square-loyalty-points'));
        }

        check_admin_referer('square_loyalty_points_download_role_run_csv_' . $run_id);
        $run = $this->manager->get_role_run($run_id);
        if (!$run) {
            wp_die(esc_html__('Role activity entry not found.', 'square-loyalty-points'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=square-loyalty-role-run-' . $run_id . '-' . gmdate('Ymd-His') . '.csv');
        $output = fopen('php://output', 'w');
        if (!$output) {
            exit;
        }

        fputcsv($output, array('Run ID', 'Date', 'Role', 'Operation', 'Points', 'Note'));
        fputcsv($output, array((int) $run->id, $this->format_gmt_date((string) $run->created_at), $this->get_role_run_display_label($run), $this->get_operation_label((string) $run->operation), (int) $run->points, (string) $run->note));
        fputcsv($output, array());
        fputcsv($output, array('Status', 'User ID', 'Name', 'Email', 'Square Customer ID', 'Loyalty Account ID', 'Square Event ID', 'Message'));

        $groups = array(
            'Applied' => $this->manager->decode_role_run_participants($run->applied_participants),
            'Skipped' => $this->manager->decode_role_run_participants($run->skipped_participants),
            'Failed' => $this->manager->decode_role_run_participants($run->failed_participants),
            'Excluded' => $this->manager->decode_role_run_participants($run->excluded_participants),
        );

        foreach ($groups as $status => $participants) {
            foreach ($participants as $participant) {
                fputcsv($output, array($status, (int) $participant['id'], (string) $participant['name'], (string) $participant['email'], (string) $participant['square_customer_id'], (string) $participant['loyalty_account_id'], (string) $participant['square_event_id'], (string) $participant['message']));
            }
        }

        fclose($output);
        exit;
    }

    public function handle_apply_role_excluded() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'square-loyalty-points'));
        }

        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $role_activity_page = isset($_POST['role_activity_page']) ? max(1, absint($_POST['role_activity_page'])) : 1;

        $redirect_args = array(
            'page' => 'square-loyalty-points',
            'tab' => 'role',
            'view_run' => $run_id,
            'role_activity_page' => $role_activity_page,
        );
        $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));

        if ($run_id <= 0 || $user_id <= 0) {
            $this->redirect_with_result($redirect_url, new WP_Error('square_loyalty_invalid_excluded_apply', __('Invalid excluded customer action.', 'square-loyalty-points')), '');
        }

        check_admin_referer('square_loyalty_points_apply_role_excluded_' . $run_id . '_' . $user_id);

        $run = $this->manager->get_role_run($run_id);
        if (!$run) {
            $this->redirect_with_result($redirect_url, new WP_Error('square_loyalty_missing_role_run', __('Role activity entry not found.', 'square-loyalty-points')), '');
        }

        if (!empty($run->role_key)) {
            $redirect_args['selected_role'] = sanitize_key((string) $run->role_key);
            $redirect_url = add_query_arg($redirect_args, admin_url('admin.php'));
        }

        $applied = $this->manager->decode_role_run_participants(isset($run->applied_participants) ? $run->applied_participants : '');
        $excluded = $this->manager->decode_role_run_participants(isset($run->excluded_participants) ? $run->excluded_participants : '');
        $target_participant = null;
        $remaining_excluded = array();

        foreach ($excluded as $participant) {
            $participant_user_id = isset($participant['id']) ? absint($participant['id']) : 0;
            if ($participant_user_id === $user_id && $target_participant === null) {
                $target_participant = $participant;
                continue;
            }

            $remaining_excluded[] = $participant;
        }

        if ($target_participant === null) {
            $this->redirect_with_result($redirect_url, new WP_Error('square_loyalty_not_excluded', __('Customer is not currently excluded for this role action.', 'square-loyalty-points')), '');
        }

        $operation = sanitize_key(isset($run->operation) ? (string) $run->operation : '');
        $points = isset($run->points) ? absint($run->points) : 0;
        $note = isset($run->note) ? trim((string) $run->note) : '';
        $result = $this->perform_points_operation($user_id, $operation, $points, $note, 'role_reinclude', $run_id);

        if (is_wp_error($result)) {
            $this->redirect_with_result($redirect_url, $result, '');
        }

        $user = get_user_by('id', $user_id);
        $applied_participant = $user ? $this->build_user_participant($user) : $target_participant;
        $applied_participant['status'] = !empty($result['status']) ? sanitize_key((string) $result['status']) : 'success';
        $applied_participant['square_customer_id'] = isset($result['square_customer_id']) ? (string) $result['square_customer_id'] : '';
        $applied_participant['loyalty_account_id'] = isset($result['loyalty_account_id']) ? (string) $result['loyalty_account_id'] : '';
        $applied_participant['square_event_id'] = isset($result['square_event_id']) ? (string) $result['square_event_id'] : '';
        $applied_participant['message'] = isset($result['message']) ? (string) $result['message'] : '';

        $already_applied = false;
        foreach ($applied as $applied_person) {
            $applied_user_id = isset($applied_person['id']) ? absint($applied_person['id']) : 0;
            if ($applied_user_id === $user_id) {
                $already_applied = true;
                break;
            }
        }

        if (!$already_applied) {
            $applied[] = $applied_participant;
        }

        $this->manager->update_role_run_participants($run_id, $applied, $remaining_excluded);

        $this->redirect_with_result($redirect_url, true, __('Excluded customer has now received the role action.', 'square-loyalty-points'));
    }

    public function ajax_user_search() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'square-loyalty-points')), 403);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'square_loyalty_points_user_search')) {
            wp_send_json_error(array('message' => __('Invalid request.', 'square-loyalty-points')), 400);
        }

        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        if (strlen($term) < 2) {
            wp_send_json_success(array('results' => array()));
        }

        $results = array();
        foreach ($this->search_users_for_manage($term, 8) as $user) {
            $results[] = array(
                'id' => (int) $user->ID,
                'name' => $this->get_customer_display_name($user),
                'email' => (string) $user->user_email,
                'url' => add_query_arg(array('page' => 'square-loyalty-points', 'tab' => 'manage', 'user_id' => $user->ID), admin_url('admin.php')),
            );
        }

        wp_send_json_success(array('results' => $results));
    }

    public function ajax_role_member_search() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'square-loyalty-points')), 403);
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'square_loyalty_points_role_member_search')) {
            wp_send_json_error(array('message' => __('Invalid request.', 'square-loyalty-points')), 400);
        }

        $role = isset($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $roles = $this->get_configured_role_dropdown_roles();
        if ($role === '' || strlen(trim($term)) < 2 || !isset($roles[$role])) {
            wp_send_json_success(array('results' => array()));
        }

        $results = array();
        foreach ($this->search_users_for_role($role, $term, 20) as $user) {
            $results[] = array('id' => (int) $user->ID, 'name' => $this->get_customer_display_name($user), 'email' => (string) $user->user_email);
        }

        wp_send_json_success(array('results' => $results));
    }

    private function perform_points_operation($user_id, $operation, $points, $note, $source, $role_run_id = null) {
        $validation = $this->validate_operation_request($operation, $points, $note);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $user_id = absint($user_id);
        if ($user_id <= 0 || !get_user_by('id', $user_id)) {
            return new WP_Error('square_loyalty_invalid_user', __('Invalid user.', 'square-loyalty-points'));
        }

        if (!$this->square_api->is_configured()) {
            return new WP_Error('square_loyalty_not_configured', __('Square access token is not configured.', 'square-loyalty-points'));
        }

        $customer_id = $this->get_user_square_customer_id($user_id);
        if ($customer_id === '') {
            return new WP_Error('square_loyalty_missing_customer_id', __('No Square customer ID was found for this WordPress user.', 'square-loyalty-points'));
        }

        $account = $this->square_api->get_loyalty_account_by_customer_id($customer_id);
        if (is_wp_error($account)) {
            return $account;
        }

        if (empty($account) || empty($account['id'])) {
            if (empty($this->settings['auto_enroll_missing_accounts'])) {
                return new WP_Error('square_loyalty_no_account', __('No Square Loyalty account was found for this customer.', 'square-loyalty-points'));
            }

            $account = $this->ensure_user_loyalty_account($user_id, false);
            if (is_wp_error($account)) {
                return $account;
            }
        }

        $current_balance = isset($account['balance']) ? (int) $account['balance'] : 0;
        $delta = $this->calculate_operation_delta($operation, $points, $current_balance);
        if ($delta === 0) {
            return array(
                'status' => 'no_change',
                'message' => __('Balance already matches requested value.', 'square-loyalty-points'),
                'square_customer_id' => $customer_id,
                'loyalty_account_id' => (string) $account['id'],
                'square_event_id' => '',
            );
        }

        $event = $this->square_api->adjust_points(
            (string) $account['id'],
            $delta,
            $note,
            !empty($this->settings['allow_negative_balance']),
            'square-loyalty-points-' . $user_id . '-' . time() . '-' . wp_generate_uuid4()
        );

        if (is_wp_error($event)) {
            return $event;
        }

        $event_id = is_array($event) && !empty($event['id']) ? (string) $event['id'] : '';
        $action = $this->get_action_key($source, $operation, $delta);
        $this->manager->log_activity(
            array(
                'user_id' => $user_id,
                'square_customer_id' => $customer_id,
                'loyalty_account_id' => (string) $account['id'],
                'square_event_id' => $event_id,
                'action' => $action,
                'points' => $delta,
                'note' => $note,
                'status' => 'success',
                'role_run_id' => $role_run_id,
                'admin_user_id' => get_current_user_id(),
            )
        );

        return array(
            'status' => 'success',
            'message' => __('Square adjustment applied.', 'square-loyalty-points'),
            'points' => $delta,
            'square_customer_id' => $customer_id,
            'loyalty_account_id' => (string) $account['id'],
            'square_event_id' => $event_id,
            'event' => $event,
        );
    }

    private function ensure_user_loyalty_account($user_id, $log_enrollment = true) {
        $user_id = absint($user_id);
        if ($user_id <= 0 || !get_user_by('id', $user_id)) {
            return new WP_Error('square_loyalty_invalid_user', __('Invalid user.', 'square-loyalty-points'));
        }

        if (!$this->square_api->is_configured()) {
            return new WP_Error('square_loyalty_not_configured', __('Square access token is not configured.', 'square-loyalty-points'));
        }

        $customer_id = $this->get_user_square_customer_id($user_id);
        if ($customer_id === '') {
            return new WP_Error('square_loyalty_missing_customer_id', __('No Square customer ID was found for this WordPress user.', 'square-loyalty-points'));
        }

        $existing_account = $this->square_api->get_loyalty_account_by_customer_id($customer_id);
        if (is_wp_error($existing_account)) {
            return $existing_account;
        }

        if (!empty($existing_account) && !empty($existing_account['id'])) {
            return $existing_account;
        }

        $phone_number = $this->get_user_loyalty_phone_number($user_id);
        if ($phone_number === '') {
            return new WP_Error('square_loyalty_missing_phone', __('This customer has no phone number available for Square Loyalty enrollment.', 'square-loyalty-points'));
        }

        $program = $this->square_api->retrieve_program();
        if (is_wp_error($program)) {
            return $program;
        }

        $program_id = isset($program['id']) ? (string) $program['id'] : '';
        if ($program_id === '') {
            return new WP_Error('square_loyalty_missing_program', __('Square did not return an active loyalty program ID.', 'square-loyalty-points'));
        }

        if (!empty($program['status']) && strtoupper((string) $program['status']) !== 'ACTIVE') {
            return new WP_Error('square_loyalty_program_inactive', __('The Square Loyalty program is not active for this seller.', 'square-loyalty-points'));
        }

        $account = $this->square_api->create_loyalty_account(
            $program_id,
            $customer_id,
            $phone_number,
            'square-loyalty-enroll-' . $user_id . '-' . wp_generate_uuid4()
        );

        if (is_wp_error($account)) {
            return $account;
        }

        if ($log_enrollment) {
            $this->manager->log_activity(
                array(
                    'user_id' => $user_id,
                    'square_customer_id' => $customer_id,
                    'loyalty_account_id' => isset($account['id']) ? (string) $account['id'] : '',
                    'square_event_id' => '',
                    'action' => 'enrolled',
                    'points' => 0,
                    'note' => __('Customer enrolled in Square Loyalty.', 'square-loyalty-points'),
                    'status' => 'success',
                    'admin_user_id' => get_current_user_id(),
                )
            );
        }

        return $account;
    }

    private function validate_operation_request($operation, $points, $note) {
        if (!in_array($operation, array('add', 'deduct', 'set'), true)) {
            return new WP_Error('square_loyalty_invalid_operation', __('Please choose a valid operation.', 'square-loyalty-points'));
        }

        $points = absint($points);
        if (($operation === 'add' || $operation === 'deduct') && $points <= 0) {
            return new WP_Error('square_loyalty_invalid_points', __('Points must be greater than zero.', 'square-loyalty-points'));
        }

        if ($operation === 'set' && $points < 0) {
            return new WP_Error('square_loyalty_invalid_points', __('New balance must be zero or greater.', 'square-loyalty-points'));
        }

        if (trim((string) $note) === '') {
            return new WP_Error('square_loyalty_note_required', __('Reason is required.', 'square-loyalty-points'));
        }

        return true;
    }

    private function calculate_operation_delta($operation, $points, $current_balance) {
        $points = absint($points);
        $current_balance = (int) $current_balance;

        if ($operation === 'add') {
            return $points;
        }

        if ($operation === 'deduct') {
            return -$points;
        }

        if ($operation === 'set') {
            return $points - $current_balance;
        }

        return 0;
    }

    private function get_action_key($source, $operation, $delta) {
        $source = sanitize_key((string) $source);
        $operation = sanitize_key((string) $operation);
        if ($operation === 'set') {
            $operation = $delta >= 0 ? 'set_increase' : 'set_decrease';
        }

        return $source . '_' . $operation;
    }

    private function get_events_for_loyalty_account($loyalty_account_id, $limit = 20) {
        if (!$this->square_api->is_configured() || trim((string) $loyalty_account_id) === '') {
            return array();
        }

        $response = $this->square_api->search_loyalty_events(array('loyalty_account_id' => $loyalty_account_id, 'limit' => $limit));
        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['events']) && is_array($response['events']) ? $response['events'] : array();
    }

    private function render_square_events_table($events, $show_customer, $account = array()) {
        if (is_wp_error($events)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($events->get_error_message()) . '</p></div>';
            return;
        }

        if (empty($events)) {
            echo '<p>' . esc_html__('No Square loyalty activity found.', 'square-loyalty-points') . '</p>';
            return;
        }

        $table_class = is_admin()
            ? 'widefat striped credits-manage-history-table square-loyalty-events-table'
            : 'shop_table shop_table_responsive my_account_orders credits-history-table square-loyalty-events-table';
        $is_admin_table = is_admin();
        $expiry_map = $is_admin_table ? array() : $this->build_frontend_event_expiry_map($events, $account);

        echo '<table class="' . esc_attr($table_class) . '">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'square-loyalty-points') . '</th>';
        if ($show_customer) {
            echo '<th>' . esc_html__('Customer', 'square-loyalty-points') . '</th>';
        }
        echo '<th>' . esc_html($is_admin_table ? __('Event', 'square-loyalty-points') : __('Details', 'square-loyalty-points')) . '</th><th>' . esc_html__('Points', 'square-loyalty-points') . '</th>';
        echo $is_admin_table ? '<th>' . esc_html__('Source', 'square-loyalty-points') . '</th>' : '<th>' . esc_html__('Expiry', 'square-loyalty-points') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $event_index => $event) {
            if (!is_array($event)) {
                continue;
            }

            $points = $this->get_event_points($event);
            $amount_class = $points >= 0 ? 'credits-amount-positive' : 'credits-amount-negative';
            echo '<tr>';
            echo '<td>' . esc_html($this->format_square_datetime(isset($event['created_at']) ? (string) $event['created_at'] : '', !$is_admin_table)) . '</td>';
            if ($show_customer) {
                echo '<td>' . esc_html($this->get_event_customer_label($event)) . '</td>';
            }
            if ($is_admin_table) {
                echo '<td><strong>' . esc_html($this->get_event_type_label(isset($event['type']) ? (string) $event['type'] : '')) . '</strong>';
                $note = $this->get_event_note($event);
                if ($note !== '') {
                    echo '<br><span class="description">' . esc_html($note) . '</span>';
                }
                echo '</td>';
            } else {
                echo '<td class="square-loyalty-event-detail">' . esc_html($this->get_frontend_event_description($event)) . '</td>';
            }
            echo '<td class="' . esc_attr($amount_class) . '">' . esc_html($this->format_signed_points($points)) . '</td>';
            echo $is_admin_table
                ? '<td>' . esc_html(isset($event['source']) ? (string) $event['source'] : '') . '</td>'
                : '<td>' . esc_html(isset($expiry_map[$event_index]) ? $expiry_map[$event_index] : '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_expiring_deadlines($account, $admin_table) {
        $deadlines = isset($account['expiring_point_deadlines']) && is_array($account['expiring_point_deadlines']) ? $account['expiring_point_deadlines'] : array();
        echo $admin_table
            ? '<h3 class="credits-manage-expiry-heading"><i class="fa-duotone fa-calendar-days" aria-hidden="true"></i> ' . esc_html__('Upcoming Expiry', 'square-loyalty-points') . '</h3>'
            : '<h3>' . esc_html__('Upcoming Expiry', 'square-loyalty-points') . '</h3>';

        if (empty($deadlines)) {
            echo '<p class="credits-manage-expiry-empty">' . esc_html__('No active Square expiry deadlines for this account.', 'square-loyalty-points') . '</p>';
            return;
        }

        echo '<table class="widefat striped credits-manage-expiry-table"><thead><tr><th>' . esc_html__('Expiry date', 'square-loyalty-points') . '</th><th>' . esc_html__('Points', 'square-loyalty-points') . '</th></tr></thead><tbody>';
        foreach ($deadlines as $deadline) {
            $points = isset($deadline['points']) ? (int) $deadline['points'] : 0;
            $expires_at = isset($deadline['expires_at']) ? (string) $deadline['expires_at'] : '';
            echo '<tr><td>' . esc_html($this->format_square_expiry_date($expires_at)) . '</td><td class="credits-manage-expiry-amount">' . esc_html($this->format_points($points)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function build_frontend_event_expiry_map($events, $account) {
        $deadlines = $this->get_account_expiry_deadlines($account);
        if (empty($deadlines)) {
            return array();
        }

        $positive_events = array();
        foreach ($events as $event_index => $event) {
            if (!is_array($event)) {
                continue;
            }

            $points = $this->get_event_points($event);
            if ($points <= 0) {
                continue;
            }

            $positive_events[] = array(
                'index' => $event_index,
                'points' => $points,
                'created_at' => isset($event['created_at']) ? (string) $event['created_at'] : '',
            );
        }

        usort($positive_events, function ($a, $b) {
            return strcmp($a['created_at'], $b['created_at']);
        });

        $expiry_map = array();
        $deadline_index = 0;

        foreach ($positive_events as $event) {
            $remaining_points = (int) $event['points'];
            $labels = array();

            while ($remaining_points > 0 && isset($deadlines[$deadline_index])) {
                $deadline_points = isset($deadlines[$deadline_index]['points']) ? (int) $deadlines[$deadline_index]['points'] : 0;
                if ($deadline_points <= 0) {
                    $deadline_index++;
                    continue;
                }

                $labels[$deadlines[$deadline_index]['label']] = true;
                $points_to_consume = min($remaining_points, $deadline_points);
                $remaining_points -= $points_to_consume;
                $deadlines[$deadline_index]['points'] -= $points_to_consume;

                if ($deadlines[$deadline_index]['points'] <= 0) {
                    $deadline_index++;
                }
            }

            if (!empty($labels)) {
                $expiry_map[$event['index']] = implode(', ', array_keys($labels));
            }
        }

        return $expiry_map;
    }

    private function get_account_expiry_deadlines($account) {
        $deadlines = isset($account['expiring_point_deadlines']) && is_array($account['expiring_point_deadlines']) ? $account['expiring_point_deadlines'] : array();
        $formatted = array();

        foreach ($deadlines as $deadline) {
            if (!is_array($deadline)) {
                continue;
            }

            $points = isset($deadline['points']) ? (int) $deadline['points'] : 0;
            $expires_at = isset($deadline['expires_at']) ? (string) $deadline['expires_at'] : '';
            $label = $this->format_square_expiry_date($expires_at);
            if ($points <= 0 || $label === '') {
                continue;
            }

            $formatted[] = array(
                'points' => $points,
                'expires_at' => $expires_at,
                'label' => $label,
            );
        }

        usort($formatted, function ($a, $b) {
            return strcmp($a['expires_at'], $b['expires_at']);
        });

        return $formatted;
    }

    private function get_event_points($event) {
        $type = isset($event['type']) ? strtoupper((string) $event['type']) : '';
        $map = array(
            'ACCUMULATE_POINTS' => 'accumulate_points',
            'ACCUMULATE_PROMOTION_POINTS' => 'accumulate_promotion_points',
            'ADJUST_POINTS' => 'adjust_points',
            'CREATE_REWARD' => 'create_reward',
            'DELETE_REWARD' => 'delete_reward',
            'EXPIRE_POINTS' => 'expire_points',
            'OTHER' => 'other_event',
        );

        if (!isset($map[$type]) || empty($event[$map[$type]]) || !is_array($event[$map[$type]])) {
            return 0;
        }

        $points = isset($event[$map[$type]]['points']) ? (int) $event[$map[$type]]['points'] : 0;
        if ($type === 'CREATE_REWARD' && $points > 0) {
            return -$points;
        }
        if ($type === 'EXPIRE_POINTS' && $points > 0) {
            return -$points;
        }

        return $points;
    }

    private function get_event_type_label($type) {
        $type = strtoupper((string) $type);
        $labels = array(
            'ACCUMULATE_POINTS' => __('Points earned', 'square-loyalty-points'),
            'ACCUMULATE_PROMOTION_POINTS' => __('Promotion points earned', 'square-loyalty-points'),
            'ADJUST_POINTS' => __('Manual adjustment', 'square-loyalty-points'),
            'CREATE_REWARD' => __('Points spent on reward', 'square-loyalty-points'),
            'REDEEM_REWARD' => __('Reward redeemed', 'square-loyalty-points'),
            'DELETE_REWARD' => __('Reward deleted, points returned', 'square-loyalty-points'),
            'EXPIRE_POINTS' => __('Points expired', 'square-loyalty-points'),
            'OTHER' => __('Square adjustment', 'square-loyalty-points'),
        );

        return isset($labels[$type]) ? $labels[$type] : ucwords(strtolower(str_replace('_', ' ', $type)));
    }

    private function get_event_note($event) {
        $type = isset($event['type']) ? strtoupper((string) $event['type']) : '';
        if ($type === 'ADJUST_POINTS' && !empty($event['adjust_points']['reason'])) {
            return (string) $event['adjust_points']['reason'];
        }
        foreach (array('accumulate_points', 'redeem_reward', 'create_reward') as $key) {
            if (!empty($event[$key]['order_id'])) {
                return sprintf(__('Order %s', 'square-loyalty-points'), (string) $event[$key]['order_id']);
            }
            if (!empty($event[$key]['reward_id'])) {
                return sprintf(__('Reward %s', 'square-loyalty-points'), (string) $event[$key]['reward_id']);
            }
        }

        return '';
    }

    private function get_frontend_event_description($event) {
        $note = $this->get_event_note($event);
        if ($note !== '') {
            return $note;
        }

        $type = isset($event['type']) ? strtoupper((string) $event['type']) : '';
        if ($type === 'EXPIRE_POINTS') {
            return __('Expired', 'square-loyalty-points');
        }
        if (in_array($type, array('CREATE_REWARD', 'REDEEM_REWARD'), true)) {
            return __('Used for a reward', 'square-loyalty-points');
        }
        if ($type === 'DELETE_REWARD') {
            return __('Returned from a reward', 'square-loyalty-points');
        }

        $points = $this->get_event_points($event);
        if ($points > 0) {
            return __('Added', 'square-loyalty-points');
        }
        if ($points < 0) {
            return __('Used', 'square-loyalty-points');
        }

        return __('Updated', 'square-loyalty-points');
    }

    private function filter_events_by_customer_search($events, $search) {
        $search = trim((string) $search);
        if ($search === '') {
            return $events;
        }

        $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
        $filtered = array();

        foreach ($events as $event) {
            $label = $this->get_event_customer_label($event);
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
            if (strpos($haystack, $needle) !== false) {
                $filtered[] = $event;
            }
        }

        return $filtered;
    }

    private function get_event_customer_label($event) {
        $account_id = isset($event['loyalty_account_id']) ? (string) $event['loyalty_account_id'] : '';
        if ($account_id === '') {
            return __('Unknown customer', 'square-loyalty-points');
        }

        $account = $this->get_cached_loyalty_account($account_id);
        if (is_wp_error($account) || empty($account['customer_id'])) {
            return sprintf(__('Loyalty account %s', 'square-loyalty-points'), $account_id);
        }

        $customer_id = (string) $account['customer_id'];
        $user = $this->manager->get_user_by_square_customer_id($customer_id, $this->get_square_customer_meta_key());
        if ($user) {
            return $this->get_customer_display_name($user) . ' (' . $user->user_email . ')';
        }

        return $customer_id;
    }

    private function get_cached_loyalty_account($account_id) {
        if (isset($this->loyalty_account_cache[$account_id])) {
            return $this->loyalty_account_cache[$account_id];
        }

        $account = $this->square_api->retrieve_loyalty_account($account_id);
        $this->loyalty_account_cache[$account_id] = $account;
        return $account;
    }

    private function user_has_square_loyalty_account($user_id) {
        static $cache = array();
        $user_id = absint($user_id);
        if ($user_id <= 0 || !$this->square_api->is_configured()) {
            return false;
        }

        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }

        $customer_id = $this->get_user_square_customer_id($user_id);
        if ($customer_id === '') {
            $cache[$user_id] = false;
            return false;
        }

        $account = $this->square_api->get_loyalty_account_by_customer_id($customer_id);
        $cache[$user_id] = !is_wp_error($account) && !empty($account) && !empty($account['id']);

        return $cache[$user_id];
    }

    private function get_user_square_customer_id($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return '';
        }

        $meta_key = $this->get_square_customer_meta_key();
        $value = trim((string) get_user_meta($user_id, $meta_key, true));
        if ($value !== '') {
            return $this->sanitize_square_customer_id($value);
        }

        if (empty($this->settings['auto_detect_customer_meta_key'])) {
            return '';
        }

        $common_keys = array(
            'square_customer_id',
            '_square_customer_id',
            'square_customer',
            '_square_customer',
            'squareup_customer_id',
            '_squareup_customer_id',
            'wc_square_customer_id',
            '_wc_square_customer_id',
            'woocommerce_square_customer_id',
            '_woocommerce_square_customer_id',
        );

        foreach ($common_keys as $key) {
            if ($key === $meta_key) {
                continue;
            }
            $value = trim((string) get_user_meta($user_id, $key, true));
            if ($this->looks_like_square_customer_id($value)) {
                return $this->sanitize_square_customer_id($value);
            }
        }

        $all_meta = get_user_meta($user_id);
        foreach ($all_meta as $key => $values) {
            $key_lc = strtolower((string) $key);
            if (strpos($key_lc, 'square') === false || strpos($key_lc, 'customer') === false) {
                continue;
            }
            foreach ((array) $values as $candidate) {
                if (is_scalar($candidate) && $this->looks_like_square_customer_id((string) $candidate)) {
                    return $this->sanitize_square_customer_id((string) $candidate);
                }
            }
        }

        return '';
    }

    private function get_user_loyalty_phone_number($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return '';
        }

        $keys = array(
            $this->get_loyalty_phone_meta_key(),
            'billing_phone',
            'phone',
            'mobile_phone',
            'shipping_phone',
        );
        $keys = array_values(array_unique(array_filter($keys)));

        foreach ($keys as $key) {
            $value = trim((string) get_user_meta($user_id, $key, true));
            $phone = $this->normalize_loyalty_phone_number($value);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    private function normalize_loyalty_phone_number($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $phone = preg_replace('/[^\d+]/', '', $value);
        if ($phone === null || $phone === '+') {
            return '';
        }

        if (strpos($phone, '+') !== 0) {
            $digits = preg_replace('/\D/', '', $phone);
            if ($digits !== null && strlen($digits) === 10) {
                return '+1' . $digits;
            }
            if ($digits !== null && strlen($digits) === 11 && strpos($digits, '1') === 0) {
                return '+' . $digits;
            }
        }

        return $phone;
    }

    private function looks_like_square_customer_id($value) {
        $value = trim((string) $value);
        return $value !== '' && preg_match('/^[A-Za-z0-9_-]{10,64}$/', $value);
    }

    private function sanitize_square_customer_id($value) {
        return preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $value));
    }

    private function render_admin_result_notice() {
        if (!isset($_GET['square_loyalty_points_notice']) || !isset($_GET['square_loyalty_points_message'])) {
            return;
        }

        $notice_type = sanitize_key(wp_unslash($_GET['square_loyalty_points_notice']));
        $message = sanitize_text_field(rawurldecode(wp_unslash($_GET['square_loyalty_points_message'])));
        if ($message === '') {
            return;
        }

        $class = $notice_type === 'success' ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function redirect_with_result($base_url, $result, $success_message) {
        if (is_wp_error($result)) {
            $url = add_query_arg(array('square_loyalty_points_notice' => 'error', 'square_loyalty_points_message' => rawurlencode($result->get_error_message())), $base_url);
        } else {
            $url = add_query_arg(array('square_loyalty_points_notice' => 'success', 'square_loyalty_points_message' => rawurlencode($success_message)), $base_url);
        }

        wp_safe_redirect($url);
        exit;
    }

    private function render_pagination($current_page, $total_pages, $base_args, $page_key) {
        $current_page = max(1, absint($current_page));
        $total_pages = max(1, absint($total_pages));
        if ($total_pages <= 1) {
            return;
        }

        $base_args = array_filter(
            (array) $base_args,
            static function ($value) {
                return $value !== null && $value !== '';
            }
        );

        echo '<div class="credits-pagination">';
        if ($current_page > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($base_args, array($page_key => $current_page - 1)), admin_url('admin.php'))) . '"><i class="fa-duotone fa-angle-left" aria-hidden="true"></i> ' . esc_html__('Previous', 'square-loyalty-points') . '</a>';
        }
        echo '<span class="credits-pagination-meta">' . esc_html(sprintf(__('Page %1$d of %2$d', 'square-loyalty-points'), $current_page, $total_pages)) . '</span>';
        if ($current_page < $total_pages) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($base_args, array($page_key => $current_page + 1)), admin_url('admin.php'))) . '">' . esc_html__('Next', 'square-loyalty-points') . ' <i class="fa-duotone fa-angle-right" aria-hidden="true"></i></a>';
        }
        echo '</div>';
    }

    private function search_users_for_manage($term, $limit = 20) {
        return $this->search_users($term, '', $limit);
    }

    private function search_users_for_role($role, $term, $limit = 20) {
        return $this->search_users($term, $role, $limit);
    }

    private function search_users($term, $role = '', $limit = 20) {
        $term = trim((string) $term);
        $role = sanitize_key((string) $role);
        $limit = max(1, absint($limit));
        if ($term === '') {
            return array();
        }

        $name_meta_query = array('relation' => 'OR', array('key' => 'first_name', 'value' => $term, 'compare' => 'LIKE'), array('key' => 'last_name', 'value' => $term, 'compare' => 'LIKE'));
        $query_base = array('number' => $limit, 'orderby' => 'display_name', 'order' => 'ASC');
        if ($role !== '') {
            $query_base['role'] = $role;
        }

        $direct_matches = get_users(array_merge($query_base, array('search' => '*' . $term . '*', 'search_columns' => array('user_login', 'user_email', 'display_name'))));
        $meta_matches = get_users(array_merge($query_base, array('meta_query' => $name_meta_query)));
        $unique = array();
        foreach (array($direct_matches, $meta_matches) as $group) {
            foreach ($group as $user) {
                $user_id = isset($user->ID) ? absint($user->ID) : 0;
                if ($user_id <= 0 || isset($unique[$user_id])) {
                    continue;
                }
                $unique[$user_id] = $user;
                if (count($unique) >= $limit) {
                    break 2;
                }
            }
        }

        return array_values($unique);
    }

    private function get_customer_display_name($user) {
        if (!is_object($user)) {
            return '';
        }

        $first = trim((string) get_user_meta($user->ID, 'first_name', true));
        $last = trim((string) get_user_meta($user->ID, 'last_name', true));
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }

        if (!empty($user->display_name)) {
            return (string) $user->display_name;
        }

        return (string) $user->user_login;
    }

    private function get_user_roles_label($user_id, $separator = ', ') {
        $user = get_user_by('id', absint($user_id));
        if (!$user) {
            return __('No role', 'square-loyalty-points');
        }

        $labels = array();
        foreach ((array) $user->roles as $role) {
            $labels[] = $this->get_role_label($role);
        }

        return !empty($labels) ? implode($separator, $labels) : __('No role', 'square-loyalty-points');
    }

    private function get_role_dropdown_role_keys() {
        return $this->sanitize_role_keys(isset($this->settings['role_dropdown_role_keys']) ? $this->settings['role_dropdown_role_keys'] : array());
    }

    private function get_configured_role_dropdown_roles() {
        global $wp_roles;
        $roles = $wp_roles ? $wp_roles->roles : array();
        $configured = $this->get_role_dropdown_role_keys();
        if (empty($configured)) {
            return $roles;
        }

        return array_intersect_key($roles, array_fill_keys($configured, true));
    }

    private function sanitize_role_keys($role_keys) {
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $role_keys))));
    }

    private function sanitize_user_id_list($raw_ids) {
        $parts = is_array($raw_ids) ? $raw_ids : preg_split('/[\s,]+/', (string) $raw_ids);
        if (!is_array($parts)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('absint', $parts))));
    }

    private function build_user_participant($user) {
        return array(
            'id' => is_object($user) && isset($user->ID) ? (int) $user->ID : 0,
            'name' => is_object($user) ? $this->get_customer_display_name($user) : '',
            'email' => is_object($user) && isset($user->user_email) ? sanitize_email((string) $user->user_email) : '',
            'square_customer_id' => is_object($user) && isset($user->ID) ? $this->get_user_square_customer_id((int) $user->ID) : '',
            'loyalty_account_id' => '',
            'square_event_id' => '',
            'status' => '',
            'message' => '',
        );
    }

    private function get_role_label($role_key) {
        $role_key = sanitize_key((string) $role_key);
        if ($role_key === '') {
            return '';
        }

        global $wp_roles;
        $roles = $wp_roles ? $wp_roles->roles : array();
        if (isset($roles[$role_key]['name'])) {
            return translate_user_role($roles[$role_key]['name']);
        }

        return ucfirst(str_replace('_', ' ', $role_key));
    }

    private function get_role_run_display_label($run) {
        $role_label = is_object($run) && !empty($run->role_label) ? sanitize_text_field((string) $run->role_label) : '';
        if ($role_label !== '') {
            return $role_label;
        }

        return $this->get_role_label(is_object($run) && !empty($run->role_key) ? (string) $run->role_key : '');
    }

    private function get_operation_label($operation) {
        $labels = array('add' => __('Add', 'square-loyalty-points'), 'deduct' => __('Remove', 'square-loyalty-points'), 'set' => __('Set', 'square-loyalty-points'));
        $operation = sanitize_key((string) $operation);
        return isset($labels[$operation]) ? $labels[$operation] : ucfirst(str_replace('_', ' ', $operation));
    }

    private function format_points($points) {
        $points = (int) $points;
        $label = abs($points) === 1 ? $this->get_label_singular() : $this->get_label_plural();
        return number_format_i18n($points) . ' ' . $label;
    }

    private function format_signed_points($points) {
        $points = (int) $points;
        $prefix = $points > 0 ? '+' : ($points < 0 ? '-' : '');
        $label = abs($points) === 1 ? $this->get_label_singular() : $this->get_label_plural();
        return $prefix . number_format_i18n(abs($points)) . ' ' . $label;
    }

    private function format_square_datetime($value, $date_only = false) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($value);
            $date = $date->setTimezone(wp_timezone());
            return $date_only ? $date->format(get_option('date_format')) : $date->format(get_option('date_format') . ' ' . get_option('time_format'));
        } catch (Exception $e) {
            return $value;
        }
    }

    private function format_square_expiry_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
                $date = new DateTimeImmutable($matches[1], wp_timezone());
            } else {
                $date = new DateTimeImmutable($value);
                $date = $date->setTimezone(wp_timezone());
            }

            // Square returns the cutoff instant; its dashboard shows the last usable calendar day.
            return $date->modify('-1 day')->format(get_option('date_format'));
        } catch (Exception $e) {
            return $value;
        }
    }

    private function format_local_date_short($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($value, wp_timezone());
            return $date->format('M j');
        } catch (Exception $e) {
            return $value;
        }
    }

    private function format_local_date_only($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($value, wp_timezone());
            return $date->format(get_option('date_format'));
        } catch (Exception $e) {
            return $value;
        }
    }

    private function format_gmt_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $date->setTimezone(wp_timezone())->format(get_option('date_format') . ' ' . get_option('time_format'));
        } catch (Exception $e) {
            return $value;
        }
    }
}
