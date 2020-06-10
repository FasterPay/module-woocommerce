<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings for FasterPay Gateway
 */
$defaultPingbackUrl = get_site_url() . '/?wc-api=fasterpay_gateway&action=ipn';
return array(
    'enabled' => array(
        'title' => __('Enable/Disable', FP_TEXT_DOMAIN),
        'type' => 'checkbox',
        'label' => __('Enable the FasterPay Payment Solution', FP_TEXT_DOMAIN),
        'default' => 'yes'
    ),
    'title' => array(
        'title' => __('Title', FP_TEXT_DOMAIN),
        'type' => 'text',
        'description' => __('This controls the title which the user sees during checkout.', FP_TEXT_DOMAIN),
        'default' => __('FasterPay', FP_TEXT_DOMAIN)
    ),
    'description' => array(
        'title' => __('Description', FP_TEXT_DOMAIN),
        'type' => 'text',
        'description' => __('This controls the description which the user sees during checkout.', FP_TEXT_DOMAIN),
        'default' => __("Pay via FasterPay.", FP_TEXT_DOMAIN)
    ),
    'public_key' => array(
        'title' => __('Public Key', FP_TEXT_DOMAIN),
        'type' => 'text',
        'description' => __('Your FasterPay Public Key', FP_TEXT_DOMAIN),
        'default' => ''
    ),
    'private_key' => array(
        'title' => __('Private Key', FP_TEXT_DOMAIN),
        'type' => 'text',
        'description' => __('Your FasterPay Private Key', FP_TEXT_DOMAIN),
        'default' => ''
    ),
    'success_url' => array(
        'title' => __('Success URL', FP_TEXT_DOMAIN),
        'type' => 'text',
        'description' => __('URL to redirect the customer once the payment is sucessful. Default: [Woocommerce success url]', FP_TEXT_DOMAIN),
        'default' => ''
    ),
    'allow_pingback_url' => array(
        'title' => __('Enable custom pingback URL', FP_TEXT_DOMAIN),
        'type' => 'checkbox',
        'name' => 'allow_pingback_url',
        'class'	=> 'activate_pingback',
    ),
    'pingback_url' => array(
        'title' => __('Custom pingback URL', FP_TEXT_DOMAIN),
        'type' => 'text',
        'description' => sprintf(__('Keep it blank to use default URL: [%s]', FP_TEXT_DOMAIN), $defaultPingbackUrl),
    ),
    'test_mode' => array(
        'title' => __('Test Mode', FP_TEXT_DOMAIN),
        'type' => 'select',
        'description' => __('Enable test mode', FP_TEXT_DOMAIN),
        'options' => array(
            '0' => 'No',
            '1' => 'Yes'
        ),
        'default' => '0'
    ),
);
