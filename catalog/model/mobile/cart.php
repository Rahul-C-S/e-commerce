<?php

namespace Opencart\Catalog\Model\Mobile;

class Cart extends \Opencart\System\Engine\Model {
    /**
     * Get products in cart
     *
     * @param int $customer_id
     * @return array
     */
    public function getProducts(int $customer_id): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "cart` WHERE customer_id = '" . (int)$customer_id . "'");

        $products = [];
        
        foreach ($query->rows as $row) {
            $product_query = $this->db->query("SELECT p.*, pd.name, pd.description FROM " . DB_PREFIX . "product p 
                LEFT JOIN " . DB_PREFIX . "product_description pd ON (p.product_id = pd.product_id) 
                WHERE p.product_id = '" . (int)$row['product_id'] . "' 
                AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "'");
            
            if ($product_query->num_rows) {
                $option_data = [];
                
                $options = json_decode($row['option'], true);
                
                foreach ($options as $product_option_id => $value) {
                    $option_query = $this->db->query("SELECT po.product_option_id, po.option_id, o.type, od.name 
                        FROM " . DB_PREFIX . "product_option po 
                        LEFT JOIN `" . DB_PREFIX . "option` o ON (po.option_id = o.option_id) 
                        LEFT JOIN `" . DB_PREFIX . "option_description` od ON (o.option_id = od.option_id AND od.language_id = '" . (int)$this->config->get('config_language_id') . "') 
                        WHERE po.product_option_id = '" . (int)$product_option_id . "'");
                    
                    if ($option_query->num_rows) {
                        switch ($option_query->row['type']) {
                            case 'select':
                            case 'radio':
                            case 'image':
                                $option_value_query = $this->db->query("SELECT 
                                    pov.option_value_id,
                                    pov.quantity,
                                    pov.subtract,
                                    pov.price,
                                    pov.price_prefix,
                                    pov.points,
                                    pov.points_prefix,
                                    pov.weight,
                                    pov.weight_prefix,
                                    ovd.name
                                    FROM " . DB_PREFIX . "product_option_value pov 
                                    LEFT JOIN " . DB_PREFIX . "option_value ov ON (pov.option_value_id = ov.option_value_id)
                                    LEFT JOIN " . DB_PREFIX . "option_value_description ovd ON (ov.option_value_id = ovd.option_value_id 
                                        AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "'
                                        AND ovd.option_id = '" . (int)$option_query->row['option_id'] . "')
                                    WHERE pov.product_option_value_id = '" . (int)$value . "'");
                                
                                if ($option_value_query->num_rows) {
                                    $option_data[] = [
                                        'product_option_id' => $product_option_id,
                                        'product_option_value_id' => $value,
                                        'option_id' => $option_query->row['option_id'],
                                        'option_value_id' => $option_value_query->row['option_value_id'],
                                        'name' => $option_query->row['name'],
                                        'value' => $option_value_query->row['name'],
                                        'type' => $option_query->row['type'],
                                        'quantity' => $option_value_query->row['quantity'],
                                        'subtract' => $option_value_query->row['subtract'],
                                        'price' => $option_value_query->row['price'],
                                        'price_prefix' => $option_value_query->row['price_prefix'],
                                        'points' => $option_value_query->row['points'],
                                        'points_prefix' => $option_value_query->row['points_prefix'],
                                        'weight' => $option_value_query->row['weight'],
                                        'weight_prefix' => $option_value_query->row['weight_prefix']
                                    ];
                                }
                                break;
                                
                            case 'checkbox':
                                if (is_array($value)) {
                                    foreach ($value as $product_option_value_id) {
                                        $option_value_query = $this->db->query("SELECT 
                                            pov.option_value_id,
                                            pov.quantity,
                                            pov.subtract,
                                            pov.price,
                                            pov.price_prefix,
                                            pov.points,
                                            pov.points_prefix,
                                            pov.weight,
                                            pov.weight_prefix,
                                            ovd.name
                                            FROM " . DB_PREFIX . "product_option_value pov 
                                            LEFT JOIN " . DB_PREFIX . "option_value ov ON (pov.option_value_id = ov.option_value_id)
                                            LEFT JOIN " . DB_PREFIX . "option_value_description ovd ON (ov.option_value_id = ovd.option_value_id 
                                                AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "'
                                                AND ovd.option_id = '" . (int)$option_query->row['option_id'] . "')
                                            WHERE pov.product_option_value_id = '" . (int)$product_option_value_id . "'");
                                            
                                        if ($option_value_query->num_rows) {
                                            $option_data[] = [
                                                'product_option_id' => $product_option_id,
                                                'product_option_value_id' => $product_option_value_id,
                                                'option_id' => $option_query->row['option_id'],
                                                'option_value_id' => $option_value_query->row['option_value_id'],
                                                'name' => $option_query->row['name'],
                                                'value' => $option_value_query->row['name'],
                                                'type' => $option_query->row['type'],
                                                'quantity' => $option_value_query->row['quantity'],
                                                'subtract' => $option_value_query->row['subtract'],
                                                'price' => $option_value_query->row['price'],
                                                'price_prefix' => $option_value_query->row['price_prefix'],
                                                'points' => $option_value_query->row['points'],
                                                'points_prefix' => $option_value_query->row['points_prefix'],
                                                'weight' => $option_value_query->row['weight'],
                                                'weight_prefix' => $option_value_query->row['weight_prefix']
                                            ];
                                        }
                                    }
                                }
                                break;
                                
                            case 'text':
                            case 'textarea':
                            case 'file':
                            case 'date':
                            case 'time':
                            case 'datetime':
                            case 'date_time':
                                $option_data[] = [
                                    'product_option_id' => $product_option_id,
                                    'product_option_value_id' => '',
                                    'option_id' => $option_query->row['option_id'],
                                    'option_value_id' => '',
                                    'name' => $option_query->row['name'],
                                    'value' => $value,
                                    'type' => $option_query->row['type'],
                                    'quantity' => '',
                                    'subtract' => '',
                                    'price' => '',
                                    'price_prefix' => '',
                                    'points' => '',
                                    'points_prefix' => '',
                                    'weight' => '',
                                    'weight_prefix' => ''
                                ];
                                break;
                        }
                    }
                }

                $subscription_data = [];
                if ($row['subscription_plan_id']) {
                    $subscription_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "subscription_plan` sp LEFT JOIN `" . DB_PREFIX . "subscription_plan_description` spd ON (sp.subscription_plan_id = spd.subscription_plan_id) WHERE sp.subscription_plan_id = '" . (int)$row['subscription_plan_id'] . "' AND spd.language_id = '" . (int)$this->config->get('config_language_id') . "'");
                    
                    if ($subscription_query->num_rows) {
                        $subscription_data = $subscription_query->row;
                    }
                }

                $price = $product_query->row['price'];
                
                // Get special price if available
                $special_query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product_special WHERE product_id = '" . (int)$row['product_id'] . "' AND customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) ORDER BY priority ASC, price ASC LIMIT 1");
                
                if ($special_query->num_rows) {
                    $price = $special_query->row['price'];
                }

                // Calculate total price including options
                $total_price = $price;
                $total_points = 0;
                $total_weight = $product_query->row['weight'];

                foreach ($option_data as $option) {
                    if ($option['price'] !== '' && $option['price_prefix'] !== '') {
                        if ($option['price_prefix'] === '+') {
                            $total_price += $option['price'];
                        } elseif ($option['price_prefix'] === '-') {
                            $total_price -= $option['price'];
                        }
                    }

                    if ($option['points'] !== '' && $option['points_prefix'] !== '') {
                        if ($option['points_prefix'] === '+') {
                            $total_points += $option['points'];
                        } elseif ($option['points_prefix'] === '-') {
                            $total_points -= $option['points'];
                        }
                    }

                    if ($option['weight'] !== '' && $option['weight_prefix'] !== '') {
                        if ($option['weight_prefix'] === '+') {
                            $total_weight += $option['weight'];
                        } elseif ($option['weight_prefix'] === '-') {
                            $total_weight -= $option['weight'];
                        }
                    }
                }

                $products[] = [
                    'cart_id' => $row['cart_id'],
                    'product_id' => $row['product_id'],
                    'name' => $product_query->row['name'],
                    'model' => $product_query->row['model'],
                    'option' => $option_data,
                    'subscription' => $subscription_data,
                    'quantity' => $row['quantity'],
                    'stock' => $product_query->row['quantity'] >= $row['quantity'],
                    'minimum' => $product_query->row['minimum'] ?: 1,
                    'shipping' => $product_query->row['shipping'] ?? 1,
                    'download' => $product_query->row['download'] ?? 0,
                    'subtract' => $product_query->row['subtract'] ?? 1,
                    'tax_class_id' => $product_query->row['tax_class_id'],
                    'reward' => $product_query->row['points'],
                    'price' => $total_price,
                    'total' => $total_price * $row['quantity'],
                    'base_price' => $price,
                    'base_points' => $product_query->row['points'],
                    'points' => $total_points,
                    'weight' => $total_weight,
                    'weight_class_id' => $product_query->row['weight_class_id'],
                    'length' => $product_query->row['length'],
                    'width' => $product_query->row['width'],
                    'height' => $product_query->row['height'],
                    'length_class_id' => $product_query->row['length_class_id'],
                    'recurring' => $product_query->row['recurring'] ?? 0,
                    'image' => $product_query->row['image'] ?? ''
                ];
            }
        }

        return $products;
    }

    /**
     * Add product to cart
     *
     * @param int $customer_id
     * @param int $product_id
     * @param int $quantity
     * @param array $option
     * @param int $subscription_plan_id
     * @return void
     */
    public function add(int $customer_id, int $product_id, int $quantity = 1, array $option = [], int $subscription_plan_id = 0): void {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "cart` 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND product_id = '" . (int)$product_id . "' 
            AND subscription_plan_id = '" . (int)$subscription_plan_id . "' 
            AND `option` = '" . $this->db->escape(json_encode($option)) . "'");

        if ($query->num_rows) {
            $this->db->query("UPDATE `" . DB_PREFIX . "cart` 
                SET quantity = quantity + " . (int)$quantity . " 
                WHERE cart_id = '" . (int)$query->row['cart_id'] . "'");
        } else {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "cart` SET 
                customer_id = '" . (int)$customer_id . "', 
                product_id = '" . (int)$product_id . "', 
                quantity = '" . (int)$quantity . "', 
                `option` = '" . $this->db->escape(json_encode($option)) . "',
                subscription_plan_id = '" . (int)$subscription_plan_id . "',
                date_added = NOW()");
        }
    }

    /**
     * Update cart item quantity
     *
     * @param int $customer_id
     * @param int $cart_id
     * @param int $quantity
     * @return void
     */
    public function update(int $customer_id, int $cart_id, int $quantity): void {
        $this->db->query("UPDATE `" . DB_PREFIX . "cart` SET 
            quantity = '" . (int)$quantity . "' 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND cart_id = '" . (int)$cart_id . "'");
    }

    /**
     * Remove item from cart
     *
     * @param int $customer_id
     * @param int $cart_id
     * @return void
     */
    public function remove(int $customer_id, int $cart_id): void {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND cart_id = '" . (int)$cart_id . "'");
    }

    /**
     * Check if product exists in customer's cart
     *
     * @param int $customer_id
     * @param int $cart_id
     * @return bool
     */
    public function hasProduct(int $customer_id, int $cart_id): bool {
        $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "cart` 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND cart_id = '" . (int)$cart_id . "'");
            
        return $query->row['total'] > 0;
    }

    /**
     * Get cart totals
     *
     * @param array &$totals
     * @param array &$taxes
     * @param float &$total
     * @return void
     */
    public function getTotals(array &$totals, array &$taxes, float &$total): void {
        $this->load->model('setting/extension');

        $sort_order = [];

        $results = $this->model_setting_extension->getExtensionsByType('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/' . $result['extension'] . '/total/' . $result['code']);

                // We have to put the totals in an array so that they can be output to the API in the correct order
                $this->{'model_extension_' . $result['extension'] . '_total_' . $result['code']}->getTotal($totals, $taxes, $total);
            }
        }

        $sort_order = [];

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);
    }

    /**
     * Clear customer's cart
     *
     * @param int $customer_id
     * @return void
     */
    public function clear(int $customer_id): void {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE customer_id = '" . (int)$customer_id . "'");
    }

    /**
     * Get total number of items in cart
     *
     * @param int $customer_id
     * @return int
     */
    public function getCartItemCount(int $customer_id): int {
        $query = $this->db->query("SELECT SUM(quantity) as total FROM `" . DB_PREFIX . "cart` 
            WHERE customer_id = '" . (int)$customer_id . "'");
            
        return (int)$query->row['total'];
    }
}