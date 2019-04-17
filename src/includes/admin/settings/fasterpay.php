<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings for FasterPay Gateway
 */
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
