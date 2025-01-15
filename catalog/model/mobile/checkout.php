<?php

namespace Opencart\Catalog\Model\Mobile;

class Checkout extends \Opencart\System\Engine\Model
{
    public function getShippingMethods(): array
    {
        $method_data = [];

        $this->load->model('setting/extension');

        $results = $this->model_setting_extension->getExtensionsByType('shipping');

        foreach ($results as $result) {
            // Get all shipping methods, regardless of status


            $method_data[] = [
                'code' => $result['code'],
                'shipping_method' => ucfirst($result['code']),
            ];
        }

        // Sort by title
        usort($method_data, function ($a, $b) {
            return strcasecmp($a['title'], $b['title']);
        });

        return $method_data;
    }

    public function getCheckoutData(int $customer_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mobile_checkout` 
            WHERE customer_id = '" . (int)$customer_id . "'");

        return $query->row;
    }


    public function fetchPaymentMethods(): array
    {
        $method_data = [];

        $this->load->model('setting/extension');

        $results = $this->model_setting_extension->getExtensionsByType('payment');

        foreach ($results as $result) {
            // Get all methods regardless of status

            $terms = $this->config->get('payment_' . $result['code'] . '_terms');
            $sort_order = $this->config->get('payment_' . $result['code'] . '_sort_order');

            $method_data[] = [
                'code' => (string)$result['code'],
                'title' =>   ucfirst($result['code']),
                'terms' => $terms ? $terms : '',
                'sortOrder' => $sort_order ? (string)$sort_order : '0'
            ];
        }

        // Sort by sortOrder
        usort($method_data, function ($a, $b) {
            return $a['sortOrder'] - $b['sortOrder'];
        });

        return $method_data;
    }

    public function setPaymentMethod(int $customer_id, string $payment_code): bool
    {
        if (!$this->config->get('payment_' . $payment_code . '_status')) {
            return false;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "mobile_checkout` 
            SET customer_id = '" . (int)$customer_id . "',
                payment_method = '" . $this->db->escape($payment_code) . "',
                date_modified = NOW()
            ON DUPLICATE KEY UPDATE 
                payment_method = '" . $this->db->escape($payment_code) . "',
                date_modified = NOW()");

        return true;
    }

