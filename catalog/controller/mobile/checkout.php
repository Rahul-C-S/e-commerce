<?php

namespace Opencart\Catalog\Controller\Mobile;

class Checkout extends ApiController {
    /**
     * Get available shipping methods
     *
     * @return void
     */
    public function shipping_methods(): void {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_method');
        $this->load->model('mobile/checkout');

        $json = [];

        // Validate cart has products
        if (!$this->cart->hasProducts()) {
            $json['error']['warning'] = $this->language->get('error_empty_cart');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Validate cart has shipping products
        if (!$this->cart->hasShipping()) {
            $json['error']['warning'] = $this->language->get('error_no_shipping');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Get shipping address
        $shipping_address = $this->model_mobile_checkout->getShippingAddress($customer['customer_id']);
        if (!$shipping_address) {
            $json['error']['warning'] = $this->language->get('error_address');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Get available shipping methods
        $shipping_methods = $this->model_mobile_checkout->getShippingMethods($shipping_address);
        
        $json['shipping_methods'] = [];
        
        foreach ($shipping_methods as $shipping_method) {
            if ($shipping_method['quote']) {
                $json['shipping_methods'][] = [
                    'title' => $shipping_method['title'],
                    'quote' => $shipping_method['quote'],
                    'sort_order' => $shipping_method['sort_order'],
                    'error' => $shipping_method['error']
                ];
            }
        }

        $this->response->setOutput($this->jsonp($json,true));
    }

    /**
     * Set or get shipping address
     *
     * @return void
     */
    public function shipping_address(): void {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_address');
        $this->load->model('mobile/checkout');

        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            // Validate if customer has products in their cart
            if (!$this->cart->hasProducts()) {
                $json['error']['warning'] = $this->language->get('error_empty_cart');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            // Validate minimum quantity requirements
            if (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout')) {
                $json['error']['warning'] = $this->language->get('error_stock');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            // Validate the address data
            $this->load->library('validation');
            
            if (!$this->validation->validateLength(trim($this->request->post['firstname']), 1, 32)) {
                $json['error']['warning'] = $this->language->get('error_firstname');
            }

            if (!$this->validation->validateLength(trim($this->request->post['lastname']), 1, 32)) {
                $json['error']['warning'] = $this->language->get('error_lastname');
            }

            if (!$this->validation->validateLength(trim($this->request->post['address_1']), 3, 128)) {
                $json['error']['warning'] = $this->language->get('error_address_1');
            }

            if (!$this->validation->validateLength($this->request->post['city'], 2, 32)) {
                $json['error']['warning'] = $this->language->get('error_city');
            }

            $this->load->model('localisation/country');
            $country_info = $this->model_localisation_country->getCountry($this->request->post['country_id']);

            if ($country_info && $country_info['postcode_required'] && !$this->validation->validateLength(trim($this->request->post['postcode']), 2, 10)) {
                $json['error']['postcode'] = $this->language->get('error_postcode');
            }

            if (!$country_info) {
                $json['error']['country'] = $this->language->get('error_country');
            }

            if (!isset($this->request->post['zone_id']) || !$this->request->post['zone_id']) {
                $json['error']['zone'] = $this->language->get('error_zone');
            }

            if (!$json) {
                $address_data = [
                    'firstname' => $this->request->post['firstname'],
                    'lastname' => $this->request->post['lastname'],
                    'company' => $this->request->post['company'] ?? '',
                    'address_1' => $this->request->post['address_1'],
                    'address_2' => $this->request->post['address_2'] ?? '',
                    'city' => $this->request->post['city'],
                    'postcode' => $this->request->post['postcode'],
                    'country_id' => $this->request->post['country_id'],
                    'zone_id' => $this->request->post['zone_id']
                ];

                $this->model_mobile_checkout->setShippingAddress($customer['customer_id'], $address_data);

                $json['success'] = $this->language->get('text_success');
            }
        } else {
            // Return current shipping address
            $address = $this->model_mobile_checkout->getShippingAddress($customer['customer_id']);
            if ($address) {
                $json = $address;
            }
        }

        $this->response->setOutput($this->jsonp($json,true));
    }

    /**
     * Set shipping method
     *
     * @return void
     */
    public function shipping_method(): void {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_method');
        $this->load->model('mobile/checkout');

        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if (!isset($this->request->post['shipping_method'])) {
                $json['error']['warning'] = $this->language->get('error_shipping');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            $shipping = explode('.', $this->request->post['shipping_method']);

            if (!isset($shipping[0]) || !isset($shipping[1]) || !$this->model_mobile_checkout->validateShippingMethod($shipping[0], $shipping[1])) {
                $json['error']['warning'] = $this->language->get('error_shipping');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            $this->model_mobile_checkout->setShippingMethod($customer['customer_id'], $shipping[0], $shipping[1]);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->setOutput($this->jsonp($json,true));
    }

    /**
     * Apply or remove voucher
     *
     * @return void
     */
    public function voucher(): void {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('extension/opencart/total/voucher');
        $this->load->model('mobile/checkout');

        $json = [];

        if ($this->request->server['REQUEST_METHOD'] == 'POST') {
            if (!isset($this->request->post['code']) || empty($this->request->post['code'])) {
                $json['error']['warning'] = $this->language->get('error_empty');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            $voucher_info = $this->model_mobile_checkout->getVoucher($this->request->post['code']);

            if (!$voucher_info) {
                $json['error']['warning'] = $this->language->get('error_voucher');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            $this->model_mobile_checkout->setVoucher($customer['customer_id'], $this->request->post['code']);
            $json['success'] = $this->language->get('text_success');
            
        } elseif ($this->request->server['REQUEST_METHOD'] == 'DELETE') {
            $this->model_mobile_checkout->removeVoucher($customer['customer_id']);
            $json['success'] = $this->language->get('text_remove');
        }

        // Get updated totals
        $totals = [];
        $taxes = $this->cart->getTaxes();
        $total = 0;

        $this->model_mobile_checkout->getTotals($totals, $taxes, $total);
        
        $json['totals'] = [];
        foreach ($totals as $total) {
            $json['totals'][] = [
                'title' => $total['title'],
                'value' => $this->currency->format($total['value'], $this->config->get('config_currency'))
            ];
        }

        $this->response->setOutput($this->jsonp($json,true));
    }

    /**
     * Confirm and place order
     *
     * @return void
     */
    public function confirm(): void {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/checkout');
        $this->load->model('mobile/checkout');

        $json = [];

        // Validate cart
        if (!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) {
            $json['error']['warning'] = $this->language->get('error_empty_cart');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Validate minimum quantity requirements
        if (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout')) {
            $json['error']['warning'] = $this->language->get('error_stock');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Validate shipping address
        if ($this->cart->hasShipping()) {
            $shipping_address = $this->model_mobile_checkout->getShippingAddress($customer['customer_id']);
            if (!$shipping_address) {
                $json['error']['warning'] = $this->language->get('error_shipping_address');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            // Validate shipping method
            if (!$this->model_mobile_checkout->getShippingMethod($customer['customer_id'])) {
                $json['error']['warning'] = $this->language->get('error_shipping_method');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }
        }

        if (!$json) {
            $order_data = $this->model_mobile_checkout->prepareOrder($customer['customer_id']);
            
            // Register the order
            $order_id = $this->model_checkout_order->addOrder($order_data);
            
            // Set the order status
            $this->model_checkout_order->addHistory($order_id, $this->config->get('config_order_status_id'));
            
            // Clear cart
            $this->cart->clear();
            
            $json['success'] = $this->language->get('text_success');
            $json['order_id'] = $order_id;
        }

        $this->response->setOutput($this->jsonp($json,true));
    }

    /**
     * Get order summary before confirming
     *
     * @return void
     */
    public function summary(): void {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/checkout');

        $json = [];

        // Get products
        $json['products'] = [];
        
        $products = $this->model_mobile_cart->getProducts($customer['customer_id']);
        
        foreach ($products as $product) {
            $json['products'][] = [
                'name' => $product['name'],
                'quantity' => $product['quantity'],
                'price' => $this->currency->format($product['price'], $this->config->get('config_currency')),
                'total' => $this->currency->format($product['total'], $this->config->get('config_currency'))
            ];
        }

        // Get shipping address if applicable
        if ($this->cart->hasShipping()) {
            $json['shipping_address'] = $this->model_mobile_checkout->getShippingAddress($customer['customer_id']);
        }

        // Get selected shipping method if applicable
        if ($this->cart->hasShipping()) {
            $json['shipping_method'] = $this->model_mobile_checkout->getShippingMethod($customer['customer_id']);
        }

        // Get totals
        $totals = [];
        $taxes = $this->cart->getTaxes();
        $total = 0;

        $this->model_mobile_checkout->getTotals($totals, $taxes, $total);
        
        $json['totals'] = [];
        foreach ($totals as $total) {
            $json['totals'][] = [
                'title' => $total['title'],
                'value' => $this->currency->format($total['value'], $this->config->get('config_currency'))
            ];
        }

        $this->response->setOutput($this->jsonp($json,true));
    }
}