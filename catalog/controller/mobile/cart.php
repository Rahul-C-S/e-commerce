<?php

namespace Opencart\Catalog\Controller\Mobile;

class Cart extends ApiController
{
    /**
     * Get cart items for a customer
     *
     * @return void
     */
    public function items(): void
    {
        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $this->load->model('mobile/cart');
        $this->load->model('tool/image');
        $this->load->model('tool/upload');

        $customer_id = $customer['customer_id'];
        $products = $this->model_mobile_cart->getProducts($customer_id);

        $json['products'] = [];

        // Get default currency from config if session is not available
        $currency = $this->config->get('config_currency');

        foreach ($products as $product) {
            $option_data = [];

            // Check if option exists and is an array before processing
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

            // Get subscription details if exists
            $subscription_data = '';
            if (!empty($product['subscription']) && is_array($product['subscription'])) {
                $price = $this->currency->format($this->tax->calculate($product['subscription']['price'], $product['tax_class_id'], $this->config->get('config_tax')), $currency);
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
                'price' => $this->currency->format($product['price'], $currency),
                'total' => $this->currency->format($product['total'], $currency),
                'image' => $this->model_tool_image->resize($product['image'], 100, 100)
            ];
        }

        // Get totals
        $totals = [];
        $taxes = $this->cart->getTaxes();
        $total = 0;

        $this->model_mobile_cart->getTotals($totals, $taxes, $total);

        foreach ($totals as $total) {
            $json['totals'][] = [
                'title' => $total['title'],
                'value' => $this->currency->format($total['value'], $currency)
            ];
        }
        $this->log->write(print_r($json, true));
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
         $subscription_plan_id = isset($this->request->post['subscription_plan_id']) ? (int)$this->request->post['subscription_plan_id'] : 0;
     
         // Handle options - get raw option data
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
                     $sanitized_options[$clean_option_id] = (string)$option_value;  // Keep as string to match product_option_value_id
                 }
             }
         }
     
         $this->load->model('catalog/product');
         $this->load->model('mobile/cart');
     
         $product_info = $this->model_catalog_product->getProduct($product_id);
     
         if (!$product_info) {
             $json['error']['warning'] = 'Product not found';
             $this->response->setOutput($this->jsonp($json, true));
             return;
         }
     
         // Validate product options
         $product_options = $this->model_catalog_product->getOptions($product_id);
         
         foreach ($product_options as $product_option) {
             $option_id = $product_option['product_option_id'];
     
             // Debug log for each option being processed
             $this->log->write("Processing option {$option_id}:");
             $this->log->write("- Type: " . $product_option['type']);
             $this->log->write("- Required: " . ($product_option['required'] ? 'yes' : 'no'));
             $this->log->write("- Value received: " . (isset($sanitized_options[$option_id]) ? var_export($sanitized_options[$option_id], true) : 'not set'));
     
             if ($product_option['required']) {
                 if (!isset($sanitized_options[$option_id]) || 
                     (is_string($sanitized_options[$option_id]) && trim($sanitized_options[$option_id]) === '') || 
                     (is_array($sanitized_options[$option_id]) && empty($sanitized_options[$option_id])) ||
                     $sanitized_options[$option_id] === null) {
                     
                     $json['error']['option'][$option_id] = $product_option['name'] . ' is required!';
                     continue;
                 }
             }
     
             // Validate option value if set
             if (isset($sanitized_options[$option_id])) {
                 $value = $sanitized_options[$option_id];
                 
                 switch ($product_option['type']) {
                     case 'text':
                     case 'textarea':
                         if (isset($product_option['min_length']) && strlen($value) < $product_option['min_length']) {
                             $json['error']['option'][$option_id] = $product_option['name'] . ' must be at least ' . $product_option['min_length'] . ' characters!';
                         }
                         if (isset($product_option['max_length']) && strlen($value) > $product_option['max_length']) {
                             $json['error']['option'][$option_id] = $product_option['name'] . ' cannot be more than ' . $product_option['max_length'] . ' characters!';
                         }
                         break;
     
                     case 'date':
                         if (!empty($value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                             $json['error']['option'][$option_id] = 'Invalid date format for ' . $product_option['name'];
                         }
                         break;
     
                     case 'time':
                         if (!empty($value) && !preg_match('/^\d{2}:\d{2}$/', $value)) {
                             $json['error']['option'][$option_id] = 'Invalid time format for ' . $product_option['name'];
                         }
                         break;
     
                     case 'datetime':
                         if (!empty($value) && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                             $json['error']['option'][$option_id] = 'Invalid datetime format for ' . $product_option['name'];
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
                             $json['error']['option'][$option_id] = 'Invalid value for ' . $product_option['name'];
                             // Debug log for validation failure
                             $this->log->write("Invalid value for {$product_option['name']}: Received '{$value}', Valid values: " . implode(', ', $valid_values));
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
                                     $json['error']['option'][$option_id] = 'Invalid value for ' . $product_option['name'];
                                     break;
                                 }
                             }
                         } else {
                             $json['error']['option'][$option_id] = 'Invalid value for ' . $product_option['name'];
                         }
                         break;
                 }
             }
         }
     
         // If there are any option errors, return them
         if (!empty($json['error'])) {
             $this->log->write("Validation errors: " . print_r($json['error'], true));
             $this->response->setOutput($this->jsonp($json, true));
             return;
         }
     
         // Add to cart
         $this->model_mobile_cart->add($customer['customer_id'], $product_id, $quantity, $sanitized_options, $subscription_plan_id);
     
         $json['success'] = $product_info['name'] . ' added to cart';
         $this->response->setOutput($this->jsonp($json, true));
     }

    /**
     * Update cart item quantity
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
        $this->response->setOutput($this->jsonp($json, true));
    }
}
