<?php

namespace Opencart\Catalog\Controller\Mobile;

class Checkout extends ApiController
{




    /**
     * Confirm and place the order
     * 
     * Validates checkout data, confirms stock availability,
     * creates the order and returns the order ID
     *
     * @return void
     */

    public function confirmOrder(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/cart');
        $this->load->model('checkout/cart');
        $this->load->model('mobile/checkout');
        $this->load->model('account/address');
        $this->load->model('checkout/order');

        $json = [];

        // Get product info
        $products = $this->model_mobile_cart->getProducts($customer['customer_id']);
        $currency = $this->config->get('config_currency');

        // Get checkout data
        $checkout_data = $this->model_mobile_checkout->getCheckoutData($customer['customer_id']);

        // Validate basic requirements
        if (empty($checkout_data) || empty($checkout_data['payment_method']) || empty($products)) {
            $json['error']['warning'] = 'Invalid checkout data';
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Get shipping address
        $shipping_address = $this->model_account_address->getAddress(
            $customer['customer_id'],
            $checkout_data['shipping_address_id']
        );

        if (!$shipping_address) {
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Initialize order data
        $order_data = [];

        // Store info
        $order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
        $order_data['store_id'] = $this->config->get('config_store_id');
        $order_data['store_name'] = $this->config->get('config_name');
        $order_data['store_url'] = $this->config->get('config_url');

        // Customer info
        $order_data['customer_id'] = $customer['customer_id'];
        $order_data['customer_group_id'] = $customer['customer_group_id'];
        $order_data['firstname'] = $customer['firstname'];
        $order_data['lastname'] = $customer['lastname'];
        $order_data['email'] = $customer['email'];
        $order_data['telephone'] = $customer['telephone'];
        $order_data['custom_field'] = [];

        // Payment address (same as shipping for mobile)
        $order_data['payment_address_id'] = $shipping_address['address_id'];
        $order_data['payment_firstname'] = $shipping_address['firstname'];
        $order_data['payment_lastname'] = $shipping_address['lastname'];
        $order_data['payment_company'] = $shipping_address['company'];
        $order_data['payment_address_1'] = $shipping_address['address_1'];
        $order_data['payment_address_2'] = $shipping_address['address_2'];
        $order_data['payment_city'] = $shipping_address['city'];
        $order_data['payment_postcode'] = $shipping_address['postcode'];
        $order_data['payment_zone'] = $shipping_address['zone'];
        $order_data['payment_zone_id'] = $shipping_address['zone_id'];
        $order_data['payment_country'] = $shipping_address['country'];
        $order_data['payment_country_id'] = $shipping_address['country_id'];
        $order_data['payment_address_format'] = $shipping_address['address_format'];
        $order_data['payment_custom_field'] = [];
        // Payment method
        $payment_method = explode('.', $checkout_data['payment_method']);
        $code = $payment_method[0] ?? '';
        $title = isset($payment_method[1]) ? ucfirst($payment_method[1]) : ucfirst($code);

        $order_data['payment_method'] = [
            'name' => $title,
            'code' => $code,
            'title' => $title,
            'terms' => ''
        ];

        // Shipping address
        $order_data['shipping_address_id'] = $shipping_address['address_id'];
        $order_data['shipping_firstname'] = $shipping_address['firstname'];
        $order_data['shipping_lastname'] = $shipping_address['lastname'];
        $order_data['shipping_company'] = $shipping_address['company'];
        $order_data['shipping_address_1'] = $shipping_address['address_1'];
        $order_data['shipping_address_2'] = $shipping_address['address_2'];
        $order_data['shipping_city'] = $shipping_address['city'];
        $order_data['shipping_postcode'] = $shipping_address['postcode'];
        $order_data['shipping_zone'] = $shipping_address['zone'];
        $order_data['shipping_zone_id'] = $shipping_address['zone_id'];
        $order_data['shipping_country'] = $shipping_address['country'];
        $order_data['shipping_country_id'] = $shipping_address['country_id'];
        $order_data['shipping_address_format'] = $shipping_address['address_format'];
        $order_data['shipping_custom_field'] = [];
        // Shipping method
        $shipping_method = explode('.', $checkout_data['shipping_method']);
        $code = $shipping_method[0] ?? '';
        $title = isset($shipping_method[1]) ? ucfirst($shipping_method[1]) : ucfirst($code);

        $order_data['shipping_method'] = [
            'name' => $title,
            'code' => $code,
            'title' => $title,
            'cost' => 0,
            'tax_class_id' => 0
        ];

        // Calculate totals
        $totals = [];
        $taxes = [];
        $total = 0;

        // Get the sub-total first
        $sub_total = 0;
        foreach ($products as $product) {
            $sub_total += $product['price'] * $product['quantity'];
        }

        // Add sub-total
        $totals[] = [
            'code'       => 'sub_total',
            'extension'  => 'opencart',
            'title'      => 'Sub-Total',
            'value'      => $sub_total,
            'sort_order' => 1
        ];

        // Calculate taxes if needed
        if ($this->config->get('config_tax')) {
            foreach ($products as $product) {
                $tax_rates = $this->tax->getRates($product['price'], $product['tax_class_id']);

                foreach ($tax_rates as $tax_rate) {
                    if (!isset($taxes[$tax_rate['tax_rate_id']])) {
                        $taxes[$tax_rate['tax_rate_id']] = ($tax_rate['amount'] * $product['quantity']);
                    } else {
                        $taxes[$tax_rate['tax_rate_id']] += ($tax_rate['amount'] * $product['quantity']);
                    }
                }
            }

            foreach ($taxes as $key => $value) {
                $totals[] = [
                    'code'       => 'tax',
                    'extension'  => 'opencart',
                    'title'      => $this->tax->getRateName($key),
                    'value'      => $value,
                    'sort_order' => 2
                ];
            }
        }


        // Calculate final total before discounts
        $total = $sub_total;
        foreach ($taxes as $value) {
            $total += $value;
        }

        // Get applied discounts and rewards
        $applied_totals = $this->model_mobile_checkout->getAppliedTotals($customer['customer_id']);

        if (isset($applied_totals['coupon'])) {
            // Add coupon total
            $totals[] = [
                'code'       => 'coupon',
                'extension'  => 'opencart',
                'title'      => $applied_totals['coupon']['name'],
                'value'      => -$applied_totals['coupon']['discount'],
                'sort_order' => 500
            ];
            // Subtract from total
            $total -= $applied_totals['coupon']['discount'];
        }

        if (isset($applied_totals['voucher'])) {
            // Add voucher total
            $totals[] = [
                'code'       => 'voucher',
                'extension'  => 'opencart',
                'title'      => 'Voucher Applied: ' . $applied_totals['voucher']['code'],
                'value'      => -$applied_totals['voucher']['amount'],
                'sort_order' => 501
            ];
            // Subtract from total
            $total -= $applied_totals['voucher']['amount'];
        }

        if (isset($applied_totals['reward'])) {
            // Add reward total
            $totals[] = [
                'code'       => 'reward',
                'extension'  => 'opencart',
                'title'      => 'Redeemed Rewards',
                'value'      => -$applied_totals['reward']['value'],
                'sort_order' => 502
            ];
            // Subtract from total
            $total -= $applied_totals['reward']['value'];
        }

        // Add final total last, after all deductions
        $totals[] = [
            'code'       => 'total',
            'extension'  => 'opencart',
            'title'      => 'Total',
            'value'      => $total,
            'sort_order' => 9999
        ];

        // Add totals to order data
        $order_data['totals'] = $totals;
        $order_data['total'] = $total;
        $order_data['taxes'] = $taxes;



        // Products
        $order_data['products'] = [];
        foreach ($products as $product) {
            // Process product options if they exist
            $option_data = [];
            if (isset($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    $option_data[] = [
                        'product_option_id'       => $option['product_option_id'] ?? 0,
                        'product_option_value_id' => $option['product_option_value_id'] ?? 0,
                        'option_id'               => $option['option_id'] ?? 0,
                        'option_value_id'         => $option['option_value_id'] ?? 0,
                        'name'                    => $option['name'] ?? '',
                        'value'                   => $option['value'] ?? '',
                        'type'                    => $option['type'] ?? ''
                    ];
                }
            }

            $product_data = [
                'product_id' => $product['product_id'],
                'name'       => $product['name'],
                'model'      => $product['model'],
                'quantity'   => $product['quantity'],
                'price'      => $product['price'],
                'total'      => $product['total'],
                'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
                'reward'     => $product['reward'] ?? 0,
                'master_id'  => $product['master_id'] ?? 0,
                // 'option'     => [],
                'option'     => $option_data,
                'download'   => $product['download'] ?? [],
                'subtract'   => $product['subtract'] ?? 1,
                'subscription' => $product['subscription'] ?? []
            ];

            $order_data['products'][] = $product_data;
        }

        // Additional data
        $order_data['comment'] = '';
        $order_data['affiliate_id'] = 0;
        $order_data['commission'] = 0;
        $order_data['marketing_id'] = 0;
        $order_data['tracking'] = '';
        $order_data['language_id'] = $this->config->get('config_language_id');
        $order_data['currency_id'] = $this->currency->getId($currency);
        $order_data['currency_code'] = $currency;
        $order_data['currency_value'] = $this->currency->getValue($currency);
        $order_data['ip'] = $this->request->server['REMOTE_ADDR'];
        $order_data['forwarded_ip'] = isset($this->request->server['HTTP_X_FORWARDED_FOR']) ?
            $this->request->server['HTTP_X_FORWARDED_FOR'] : (isset($this->request->server['HTTP_CLIENT_IP']) ? $this->request->server['HTTP_CLIENT_IP'] : '');
        $order_data['user_agent'] = isset($this->request->server['HTTP_USER_AGENT']) ?
            $this->request->server['HTTP_USER_AGENT'] : '';
        $order_data['accept_language'] = isset($this->request->server['HTTP_ACCEPT_LANGUAGE']) ?
            $this->request->server['HTTP_ACCEPT_LANGUAGE'] : '';

        try {
            // Add order
            $order_id = $this->model_checkout_order->addOrder($order_data);

            // Set initial status to pending 
            $this->model_checkout_order->addHistory($order_id, 1);

            // Clear cart and checkout data
            $this->cart->clear();
            $this->model_mobile_cart->clear($customer['customer_id']);
            $this->model_mobile_checkout->clearCheckoutData($customer['customer_id']);

            $this->log->write('Order id: ' . $order_id);

            $this->response->setOutput($this->jsonp([
                'order_id' => $order_id,
            ], true));
            return;
        } catch (\Exception $e) {
            $this->log->write('Order creation failed: ' . $e->getMessage());

            $this->response->setOutput($this->jsonp([
                'error' => [
                    'warning' => 'Error creating order: ' . $e->getMessage()
                ]
            ], true));

            return;
        }
    }




    /**
     * Validate and extract payment method code
     * 
     * @param array $checkout_data The checkout data containing payment method information
     * @return string The payment method code
     */
    public function validatePaymentMethod($checkout_data): string
    {
        // Check if payment method exists and is properly formatted
        if (!isset($checkout_data['payment_method'])) {
            return '';
        }

        // Handle payment method based on its structure
        if (is_array($checkout_data['payment_method'])) {
            // If it's an array, expect the code to be in the 'code' key
            if (isset($checkout_data['payment_method']['code'])) {
                return oc_substr($checkout_data['payment_method']['code'], 0, strpos($checkout_data['payment_method']['code'], '.'));
            }
        } else {
            // If it's a string, process it directly
            return oc_substr($checkout_data['payment_method'], 0, strpos($checkout_data['payment_method'], '.'));
        }

        return '';
    }
    public function fetchPaymentMethods(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/checkout');

        $payment_methods = $this->model_mobile_checkout->fetchPaymentMethods();

        $this->response->setOutput($this->jsonp([
            'payment_methods' => $payment_methods
        ], true));
    }

    public function setPaymentMethod(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/payment_method');
        $this->load->model('mobile/checkout');

        if (!isset($this->request->post['payment_code'])) {
            $this->response->setOutput($this->jsonp([
                'error' => $this->language->get('error_payment')
            ], true));
            return;
        }

        $success = $this->model_mobile_checkout->setPaymentMethod(
            $customer['customer_id'],
            $this->request->post['payment_code']
        );

        if (!$success) {
            $this->response->setOutput($this->jsonp([
                'error' => $this->language->get('error_payment')
            ], true));
            return;
        }

        $this->response->setOutput($this->jsonp([
            'success' => $this->language->get('text_success')
        ], true));
    }

    public function setShippingAddress(): void
    {
        $customer = $this->authCheck();
        $json = [];

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_address');
        $this->load->model('mobile/checkout');

        if (!isset($this->request->post['address_id'])) {
            $json['error']['warning'] =  $this->language->get('error_address');
        }

        if (!$json) {
            $success = $this->model_mobile_checkout->setShippingAddress(
                $customer['customer_id'],
                (int)$this->request->post['address_id']
            );
            if (!$success) {
                $json['error']['warning'] =  $this->language->get('error_address');
            } else {
                $json['success'] = $this->language->get('text_success');
            }
        }


        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Get available shipping methods
     *
     * @return void
     */

    public function getShippingMethods(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $json = [];

        $this->load->model('mobile/checkout');


        $json['shipping_methods'] = $this->model_mobile_checkout->getShippingMethods();
        $this->response->setOutput($this->jsonp($json, true));
    }

    public function setShippingMethod(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }
        $json = [];

        $this->load->model('mobile/checkout');


        if (!isset($this->request->post['shipping_code'])) {
            $json['error']['warning'] = 'Please select a shipping method';
        }

        if (!$json) {
            $success = $this->model_mobile_checkout->setShippingMethod(
                $customer['customer_id'],
                $this->request->post['shipping_code']
            );
            if (!$success) {
                $json['error']['warning'] = 'This shipping method is not available';
            } else {
                $json['success'] = 'Shipping method has been updated successfully';
            }
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function applyCoupon(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }
        $json = [];

        $this->load->language('extension/opencart/total/coupon');
        $this->load->model('mobile/checkout');

        if (!isset($this->request->post['coupon'])) {
            $json['error']['warning'] = $this->language->get('error_coupon');
            $this->response->setOutput($this->jsonp($json, false));
            return;
        }

        $result = $this->model_mobile_checkout->applyCoupon(
            $customer['customer_id'],
            $this->request->post['coupon']
        );
        if ($result['success']) {
            $json['success'] = $result['coupon']['name'] . ' has been applied.';
        } else {
            $json['error']['warning'] = 'Invalid coupon';
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function removeCoupon(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }
        $json = [];

        $this->load->model('mobile/checkout');

        $result = $this->model_mobile_checkout->removeCoupon($customer['customer_id']);
        if ($result['success']) {
            $json['success'] = 'Coupon has been removed.';
        } else {
            $json['error']['warning'] = 'Invalid coupon';
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function applyVoucher(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $json = [];

        $this->load->language('extension/opencart/total/voucher');
        $this->load->model('mobile/checkout');

        if (!isset($this->request->post['voucher'])) {
            $json['error']['warning'] =  $this->language->get('error_voucher');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        $result = $this->model_mobile_checkout->applyVoucher(
            $customer['customer_id'],
            $this->request->post['voucher']
        );

        if ($result['success']) {
            $json['success'] = 'Voucher ' . $result['voucher']['code'] . ' has been applied.';
        } else {
            $json['error']['warning'] = 'Invalid voucher!';
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function removeVoucher(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/checkout');

        $result = $this->model_mobile_checkout->removeVoucher($customer['customer_id']);

        if ($result['success']) {
            $json['success'] = 'Voucher has been removed.';
        } else {
            $json['error']['warning'] = 'Invalid Voucher';
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function applyReward(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('extension/opencart/total/reward');
        $this->load->model('mobile/checkout');

        if (!isset($this->request->post['points'])) {
            $this->response->setOutput($this->jsonp([
                'error' => $this->language->get('error_reward')
            ], false));
            return;
        }

        $result = $this->model_mobile_checkout->applyReward(
            $customer['customer_id'],
            (int)$this->request->post['points']
        );

        $this->response->setOutput($this->jsonp($result, !isset($result['error'])));
    }

    public function removeReward(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/checkout');

        $result = $this->model_mobile_checkout->removeReward($customer['customer_id']);

        $this->response->setOutput($this->jsonp($result, true));
    }

    public function getAppliedTotals(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/checkout');

        $totals = $this->model_mobile_checkout->getAppliedTotals($customer['customer_id']);

        $this->response->setOutput($this->jsonp([
            'totals' => $totals
        ], true));
    }

    public function reviewOrder(): void
    {


        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $json = [];

        $json['order_review'] = [];

        $this->load->model('mobile/checkout');
        $json['order_review'] = $this->model_mobile_checkout->reviewOrder($customer['customer_id']);

        $this->response->setOutput($this->jsonp($json, true));
    }
}
