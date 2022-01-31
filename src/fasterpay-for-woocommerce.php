<?php

defined('ABSPATH') or exit();
/*
 * Plugin Name: FasterPay for WooCommerce
 * Plugin URI:
 * Description: Official FasterPay module for WordPress WooCommerce.
 * Version: 1.3.3
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
define('FP_ORDER_STATUS_REFUNDED', 'wc-refunded');
define('FP_ORDER_STATUS_CANCELLED', 'wc-cancelled');
define('FP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FP_PLUGIN_URL', plugins_url('', __FILE__));
define('FP_DELIVERY_STATUS_ORDER_PLACED', 'order_placed');
define('FP_DELIVERY_STATUS_ORDER_SHIPPED', 'order_shipped');
define('FP_DELIVERY_STATUS_DELIVERED', 'delivered');

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
    wp_register_script('fasterpay_script', FP_PLUGIN_URL . '/assets/js/payment.js', array('jquery'), '1', true);
    wp_enqueue_script('fasterpay_script');
}

add_action('wp_enqueue_scripts', 'fasterpay_scripts');

/**
 * Add FasterPay style in admin section
 */
function fasterpay_admin_scripts() {
    $screen = get_current_screen();
    if ($screen->id == 'woocommerce_page_wc-settings') {
        wp_register_script('fasterpay_admin_script', FP_PLUGIN_URL . '/assets/js/admin_script.js', array(), '1', true);
        wp_enqueue_script('fasterpay_admin_script');
    }
}

add_action('admin_enqueue_scripts', 'fasterpay_admin_scripts');

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

add_action('updated_postmeta', 'fp_on_order_tracking_change', 10, 4);

add_action('added_post_meta', 'fp_on_order_tracking_change', 10, 4);

function fp_on_order_tracking_change($meta_id, $post_id, $meta_key, $meta_value) {
    if ($meta_key != '_wc_shipment_tracking_items' || empty($meta_value)) {
        return;
    }

    $tracking_data = $meta_value;

    if (!is_array($meta_value)) {
        $tracking_data = unserialize($meta_value);
    }

    if (empty($tracking_data) || empty($tracking_data[count($tracking_data) - 1])) {
        return;
    }

    $tracking_data = $tracking_data[0];

    $order = wc_get_order($post_id);
    if (!$order) {
        return;
    }

    // if is virtual
    if (fp_is_virtual($order)) {
        return;
    }

    $gateway = fp_get_order_fasterpay_gateway($order);
    if (!$gateway || $gateway->id != FasterPay_Gateway::GATEWAY_ID) {
        return;
    }

    fp_update_delivery_status($order, FP_DELIVERY_STATUS_ORDER_SHIPPED, $tracking_data);
}

function fp_update_delivery_status(WC_Order $order, $status, $tracking_data = null) {
    try {
        $gateway = fp_get_order_fasterpay_gateway($order);
        if (!$gateway) {
            throw new \Exception('Wrong order gateway');
        }

        $fpGateway = new FasterPay\Gateway([
            'publicKey' => $gateway->settings['public_key'],
            'privateKey' => $gateway->settings['private_key'],
            'isTest' => $gateway->settings['test_mode'],
        ]);

        $data = fp_prepare_delivery_data($order, $status, $fpGateway, $tracking_data);
        $endPoint = $fpGateway->getConfig()->getApiBaseUrl() . '/api/v1/deliveries';
        $response = wp_remote_post($endPoint, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($data)
        ]);
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

function fp_prepare_delivery_data(WC_Order $order, $status, FasterPay\Gateway $fpGateway, $tracking_data = null) {
    $data = [
        "payment_order_id" => $order->get_transaction_id(),
        "merchant_reference_id" => (string)fp_get_wc_order_id($order),
        "sign_version" => FasterPay\Services\Signature::SIGN_VERSION_2,
        "status" => $status,
        "refundable" => true,
        "details" => 'woocommerce delivery action',
        "reason" => $order->get_customer_note() ?: "None",
        "estimated_delivery_datetime" => !empty($tracking_data['date_shipped']) ? date('Y-m-d H:i:s O', $tracking_data['date_shipped']) : date('Y-m-d H:i:s O'),
        "carrier_tracking_id" => !empty($tracking_data['tracking_number']) ? $tracking_data['tracking_number'] : "N/A",
        "carrier_type" => !empty($tracking_data['tracking_provider']) ? $tracking_data['tracking_provider'] : (!empty($tracking_data['custom_tracking_provider']) ? $tracking_data['custom_tracking_provider'] : "N/A"),
        "shipping_address" => [
            "country_code" => $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country(),
            "city" => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
            "zip" => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
            "state" => $order->get_shipping_state() ? $order->get_shipping_state() : ($order->get_billing_state() ?: 'N/A'),
            "street" => $order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
            "phone" => $order->get_billing_phone(),
            "first_name" => $order->get_shipping_first_name() ? $order->get_shipping_first_name() : $order->get_billing_first_name(),
            "last_name" => $order->get_shipping_last_name() ? $order->get_shipping_last_name() : $order->get_billing_last_name(),
            "email" => $order->get_billing_email()
        ],
        "attachments" => ['N/A'],
        "type" => !fp_is_virtual($order) ? "physical" : "digital",
        "public_key" => $fpGateway->getConfig()->getPublicKey(),
    ];
    
    if (!empty($tracking_data['custom_tracking_link'])) {
        $data["carrier_tracking_url"] = $tracking_data['custom_tracking_link'];
    }

    $data["hash"] = $fpGateway->signature()->calculateWidgetSignature($data);

    return $data;
}

function fp_get_order_fasterpay_gateway(WC_Order $order) {
    $paymentGateway = wc_get_payment_gateway_by_order($order);

    if ($paymentGateway->id == FasterPay_Gateway::GATEWAY_ID) {
        return $paymentGateway;
    }
    return false;
}

function fp_get_wc_order_id(WC_Order $order) {
    return !method_exists($order, 'get_id') ? $order->id : $order->get_id();
}

function fp_order_tracking($order_id) {
    $tracking_data = get_post_meta($order_id, '_wc_shipment_tracking_items', true);

    if (empty($tracking_data)) {
        return false;
    }

    $tracking_data = unserialize($tracking_data);

    if (empty($tracking_data)) {
        return false;
    }

    $tracking_data = $tracking_data[0];

    return $tracking_data;
}

function fp_is_virtual(WC_Order $order) {
    $items = $order->get_items();
    foreach ($items as $item) {
        if ($item->is_type('line_item')) {
            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            if ($product->is_virtual()) {
                return true;
            }
        }
    }

    return false;
}
