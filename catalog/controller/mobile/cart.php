<?php

namespace Opencart\Catalog\Controller\Mobile;

class Cart extends ApiController
{

    public function summary(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/cart');
        $this->load->model('mobile/cart');
        $this->load->model('checkout/cart');
        $this->load->model('tool/image');
        $this->load->model('tool/upload');

        $json = [];
        $customer_id = (int)$customer['customer_id'];



        // Get original products with shipping info
        $products = $this->model_mobile_cart->getProducts($customer_id);
        $json['products'] = [];

        // Get default currency
        $currency = $this->config->get('config_currency');

        foreach ($products as $product) {
            $option_data = [];

            // Process options
            if (!empty($product['option']) && is_array($product['option'])) {
                foreach ($product['option'] as $option) {
                    $option_data[] = [
                        'product_option_id' => $option['product_option_id'],
                        'product_option_value_id' => $option['product_option_value_id'],
                        'name' => $option['name'],
                        'value' => $option['value']
                    ];
                }
            }

            // Process subscription
            $subscription_data = [];
            if (!empty($product['subscription']) && is_array($product['subscription'])) {
                $price = $this->currency->format(
                    $this->tax->calculate(
                        $product['subscription']['price'],
                        $product['tax_class_id'],
                        $this->config->get('config_tax')
                    ),
                    $currency
                );

                $subscription_data = [
                    'subscription_plan_id' => $product['subscription']['subscription_plan_id'],
                    'name' => $product['subscription']['name'],
                    'price' => $price,
                    'frequency' => $product['subscription']['frequency'],
                    'duration' => $product['subscription']['duration']
                ];
            }

            $json['products'][] = [
                'cart_id' => $product['cart_id'],
                'product_id' => $product['product_id'],
                'name' => $product['name'],
                'model' => $product['model'],
                'option' => $option_data,
                'subscription' => $subscription_data,
                'quantity' => $product['quantity'],
                'stock' => $product['stock'],
                'minimum' => $product['minimum'],
                'price' => $this->currency->format($product['price'], $currency),
                'total' => $this->currency->format($product['total'], $currency),
                'reward' => $product['reward'] ?? 0,
                'points' => $product['points'] ?? 0,
                'tax_class_id' => $product['tax_class_id'],
                'weight' => $product['weight'],
                'weight_class_id' => $product['weight_class_id'],
                'length' => $product['length'],
                'width' => $product['width'],
                'height' => $product['height'],
                'length_class_id' => $product['length_class_id'],
                'recurring' => $product['recurring'] ?? 0,
                'image' => $this->model_tool_image->resize($product['image'], 100, 100)
            ];
        }

        $json['totals'] = [];



        $json['totals'] = [];
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

            // Add each tax to totals
            foreach ($taxes as $key => $value) {
                $totals[] = [
                    'code'       => 'tax',
                    'title'      => $this->tax->getRateName($key),
                    'value'      => $value,
                    'sort_order' => 2
                ];
            }
        }

        // Calculate final total
        $total = $sub_total;
        foreach ($taxes as $value) {
            $total += $value;
        }

        // Add total
        $totals[] = [
            'code'       => 'total',
            'title'      => 'Total',
            'value'      => $total,
            'sort_order' => 9999
        ];

        // Sort totals by sort_order
        array_multisort(array_column($totals, 'sort_order'), SORT_ASC, $totals);

        // Format for output
        foreach ($totals as $result) {
            $json['totals'][] = [
                'title' => $result['title'],
                'value' => $this->currency->format($result['value'], $currency)
            ];
        }


        // Check if any product requires shipping
        $has_shipping = false;
        foreach ($products as $product) {
            if (!empty($product['shipping']) && $product['shipping']) {
                $has_shipping = true;
                break;
            }
        }

        $json['has_shipping'] = $has_shipping;


