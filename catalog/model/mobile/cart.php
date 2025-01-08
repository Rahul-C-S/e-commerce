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
            $product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product p LEFT JOIN " . DB_PREFIX . "product_description pd ON (p.product_id = pd.product_id) WHERE p.product_id = '" . (int)$row['product_id'] . "' AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "'");
            
            if ($product_query->num_rows) {
                $option_data = [];
                
                $options = json_decode($row['option'], true);
                
                foreach ($options as $product_option_id => $value) {
                    $option_query = $this->db->query("SELECT po.product_option_id, po.option_id, o.name, o.type FROM " . DB_PREFIX . "product_option po LEFT JOIN `" . DB_PREFIX . "option` o ON (po.option_id = o.option_id) WHERE po.product_option_id = '" . (int)$product_option_id . "'");
                    
                    if ($option_query->num_rows) {
                        if ($option_query->row['type'] == 'select' || $option_query->row['type'] == 'radio' || $option_query->row['type'] == 'image') {
                            $option_value_query = $this->db->query("SELECT pov.option_value_id, ov.name, pov.quantity, pov.subtract, pov.price, pov.price_prefix FROM " . DB_PREFIX . "product_option_value pov LEFT JOIN " . DB_PREFIX . "option_value ov ON (pov.option_value_id = ov.option_value_id) WHERE pov.product_option_value_id = '" . (int)$value . "'");
                            
                            if ($option_value_query->num_rows) {
                                $option_data[] = [
                                    'product_option_id' => $product_option_id,
                                    'product_option_value_id' => $value,
                                    'name' => $option_query->row['name'],
                                    'value' => $option_value_query->row['name']
                                ];
                            }
                        }
                    }
                }

                // Get subscription details if exists
                $subscription_data = [];
                if ($row['subscription_plan_id']) {
                    $subscription_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "subscription_plan` sp LEFT JOIN `" . DB_PREFIX . "subscription_plan_description` spd ON (sp.subscription_plan_id = spd.subscription_plan_id) WHERE sp.subscription_plan_id = '" . (int)$row['subscription_plan_id'] . "' AND spd.language_id = '" . (int)$this->config->get('config_language_id') . "'");
                    
                    if ($subscription_query->num_rows) {
                        $subscription_data = $subscription_query->row;
                    }
                }

                $price = $product_query->row['price'];
                
                // Handle special prices
                $special_query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product_special WHERE product_id = '" . (int)$row['product_id'] . "' AND customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) ORDER BY priority ASC, price ASC LIMIT 1");
                
                if ($special_query->num_rows) {
                    $price = $special_query->row['price'];
                }

                $products[] = [
                    'cart_id' => $row['cart_id'],
                    'product_id' => $row['product_id'],
                    'name' => $product_query->row['name'],
                    'model' => $product_query->row['model'],
                    'option' => $option_data,
                    'subscription' => $subscription_data,
                    'quantity' => $row['quantity'],
                    'price' => $price,
                    'total' => $price * $row['quantity'],
                    'image' => $product_query->row['image'],
                    'stock' => $product_query->row['quantity'] >= $row['quantity'],
                    'minimum' => $product_query->row['minimum'] ?: 1,
                    'tax_class_id' => $product_query->row['tax_class_id']
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
            // Update quantity if product already exists
            $this->db->query("UPDATE `" . DB_PREFIX . "cart` 
                SET quantity = quantity + " . (int)$quantity . " 
                WHERE cart_id = '" . (int)$query->row['cart_id'] . "'");
        } else {
            // Add new product to cart
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