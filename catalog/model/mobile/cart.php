<?php

namespace Opencart\Catalog\Model\Mobile;

class Cart extends \Opencart\System\Engine\Model
{
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
    public function add(int $customer_id, int $product_id, int $quantity = 1, array $option = [], int $subscription_plan_id = 0): void
    {
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
    public function update(int $customer_id, int $cart_id, int $quantity): void
    {
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
    public function remove(int $customer_id, int $cart_id): void
    {
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
    public function hasProduct(int $customer_id, int $cart_id): bool
    {
        $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "cart` 
            WHERE customer_id = '" . (int)$customer_id . "' 
            AND cart_id = '" . (int)$cart_id . "'");

        return $query->row['total'] > 0;
    }

    /**
     * Clear customer's cart
     *
     * @param int $customer_id
     * @return void
     */
    public function clear(int $customer_id): void
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "cart` WHERE customer_id = '" . (int)$customer_id . "'");
    }

    /**
     * Get total number of items in cart
     *
     * @param int $customer_id
     * @return int
     */
    public function getCartItemCount(int $customer_id): int
    {
        $query = $this->db->query("SELECT SUM(quantity) as total FROM `" . DB_PREFIX . "cart` 
            WHERE customer_id = '" . (int)$customer_id . "'");

        return (int)$query->row['total'];
    }

    /**
     * Get products in cart
     *
     * @param int $customer_id
     * @return array
     */
    public function getProducts(int $customer_id): array
    {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "cart` WHERE customer_id = '" . (int)$customer_id . "'");

        $products = [];

        foreach ($query->rows as $row) {
            $product_query = $this->db->query("SELECT p.*, pd.name, pd.description FROM " . DB_PREFIX . "product p 
                    LEFT JOIN " . DB_PREFIX . "product_description pd ON (p.product_id = pd.product_id) 
                    WHERE p.product_id = '" . (int)$row['product_id'] . "' 
                    AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "'");

            if ($product_query->num_rows) {
                $option_data = $this->getProductOptions($row['product_id'], $row['option']);
                $subscription_data = $this->getSubscriptionInfo($row['subscription_plan_id']);

                $price = $this->getProductPrice($product_query->row, $row['product_id']);
                $pricing_info = $this->calculateProductPricing($price, $option_data, $product_query->row, $row['quantity']);

                $products[] = array_merge([
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
                    'image' => $product_query->row['image'] ?? ''
                ], $pricing_info);
            }
        }

        return $products;
    }

    /**
     * Get option values based on option type
     * 
     * @param array $option_info
     * @param mixed $value
     * @param int $product_option_id
     * @return array
     */
    protected function getOptionValues(array $option_info, $value, int $product_option_id): array
    {
        $option_data = [];

        switch ($option_info['type']) {
            case 'select':
            case 'radio':
            case 'image':
                $option_data[] = $this->getSelectOptionValue($option_info, $value, $product_option_id);
                break;

            case 'checkbox':
                if (is_array($value)) {
                    foreach ($value as $product_option_value_id) {
                        $option_data[] = $this->getSelectOptionValue($option_info, $product_option_value_id, $product_option_id);
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
                    'option_id' => $option_info['option_id'],
                    'option_value_id' => '',
                    'name' => $option_info['name'],
                    'value' => $value,
                    'type' => $option_info['type'],
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

        return $option_data;
    }

    /**
     * Get select/radio/image option value
     * 
     * @param array $option_info
     * @param int $product_option_value_id
     * @param int $product_option_id
     * @return array
     */
    protected function getSelectOptionValue(array $option_info, $product_option_value_id, int $product_option_id): array
    {
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
                AND ovd.option_id = '" . (int)$option_info['option_id'] . "')
            WHERE pov.product_option_value_id = '" . (int)$product_option_value_id . "'");

        if ($option_value_query->num_rows) {
            return [
                'product_option_id' => $product_option_id,
                'product_option_value_id' => $product_option_value_id,
                'option_id' => $option_info['option_id'],
                'option_value_id' => $option_value_query->row['option_value_id'],
                'name' => $option_info['name'],
                'value' => $option_value_query->row['name'],
                'type' => $option_info['type'],
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

        return [];
    }

    /**
     * Get product options
     *
     * @param int $product_id
     * @param string $options_json
     * @return array
     */
    protected function getProductOptions(int $product_id, string $options_json): array
    {
        $option_data = [];
        $options = json_decode($options_json, true);

        foreach ($options as $product_option_id => $value) {
            $option_query = $this->db->query("SELECT po.product_option_id, po.option_id, o.type, od.name 
                    FROM " . DB_PREFIX . "product_option po 
                    LEFT JOIN `" . DB_PREFIX . "option` o ON (po.option_id = o.option_id) 
                    LEFT JOIN `" . DB_PREFIX . "option_description` od ON (o.option_id = od.option_id 
                        AND od.language_id = '" . (int)$this->config->get('config_language_id') . "') 
                    WHERE po.product_option_id = '" . (int)$product_option_id . "'");

            if ($option_query->num_rows) {
                $option_data = array_merge(
                    $option_data,
                    $this->getOptionValues($option_query->row, $value, $product_option_id)
                );
            }
        }

        return $option_data;
    }

    /**
     * Get subscription information
     *
     * @param int $subscription_plan_id
     * @return array
     */
    protected function getSubscriptionInfo(int $subscription_plan_id): array
    {
        if (!$subscription_plan_id) {
            return [];
        }

        $subscription_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "subscription_plan` sp 
                LEFT JOIN `" . DB_PREFIX . "subscription_plan_description` spd 
                ON (sp.subscription_plan_id = spd.subscription_plan_id) 
                WHERE sp.subscription_plan_id = '" . (int)$subscription_plan_id . "' 
                AND spd.language_id = '" . (int)$this->config->get('config_language_id') . "'");

        return $subscription_query->num_rows ? $subscription_query->row : [];
    }

    /**
     * Get product base price
     *
     * @param array $product
     * @param int $product_id
     * @return float
     */
    protected function getProductPrice(array $product, int $product_id): float
    {
        $price = $product['price'];

        // Get special price if available
        $special_query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product_special 
                WHERE product_id = '" . (int)$product_id . "' 
                AND customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' 
                AND ((date_start = '0000-00-00' OR date_start < NOW()) 
                AND (date_end = '0000-00-00' OR date_end > NOW())) 
                ORDER BY priority ASC, price ASC LIMIT 1");

        if ($special_query->num_rows) {
            $price = $special_query->row['price'];
        }

        return (float)$price;
    }

    /**
     * Calculate product pricing including options
     *
     * @param float $base_price
     * @param array $option_data
     * @param array $product
     * @param int $quantity
     * @return array
     */
    protected function calculateProductPricing(float $base_price, array $option_data, array $product, int $quantity): array
    {
        $total_price = $base_price;
        $total_points = 0;
        $total_weight = $product['weight'];

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

        return [
            'price' => $total_price,
            'total' => $total_price * $quantity,
            'base_price' => $base_price,
            'base_points' => $product['points'],
            'points' => $total_points,
            'weight' => $total_weight,
            'weight_class_id' => $product['weight_class_id'],
            'length' => $product['length'],
            'width' => $product['width'],
            'height' => $product['height'],
            'length_class_id' => $product['length_class_id']
        ];
    }

    


}
