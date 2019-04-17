<?php

abstract class FasterPay_Abstract extends WC_Payment_Gateway
{
    public $wcVersion = '';
    public $wcsVersion = '';

    public function __construct()
    {
        $this->plugin_path = FP_PLUGIN_PATH;

        // Load the settings.
        $this->init_settings();
        $this->init_form_fields();

        $this->wcVersion = $this->get_woo_version_number('woocommerce');
        $this->wcsVersion = $this->get_woo_version_number('woocommerce-subscriptions');
    }

    protected function get_template($templateFileName, $data = array())
    {
        if (file_exists($this->plugin_path . 'templates/' . $templateFileName)) {
            $content = file_get_contents($this->plugin_path . 'templates/' . $templateFileName);
            foreach ($data as $key => $var) {
                $content = str_replace('{{' . $key . '}}', $var, $content);
            }
            return $content;
        }
        return false;
    }

    /*
     * Display administrative fields under the Payment Gateways tab in the Settings page
     */
    function init_form_fields()
    {
        $this->form_fields = include($this->plugin_path . 'includes/admin/settings/' . $this->id . '.php');
    }

    /**
     * Displays a short description to the user during checkout
     */
    function payment_fields()
    {
        echo $this->settings['description'];
    }

    /**
     * Displays text like introduction, instructions in the admin area of the widget
     */
    public function admin_options()
    {
        ob_start();
        $this->generate_settings_html();
        $settings = ob_get_contents();
        ob_clean();

        echo $this->get_template('admin/options.html', array(
            'title' => $this->method_title,
            'description' => $this->method_description,
            'settings' => $settings
        ));
    }

    public function standardizePeriod($type) {
        return strtolower($type[0]);
    }


    function get_woo_version_number($type) {
        // If get_plugins() isn't available, require it
        if ( ! function_exists( 'get_plugins' ) )
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

        // Create the plugins folder and file variables
        $plugin_folder = get_plugins( '/' . $type );
        $plugin_file = $type . '.php';

        // If the plugin version number is set, return it
        if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
            return $plugin_folder[$plugin_file]['Version'];

        } else {
            // Otherwise return null
            return NULL;
        }
    }

    function get_order_data(WC_Order $order) {
        $orderData = array();
        $orderData['total'] = $order->get_total();
        $orderData['user_id'] = $order->get_user_id();

        if (version_compare('2.7', $this->wcVersion, '>')) {
            $orderData = array_merge($orderData, array(
                'order_id' => $order->id,
                'billing_city' => $order->billing_city,
                'billing_state' => $order->billing_state,
                'billing_address1' => $order->billing_address_1,
                'billing_country' => $order->billing_country,
                'billing_postcode' => $order->billing_postcode,
                'billing_firstname' => $order->billing_first_name,
                'billing_lastname' => $order->billing_last_name,
                'billing_email' => $order->billing_email,
                'currencyCode' => $order->order_currency,
            ));
        } else {
            $orderData = array_merge($orderData, array(
                'order_id' => $order->get_id(),
                'billing_city' => $order->get_billing_city(),
                'billing_state' => $order->get_billing_state(),
                'billing_address1' => $order->get_billing_address_1(),
                'billing_country' => $order->get_billing_country(),
                'billing_postcode' => $order->get_billing_postcode(),
                'billing_firstname' => $order->get_billing_first_name(),
                'billing_lastname' => $order->get_billing_last_name(),
                'billing_email' => $order->get_billing_email(),
                'currencyCode' => $order->get_currency(),
            ));
        }

        return $orderData;
    }

    function get_recurring_data(WC_Subscription $subscription) {
        if (version_compare('2.2', $this->wcsVersion, '>')) {
            $subsData = array(
                'schedule_trial_end' => strtotime($subscription->schedule_trial_end),
                'date_created' => strtotime($subscription->order_date),
                'billing_interval' => $subscription->billing_interval,
                'billing_period' => $subscription->billing_period,
                'trial_period' => $subscription->trial_period,
                'schedule_end' => $subscription->schedule_end,
                'schedule_next_payment' => $subscription->schedule_next_payment

            );
        } else {
            $subsData = $subscription->get_data();
            unset($subsData['line_items'], $subsData['billing'], $subsData['shipping']);
            $subsData['schedule_trial_end'] = (!empty($subsData['schedule_trial_end'])) ? $subsData['schedule_trial_end']->getTimestamp() : null;
            $subsData['date_created'] = $subscription->get_data()['date_created']->getTimestamp();
            $subsData['billing_interval'] = $subscription->get_billing_interval();
            $subsData['billing_period'] = $subscription->get_billing_period();
            $subsData['trial_period'] = $subscription->get_trial_period();
        }

        return $subsData;
    }

    function get_header($key = null) {
        if ($key == null) {
            return null;
        }

        if (empty($_SERVER[$key])) {
            return null;
        }

        return $_SERVER[$key];

    }

    function s_datediff( $str_interval, $dt_menor, $dt_maior, $relative=false){

        if( is_string( $dt_menor)) $dt_menor = date_create( $dt_menor);
        if( is_string( $dt_maior)) $dt_maior = date_create( $dt_maior);

        if (is_int($dt_menor)) {
            $tmp = $dt_menor;
            $dt_menor = new DateTime();
            $dt_menor->setTimestamp($tmp);
            unset($tmp);
        }

        if (is_int($dt_maior)) {
            $tmp = $dt_maior;
            $dt_maior = new DateTime();
            $dt_maior->setTimestamp($tmp);
            unset($tmp);
        }

        $diff = date_diff( $dt_menor, $dt_maior, ! $relative);

        switch( $str_interval){
            case "year":
                $total = $diff->y + $diff->m / 12 + $diff->d / 365.25;
                break;
            case "month":
                $total= $diff->y * 12 + $diff->m + $diff->d/30 + $diff->h / 24;
                break;
            case 'week':
                $total = $diff->days / 7;
                break;
            case "day":
                $total = $diff->y * 365.25 + $diff->m * 30 + $diff->d + $diff->h/24 + $diff->i / 60;
                break;
        }
        if( $diff->invert) {
            return -1 * intval($total);
        } else {
            return intval($total);
        }
    }
}