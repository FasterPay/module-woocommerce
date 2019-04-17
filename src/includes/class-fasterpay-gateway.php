<?php
/*
 * FasterPay Gateway for WooCommerce
 *
 * Description: Official FasterPay module for WordPress WooCommerce.
 * Plugin URI:
 * Author: FasterPay
 * License: The MIT License (MIT)
 *
 */
class FasterPay_Gateway extends FasterPay_Abstract {

    const YEAR_PERIOD = 'y';
    const API_BASE_URL = 'https://pay.fasterpay.com';
    const API_SANDBOX_BASE_URL = 'https://pay.sandbox.fasterpay.com';
    const GATEWAY_ID = 'fasterpay';

    public $id;
    public $has_fields = true;

    public function __construct() {
        $this->id = self::GATEWAY_ID;
        $this->supports = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_suspension',
            'subscription_cancellation',
        );

        parent::__construct();

        if (is_file(FP_PLUGIN_PATH . '/assets/images/logo.png')) {
            $this->icon = FP_PLUGIN_URL . '/assets/images/logo.png';
        } else {
            $this->title = $this->settings['title'];
        }

        $this->mcIcon = FP_PLUGIN_URL .'/assets/images/mc.svg';
        $this->visaIcon = FP_PLUGIN_URL . '/assets/images/visa.svg';