        $this->response->setOutput($this->jsonp($json, true));
    }


    /**
     * Add product to cart
     *
     * @return void
     */
    public function add(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $json = [];

        // Get and validate basic parameters
        $product_id = isset($this->request->post['product_id']) ? (int)$this->request->post['product_id'] : 0;
        $quantity = isset($this->request->post['quantity']) ? (int)$this->request->post['quantity'] : 1;
        $subscription_plan_id = isset($this->request->post['subscription_plan_id']) ?
            (int)$this->request->post['subscription_plan_id'] : 0;

        // Handle options
        $raw_options = isset($this->request->post['option']) ? $this->request->post['option'] : '{}';

        // If options is already an array
        if (is_array($raw_options)) {
            $options = $raw_options;
        } else {
            // Clean up the input string
            $raw_options = str_replace('&quot;', '"', $raw_options);
            $raw_options = stripslashes($raw_options);
            $raw_options = trim($raw_options);

            try {
                $options = json_decode($raw_options, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception(json_last_error_msg());
                }
            } catch (\Exception $e) {
                $this->log->write("JSON decode error: " . $e->getMessage());
                $this->log->write("Raw options (hex): " . bin2hex($raw_options));
                $options = [];
            }
        }

        // Validate options is an array
        if (!is_array($options)) {
            $this->log->write("Error: Decoded options is not an array, type: " . gettype($options));
            $options = [];
        }

        // Validate and sanitize options
        $sanitized_options = [];
        if (is_array($options)) {
            foreach ($options as $option_id => $option_value) {
                // Convert option_id to integer for array key
                $clean_option_id = (int)$option_id;

                // Handle different option value types
                if (is_array($option_value)) {
                    // Handle checkbox type (multiple values)
                    $sanitized_options[$clean_option_id] = array_map('intval', $option_value);
                } else if (is_string($option_value)) {
                    if (strpos($option_id, 'date') !== false || strpos($option_id, 'time') !== false) {
                        // Preserve date/time string format
                        $sanitized_options[$clean_option_id] = trim($option_value);
                    } else {
                        // Regular text input
                        $sanitized_options[$clean_option_id] = htmlspecialchars(trim($option_value));
                    }
                } else if (is_numeric($option_value)) {
                    // Handle numeric values (radio, select)
                    $sanitized_options[$clean_option_id] = (string)$option_value;
                }
            }
        }

        $this->load->model('catalog/product');
        $this->load->model('mobile/cart');

        $product_info = $this->model_catalog_product->getProduct($product_id);

        if (!$product_info) {
            $json['error']['warning'] = $this->language->get('error_product');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        // Validate product options
        $product_options = $this->model_catalog_product->getOptions($product_id);

        foreach ($product_options as $product_option) {
            $option_id = $product_option['product_option_id'];

            if ($product_option['required']) {
                if (
                    !isset($sanitized_options[$option_id]) ||
                    (is_string($sanitized_options[$option_id]) && trim($sanitized_options[$option_id]) === '') ||
                    (is_array($sanitized_options[$option_id]) && empty($sanitized_options[$option_id])) ||
                    $sanitized_options[$option_id] === null
                ) {
                    $json['error']['option'][$option_id] = sprintf($this->language->get('error_required'), $product_option['name']);
                    continue;
                }
            }

            // Validate option values
            if (isset($sanitized_options[$option_id])) {
                $value = $sanitized_options[$option_id];

                switch ($product_option['type']) {
                    case 'text':
                    case 'textarea':
                        if (isset($product_option['min_length']) && strlen($value) < $product_option['min_length']) {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_min_length'),
                                $product_option['name'],
                                $product_option['min_length']
                            );
                        }
                        if (isset($product_option['max_length']) && strlen($value) > $product_option['max_length']) {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_max_length'),
                                $product_option['name'],
                                $product_option['max_length']
                            );
                        }
                        break;

                    case 'date':
                        if (!empty($value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_date'),
                                $product_option['name']
                            );
                        }
                        break;

                    case 'time':
                        if (!empty($value) && !preg_match('/^\d{2}:\d{2}$/', $value)) {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_time'),
                                $product_option['name']
                            );
                        }
                        break;

                    case 'datetime':
                        if (!empty($value) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_datetime'),
                                $product_option['name']
                            );
                        }
                        break;

                    case 'select':
                    case 'radio':
                        // Get valid option values
                        $valid_values = [];
                        foreach ($product_option['product_option_value'] as $option_value) {
                            $valid_values[] = (string)$option_value['product_option_value_id'];
                        }

                        // Validate selected value exists
                        if (!in_array((string)$value, $valid_values)) {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_invalid_option'),
                                $product_option['name']
                            );
                        }
                        break;

                    case 'checkbox':
                        if (is_array($value)) {
                            $valid_values = [];
                            foreach ($product_option['product_option_value'] as $option_value) {
                                $valid_values[] = (string)$option_value['product_option_value_id'];
                            }

                            foreach ($value as $checkbox_value) {
                                if (!in_array((string)$checkbox_value, $valid_values)) {
                                    $json['error']['option'][$option_id] = sprintf(
                                        $this->language->get('error_invalid_option'),
                                        $product_option['name']
                                    );
                                    break;
                                }
                            }
                        } else {
                            $json['error']['option'][$option_id] = sprintf(
                                $this->language->get('error_invalid_option'),
                                $product_option['name']
                            );
                        }
                        break;
                }
            }
        }

        if (!$json) {
            $this->model_mobile_cart->add($customer['customer_id'], $product_id, $quantity, $sanitized_options, $subscription_plan_id);

            $json['success'] = sprintf(
                $this->language->get('text_success'),
                $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product_id),
                $product_info['name'],
                $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'))
            );

            // Get updated cart count
            $json['total'] = $this->model_mobile_cart->getCartItemCount($customer['customer_id']);
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Update cart item
     *
     * @return void
     */
    public function update(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/cart');

        $json = [];

        $cart_id = isset($this->request->post['cart_id']) ? (int)$this->request->post['cart_id'] : 0;
        $quantity = isset($this->request->post['quantity']) ? (int)$this->request->post['quantity'] : 1;

        $this->load->model('mobile/cart');

        if (!$this->model_mobile_cart->hasProduct($customer['customer_id'], $cart_id)) {
            $json['error']['warning'] = $this->language->get('error_product');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        $this->model_mobile_cart->update($customer['customer_id'], $cart_id, $quantity);

        $json['success'] = $this->language->get('text_edit');

        // Get updated cart count
        $json['total'] = $this->model_mobile_cart->getCartItemCount($customer['customer_id']);

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Remove item from cart
     *
     * @return void
     */
    public function remove(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/cart');

        $json = [];

        $cart_id = isset($this->request->post['cart_id']) ? (int)$this->request->post['cart_id'] : 0;

        $this->load->model('mobile/cart');

        if (!$this->model_mobile_cart->hasProduct($customer['customer_id'], $cart_id)) {
            $json['error']['warning'] = $this->language->get('error_product');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        $this->model_mobile_cart->remove($customer['customer_id'], $cart_id);

        $json['success'] = $this->language->get('text_remove');

        // Get updated cart count
        $json['total'] = $this->model_mobile_cart->getCartItemCount($customer['customer_id']);

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Set shipping address
     *
     * @return void
     */
    public function setShippingAddress(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_address');

        $json = [];

        if (!$this->cart->hasShipping()) {
            $json['error'] = $this->language->get('error_not_required');
        } else {
            // Validate required fields
            $required_fields = [
                'firstname'    => 'error_firstname',
                'lastname'     => 'error_lastname',
                'address_1'    => 'error_address_1',
                'city'        => 'error_city',
                'country_id'  => 'error_country',
                'zone_id'     => 'error_zone',
                'postcode'    => 'error_postcode'
            ];

            foreach ($required_fields as $field => $error) {
                if (empty($this->request->post[$field])) {
                    $json['error'][$field] = $this->language->get($error);
                }
            }

            // Validate country
            if (!empty($this->request->post['country_id'])) {
                $this->load->model('localisation/country');

                $country_info = $this->model_localisation_country->getCountry($this->request->post['country_id']);

                if (!$country_info) {
                    $json['error']['country'] = $this->language->get('error_country');
                } elseif ($country_info['postcode_required'] && empty($this->request->post['postcode'])) {
                    $json['error']['postcode'] = $this->language->get('error_postcode');
                }
            }

            // Validate zone
            if (!empty($this->request->post['zone_id'])) {
                $this->load->model('localisation/zone');

                $zone_info = $this->model_localisation_zone->getZone($this->request->post['zone_id']);

                if (!$zone_info) {
                    $json['error']['zone'] = $this->language->get('error_zone');
                }
            }

            if (!$json) {
                $this->load->model('mobile/cart');

                $address_data = [
                    'firstname' => $this->request->post['firstname'],
                    'lastname'  => $this->request->post['lastname'],
                    'address_1' => $this->request->post['address_1'],
                    'address_2' => $this->request->post['address_2'] ?? '',
                    'city'      => $this->request->post['city'],
                    'postcode'  => $this->request->post['postcode'],
                    'country_id' => (int)$this->request->post['country_id'],
                    'zone_id'   => (int)$this->request->post['zone_id']
                ];

                $this->model_mobile_cart->setShippingAddress($customer['customer_id'], $address_data);

                $json['success'] = $this->language->get('text_success');

                // Clear any previously selected shipping method
                $this->model_mobile_cart->clearShippingMethod($customer['customer_id']);
            }
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Get shipping methods
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

        $this->load->language('checkout/shipping_method');

        $json = [];

        if (!$this->cart->hasShipping()) {
            $json['error'] = $this->language->get('error_not_required');
        } else {
            $this->load->model('mobile/cart');

            // Check if shipping address is set
            $shipping_address = $this->model_mobile_cart->getShippingAddress($customer['customer_id']);

            if (!$shipping_address) {
                $json['error'] = $this->language->get('error_address');
            } else {
                // Load available shipping methods
                $this->load->model('checkout/shipping_method');

                $shipping_methods = $this->model_checkout_shipping_method->getMethods($shipping_address);

                if ($shipping_methods) {
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

                    if (!empty($json['shipping_methods'])) {
                        // Get currently selected method
                        $selected_method = $this->model_mobile_cart->getShippingMethod($customer['customer_id']);
                        if ($selected_method) {
                            $json['selected'] = $selected_method['code'];
                        }
                    } else {
                        $json['error'] = sprintf($this->language->get('error_no_shipping'), $this->url->link('information/contact'));
                    }
                } else {
                    $json['error'] = sprintf($this->language->get('error_no_shipping'), $this->url->link('information/contact'));
                }
            }
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Set shipping method
     *
     * @return void
     */
    public function setShippingMethod(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->language('checkout/shipping_method');

        $json = [];

        if (!$this->cart->hasShipping()) {
            $json['error'] = $this->language->get('error_not_required');
        } else {
            if (!isset($this->request->post['shipping_method'])) {
                $json['error'] = $this->language->get('error_shipping');
            } else {
                $shipping = explode('.', $this->request->post['shipping_method']);

                if (!isset($shipping[0]) || !isset($shipping[1])) {
                    $json['error'] = $this->language->get('error_shipping');
                }
            }

            if (!$json) {
                $this->load->model('mobile/cart');

                // Load available shipping methods
                $this->load->model('checkout/shipping_method');
                $shipping_address = $this->model_mobile_cart->getShippingAddress($customer['customer_id']);

                if ($shipping_address) {
                    $shipping_methods = $this->model_checkout_shipping_method->getMethods($shipping_address);

                    if (isset($shipping_methods[$shipping[0]]['quote'][$shipping[1]])) {
                        $shipping_method = $shipping_methods[$shipping[0]]['quote'][$shipping[1]];

                        // Save selected shipping method
                        $this->model_mobile_cart->setShippingMethod($customer['customer_id'], $shipping_method);

                        $json['success'] = $this->language->get('text_success');
                    } else {
                        $json['error'] = $this->language->get('error_shipping');
                    }
                } else {
                    $json['error'] = $this->language->get('error_address');
                }
            }
        }

        $this->response->setOutput($this->jsonp($json, true));
    }
}
