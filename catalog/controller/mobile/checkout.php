<?php

namespace Opencart\Catalog\Controller\Mobile;

class Checkout extends ApiController
{


    /**
     * Apply or remove coupon
     */
    public function saveCoupon(): void
    {
        $customer = $this->authCheck();


        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('extension/opencart/total/coupon');
        $this->load->model('marketing/coupon');
        $this->load->model('mobile/cart');

        $json = [];

        // Get coupon from request, default to empty string
        $coupon = $this->request->post['coupon'] ?? '';

        // Check if coupon module is enabled
        if (!$this->config->get('total_coupon_status')) {
            $json['error'] = $this->language->get('error_status');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // If a coupon code is provided, validate it
        if ($coupon) {
            $coupon_info = $this->model_marketing_coupon->getCoupon($coupon);

            if (!$coupon_info) {
                $json['error'] = $this->language->get('error_coupon');
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }
        }

        // If no errors, proceed with coupon application or removal
        if ($coupon) {
            // Apply coupon
            $json['success'] = $this->language->get('text_success');

            // Here you would typically update the cart with the coupon
            // This might involve:
            // 1. Storing the coupon code with the cart
            // 2. Recalculating cart totals
            $this->model_mobile_cart->applyCoupon($customer['customer_id'], $coupon);

            $json['coupon_details'] = [
                'code' => $coupon_info['code'],
                'name' => $coupon_info['name'],
                'type' => $coupon_info['type'],
                'discount' => $coupon_info['discount']
            ];
        } else {
            // Remove coupon
            $json['success'] = $this->language->get('text_remove');

            // Remove coupon from cart
            $this->model_mobile_cart->removeCoupon($customer['customer_id']);
        }

        // Recalculate totals (this would be in your cart model)
        $cart_totals = $this->model_mobile_cart->calculateTotals($customer['customer_id']);
        $json['cart_totals'] = $cart_totals;

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Get current applied coupon
     */
    public function getCurrentCoupon(): void
    {
        $customer = $this->authCheck();


        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/cart');
        $this->load->model('marketing/coupon');

        $json = [];

        // Get current coupon from cart
        $current_coupon_code = $this->model_mobile_cart->getAppliedCoupon($customer['customer_id']);

        if (!$current_coupon_code) {
            $json['error'] = $this->language->get('error_no_coupon');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Validate coupon
        $coupon_info = $this->model_marketing_coupon->getCoupon($current_coupon_code);

        if (!$coupon_info) {
            $json['error'] = $this->language->get('error_coupon');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        $json['success'] = true;
        $json['coupon'] = [
            'code' => $coupon_info['code'],
            'name' => $coupon_info['name'],
            'type' => $coupon_info['type'],
            'discount' => $coupon_info['discount'],
            'date_start' => $coupon_info['date_start'],
            'date_end' => $coupon_info['date_end']
        ];

        $this->response->setOutput($this->jsonp($json, true));
    }




    /**
     * Get available shipping methods
     *
     * @return void
     */
    public function shipping_methods(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_method');
        $this->load->model('mobile/checkout');
        $this->load->model('mobile/cart');

        $json = [];

        // Validate cart has products
        $cart_products = $this->model_mobile_cart->getProducts($customer['customer_id']);
        if (empty($cart_products)) {
            $json['error']['warning'] = $this->language->get('error_empty_cart');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Validate cart has shipping products
        $has_shipping = false;
        foreach ($cart_products as $product) {
            if ($product['shipping']) {
                $has_shipping = true;
                break;
            }
        }

        if (!$has_shipping) {
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

        $this->response->setOutput($this->jsonp($json, true));
    }
}