        $this->method_title = __('FasterPay', FP_TEXT_DOMAIN);
        $this->method_description = __('Enables the FasterPay Payment Solution. The easiest way to monetize your game or web service globally.', FP_TEXT_DOMAIN);
        $this->notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'FasterPay_Gateway', home_url('/')));

        // Our Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_' . $this->id . '_gateway', array($this, 'handle_action'));

        add_action('woocommerce_subscription_cancelled_' . $this->id, array($this, 'cancel_subscription_action'));
        add_filter('woocommerce_subscription_payment_gateway_supports', array($this, 'add_feature_support_for_subscription'), 11, 3);

        add_filter( 'wc_get_template', __CLASS__ . '::add_view_subscription_template', 10, 5 );
    }

    /**
     * @param $order_id
     */
    function receipt_page($order_id) {
        if (WC()->cart->is_empty()) {
            wc_add_notice('Empty Cart', 'error');
            return;
        }
        $order = wc_get_order($order_id);
        $orderData = $this->get_order_data($order);

        $params = array(
            'description' => 'Order #' . $orderData['order_id'],
            'amount' => $orderData['total'],
            'currency' => $orderData['currencyCode'],
            'merchant_order_id' => $orderData['order_id'],
            'success_url' => !empty($this->settings['success_url']) ? $this->settings['success_url'] : $order->get_checkout_order_received_url(),
            'module_source' => 'woocommerce'
        );

        try {
            if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                $params = array_merge($params, $this->prepare_subscription_data($order));
            }
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
        }

        $gateway = new FasterPay\Gateway(array(
            'publicKey' 	=> $this->settings['public_key'],
            'privateKey'	=> $this->settings['private_key'],
            'isTest'        => $this->settings['test_mode'],
        ));

        $form = $gateway->paymentForm()->buildForm($params);
        // Clear shopping cart
        WC()->cart->empty_cart();
        echo $form;
        return;
    }

    function prepare_subscription_data(WC_Order $order) {
        $params = array();
        $orderData = $this->get_order_data($order);

        $subscription = wcs_get_subscriptions_for_order($order);
        $subscription = reset($subscription); // The current version does not support multi subscription
        $subsData = $this->get_recurring_data($subscription);

        if ($this->standardizePeriod($subsData['billing_period']) == FasterPay_Gateway::YEAR_PERIOD) {
            $subsData['billing_period'] = 'month';
            $subsData['billing_interval'] *= 12;
        }

        $params['recurring_name'] = sprintf(__('Order #%s - recurring payment', FP_TEXT_DOMAIN), $order->get_order_number());
        $params['recurring_sku_id'] = sprintf(__('recurring_%s', FP_TEXT_DOMAIN), $order->get_order_number());
        $params['amount'] = WC_Subscriptions_Order::get_recurring_total($order);

        $params['recurring_period'] = $subsData['billing_interval'] . $this->standardizePeriod($subsData['billing_period']);

        if (!empty($subsData['schedule_trial_end'])) { // has trial
            $params['recurring_trial_amount'] = $orderData['total'];
            $params['recurring_trial_period'] = $this->s_datediff($subsData['trial_period'], $subsData['schedule_trial_end'], $subsData['date_created']) . $this->standardizePeriod($subsData['trial_period']);
            if (!empty($subsData['schedule_end'])) {
                $params['recurring_duration'] = $this->s_datediff($subsData['billing_period'], $subsData['schedule_end'], $subsData['schedule_next_payment']) . $this->standardizePeriod($subsData['billing_period']);
            }
        } else {
            if ($orderData['total'] != WC_Subscriptions_Order::get_recurring_total($order)) { // has setup fee
                $params['recurring_trial_period'] = 1 . $this->standardizePeriod($subsData['billing_period']);
                $params['recurring_trial_amount'] = $orderData['total'];
                if (!empty($subsData['schedule_end'])) {
                    $params['recurring_duration'] = ($this->s_datediff($subsData['billing_period'], $subsData['schedule_end'], $subsData['date_created']) - 1 ). $this->standardizePeriod($subsData['billing_period']);
                }
            } else if (!empty($subsData['schedule_end'])) {
                $params['recurring_duration'] = $this->s_datediff($subsData['billing_period'], $subsData['schedule_end'], $subsData['date_created']) . $this->standardizePeriod($subsData['billing_period']);
            }
        }

        return $params;
    }

    /**
     * Process the order after payment is made
     * @param int $order_id
     * @return array
     */
    function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (version_compare('2.7', $this->wcVersion, '>')) {
            return array(
                'result' => 'success',
                'redirect' => add_query_arg(
                    'key',
                    $order->order_key,
                    add_query_arg(
                        'order',
                        $order->id,
                        $order->get_checkout_payment_url(true)
                    )
                )
            );
        } else {
            return array(
                'result' => 'success',
                'redirect' => add_query_arg(
                    'key',
                    $order->get_order_key(),
                    $order->get_checkout_payment_url(true)
                )
            );
        }


    }

    /**
     * Check the response from FasterPay's Servers
     */
    function ipn_response() {
        $pingbackData = $this->get_post_data();

        $iniOrderId = null;
        if (!empty($pingbackData['subscription'])) {
            $iniOrderId = $pingbackData['subscription']['init_merchant_order_id'];
        } else {
            $iniOrderId = $pingbackData['payment_order']['merchant_order_id'];
        }

        $order = wc_get_order($iniOrderId);

        if (!$order) {
            exit('The order is Invalid!');
        }

        if (!($paymentGateway = $this->validateGateway($order))) {
            exit('');
        }

        $gateway = new FasterPay\Gateway(array(
            'publicKey' 	=> $paymentGateway->settings['public_key'],
            'privateKey'	=> $paymentGateway->settings['private_key'],
        ));

        if($gateway->pingback()->validate(
            array("apiKey" => $this->get_header('HTTP_X_APIKEY')))
        ){
            #TODO: Write your code to deliver contents to the End-User.

            if ($pingbackData['payment_order']['status'] == 'successful') {
                if ($order->get_status() == FP_ORDER_STATUS_PROCESSING) {
                    die(FP_DEFAULT_SUCCESS_PINGBACK_VALUE);
                }

                if(fasterpay_subscription_enable()) {
                    $subscriptions = wcs_get_subscriptions_for_order( $iniOrderId, array( 'order_type' => 'parent' ) );
                    $subscription  = array_shift( $subscriptions );
                    $subscription_key = get_post_meta($iniOrderId, '_payment_order_id');
                }

                $fpPaymentId = $pingbackData['payment_order']['id'];
                if (!empty($pingbackData['subscription'])) {
                    if (!empty($pingbackData['subscription']['recurring_id']) && $pingbackData['subscription']['recurring_id'] != $fpPaymentId && (isset($subscription_key[0]) && $subscription_key[0] == $pingbackData['subscription']['recurring_id'])) { // recurring payment
                        $subscription->update_status('on-hold');
                        $subscription->add_order_note(__('Subscription renewal payment due: Status changed from Active to On hold.', FP_TEXT_DOMAIN));
                        $order = wcs_create_renewal_order( $subscription );
                        $order->set_payment_method($subscription->payment_gateway);
                    } else { // first payment
                        $order->add_order_note(sprintf(__('FasterPay subscription payment approved (ID: %s)', FP_TEXT_DOMAIN), $fpPaymentId));
                    }
                    update_post_meta( !method_exists($order, 'get_id') ? $order->id : $order->get_id(), '_payment_order_id', $fpPaymentId);
                    update_post_meta( !method_exists($subscription, 'get_id') ? $subscription->id : $subscription->get_id(), 'fp_transaction_id', $pingbackData['subscription']['id']);
                }

                $order->add_order_note(__('Payment approved by FasterPay - Transaction Id: ' . $fpPaymentId, FP_TEXT_DOMAIN));
                $order->payment_complete($fpPaymentId);

                if (!empty($subscriptions)) {
                    $action_args = array('subscription_id' => !method_exists($subscription, 'get_id') ? $subscription->id : $subscription->get_id());
                    $hooks = array(
                        'woocommerce_scheduled_subscription_payment',
                    );

                    foreach($hooks as $hook) {
                        $result = wc_unschedule_action($hook, $action_args);
                    }
                }
            }


            exit(FP_DEFAULT_SUCCESS_PINGBACK_VALUE);
        }

        exit();
    }

    /**
     * Process Ajax Request
     */
    function ajax_response() {
        $order = wc_get_order(intval($_POST['order_id']));
        $return = array(
            'status' => false,
            'url' => ''
        );

        if ($order) {
            if ($order->get_status() == FP_ORDER_STATUS_PROCESSING) {
                WC()->cart->empty_cart();
                $return['status'] = true;
                $return['url'] = get_permalink(wc_get_page_id('checkout')) . '/order-received/' . intval($_POST['order_id']) . '?key=' . $order->post->post_password;
            }
        }
        die(json_encode($return));
    }

    /**
     * Handle Action
     */
    function handle_action() {
        switch ($_GET['action']) {
            case 'ajax':
                $this->ajax_response();
                break;
            case 'ipn':
                $this->ipn_response();
                break;
            default:
                break;
        }
    }

    /**
     * @param $is_supported
     * @param $feature
     * @param $subscription
     * @return bool
     */
    public function add_feature_support_for_subscription($is_supported, $feature, $subscription) {
        if ($this->id === $subscription->get_payment_method()) {

            if ('gateway_scheduled_payments' === $feature) {
                $is_supported = false;
            } elseif (in_array($feature, $this->supports)) {
                $is_supported = true;
            }
        }
        return $is_supported;
    }

    /**
     * Cancel subscription
     *
     * @param $subscription
     */
    public function cancel_subscription_action($subscription) {
        $subscriptionId = get_post_meta(!method_exists($subscription, 'get_id') ? $subscription->id : $subscription->get_id(), 'fp_transaction_id');
        if (empty($subscriptionId)) {
            return;
        }

        $order_id = !method_exists($subscription, 'get_id') ? $subscription->order->id : $subscription->order->get_id();
        if (!($gateway = $this->validateGateway(wc_get_order($order_id)))) {
            return;
        }

        if ($gateway->settings['test_mode']) {
            $url = self::API_SANDBOX_BASE_URL;
        } else {
            $url = self::API_BASE_URL;
        }

        $url .= '/api/subscription/'.$subscriptionId[0].'/cancel';
        $result = $this->httpAction('POST', $url, array(), array('X-ApiKey: ' .$this->settings['private_key']));
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!($gateway = $this->validateGateway($order))) {
            return new WP_Error('order_is_invalid', __('Wrong payment method', FP_TEXT_DOMAIN), 404);
        }
        if (!$order) {
            return new WP_Error('order_is_invalid', __('The order is Invalid!', FP_TEXT_DOMAIN), 404);
        }

        if ($gateway->settings['test_mode']) {
            $url = self::API_SANDBOX_BASE_URL;
        } else {
            $url = self::API_BASE_URL;
        }
        $url .= '/payment/'.$order->get_transaction_id().'/refund';

        $result = $this->httpAction('POST', $url, array('amount' => $amount), array('X-ApiKey: ' .$this->settings['private_key']));
        $result = json_decode($result, true);

        if ($result['success']) {
            return true;
        }

        return new WP_Error('fp_refund_failed', __(strip_tags($result['message']), FP_TEXT_DOMAIN), 404);


    }

    public function httpAction($requestType = 'GET', $url = '', $params = array(), $headers = array()) {
        $curl = curl_init();

        // CURL_SSLVERSION_TLSv1_2 is defined in libcurl version 7.34 or later
        // but unless PHP has been compiled with the correct libcurl headers it
        // won't be defined in your PHP instance.  PHP > 5.5.19 or > 5.6.3
        if (! defined('CURL_SSLVERSION_TLSv1_2')) {
            define('CURL_SSLVERSION_TLSv1_2', 6);
        }

        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }

        if (!empty($params)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $requestType);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, true);

        $response = curl_exec($curl);

        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        curl_close($curl);
        return $this->prepareHttpResponse($body);
    }

    protected function prepareHttpResponse($string = '')
    {
        return preg_replace('/\x{FEFF}/u', '', $string);
    }

    public function validateGateway($order) {
        $paymentGateway = wc_get_payment_gateway_by_order($order);

        if ($paymentGateway->id == $this->id) {
            return $paymentGateway;
        }
        return false;
    }

    public function add_view_subscription_template( $located, $template_name, $args, $template_path, $default_path ) {
        global $wp;

        if ($template_name == 'checkout/payment-method.php' && (!empty($args['gateway']) && $args['gateway']->id == self::GATEWAY_ID)) {
            $located = wc_locate_template( 'checkout/payment-method.php', $template_path, FP_PLUGIN_PATH . 'templates/' );
        }

        return $located;
    }
}
