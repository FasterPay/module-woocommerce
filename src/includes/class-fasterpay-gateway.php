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

    const BEFORE_CREATE_REFUND_IN_PINGBACK_HOOK = 'fp_before_wc_create_refund_in_refund_pingback';
    const YEAR_PERIOD = 'y';
    const GATEWAY_ID = 'fasterpay';
    const PAYMENT_EVENT = 'payment';
    const PAYMENT_ORDER_SUCCESS_STATUS = 'successful';
    const REFUND_EVENT = [
        'partial_refund',
        'refund'
    ];
    const REFUND_PAYMENT_ORDER_SUCCESS_STATUS = [
        'reversal_refunded_partially',
        'reversal_refunded'
    ];
    const MERCHANT_ORDER_ID_DELIMITER = '--#--';

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
            'subscription_reactivation',
        );

        parent::__construct();

        if (false && is_file(FP_PLUGIN_PATH . '/assets/images/logo.png')) {
            $this->icon = FP_PLUGIN_URL . '/assets/images/logo.png';
        } else {
            $this->title = $this->settings['title'];
        }

        $this->mcIcon = FP_PLUGIN_URL . '/assets/images/mc.svg';
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

        add_filter( 'wc_get_template', array($this, 'add_view_subscription_template'), 10, 5 );
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
        $suffixizedMerchantOrderId = $this->suffixize_merchant_order_id($orderData['order_id']);

        $params = array(
            'description' => 'Order #' . $orderData['order_id'],
            'amount' => $orderData['total'],
            'currency' => $orderData['currencyCode'],
            'merchant_order_id' => $suffixizedMerchantOrderId,
            'success_url' => !empty($this->settings['success_url']) ? $this->settings['success_url'] : $order->get_checkout_order_received_url(),
            'module_source' => 'woocommerce',
            'sign_version' => FasterPay\Services\Signature::SIGN_VERSION_2
        );

        if ($this->settings['allow_pingback_url'] == 'yes') {
            $pingback = array(
                'pingback_url' => !empty($this->settings['pingback_url']) ? $this->settings['pingback_url'] : $this->getDefaultPingbackUrl(),
            );
            $params = wp_parse_args($pingback, $params);
        }

        $order->add_order_note(sprintf(__('Payment was sent to FasterPay with reference Number: %s', FP_TEXT_DOMAIN),  $suffixizedMerchantOrderId));

        try {
            if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                $params = array_merge($params, $this->prepare_subscription_data($order));
            }
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
        }

        $gateway = new FasterPay\Gateway(array(
            'publicKey' => $this->settings['public_key'],
            'privateKey' => $this->settings['private_key'],
            'isTest' => $this->settings['test_mode'],
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
    function ipn_response()
    {
        try {
            $signVersion = $this->getSignatureVersion();
            $pingbackData = null;
            switch ($signVersion) {
                case FasterPay\Services\Signature::SIGN_VERSION_1:
                    $validationParams = ["apiKey" => $this->get_header("HTTP_X_APIKEY")];
                    $pingbackData = $this->get_post_data();
                    break;
                case FasterPay\Services\Signature::SIGN_VERSION_2:
                    $rawPostData = file_get_contents('php://input');
                    $validationParams = [
                        'pingbackData' => $rawPostData,
                        'signVersion' => $signVersion,
                        'signature' => $this->get_header("HTTP_X_FASTERPAY_SIGNATURE"),
                    ];
                    $pingbackData = json_decode($rawPostData, 1);
                    break;
                default:
                    throw new Exception('NOK - Wrong sign version - ' . $signVersion);
            }

            if (empty($pingbackData)) {
                throw new Exception('NOK - Empty pingback data');
            }

            $order = $this->getWcRootOrder($pingbackData);

            if (!$order) {
                throw new Exception('NOK - The order is Invalid!');
            }

            if (!($paymentGateway = $this->getPaymentGatewayFromOrder($order))) {
                throw new Exception('NOK - Wrong Payment Gateway');
            }

            $gateway = new FasterPay\Gateway(array(
                'publicKey' => $paymentGateway->settings['public_key'],
                'privateKey' => $paymentGateway->settings['private_key'],
                'isTest' => $paymentGateway->settings['test_mode'],
            ));

            if (!$gateway->pingback()->validate($validationParams)) {
                throw new Exception('NOK - Invalid Pingback');
            }

            if ($this->isPaymentEvent($pingbackData)) {
                $this->processPaymentEvent($order, $pingbackData);
            } elseif ($this->isRefundEvent($pingbackData)) {
                $this->processRefundEvent($order, $pingbackData);
            } else {
                throw new Exception('NOK - Invalid pingback event');
            }

            exit(FP_DEFAULT_SUCCESS_PINGBACK_VALUE);
        } catch (Exception $e) {
            exit('NOK - ' . $e->getMessage());
        }
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
     * @param $order_id
     * @return string
     */
    public function suffixize_merchant_order_id($order_id) {
        $suffixizedMerchantOrderId = $order_id . self::MERCHANT_ORDER_ID_DELIMITER . preg_replace('#^https?://#', '', get_site_url());
        return $suffixizedMerchantOrderId;
    }

    /**
     * @param $merchant_order_id
     * @return mixed|string
     */
    public function unsuffixize_merchant_order_id($merchant_order_id) {
        $unsuffixize = explode(self::MERCHANT_ORDER_ID_DELIMITER, $merchant_order_id);
        return $unsuffixize[0];
    }

    /**
     * @return string
     */
    protected function getDefaultPingbackUrl() {
        $pingback_url = get_site_url() . '/?wc-api=fasterpay_gateway&action=ipn';
        return $pingback_url;
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
            return new WP_Error('subscription_is_invalid', __('Subscription not found', FP_TEXT_DOMAIN), 404);
        }

        $order_id = !method_exists($subscription, 'get_id') ? $subscription->order->id : $subscription->order->get_id();
        if (!($gateway = $this->validateGateway(wc_get_order($order_id)))) {
            return new WP_Error('payment_method_is_invalid', __('Wrong payment method', FP_TEXT_DOMAIN), 404);
        }

        $fpGateway = new FasterPay\Gateway([
            'publicKey' => $gateway->settings['public_key'],
            'privateKey' => $gateway->settings['private_key'],
            'isTest' => $gateway->settings['test_mode'],
        ]);

        try {
            $cancellationResponse = $fpGateway->subscriptionService()->cancel($subscriptionId[0]);
        } catch (FasterPay\Exception $e) {
            return new WP_Error('fp_cancel_subscription_failed', __(strip_tags($e->getMessage()), FP_TEXT_DOMAIN), 404);
        }

        if ($cancellationResponse->isSuccessful()) {
            return true;
        }

        return new WP_Error('fp_cancel_subscription_failed', __(strip_tags($cancellationResponse->getErrors()->getMessage()), FP_TEXT_DOMAIN), 404);
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!($gateway = $this->getPaymentGatewayFromOrder($order))) {
            return new WP_Error('order_is_invalid', __('Wrong payment method', FP_TEXT_DOMAIN), 404);
        }
        if (!$order) {
            return new WP_Error('order_is_invalid', __('The order is Invalid!', FP_TEXT_DOMAIN), 404);
        }

        $fpGateway = new FasterPay\Gateway([
            'publicKey' => $gateway->settings['public_key'],
            'privateKey' => $gateway->settings['private_key'],
            'isTest' => $gateway->settings['test_mode'],
        ]);

        $orderId = $order->get_transaction_id();

        if (!$this->isRefundPingbackCalled()) {
            $result = wp_remote_post(
                $fpGateway->getConfig()->getApiBaseUrl() . '/payment/' . $orderId . '/refund',
                [
                    'headers' => [
                        'content-type' => 'application/json',
                        'x-apikey' => $fpGateway->getConfig()->getPrivateKey()
                    ],
                    'body' => json_encode(['amount' => $amount]),
                    'blocking' => false
                ]
            );

            if (is_wp_error($result)) {
                return $result;
            }

            return new WP_Error('fp_refund_proccessing', __(strip_tags('Your refund transaction is being processed'), FP_TEXT_DOMAIN), 404);
        }

        return true;
    }

    public function validateGateway($order) {
        $paymentGateway = wc_get_payment_gateway_by_order($order);

        if ($paymentGateway->id == $this->id) {
            return $paymentGateway;
        }
        return false;
    }


    public function getPaymentGatewayFromOrder($order) {
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

    protected function processPaymentEvent($order, $pingbackData) {
        if (!$this->isPaymentPingbackSuccess($pingbackData)) {
            return;
        }

        if (!$this->validatePaymentPingbackData($pingbackData)) {
            throw new Exception('Invalid pingback data!');
        }

        if (
            ($this->isOneTimePayment($pingbackData) || $this->isInitSubscriptionPayment($pingbackData))
            && !$this->canProcessPaymentOrder($order)
        ) {
            return;
        }

        $fpPaymentId = $this->getFpPaymentOrderId($pingbackData);
        if ($this->isSubscriptionPayment($pingbackData)) {
            if (!fasterpay_subscription_enable()) {
                throw new Exception('Subscription disabled!');
            }
            return $this->processSubscriptionPayment($order, $pingbackData);
        }
        $this->processOneTimePayment($order, $pingbackData);

        // call delivery api
        // if physical
        if (!fp_is_virtual($order)) {
            $status = FP_DELIVERY_STATUS_ORDER_PLACED;
        } else {
            $status = FP_DELIVERY_STATUS_DELIVERED;
        }
        fp_update_delivery_status($order, $status);
    }

    protected function processRefundEvent($order, $pingbackData) {
        if (!$this->isRefundPingbackSuccess($pingbackData)) {
            return;
        }

        if (!$this->validateRefundPingbackData($pingbackData)) {
            throw new Exception('Invalid pingback data!');
        }

        if (!$this->canProcessRefundOrder($order)) {
            return;
        }

        $fpPaymentId = $this->getFpPaymentOrderId($pingbackData);
        $orderId = $this->getFpPaymentMerchantOrderId($pingbackData);
        $refundAmount = $pingbackData['payment_order']['refund_amount'];
        $refId = $pingbackData['payment_order']['reference_id'];
        $args = [
            'amount' => $refundAmount,
            'reason' => null,
            'order_id' => $orderId,
            'line_items' => [],
            'refund_payment' => true,
            'restock_items' => false,
        ];
        do_action(self::BEFORE_CREATE_REFUND_IN_PINGBACK_HOOK);
        $refund = wc_create_refund($args);

        if (is_wp_error($refund)) {
            throw new Exception($refund->get_error_message());
        }

        $order->add_order_note(sprintf(__('Refund approved by FasterPay - Transaction Id: %1$s - Ref Id: %2$s', FP_TEXT_DOMAIN),
            $fpPaymentId, $refId));
    }

    protected function processOneTimePayment($order, $pingbackData) {
        $fpPaymentId = $this->getFpPaymentOrderId($pingbackData);
        $order->add_order_note(sprintf(__('Payment approved by FasterPay - Transaction Id: %s', FP_TEXT_DOMAIN), $fpPaymentId));
        $order->payment_complete($fpPaymentId);
    }

    protected function processSubscriptionPayment($order, $pingbackData) {
        $fpPaymentId = $this->getFpPaymentOrderId($pingbackData);
        $initOrderId = $this->getWcOrderId($order);
        $subscription = $this->getWcOrderSubscription($initOrderId);
        $subscriptionKey = $this->getSubscriptionKeyByOrderId($initOrderId);

        if (!$this->isInitSubscriptionPayment($pingbackData) && $subscriptionKey == $pingbackData['subscription']['recurring_id']) { // recurring payment
            $subscription->update_status('on-hold');
            $subscription->add_order_note(__('Subscription renewal payment due: Status changed from Active to On hold.',
                FP_TEXT_DOMAIN));
            $order = wcs_create_renewal_order($subscription);
            $order->set_payment_method($subscription->payment_gateway);
        } else {
            $order->add_order_note(sprintf(__('FasterPay subscription payment approved (ID: %s)',
                FP_TEXT_DOMAIN), $fpPaymentId));
        }
        $this->setSubscriptionKeyByOrderId($this->getWcOrderId($order), $fpPaymentId);
        $this->setFpSubscriptionId($this->getWcSubscriptionId($subscription), $pingbackData['subscription']['id']);

        $order->add_order_note(sprintf(__('Payment approved by FasterPay - Transaction Id: %s', FP_TEXT_DOMAIN),
            $fpPaymentId));
        $order->payment_complete($fpPaymentId);

        if (!empty($subscription)) {
            $action_args = array(
                'subscription_id' => $this->getWcSubscriptionId($subscription)
            );
            $hooks = array(
                'woocommerce_scheduled_subscription_payment',
            );

            foreach ($hooks as $hook) {
                $result = wc_unschedule_action($hook, $action_args);
            }
        }
    }

    protected function validatePaymentPingbackData($pingbackData) {
        if (
            empty($pingbackData['payment_order']['id'])
            || empty($pingbackData['payment_order']['merchant_order_id'])
            || empty($pingbackData['payment_order']['status'])
        ) {
            return false;
        }

        if (!empty($pingbackData['subscription'])) {
            return !(
                empty($pingbackData['subscription']['id'])
                || empty($pingbackData['subscription']['recurring_id'])
                || empty($pingbackData['subscription']['status'])
                || empty($pingbackData['subscription']['init_merchant_order_id'])
            );
        }

        return true;
    }

    protected function getWcOrderId($order) {
        return !method_exists($order, 'get_id') ? $order->id : $order->get_id();
    }

    protected function getWcSubscriptionId($subscription) {
        return !method_exists($subscription, 'get_id') ? $subscription->id : $subscription->get_id();
    }

    protected function validateRefundPingbackData($pingbackData) {
        return !(
            empty($pingbackData['payment_order']['id'])
            || empty($pingbackData['payment_order']['merchant_order_id'])
            || empty($pingbackData['payment_order']['status'])
            || empty($pingbackData['payment_order']['reference_id'])
            || empty($pingbackData['payment_order']['refund_amount'])
        );
    }

    protected function getWcOrderSubscription($orderId) {
        $subscriptions = wcs_get_subscriptions_for_order($orderId, array('order_type' => 'parent'));
        $subscription = array_shift($subscriptions);
        return $subscription;
    }

    protected function getFpInitMerchantOrderId($pingbackData) {
        $initOrderId = null;
        if (!empty($pingbackData['subscription'])) {
            $initOrderId = $pingbackData['subscription']['init_merchant_order_id'];
        } else {
            $initOrderId = $pingbackData['payment_order']['merchant_order_id'];
        }

        $initOrderId = $this->unsuffixize_merchant_order_id($initOrderId);

        return $initOrderId;
    }

    protected function getSignatureVersion() {
        $signVersion = $this->get_header('HTTP_X_FASTERPAY_SIGNATURE_VERSION');
        return !empty($signVersion) ? $signVersion : FasterPay\Services\Signature::SIGN_VERSION_1;
    }

    protected function getWcRootOrder($pingbackData) {
        $initOrderId = $this->getFpInitMerchantOrderId($pingbackData);
        $order = wc_get_order($initOrderId);
        return $order;
    }

    protected function getFpPaymentOrderId($pingbackData) {
        $paymentOrderId = null;
        if (!empty($pingbackData['payment_order']['id'])) {
            $paymentOrderId = $pingbackData['payment_order']['id'];
        }
        return $paymentOrderId;
    }

    protected function getFpPaymentMerchantOrderId($pingbackData) {
        $paymentMerchantOrderId = null;
        if (!empty($pingbackData['payment_order']['merchant_order_id'])) {
            $paymentMerchantOrderId = $this->unsuffixize_merchant_order_id($pingbackData['payment_order']['merchant_order_id']);
        }
        return $paymentMerchantOrderId;
    }

    protected function getSubscriptionKeyByOrderId($orderId)
    {
        $meta = get_post_meta($orderId, '_payment_order_id');
        return empty($meta[0]) ? null : $meta[0];
    }

    protected function setSubscriptionKeyByOrderId($orderId, $subscriptionKey)
    {
        return update_post_meta($orderId, '_payment_order_id', $subscriptionKey);
    }

    protected function getFpSubscriptionId($wcSubscriptionId) {
        $meta = get_post_meta($wcSubscriptionId, 'fp_transaction_id');
        return empty($meta[0]) ? null : $meta[0];
    }

    protected function setFpSubscriptionId($wcSubscriptionId, $fbSubscriptionId) {
        return update_post_meta($wcSubscriptionId, 'fp_transaction_id', $fbSubscriptionId);
    }

    protected function isRefundEvent($pingbackData) {
        return in_array($pingbackData['event'], self::REFUND_EVENT);
    }

    protected function isPaymentEvent($pingbackData) {
        return $pingbackData['event'] == self::PAYMENT_EVENT;
    }

    protected function isSubscriptionPayment($pingbackData) {
        return (
            $this->isPaymentEvent($pingbackData)
            && !empty($pingbackData['subscription'])
        );
    }

    protected function isOneTimePayment($pingbackData) {
        return (
            $this->isPaymentEvent($pingbackData)
            && !$this->isSubscriptionPayment($pingbackData)
        );
    }

    protected function isInitSubscriptionPayment($pingbackData) {
        return (
            $this->isSubscriptionPayment($pingbackData)
            && $pingbackData['subscription']['init_merchant_order_id'] == $pingbackData['payment_order']['merchant_order_id']
        );
    }

    protected function canProcessRefundOrder($order) {
        return !in_array(
            $order->get_status(),
            array_map(
                [
                    self::class,
                    'wcOrderStatusWithoutPrefix'
                ],
                [
                    FP_ORDER_STATUS_PENDING,
                    FP_ORDER_STATUS_REFUNDED,
                    FP_ORDER_STATUS_CANCELLED
                ]
            )
        );
    }

    protected function canProcessPaymentOrder($order) {
        return !in_array(
            $order->get_status(),
            array_map(
                [
                    self::class,
                    'wcOrderStatusWithoutPrefix'
                ],
                [
                    FP_ORDER_STATUS_PROCESSING,
                    FP_ORDER_STATUS_COMPLETED
                ]
            )
        );
    }

    protected function canClearCart($order) {
        return $order->get_status() == self::wcOrderStatusWithoutPrefix(FP_ORDER_STATUS_PROCESSING);
    }

    protected function isRefundPingbackCalled() {
        return did_action(self::BEFORE_CREATE_REFUND_IN_PINGBACK_HOOK);
    }

    protected function isRefundPingbackSuccess($pingbackData) {
        return in_array($pingbackData['payment_order']['status'], self::REFUND_PAYMENT_ORDER_SUCCESS_STATUS);
    }

    protected function isPaymentPingbackSuccess($pingbackData) {
        return $pingbackData['payment_order']['status'] == self::PAYMENT_ORDER_SUCCESS_STATUS;
    }

    public static function wcOrderStatusWithoutPrefix($status) {
        return 'wc-' === substr($status, 0, 3) ? substr($status, 3) : $status;
    }
}