    public function setShippingAddress(int $customer_id, int $address_id): bool
    {
        $this->load->model('account/address');

        $address_info = $this->model_account_address->getAddress($customer_id, $address_id);

        if (!$address_info) {
            return false;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "mobile_checkout` 
            SET customer_id = '" . (int)$customer_id . "',
                shipping_address_id = '" . (int)$address_id . "',
                date_modified = NOW()
            ON DUPLICATE KEY UPDATE 
                shipping_address_id = '" . (int)$address_id . "',
                date_modified = NOW()");

        return true;
    }

    public function setShippingMethod(int $customer_id, string $shipping_code): bool
    {
        $this->load->model('setting/extension');

        // First check if shipping method exists at all
        $results = $this->model_setting_extension->getExtensionsByType('shipping');
        $valid_method = false;

        foreach ($results as $result) {
            if ($result['code'] === $shipping_code) {
                $valid_method = true;
                break;
            }
        }

        if (!$valid_method) {
            return false;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "mobile_checkout` 
            SET customer_id = '" . (int)$customer_id . "',
                shipping_method = '" . $this->db->escape($shipping_code) . "',
                date_modified = NOW()
            ON DUPLICATE KEY UPDATE 
                shipping_method = '" . $this->db->escape($shipping_code) . "',
                date_modified = NOW()");

        return true;
    }


    public function applyCoupon(int $customer_id, string $coupon): array
    {
        if (!$this->config->get('total_coupon_status')) {
            return ['error' => $this->language->get('error_status')];
        }

        $this->load->model('marketing/coupon');
        $coupon_info = $this->model_marketing_coupon->getCoupon($coupon);

        if (!$coupon_info) {
            return ['error' => $this->language->get('error_coupon')];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "mobile_checkout` 
        SET customer_id = '" . (int)$customer_id . "',
            coupon_code = '" . $this->db->escape($coupon) . "',
            date_modified = NOW()
        ON DUPLICATE KEY UPDATE 
            coupon_code = '" . $this->db->escape($coupon) . "',
            date_modified = NOW()");

        return [
            'success' => true,
            'coupon' => [
                'name' => $coupon_info['name'],
                'code' => $coupon_info['code'],
                'discount' => $coupon_info['discount']
            ]
        ];
    }

    public function removeCoupon(int $customer_id): array
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "mobile_checkout` 
        SET coupon_code = NULL,
            date_modified = NOW()
        WHERE customer_id = '" . (int)$customer_id . "'");

        return ['success' => true];
    }

    public function applyVoucher(int $customer_id, string $voucher): array
    {
        if (!$this->config->get('total_voucher_status')) {
            return ['error' => $this->language->get('error_status')];
        }

        $this->load->model('checkout/voucher');
        $voucher_info = $this->model_checkout_voucher->getVoucher($voucher);

        if (!$voucher_info) {
            return ['error' => $this->language->get('error_voucher')];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "mobile_checkout` 
        SET customer_id = '" . (int)$customer_id . "',
            voucher_code = '" . $this->db->escape($voucher) . "',
            date_modified = NOW()
        ON DUPLICATE KEY UPDATE 
            voucher_code = '" . $this->db->escape($voucher) . "',
            date_modified = NOW()");

        return [
            'success' => true,
            'voucher' => [
                'code' => $voucher_info['code'],
                'amount' => $voucher_info['amount']
            ]
        ];
    }

    public function removeVoucher(int $customer_id): array
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "mobile_checkout` 
        SET voucher_code = NULL,
            date_modified = NOW()
        WHERE customer_id = '" . (int)$customer_id . "'");

        return ['success' => true];
    }

    public function applyReward(int $customer_id, int $points): array
    {
        if (!$this->config->get('total_reward_status')) {
            return ['error' => $this->language->get('error_status')];
        }

        // Get available points
        $available_points = $this->customer->getRewardPoints();

        if ($points > $available_points) {
            return ['error' => sprintf($this->language->get('error_points'), $points)];
        }

        // Calculate maximum points that can be used
        $points_total = 0;
        $this->load->model('mobile/cart');
        $cart_products = $this->model_mobile_cart->getProducts($customer_id);

        foreach ($cart_products as $product) {
            if (isset($product['points'])) {
                $points_total += $product['points'];
            }
        }

        if ($points > $points_total) {
            return ['error' => sprintf($this->language->get('error_maximum'), $points_total)];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "mobile_checkout` 
        SET customer_id = '" . (int)$customer_id . "',
            reward_points = '" . (int)$points . "',
            date_modified = NOW()
        ON DUPLICATE KEY UPDATE 
            reward_points = '" . (int)$points . "',
            date_modified = NOW()");

        return [
            'success' => true,
            'points' => $points,
            'points_value' => $this->currency->format($this->config->get('config_reward_point_value') * $points)
        ];
    }

    public function removeReward(int $customer_id): array
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "mobile_checkout` 
        SET reward_points = NULL,
            date_modified = NOW()
        WHERE customer_id = '" . (int)$customer_id . "'");

        return ['success' => true];
    }

    public function getAppliedTotals(int $customer_id): array
    {
        // First check if the columns exist
        $column_query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "mobile_checkout` 
            WHERE Field IN ('coupon_code', 'voucher_code', 'reward_points')");

        $existing_columns = array_map(function ($row) {
            return $row['Field'];
        }, $column_query->rows);

        // Build the SELECT query dynamically based on existing columns
        $select_columns = [];
        foreach (['coupon_code', 'voucher_code', 'reward_points'] as $column) {
            if (in_array($column, $existing_columns)) {
                $select_columns[] = $column;
            }
        }

        if (empty($select_columns)) {
            return [];
        }

        $query = $this->db->query("SELECT " . implode(', ', $select_columns) . " 
            FROM `" . DB_PREFIX . "mobile_checkout` 
            WHERE customer_id = '" . (int)$customer_id . "'");

        $totals = [];

        if ($query->row) {
            if (isset($query->row['coupon_code']) && $query->row['coupon_code']) {
                $this->load->model('marketing/coupon');
                $coupon_info = $this->model_marketing_coupon->getCoupon($query->row['coupon_code']);
                if ($coupon_info) {
                    $totals['coupon'] = $coupon_info;
                }
            }

            if (isset($query->row['voucher_code']) && $query->row['voucher_code']) {
                $this->load->model('checkout/voucher');
                $voucher_info = $this->model_checkout_voucher->getVoucher($query->row['voucher_code']);
                if ($voucher_info) {
                    $totals['voucher'] = $voucher_info;
                }
            }

            if (isset($query->row['reward_points']) && $query->row['reward_points']) {
                $totals['reward'] = [
                    'points' => $query->row['reward_points'],
                    'value' => $this->currency->format($this->config->get('config_reward_point_value') * $query->row['reward_points'])
                ];
            }
        }

        return $totals;
    }

    public function reviewOrder(int $customer_id): array
    {
        // Get checkout data
        $checkout_data = $this->getCheckoutData($customer_id);
        if (!$checkout_data) {
            return ['error' => 'No checkout data found'];
        }
        $this->load->model('mobile/cart');
        // Get cart products
        $cart_products = $this->model_mobile_cart->getProducts($customer_id);
        if (empty($cart_products)) {
            return ['error' => 'Cart is empty'];
        }

        // Get shipping address
        $this->load->model('account/address');
        $shipping_address = [];
        if (!empty($checkout_data['shipping_address_id'])) {

            $results = $this->model_account_address->getAddresses($customer_id, $checkout_data['shipping_address_id']);

            foreach ($results as $result) {
                $shipping_address = [
                    'address_id' => (int)$result['address_id'],
                    'firstname'  => $result['firstname'],
                    'lastname'   => $result['lastname'],
                    'company'    => $result['company'],
                    'address_1'  => $result['address_1'],
                    'address_2'  => $result['address_2'],
                    'city'       => $result['city'],
                    'postcode'   => $result['postcode'],
                    'zone'       => $result['zone'],
                    'zone_code'  => $result['zone_code'],
                    'country'    => $result['country']
                ];
            }
        }



        // Get shipping method details
        $shipping_method = [];
        if (!empty($checkout_data['shipping_method'])) {
            $shipping_methods = $this->getShippingMethods();
            foreach ($shipping_methods as $method) {
                if ($method['code'] === $checkout_data['shipping_method']) {
                    $shipping_method = $method;
                    break;
                }
            }
        }

        // Get payment method details
        $payment_method = [];
        if (!empty($checkout_data['payment_method'])) {
            $payment_methods = $this->fetchPaymentMethods();
            foreach ($payment_methods as $method) {
                if ($method['code'] === $checkout_data['payment_method']) {
                    $payment_method = $method;
                    break;
                }
            }
        }

        // Calculate totals
        $subtotal = 0;
        foreach ($cart_products as $product) {
            $subtotal += $product['total'];
        }

        // Get applied discounts and rewards
        $applied_totals = $this->getAppliedTotals($customer_id);



           // Calculate taxes if needed
           if ($this->config->get('config_tax')) {
            foreach ($result as $product) {
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
                $taxes = [
                    'code'       => 'tax',
                    'title'      => $this->tax->getRateName($key),
                    'value'      => $value,
                    'sort_order' => 2
                ];
            }
        }

        // Calculate final total
        
    



        // Calculate final total
        $total = $subtotal;

        foreach ($taxes as $value) {
            $total += $value;
        }

        $applied_totals['taxes'] = [
            ''
        ];

        if (isset($applied_totals['coupon'])) {
            $total -= $applied_totals['coupon']['discount'];
        }
        if (isset($applied_totals['voucher'])) {
            $total -= $applied_totals['voucher']['amount'];
        }
        if (isset($applied_totals['reward'])) {
            $total -= $applied_totals['reward']['value'];
        }


        

        // Format currency values
        $this->load->model('localisation/currency');
        $currency = $this->config->get('config_currency');


        return [
            'totals' => [
                'subtotal' => $this->currency->format($subtotal, $currency),
                'total' => $this->currency->format($total, $currency),
                'applied_totals' => $applied_totals
            ],
            'shipping_address' => $shipping_address,
            'shipping_method' => $shipping_method,
            'payment_method' => $payment_method,
        ];
    }
    public function clearCheckoutData(int $customer_id)
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "mobile_checkout` 
        WHERE customer_id = " . (int)$customer_id);
    }
}
