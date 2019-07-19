<?php

defined('ABSPATH') or exit();
/*
 * Plugin Name: FasterPay for WooCommerce
 * Plugin URI:
 * Description: Official FasterPay module for WordPress WooCommerce.
 * Version: 1.1.0
 * Author: The FasterPay Team
 * Author URI: https://www.fasterpay.com/
 * Text Domain: fasterpay-for-woocommerce
 * License: The MIT License (MIT)
 *
 */

define('FP_TEXT_DOMAIN', 'fasterpay-for-woocommerce');
define('FP_DEFAULT_SUCCESS_PINGBACK_VALUE', 'OK');
define('FP_ORDER_STATUS_PENDING', 'wc-pending');
define('FP_ORDER_STATUS_COMPLETED', 'wc-completed');
define('FP_ORDER_STATUS_PROCESSING', 'wc-processing');
define('FP_ORDER_STATUS_CANCELLED', 'wc-cancelled');
define('FP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FP_PLUGIN_URL', plugins_url('', __FILE__));

function fasterpay_subscription_enable(){
    return class_exists('WC_Subscriptions_Order');
}

function load_fasterpay_payments() {
    if (!class_exists('WC_Payment_Gateway')) return; // Nothing happens here is WooCommerce is not loaded

    include(FP_PLUGIN_PATH . '/lib/fasterpay-php/lib/autoload.php');
    include(FP_PLUGIN_PATH . '/includes/class-fasterpay-abstract.php');
    include(FP_PLUGIN_PATH . '/includes/class-fasterpay-gateway.php');

    function fasterpay_payments($methods) {
        $methods[] = 'FasterPay_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'fasterpay_payments');
}

add_action('plugins_loaded', 'load_fasterpay_payments', 0);

/**
 * Add FasterPay Scripts
 */
function fasterpay_scripts() {
    wp_register_script('placeholder', FP_PLUGIN_URL . '/assets/js/payment.js', array('jquery'), '1', true);
    wp_enqueue_script('placeholder');
}

add_action('wp_enqueue_scripts', 'fasterpay_scripts');

/**
 * Require the woocommerce plugin installed first
 */
function fp_child_plugin_notice() {
    ?>
    <div class="error">
        <p><?php echo __("Sorry, but FasterPay Plugin requires the Woocommerce plugin to be installed and active.", FP_TEXT_DOMAIN)?></p>
    </div>
    <?php
}

function fp_child_plugin_has_parent_plugin() {
    if (is_admin() && current_user_can('activate_plugins') && !is_plugin_active('woocommerce/woocommerce.php')) {
        add_action('admin_notices', 'fp_child_plugin_notice');

        deactivate_plugins(plugin_basename(__FILE__));
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}

add_action('admin_init', 'fp_child_plugin_has_parent_plugin');


