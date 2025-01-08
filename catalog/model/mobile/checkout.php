<?php

namespace Opencart\Catalog\Model\Mobile;

class Checkout extends \Opencart\System\Engine\Model
{

    /**
     * Get shipping address for customer
     *
     * @param int $customer_id
     * @return array
     */
    public function getShippingAddress(int $customer_id): array
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "address 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND address_id = (
                SELECT address_id 
                FROM " . DB_PREFIX . "customer 
                WHERE customer_id = '" . (int)$customer_id . "'
            )");

        if ($query->num_rows) {
            $country_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "country 
                WHERE country_id = '" . (int)$query->row['country_id'] . "'");

            $zone_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone 
                WHERE zone_id = '" . (int)$query->row['zone_id'] . "'");

            return [
                'address_id'     => $query->row['address_id'],
                'firstname'      => $query->row['firstname'],
                'lastname'       => $query->row['lastname'],
                'company'        => $query->row['company'],
                'address_1'      => $query->row['address_1'],
                'address_2'      => $query->row['address_2'],
                'postcode'       => $query->row['postcode'],
                'city'          => $query->row['city'],
                'zone_id'       => $query->row['zone_id'],
                'zone'          => ($zone_query->num_rows ? $zone_query->row['name'] : ''),
                'zone_code'     => ($zone_query->num_rows ? $zone_query->row['code'] : ''),
                'country_id'    => $query->row['country_id'],
                'country'       => ($country_query->num_rows ? $country_query->row['name'] : ''),
                'iso_code_2'    => ($country_query->num_rows ? $country_query->row['iso_code_2'] : ''),
                'iso_code_3'    => ($country_query->num_rows ? $country_query->row['iso_code_3'] : ''),
                'address_format' => ($country_query->num_rows ? $country_query->row['address_format'] : '')
            ];
        }

        return [];
    }

    /**
     * Set shipping address
     *
     * @param int $customer_id
     * @param array $data
     * @return int
     */
    public function setShippingAddress(int $customer_id, array $data): int
    {
        $this->db->query("INSERT INTO " . DB_PREFIX . "address SET 
            customer_id = '" . (int)$customer_id . "',
            firstname = '" . $this->db->escape($data['firstname']) . "',
            lastname = '" . $this->db->escape($data['lastname']) . "',
            company = '" . $this->db->escape($data['company']) . "',
            address_1 = '" . $this->db->escape($data['address_1']) . "',
            address_2 = '" . $this->db->escape($data['address_2']) . "',
            postcode = '" . $this->db->escape($data['postcode']) . "',
            city = '" . $this->db->escape($data['city']) . "',
            zone_id = '" . (int)$data['zone_id'] . "',
            country_id = '" . (int)$data['country_id'] . "'");

        $address_id = $this->db->getLastId();

        // Set as default shipping address
        $this->db->query("UPDATE " . DB_PREFIX . "customer SET 
            address_id = '" . (int)$address_id . "' 
            WHERE customer_id = '" . (int)$customer_id . "'");

        return $address_id;
    }

    /**
     * Delete shipping address
     *
     * @param int $customer_id
     * @param int $address_id
     * @return void
     */
    public function deleteShippingAddress(int $customer_id, int $address_id): void
    {
        $this->db->query("DELETE FROM " . DB_PREFIX . "address 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND address_id = '" . (int)$address_id . "'");
    }
    /**
     * Get shipping methods
     *
     * @param array $shipping_address
     * @return array
     */
    public function getShippingMethods(array $shipping_address): array
    {
        $method_data = [];

        $this->load->model('setting/extension');

        $results = $this->model_setting_extension->getExtensionsByType('shipping');

        foreach ($results as $result) {
            if ($this->config->get('shipping_' . $result['code'] . '_status')) {
                $this->load->model('extension/shipping/' . $result['code']);

                $quote = $this->{'model_extension_shipping_' . $result['code']}->getQuote($shipping_address);

                if ($quote) {
                    $method_data[$result['code']] = [
                        'title'      => $quote['title'],
                        'quote'      => $quote['quote'],
                        'sort_order' => $quote['sort_order'],
                        'error'      => $quote['error']
                    ];
                }
            }
        }

        $sort_order = [];

        foreach ($method_data as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $method_data);

        return $method_data;
    }

    /**
     * Set shipping method in session
     *
     * @param int $customer_id
     * @param string $shipping_method
     * @param string $shipping_code
     * @return void
     */
    public function setShippingMethod(int $customer_id, string $shipping_method, string $shipping_code): void
    {
        $this->session->data['shipping_method'] = $shipping_method;
        $this->session->data['shipping_code'] = $shipping_code;

        $this->db->query("UPDATE " . DB_PREFIX . "cart_shipping SET 
            shipping_method = '" . $this->db->escape($shipping_method) . "',
            shipping_code = '" . $this->db->escape($shipping_code) . "'
            WHERE customer_id = '" . (int)$customer_id . "'");
    }

    /**
     * Get selected shipping method
     *
     * @param int $customer_id
     * @return array
     */
    public function getSelectedShippingMethod(int $customer_id): array
    {
        $query = $this->db->query("SELECT shipping_method, shipping_code 
            FROM " . DB_PREFIX . "cart_shipping 
            WHERE customer_id = '" . (int)$customer_id . "'");

        if ($query->num_rows) {
            return [
                'shipping_method' => $query->row['shipping_method'],
                'shipping_code' => $query->row['shipping_code']
            ];
        }

        return [];
    }
    /**
     * Get voucher info
     *
     * @param string $code
     * @return array
     */
    public function getVoucher(string $code): array
    {
        $status = true;

        $voucher_query = $this->db->query("SELECT *, vtd.name AS theme FROM " . DB_PREFIX . "voucher v 
            LEFT JOIN " . DB_PREFIX . "voucher_theme vt ON (v.voucher_theme_id = vt.voucher_theme_id) 
            LEFT JOIN " . DB_PREFIX . "voucher_theme_description vtd ON (vt.voucher_theme_id = vtd.voucher_theme_id) 
            WHERE v.code = '" . $this->db->escape($code) . "' 
            AND vtd.language_id = '" . (int)$this->config->get('config_language_id') . "' 
            AND v.status = '1'");

        if ($voucher_query->num_rows) {
            if ($voucher_query->row['order_id']) {
                $order_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` 
                    WHERE order_id = '" . (int)$voucher_query->row['order_id'] . "' 
                    AND order_status_id = '" . (int)$this->config->get('config_complete_status_id') . "'");

                if (!$order_query->num_rows) {
                    $status = false;
                }

                $order_voucher_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_voucher` 
                    WHERE order_id = '" . (int)$voucher_query->row['order_id'] . "' 
                    AND voucher_id = '" . (int)$voucher_query->row['voucher_id'] . "'");

                if (!$order_voucher_query->num_rows) {
                    $status = false;
                }
            }

            $voucher_history_query = $this->db->query("SELECT SUM(amount) AS total 
                FROM `" . DB_PREFIX . "voucher_history` vh 
                WHERE vh.voucher_id = '" . (int)$voucher_query->row['voucher_id'] . "' 
                GROUP BY vh.voucher_id");

            if ($voucher_history_query->num_rows) {
                $amount = $voucher_query->row['amount'] + $voucher_history_query->row['total'];
            } else {
                $amount = $voucher_query->row['amount'];
            }

            if ($amount <= 0) {
                $status = false;
            }
        } else {
            $status = false;
        }

        if ($status) {
            return [
                'voucher_id'  => $voucher_query->row['voucher_id'],
                'code'        => $voucher_query->row['code'],
                'from_name'   => $voucher_query->row['from_name'],
                'from_email'  => $voucher_query->row['from_email'],
                'to_name'     => $voucher_query->row['to_name'],
                'to_email'    => $voucher_query->row['to_email'],
                'theme'       => $voucher_query->row['theme'],
                'amount'      => $amount,
                'status'      => $voucher_query->row['status'],
                'date_added'  => $voucher_query->row['date_added']
            ];
        }

        return [];
    }

    /**
     * Set voucher
     *
     * @param int $customer_id
     * @param string $code
     * @return void
     */
    public function setVoucher(int $customer_id, string $code): void
    {
        $this->db->query("DELETE FROM " . DB_PREFIX . "cart_voucher 
            WHERE customer_id = '" . (int)$customer_id . "'");

        $this->db->query("INSERT INTO " . DB_PREFIX . "cart_voucher SET 
            customer_id = '" . (int)$customer_id . "',
            code = '" . $this->db->escape($code) . "',
            date_added = NOW()");
    }

    /**
     * Remove voucher
     *
     * @param int $customer_id
     * @return void
     */
    public function removeVoucher(int $customer_id): void
    {
        $this->db->query("DELETE FROM " . DB_PREFIX . "cart_voucher 
            WHERE customer_id = '" . (int)$customer_id . "'");
    }

    /**
     * Get applied voucher
     *
     * @param int $customer_id
     * @return string
     */
    public function getAppliedVoucher(int $customer_id): string
    {
        $query = $this->db->query("SELECT code FROM " . DB_PREFIX . "cart_voucher 
            WHERE customer_id = '" . (int)$customer_id . "'");

        return $query->row['code'] ?? '';
    }
    /**
     * Get totals
     * 
     * @param array &$totals
     * @param array &$taxes
     * @param float &$total
     * @return void
     */
    public function getTotals(array &$totals, array &$taxes, float &$total): void
    {
        $this->load->model('setting/extension');

        $sort_order = [];

        $results = $this->model_setting_extension->getExtensionsByType('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);
                $this->{'model_extension_total_' . $result['code']}->getTotal($totals, $taxes, $total);
            }
        }

        $sort_order = [];

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);
    }

    /**
     * Prepare order data
     *
     * @param int $customer_id
     * @return array
     */
    public function prepareOrder(int $customer_id): array
    {
        $this->load->model('account/customer');
        $customer_info = $this->model_account_customer->getCustomer($customer_id);

        // Get default currency
        $currency_code = $this->session->data['currency'] ?? $this->config->get('config_currency');

        $order_data = [];

        // Store Info
        $order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
        $order_data['store_id'] = $this->config->get('config_store_id');
        $order_data['store_name'] = $this->config->get('config_name');
        $order_data['store_url'] = $this->config->get('config_url');

        // Customer Info
        $order_data['customer_id'] = $customer_info['customer_id'];
        $order_data['customer_group_id'] = $customer_info['customer_group_id'];
        $order_data['firstname'] = $customer_info['firstname'];
        $order_data['lastname'] = $customer_info['lastname'];
        $order_data['email'] = $customer_info['email'];
        $order_data['telephone'] = $customer_info['telephone'];

        // Shipping Address
        $shipping_address = $this->getShippingAddress($customer_id);
        if ($shipping_address) {
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
        }

        // Shipping Method
        $shipping_method = $this->getSelectedShippingMethod($customer_id);
        if ($shipping_method) {
            $order_data['shipping_method'] = $shipping_method['shipping_method'];
            $order_data['shipping_code'] = $shipping_method['shipping_code'];
        }

        // Products
        $order_data['products'] = [];

        $products = $this->cart->getProducts();

        foreach ($products as $product) {
            $option_data = [];

            foreach ($product['option'] as $option) {
                $option_data[] = [
                    'product_option_id'       => $option['product_option_id'],
                    'product_option_value_id' => $option['product_option_value_id'],
                    'option_id'               => $option['option_id'],
                    'option_value_id'         => $option['option_value_id'],
                    'name'                    => $option['name'],
                    'value'                   => $option['value'],
                    'type'                    => $option['type']
                ];
            }

            $order_data['products'][] = [
                'product_id' => $product['product_id'],
                'name'       => $product['name'],
                'model'      => $product['model'],
                'option'     => $option_data,
                'download'   => $product['download'],
                'quantity'   => $product['quantity'],
                'subtract'   => $product['subtract'],
                'price'      => $product['price'],
                'total'      => $product['total'],
                'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
                'reward'     => $product['reward']
            ];
        }

        // Vouchers
        $order_data['vouchers'] = [];

        if (!empty($this->session->data['vouchers'])) {
            foreach ($this->session->data['vouchers'] as $voucher) {
                $order_data['vouchers'][] = [
                    'description'      => $voucher['description'],
                    'code'             => $this->encryption->encrypt($voucher['code']),
                    'to_name'          => $voucher['to_name'],
                    'to_email'         => $voucher['to_email'],
                    'from_name'        => $voucher['from_name'],
                    'from_email'       => $voucher['from_email'],
                    'voucher_theme_id' => $voucher['voucher_theme_id'],
                    'message'          => $voucher['message'],
                    'amount'           => $voucher['amount']
                ];
            }
        }

        // Order Totals
        $order_data['totals'] = [];
        $totals = [];
        $taxes = $this->cart->getTaxes();
        $total = 0;

        $this->getTotals($totals, $taxes, $total);

        $order_data['totals'] = $totals;
        $order_data['total'] = $total;

        // Payment Details
        $order_data['language_id'] = $this->config->get('config_language_id');
        $order_data['currency_id'] = $this->currency->getId($currency_code);
        $order_data['currency_code'] = $currency_code;
        $order_data['currency_value'] = $this->currency->getValue($currency_code);
        $order_data['comment'] = $this->session->data['comment'] ?? '';
        $order_data['affiliate_id'] = 0;
        $order_data['commission'] = 0;
        $order_data['marketing_id'] = 0;
        $order_data['tracking'] = '';

        // Request Data
        $order_data['ip'] = $this->request->server['REMOTE_ADDR'];

        if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
            $order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
            $order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
        } else {
            $order_data['forwarded_ip'] = '';
        }

        if (isset($this->request->server['HTTP_USER_AGENT'])) {
            $order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
        } else {
            $order_data['user_agent'] = '';
        }

        if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
            $order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
        } else {
            $order_data['accept_language'] = '';
        }

        return $order_data;
    }
}
