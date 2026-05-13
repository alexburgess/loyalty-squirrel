<?php
/**
 * Plugin Name: Loyalty Squirrel
 * Plugin URI: https://example.com/
 * Description: Assign and review Square Loyalty points for WooCommerce customers from WordPress.
 * Version: 1.0.3
 * Author: Alex Burgess
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * Text Domain: square-loyalty-points
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SQUARE_LOYALTY_POINTS_VERSION', '1.0.3');
define('SQUARE_LOYALTY_POINTS_DB_VERSION', '1');
define('SQUARE_LOYALTY_POINTS_PLUGIN_FILE', __FILE__);
define('SQUARE_LOYALTY_POINTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SQUARE_LOYALTY_POINTS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SQUARE_LOYALTY_POINTS_PLUGIN_DIR . 'includes/class-square-loyalty-points-manager.php';
require_once SQUARE_LOYALTY_POINTS_PLUGIN_DIR . 'includes/class-square-loyalty-points-square-api.php';
require_once SQUARE_LOYALTY_POINTS_PLUGIN_DIR . 'includes/class-square-loyalty-points-plugin.php';

function square_loyalty_points_plugin() {
    return Square_Loyalty_Points_Plugin::instance();
}

register_activation_hook(SQUARE_LOYALTY_POINTS_PLUGIN_FILE, array('Square_Loyalty_Points_Plugin', 'activate'));
register_deactivation_hook(SQUARE_LOYALTY_POINTS_PLUGIN_FILE, array('Square_Loyalty_Points_Plugin', 'deactivate'));

add_action('plugins_loaded', 'square_loyalty_points_plugin');
