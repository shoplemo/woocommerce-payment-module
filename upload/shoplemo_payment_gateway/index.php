<?php
/*
 * Plugin Name: Shoplemo WooCommerce Ödeme Modülü
 * Plugin URI: http://www.shoplemo.com
 * Description: Shoplemo aracılığıyla WooCommerce üzerinden satış yapmak için kullanabileceğiniz Ödeme Modülü
 * Version: 1.0.0
 * Author: revoland
 * Author URI: https://github.com/RevoLand
 */

if (!defined('ABSPATH'))
{
    exit;
}

define('SHOPLEMO_VERSION', '1.0.0');
define('SHOPLEMO_PLUGIN_DIR', plugin_dir_path(__FILE__));

add_action('plugins_loaded', 'WooCommerce_Shoplemo');
add_filter('woocommerce_payment_gateways', 'init_woocommerce');

function init_woocommerce()
{
    $methods[] = 'Shoplemo';

    return $methods;
}

function WooCommerce_Shoplemo()
{
    class Shoplemo extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'shoplemo';
            $this->icon = SHOPLEMO_PLUGIN_DIR . 'shoplemo.png';
            $this->has_fields = true;
            $this->method_title = 'Shoplemo Checkout';
            $this->method_description = 'Shoplemo sistemi üzerinden alışverişinizi tamamlayabilirsiniz.';
            $this->order_button_text = __('Shoplemo\'ya ilerle', 'woocommerce');

            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->api_key = trim($this->get_option('shoplemo_api_key'));
            $this->api_secret = trim($this->get_option('shoplemo_api_secret'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
            add_action('woocommerce_api_shoplemo', [$this, 'shoplemo_response']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Ödeme Yöntemi Durumu', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Aktif', 'woocommerce'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Ödeme Yöntemi İsmi', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Shoplemo', 'woocommerce'),
                    'desc_tip' => true,
                ],
                'api_key' => [
                    'title' => __('Shoplemo Mağaza API Anahtarı', 'woocommerce'),
                    'type' => 'text',
                    'default' => '',
                ],
                'api_secret' => [
                    'title' => __('Shoplemo Mağaza API Secret', 'woocommerce'),
                    'type' => 'text',
                    'default' => '',
                ],
            ];
        }

        public function admin_options()
        {
            ?>
            <h2><?php _e('Shoplemo WooCommerce Ödeme Modülü', 'woocommerce'); ?></h2>
            <table class="form-table">
            <?php $this->generate_settings_html(); ?>
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label>Shoplemo Callback URL</label>
                </th>
                <td class="forminp">
                    <fieldset>
                        <input class="input-text regular-input" type="text" value="<?php echo $this->getCallbackUrl(); ?>" readonly>
                    </fieldset>
                </td>
		    </tr>
            </table>
            <?php
        }

        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('unpaid', __('Shoplemo üzerinden ödeme işleminin tamamlanması bekleniyor.', 'woocommerce'));

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Return thankyou redirect
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        public function receipt_page($order_id)
        {
            $order = new WC_Order($order_id);

            $getOrderItems = $order->get_items();

            foreach ($getOrderItems as $item)
            {
                $itemData = $item->get_data();
                $productInfo = $order->get_product_from_item($item);
                $orderItems[] = [
                    'category' => 0,
                    'name' => $itemData['name'],
                    'quantity' => $itemData['quantity'],
                    'type' => 1,
                    'price' => (int) (number_format($productInfo->get_price_including_tax(), 2, '.', '') * 100),
                ];
            }

            $totalShipping = $order->get_shipping_total();

            if ($totalShipping > 0)
            {
                $orderItems[] = [
                    'category' => 0,
                    'name' => 'Kargo Ücreti',
                    'quantity' => 1,
                    'type' => 1,
                    'price' => (int) (number_format($totalShipping, 2, '.', '') * 100),
                ];
            }

            $requestBody = [
                'user_email' => $order->get_billing_email(),
                'buyer_details' => [
                    'ip' => $order->get_customer_ip_address(),
                    'port' => $_SERVER['REMOTE_PORT'],
                    'city' => $order->get_billing_city(),
                    'country' => $order->get_billing_country(),
                    'gsm' => $order->get_billing_phone(),
                    'name' => $order->get_billing_first_name(),
                    'surname' => $order->get_billing_last_name(),
                ],
                'basket_details' => [
                    'currency' => 'TRY',
                    'total_price' => (int) (number_format($order->get_total(), 2, '.', '') * 100),
                    'discount_price' => (int) (number_format($order->get_discount_total(), 2, '.', '') * 100),
                    'items' => $orderItems,
                ],
                'shipping_details' => [
                    'full_name' => $order->get_formatted_shipping_full_name(),
                    'phone' => $order->get_billing_phone(),
                    'address' => $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() . ' ' . $order->get_shipping_state(),
                    'city' => $order->get_shipping_city(),
                    'country' => $order->get_shipping_country(),
                    'postalcode' => $order->get_shipping_postcode(),
                ],
                'billing_details' => [
                    'full_name' => $order->get_formatted_billing_full_name(),
                    'phone' => $order->get_billing_phone(),
                    'address' => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . ' ' . $order->get_billing_state(),
                    'city' => $order->get_billing_city(),
                    'country' => $order->get_billing_country(),
                    'postalcode' => $order->get_billing_postcode(),
                ],
                'custom_params' => json_encode([
                    'order_id' => $order_id,
                    'customer_id' => $order->get_customer_id(),
                ]),
                'user_message' => $order->get_customer_note(),
                'redirect_url' => $order->get_checkout_order_received_url(),
                'fail_redirect_url' => $order->get_cancel_order_url(),
            ];

            $requestBody = json_encode($requestBody);
            if (function_exists('curl_version'))
            {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://payment.shoplemo.com/paywith/credit_card');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 90);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 90);
                curl_setopt($ch, CURLOPT_SSLVERSION, 6);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($requestBody),
                    'Authorization: Basic ' . base64_encode($this->get_option('api_key') . ':' . $this->get_option('api_secret')),
                ]);
                $result = @curl_exec($ch);

                if (curl_errno($ch))
                {
                    die('Shoplemo connection error. Details: ' . curl_error($ch));
                }

                curl_close($ch);
                try
                {
                    $result = json_decode($result, 1);
                }
                catch (Exception $ex)
                {
                    return 'Failed to handle response';
                }
            }
            else
            {
                echo 'CURL fonksiyonu yüklü değil?';
            }
            if ($result['status'] == 'success')
            {
                ?>
            <div id="shoplemo-area">
                <script src="https://payment.shoplemo.com/assets/js/shoplemo.js"></script>
                <iframe src="<?php echo $result['url']; ?>" id="shoplemoiframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>

                <script type="text/javascript">

                setTimeout(function(){ 
                    iFrameResize({ log: true },'#shoplemoiframe');
                }, 1000);

                </script>
            </div>
        <?php
            }
            else
            {
                foreach ($result['details'] as $detail)
                {
                    echo "- {$detail} <br />";
                }
            }
        }

        public function shoplemo_response()
        {
            if (!$_POST || $_POST['status'] != 'success')
            {
                die('Shoplemo.com');
            }

            $_data = json_decode(stripslashes($_POST['data']), true);
            $hash = base64_encode(hash_hmac('sha256', $_data['progress_id'] . implode('|', $_data['payment']) . $this->get_option('api_key'), $this->get_option('api_secret'), true));

            if ($hash != $_data['hash'])
            {
                die('Shoplemo: Calculated hashes doesn\'t match!');
            }

            $custom_params = json_decode($_data['custom_params']);
            $order_id = $custom_params->order_id;
            $order = new WC_Order($order_id);
            if ($order->get_status() != 'processing' || $order->get_status() != 'completed')
            {
                if ($_POST['status'] == 'success')
                {
                    $order->payment_complete();
                    $order->add_order_note('Ödeme onaylandı.<br />## Shoplemo ##<br /># Müşteri Ödeme Tutarı: ' . $_data['payment']['paid_price'] . '<br/># Shoplemo Id: ' . $_data['progress_id'] . '<br/># Sipariş numarası: ' . $order_id);
                }
                else
                {
                    $order->update_status('failed', 'Sipariş iptal edildi.<br />## Shoplemo ##<br />Shoplemo Id: ' . $_data['progress_id'] . ' - Sipariş Id: ' . $order_id . '<br/>Hata mesajı:' . $_data['payment']['error_message'], 'woothemes');
                }
            }
            else
            {
                die('Shoplemo: Unexpected order status: ' . $order->get_status() . ' - Expected order status: processing OR completed');
            }

            die('OK');
        }

        private function getCallbackUrl()
        {
            return str_replace('https:', 'http:', add_query_arg('wc-api', 'shoplemo', home_url('/')));
        }
    }
}
