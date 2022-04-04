<?php
/*  
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
/**
 * Plugin Name: Paygine payment method (Visa/MasterCard)
 * Plugin URI: http://paygine.net/
 * Description: Receive payments via Visa/Mastercard easily with Paygine bank cards processing
 * Version: 1.1.5
 * Author: Paygine
 * Tested up to: 5.7.1
 * License: GPL3
 *
 * Text Domain: paygine-payment_method
 * Domain Path: /languages
 *
 */

defined('ABSPATH') or die("No script kiddies please!");

if (false) {
    __('Paygine payment method (Visa/MasterCard)');
    __('Receive payments via Visa/Mastercard easily with Paygine bank cards processing');
}

add_action('plugins_loaded', 'init_woocommerce_paygine', 0);

function init_woocommerce_paygine()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    load_plugin_textdomain('paygine-payment_method', false, dirname(plugin_basename(__FILE__)) . '/languages');

    class woocommerce_paygine extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->id = 'paygine';
            $this->method_title = __('Paygine', 'paygine-payment_method');
            $this->title = __('Paygine', 'paygine-payment_method');
            $this->description = __("Payments with bank cards via the <a href=\"http://www.paygine.net\" target=\"_blank\">Paygine</a> payment system.", 'paygine-payment_method');
            $this->icon = plugins_url('paygine.png', __FILE__);
            $this->has_fields = true;
            $this->notify_url = add_query_arg('wc-api', 'paygine_notify', home_url('/'));
            $this->callback_url = add_query_arg('wc-api', 'paygine', home_url('/'));

            $this->init_form_fields();
            $this->init_settings();

            // variables
            $this->sector = $this->settings['sector'];
            $this->password = $this->settings['password'];
            $this->testmode = $this->settings['testmode'];
            $this->twostepsmode = $this->settings['twostepsmode'];

            // actions
            add_action('init', array($this, 'successful_request'));
            add_action('woocommerce_api_paygine', array($this, 'callback_from_gateway'));
            add_action('woocommerce_api_paygine_notify', array($this, 'notify_from_gateway'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Admin Panel Options
         **/
        public function admin_options()
        {
            ?>
            <h3><?php _e('Paygine', 'paygine-payment_method'); ?></h3>
            <p><?php _e("Payments with bank cards via the <a href=\"http://www.paygine.net\" target=\"_blank\">Paygine</a> payment system.", 'paygine-payment_method'); ?></p>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            //  array to generate admin form
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'paygine-payment_method'),
                    'type' => 'checkbox',
                    'label' => __('Enable Paygine checkout method', 'paygine-payment_method'),
                    'default' => 'yes'
                ),

                'sector' => array(
                    'title' => __('Sector ID', 'paygine-payment_method'),
                    'type' => 'text',
                    'description' => __('Your shop identifier at Paygine', 'paygine-payment_method'),
                    'default' => 'test'
                ),

                'password' => array(
                    'title' => __('Password', 'paygine-payment_method'),
                    'type' => 'text',
                    'description' => __('Password to use for digital signature', 'paygine-payment_method'),
                    'default' => 'test'
                ),

                'testmode' => array(
                    'title' => __('Test Mode', 'paygine-payment_method'),
                    'type' => 'select',
                    'options' => array(
                        '1' => 'Test mode - real payments will not be processed',
                        '0' => 'Production mode - payments will be processed'
                    ),
                    'description' => __('Select test or live mode', 'paygine-payment_method')
                ),
                'twostepsmode' => array(
                    'title' => __('2 steps payment mode', 'paygine-payment_method'),
                    'type' => 'select',
                    'options' => array(
                        '1' => 'On',
                        '0' => 'Off'
                    ),
                    'description' => __('Turn on 2 steps mode', 'paygine-payment_method')
                ),
                'deferred' => array(
                    'title' => __('Deferred payment', 'paygine-payment_method'),
                    'type' => 'select',
                    'options' => array(
                        '1' => 'On',
                        '0' => 'Off'
                    )
                )
            );

        }

        /**
         * Register order @ Paygine and redirect user to payment form
         **/
        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            switch ($order->get_currency()) {
                case 'EUR':
                    $currency = '978';
                    break;
                case 'USD':
                    $currency = '840';
                    break;
                default:
                    $currency = '643';
                    break;
            }

            $paygine_url = "https://test.paygine.com";
            if ($this->testmode == "0")
                $paygine_url = "https://pay.paygine.com";
            $paygine_operation = "Purchase";
            if ($this->twostepsmode == "1")
                $paygine_operation = "Authorize";

            $signature = base64_encode(md5($this->sector . intval($order->get_total() * 100) . $currency . $this->password));

            $wc_order = wc_get_order($order_id);
            $items = $wc_order->get_items();
            $fiscalPositions = '';
            $fiscalAmount = 0;
            $KKT = true;

            if ($KKT) {
                foreach ($items as $item_id => $item) {
                    $item_data = $item->get_data();
                    $fiscalPositions .= $item_data['quantity'] . ';';
                    $elementPrice = $item_data['total'] / $item_data['quantity'];
                    $elementPrice = $elementPrice * 100;
                    $fiscalPositions .= $elementPrice . ';';
                    $fiscalPositions .= ($item_data['total_tax']) ?: 6 . ';';   // tax
                    $fiscalPositions .= str_ireplace([';', '|'], '', $item_data['name']) . '|';

                    $fiscalAmount += $item_data['quantity'] * $elementPrice;
                }
                if ($wc_order->get_shipping_total()) {
                    $fiscalPositions .= '1;' . $wc_order->get_shipping_total() * 100 . ';6;Доставка|';
                    $fiscalAmount += $wc_order->get_shipping_total() * 100;
                }
                $fiscalDiff = abs($fiscalAmount - intval($order->get_total() * 100));
                if ($fiscalDiff) {
                    $fiscalPositions .= '1;' . $fiscalDiff . ';6;Скидка;14|';
                }
                $fiscalPositions = substr($fiscalPositions, 0, -1);
            }

            $args = array(
                'body' => array(
                    'sector' => $this->sector,
                    'reference' => $order->get_id(),
                    'amount' => intval($order->get_total() * 100),
                    'fiscal_positions' => $fiscalPositions,
                    'description' => sprintf(__('Order #%s', 'paygine-payment_method'), ltrim($order->get_order_number(), '#')),
                    'email' => $order->get_billing_email(),
                    'notify_customer' => ($this->deferred) ? 1 : 0,
                    'currency' => $currency,
                    'mode' => 1,
                    'url' => $this->callback_url,
                    'signature' => $signature
                )
            );
            $remote_post = wp_remote_post($paygine_url . '/webapi/Register', $args);
            $remote_post = (isset($remote_post['body'])) ? $remote_post['body'] : $remote_post;
            $paygine_order_id = ($remote_post) ? $remote_post : null;

            if (intval($paygine_order_id) == 0) {
                $request_body = $args['body'];
                $request_body['email'] = ($request_body['email']) ? 'isset' : 'isnotset';
                $request_body['signature'] = ($request_body['signature']) ? 'isset' : 'isnotset';
                $this->logIt('Не удалось зарегистрировать заказ', array('paygine_order_id' => $paygine_order_id, 'request_body' => $request_body));
                return false;
            }

            $signature = base64_encode(md5($this->sector . $paygine_order_id . $this->password));

            // $order->update_status('on-hold');

            if ($this->deferred) {
                wp_redirect($this->get_return_url(wc_get_order($response->reference)));
                exit();
            }

            return array(
                'result' => 'success',
                'redirect' => "{$paygine_url}/webapi/{$paygine_operation}?sector={$this->sector}&id={$paygine_order_id}&signature={$signature}"
            );
        }

        /**
         * Callback from payment gateway was received
         **/
        public function callback_from_gateway()
        {
            // check payment status
            $paygine_order_id = intval($_REQUEST["id"]);
            if (!$paygine_order_id)
                return false;

            $paygine_operation_id = intval($_REQUEST["operation"]);
            if (!$paygine_operation_id) {
                $order_id = intval($_REQUEST["reference"]);
                $order = wc_get_order($order_id);
                if ($order)
                    $order->cancel_order(__("The order wasn't paid.", 'paygine-payment_method'));

                wc_add_notice(__("The order wasn't paid.", 'paygine-payment_method'), 'error');
                $get_checkout_url = apply_filters('woocommerce_get_checkout_url', WC()->cart->get_checkout_url());
                wp_redirect($get_checkout_url);
                exit();
            }

            // check payment operation state
            $signature = base64_encode(md5($this->sector . $paygine_order_id . $paygine_operation_id . $this->password));

            $paygine_url = "https://test.paygine.com";
            if ($this->testmode == "0")
                $paygine_url = "https://pay.paygine.com";

            $context = stream_context_create(array(
                'http' => array(
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query(array(
                        'sector' => $this->sector,
                        'id' => $paygine_order_id,
                        'operation' => $paygine_operation_id,
                        'signature' => $signature
                    )),
                )
            ));

            $repeat = 3;

            while ($repeat) {

                $repeat--;

                // pause because of possible background processing in the Paygine
                sleep(2);
                $args = array(
                    'body' => array(
                        'sector' => $this->sector,
                        'id' => $paygine_order_id,
                        'operation' => $paygine_operation_id,
                        'signature' => $signature
                    )
                );
                $xml = wp_remote_post($paygine_url . '/webapi/Operation', $args)['body'];

                if (!$xml)
                    break;
                $xml = simplexml_load_string($xml);
                if (!$xml)
                    break;
                $response = json_decode(json_encode($xml));
                if (!$response)
                    break;
                if (!$this->orderAsPayed($response))
                    continue;

                wp_redirect($this->get_return_url(wc_get_order($response->reference)));
                exit();

            }

            $order_id = intval($response->reference);
            $order = wc_get_order($order_id);
            if ($order)
                $order->cancel_order(__("The order wasn't paid [1]: " . $response->message . '.', 'paygine-payment_method'));

            wc_add_notice(__("The order wasn't paid [1]: ", 'paygine-payment_method') . $response->message . '.', 'error');
            $get_checkout_url = apply_filters('woocommerce_get_checkout_url', WC()->cart->get_checkout_url());
            wp_redirect($get_checkout_url);
            exit();

        }

        /**
         * Payment notify from gateway was received
         **/
        public function notify_from_gateway()
        {
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }

            // $xml = file_get_contents("php://input");
            $xml = $wp_filesystem->get_contents('php://input');
            if (!$xml)
                return false;
            $xml = simplexml_load_string($xml);
            if (!$xml)
                return false;
            $response = json_decode(json_encode($xml));
            if (!$response)
                return false;

            if (!$this->orderAsPayed($response)) {
                $order_id = intval($response->reference);
                $order = wc_get_order($order_id);
                if ($order)
                    $order->cancel_order(__("The order wasn't paid [2]: ", 'paygine-payment_method') . $response->message . '.');
                exit();
            }

            die("ok");

        }

        private function orderAsPayed($response)
        {
            // looking for an order
            $order_id = intval($response->reference);
            if ($order_id == 0)
                return false;

            $order = wc_get_order($order_id);
            if (!$order)
                return false;

            // check payment state
            if (($response->type != 'PURCHASE' && $response->type != 'EPAYMENT' && $response->type != 'AUTHORIZE') || $response->state != 'APPROVED')
                return false;

            // check server signature
            $tmp_response = json_decode(json_encode($response), true);
            unset($tmp_response["signature"]);
            unset($tmp_response["ofd_state"]);
            unset($tmp_response["protocol_message"]);

            $signature = base64_encode(md5(implode('', $tmp_response) . $this->password));

            if ($signature !== $response->signature) {
                $order->update_status('fail', $response->message);
                return false;
            }

            $order->add_order_note(__('Payment completed.', 'paygine-payment_method'));
            $order->payment_complete();

            // echo '<pre>' . print_r($tmp_response, true) . '<br>' . $signature . '<br>' . print_r($response, true); die();

            /*
             * сохраним в мета заказа выбранный на момент оплаты режим (1 или 2 стадии)
             */
            $paygine_mode = ($this->settings['twostepsmode']) ? 2 : 1;
            update_post_meta($order_id, 'paygine_payment_mode', $paygine_mode);

            return true;

        }


        /**
         * @param string $message
         * @param array $details
         */
        public function logIt (string $message, array $details = []) {
            $log = fopen($this->getLogFileName(), 'a+') or die("logging trouble");
            $date = date("d.m.y H:i:s");
            $msg = $date . "\t" . $message;
            if ($details) {
                $msg .= "\n" . print_r($details, true);
            }
            $msg .= "\n\n\n";
            fprintf($log, chr(0xEF).chr(0xBB).chr(0xBF));
            fwrite($log, $msg);
            fclose($log);
        }

        public function getLogFileName () {
            $signature = base64_encode($this->sector . $this->password . $_SERVER['HTTP_HOST']);
            $signature = str_ireplace('=', '', $signature);
            return 'paygine_log_' . $signature . '.txt';
        }


    } // class

    function add_paygine_gateway($methods)
    {
        $methods[] = 'woocommerce_paygine';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_paygine_gateway');
}