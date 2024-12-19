<?php

namespace Opencart\Catalog\Controller\Mobile;

class Menu extends ApiController
{
    public function index()
    {
        echo "Mobile Menu";
        return;
    }
    public function categories(): void
    {
        // Initialize response array
        $json = [];

        try {
            // Load required models
            $this->load->model('catalog/category');
            $this->load->model('catalog/product');
            $this->load->model('tool/image');

            // Get all categories
            $categories = $this->model_catalog_category->getCategories();

            $json['categories'] = [];

            foreach ($categories as $category) {
                // Get category image
                $image = $category['image'] ? $this->model_tool_image->resize(
                    $category['image'],
                    $this->config->get('config_image_category_width'),
                    $this->config->get('config_image_category_height')
                ) : '';

                // Get products for category
                $products = $this->model_catalog_product->getProducts([
                    'filter_category_id' => $category['category_id'],
                    'filter_status'      => 1  // Only active products
                ]);

                $product_ids = [];
                foreach ($products as $product) {
                    if ($product['quantity'] > 0 || $this->config->get('config_stock_checkout')) {
                        $product_ids[] = $product['product_id'];
                    }
                }

                // Build category data
                $category_data = [
                    'category_id'   => (int)$category['category_id'],
                    'name'          => strip_tags(html_entity_decode($category['name'], ENT_QUOTES, 'UTF-8')),
                    'description'   => strip_tags(html_entity_decode($category['description'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'image'         => $image,
                    'sort_order'    => (int)$category['sort_order'],
                    'status'        => (bool)$category['status'],
                    'product_count' => count($product_ids),
                    'products'      => $product_ids
                ];

                // Add parent_id if exists
                if (isset($category['parent_id'])) {
                    $category_data['parent_id'] = (int)$category['parent_id'];
                }

                $json['categories'][] = $category_data;
            }

            $json['success'] = 'Categories retrieved successfully';
            $json['status'] = true;
        } catch (\Exception $e) {
            $json['error'] = 'An error occurred while retrieving categories';
            $json['status'] = false;

            // Log the error (optional)
            $this->log->write('Categories API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Get a specific category by ID
     */
    public function category(): void
    {
        $json = [];

        if (!isset($this->request->get['category_id'])) {
            $json['error'] = 'Category ID is required';
            $json['status'] = false;
        } else {
            try {
                $this->load->model('catalog/category');
                $this->load->model('tool/image');

                $category_id = (int)$this->request->get['category_id'];
                $category_info = $this->model_catalog_category->getCategory($category_id);

                if ($category_info) {
                    // Get category image
                    $image = $category_info['image'] ? $this->model_tool_image->resize(
                        $category_info['image'],
                        $this->config->get('config_image_category_width'),
                        $this->config->get('config_image_category_height')
                    ) : '';

                    $json['category'] = [
                        'category_id'   => (int)$category_info['category_id'],
                        'name'          => strip_tags(html_entity_decode($category_info['name'], ENT_QUOTES, 'UTF-8')),
                        'description'   => strip_tags(html_entity_decode($category_info['description'] ?? '', ENT_QUOTES, 'UTF-8')),
                        'image'         => $image,
                        'sort_order'    => (int)$category_info['sort_order'],
                        'status'        => (bool)$category_info['status']
                    ];

                    if (isset($category_info['parent_id'])) {
                        $json['category']['parent_id'] = (int)$category_info['parent_id'];
                    }

                    $json['success'] = 'Category retrieved successfully';
                    $json['status'] = true;
                } else {
                    $json['error'] = 'Category not found';
                    $json['status'] = false;
                }
            } catch (\Exception $e) {
                $json['error'] = 'An error occurred while retrieving the category';
                $json['status'] = false;

                // Log the error (optional)
                $this->log->write('Categories API Error: ' . $e->getMessage());
            }
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    // Products

    public function products(): void
    {
        $json = [];

        try {
            $this->load->model('catalog/product');
            $this->load->model('tool/image');
            $this->load->model('localisation/stock_status');

            // Initialize filter array with only status filter
            $filter_data = [
                'filter_status' => 1  // Only active products
            ];

            // Handle optional category filter
            if (isset($this->request->get['category_id'])) {
                $filter_data['filter_category_id'] = (int)$this->request->get['category_id'];
            }

            // Handle optional search
            if (isset($this->request->get['search'])) {
                $filter_data['filter_name'] = $this->request->get['search'];
                $filter_data['filter_description'] = $this->request->get['search'];
            }

            // Get all products based on filters
            $products = $this->model_catalog_product->getProducts($filter_data);
            $json['products'] = [];

            foreach ($products as $product) {
                // Skip out of stock products if configured
                if (!$this->config->get('config_stock_checkout') && $product['quantity'] <= 0) {
                    continue;
                }

                // Get product categories
                $categories = [];
                $product_categories = $this->model_catalog_product->getCategories($product['product_id']);
                foreach ($product_categories as $category) {
                    $categories[] = (int)$category['category_id'];
                }

                // Get product image
                $image = '';
                if (!empty($product['image']) && is_file(DIR_IMAGE . $product['image'])) {
                    $image = $this->model_tool_image->resize(
                        $product['image'],
                        $this->config->get('config_image_product_width'),
                        $this->config->get('config_image_product_height')
                    );
                }

                // Get additional images
                $additional_images = [];
                $results = $this->model_catalog_product->getImages($product['product_id']);
                foreach ($results as $result) {
                    if (is_file(DIR_IMAGE . $result['image'])) {
                        $additional_images[] = $this->model_tool_image->resize(
                            $result['image'],
                            $this->config->get('config_image_additional_width'),
                            $this->config->get('config_image_additional_height')
                        );
                    }
                }

                // Get stock status
                $stock_status = '';
                if (isset($product['stock_status_id'])) {
                    $stock_status_info = $this->model_localisation_stock_status->getStockStatus($product['stock_status_id']);
                    if ($stock_status_info) {
                        $stock_status = $stock_status_info['name'];
                    }
                }

                // Format product data
                $product_data = [
                    'product_id'         => (int)$product['product_id'],
                    'name'               => strip_tags(html_entity_decode($product['name'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'description'        => strip_tags(html_entity_decode($product['description'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'meta_title'         => strip_tags(html_entity_decode($product['meta_title'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'meta_description'   => strip_tags(html_entity_decode($product['meta_description'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'meta_keyword'       => strip_tags(html_entity_decode($product['meta_keyword'] ?? '', ENT_QUOTES, 'UTF-8')),
                    'model'              => $product['model'] ?? '',
                    'sku'                => $product['sku'] ?? '',
                    'upc'                => $product['upc'] ?? '',
                    'ean'                => $product['ean'] ?? '',
                    'jan'                => $product['jan'] ?? '',
                    'isbn'               => $product['isbn'] ?? '',
                    'mpn'                => $product['mpn'] ?? '',
                    'location'           => $product['location'] ?? '',
                    'quantity'           => (int)($product['quantity'] ?? 0),
                    'stock_status'       => $stock_status,
                    'image'              => $image,
                    'additional_images'  => $additional_images,
                    'categories'         => $categories,
                    'price'              => (float)($product['price'] ?? 0),
                    'special'            => isset($product['special']) ? (float)$product['special'] : null,
                    'tax_class_id'       => (int)($product['tax_class_id'] ?? 0),
                    'date_available'     => $product['date_available'] ?? '',
                    'weight'             => (float)($product['weight'] ?? 0),
                    'weight_class_id'    => (int)($product['weight_class_id'] ?? 0),
                    'length'             => (float)($product['length'] ?? 0),
                    'width'              => (float)($product['width'] ?? 0),
                    'height'             => (float)($product['height'] ?? 0),
                    'length_class_id'    => (int)($product['length_class_id'] ?? 0),
                    'minimum'            => (int)($product['minimum'] ?? 1),
                    'sort_order'         => (int)($product['sort_order'] ?? 0),
                    'status'             => (bool)($product['status'] ?? false),
                    'date_added'         => $product['date_added'] ?? '',
                    'date_modified'      => $product['date_modified'] ?? '',
                    'viewed'             => (int)($product['viewed'] ?? 0),
                    'options'            => $this->getProductOptions($product['product_id'])
                ];

                $json['products'][] = $product_data;
            }

            $json['success'] = 'Products retrieved successfully';
            $json['status'] = true;
        } catch (\Exception $e) {
            $json['error'] = 'An error occurred while retrieving products';
            $json['status'] = false;

            // Log the error
            $this->log->write('Products API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    // Get single product data

    public function product(): void
    {
        $json = [];

        if (!isset($this->request->get['product_id'])) {
            $json['error'] = 'Product ID is required';
            $json['status'] = false;
        } else {
            try {
                $this->load->model('catalog/product');
                $this->load->model('tool/image');
                $this->load->model('localisation/stock_status');

                $product_id = (int)$this->request->get['product_id'];
                $product_info = $this->model_catalog_product->getProduct($product_id);

                if ($product_info && $product_info['status']) {
                    // Get product categories
                    $categories = [];
                    $product_categories = $this->model_catalog_product->getCategories($product_id);
                    foreach ($product_categories as $category) {
                        $categories[] = (int)$category['category_id'];
                    }

                    // Get product image
                    $image = '';
                    if (!empty($product_info['image']) && is_file(DIR_IMAGE . $product_info['image'])) {
                        $image = $this->model_tool_image->resize(
                            $product_info['image'],
                            $this->config->get('config_image_product_width'),
                            $this->config->get('config_image_product_height')
                        );
                    }

                    // Get additional images
                    $additional_images = [];
                    $results = $this->model_catalog_product->getImages($product_id);
                    foreach ($results as $result) {
                        if (is_file(DIR_IMAGE . $result['image'])) {
                            $additional_images[] = $this->model_tool_image->resize(
                                $result['image'],
                                $this->config->get('config_image_additional_width'),
                                $this->config->get('config_image_additional_height')
                            );
                        }
                    }

                    // Get stock status
                    $stock_status = '';
                    if (isset($product_info['stock_status_id'])) {
                        $stock_status_info = $this->model_localisation_stock_status->getStockStatus($product_info['stock_status_id']);
                        if ($stock_status_info) {
                            $stock_status = $stock_status_info['name'];
                        }
                    }

                    $json['product'] = [
                        'product_id'         => (int)$product_info['product_id'],
                        'name'               => strip_tags(html_entity_decode($product_info['name'] ?? '', ENT_QUOTES, 'UTF-8')),
                        'description'        => strip_tags(html_entity_decode($product_info['description'] ?? '', ENT_QUOTES, 'UTF-8')),
                        'meta_title'         => strip_tags(html_entity_decode($product_info['meta_title'] ?? '', ENT_QUOTES, 'UTF-8')),
                        'meta_description'   => strip_tags(html_entity_decode($product_info['meta_description'] ?? '', ENT_QUOTES, 'UTF-8')),
                        'meta_keyword'       => strip_tags(html_entity_decode($product_info['meta_keyword'] ?? '', ENT_QUOTES, 'UTF-8')),
                        'model'              => $product_info['model'] ?? '',
                        'sku'                => $product_info['sku'] ?? '',
                        'upc'                => $product_info['upc'] ?? '',
                        'ean'                => $product_info['ean'] ?? '',
                        'jan'                => $product_info['jan'] ?? '',
                        'isbn'               => $product_info['isbn'] ?? '',
                        'mpn'                => $product_info['mpn'] ?? '',
                        'location'           => $product_info['location'] ?? '',
                        'quantity'           => (int)($product_info['quantity'] ?? 0),
                        'stock_status'       => $stock_status,
                        'image'              => $image,
                        'additional_images'  => $additional_images,
                        'categories'         => $categories,
                        'price'              => (float)($product_info['price'] ?? 0),
                        'special'            => isset($product_info['special']) ? (float)$product_info['special'] : null,
                        'tax_class_id'       => (int)($product_info['tax_class_id'] ?? 0),
                        'date_available'     => $product_info['date_available'] ?? '',
                        'weight'             => (float)($product_info['weight'] ?? 0),
                        'weight_class_id'    => (int)($product_info['weight_class_id'] ?? 0),
                        'length'             => (float)($product_info['length'] ?? 0),
                        'width'              => (float)($product_info['width'] ?? 0),
                        'height'             => (float)($product_info['height'] ?? 0),
                        'length_class_id'    => (int)($product_info['length_class_id'] ?? 0),
                        'minimum'            => (int)($product_info['minimum'] ?? 1),
                        'sort_order'         => (int)($product_info['sort_order'] ?? 0),
                        'status'             => (bool)($product_info['status'] ?? false),
                        'date_added'         => $product_info['date_added'] ?? '',
                        'date_modified'      => $product_info['date_modified'] ?? '',
                        'viewed'             => (int)($product_info['viewed'] ?? 0),
                        'options'            => $this->getProductOptions($product_id)
                    ];

                    $json['success'] = 'Product retrieved successfully';
                    $json['status'] = true;
                } else {
                    $json['error'] = 'Product not found or inactive';
                    $json['status'] = false;
                }
            } catch (\Exception $e) {
                $json['error'] = 'An error occurred while retrieving the product';
                $json['status'] = false;

                // Log the error
                $this->log->write('Products API Error: ' . $e->getMessage());
            }
        }

        $this->response->setOutput($this->jsonp($json, true));
    }


    private function getProductOptions(int $product_id): array
    {
        $product_options = [];

        try {
            $product_option_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_option po 
                LEFT JOIN `" . DB_PREFIX . "option` o ON (po.option_id = o.option_id) 
                LEFT JOIN " . DB_PREFIX . "option_description od ON (o.option_id = od.option_id) 
                WHERE po.product_id = '" . (int)$product_id . "' 
                AND od.language_id = '" . (int)$this->config->get('config_language_id') . "'
                ORDER BY o.sort_order");

            foreach ($product_option_query->rows as $product_option) {
                $product_option_values = [];

                // Only get option values if this is not a text-type option
                if (in_array($product_option['type'], ['select', 'radio', 'checkbox', 'image'])) {
                    $product_option_value_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_option_value pov 
                        LEFT JOIN " . DB_PREFIX . "option_value ov ON (pov.option_value_id = ov.option_value_id) 
                        LEFT JOIN " . DB_PREFIX . "option_value_description ovd ON (ov.option_value_id = ovd.option_value_id) 
                        WHERE pov.product_option_id = '" . (int)$product_option['product_option_id'] . "' 
                        AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "'
                        ORDER BY ov.sort_order");

                    foreach ($product_option_value_query->rows as $product_option_value) {
                        $product_option_values[] = [
                            'product_option_value_id' => (string)$product_option_value['product_option_value_id'],
                            'option_value_id'         => (string)$product_option_value['option_value_id'],
                            'name'                    => $product_option_value['name'],
                            'shortcode'               => '',
                            'price'                   => (bool)($product_option_value['price_prefix'] === '+' && $product_option_value['price'] > 0),
                            'price_prefix'            => $product_option_value['price_prefix'],
                            'price_value'             => (float)$product_option_value['price'],
                            'master_option_value'     => '0'
                        ];
                    }
                }

                $product_options[] = [
                    'product_option_id' => (string)$product_option['product_option_id'],
                    'option_id'        => (string)$product_option['option_id'],
                    'name'             => $product_option['name'],
                    'type'             => $product_option['type'],
                    'option_value'     => $product_option_values,
                    'required'         => (string)$product_option['required'],
                    'default'          => '0',
                    'master_option'    => '0',
                    'master_option_value' => '0',
                    'minimum'          => '0',
                    'maximum'          => '0'
                ];
            }
        } catch (\Exception $e) {
            $this->log->write('Error getting product options: ' . $e->getMessage());
        }

        return $product_options;
    }

    public function lastModified(): void
    {
        $json = [];

        try {
            $latest_modification = $this->getLatestModification();

            $json['last_modified'] = $latest_modification;
            $json['status'] = true;
        } catch (\Exception $e) {
            $json['error'] = 'An error occurred while retrieving last modified date';
            $json['status'] = false;
            $this->log->write('LastModified API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    private function getLatestModification(): string
    {
        $sql = "SELECT GREATEST(
            COALESCE((SELECT MAX(date_modified) FROM " . DB_PREFIX . "category), '1970-01-01'),
            COALESCE((SELECT MAX(date_modified) FROM " . DB_PREFIX . "product), '1970-01-01')
        ) as latest_date";

        $query = $this->db->query($sql);

        return $query->num_rows ? $query->row['latest_date'] : '';
    }

    public function featured(): void
    {
        $json = [];

        try {
            $this->load->model('catalog/product');
            $this->load->model('tool/image');
            $this->load->model('localisation/stock_status');

            // First get module_id for featured module
            $module_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "module WHERE code = 'opencart.featured' LIMIT 1");

            if ($module_query->num_rows) {
                $module_settings = json_decode($module_query->row['setting'], true);

                if (!empty($module_settings['product'])) {
                    foreach ($module_settings['product'] as $product_id) {
                        $product_info = $this->model_catalog_product->getProduct($product_id);

                        if ($product_info && $product_info['status']) {
                            // Skip out of stock products if configured
                            if (!$this->config->get('config_stock_checkout') && $product_info['quantity'] <= 0) {
                                continue;
                            }

                            // Get stock status
                            $stock_status = '';
                            if (!empty($product_info['stock_status_id'])) {
                                $stock_status_info = $this->model_localisation_stock_status->getStockStatus($product_info['stock_status_id']);
                                if ($stock_status_info) {
                                    $stock_status = $stock_status_info['name'];
                                }
                            }

                            // Process image
                            if ($product_info['image']) {
                                $image = $this->model_tool_image->resize(
                                    $product_info['image'],
                                    $this->config->get('config_image_product_width'),
                                    $this->config->get('config_image_product_height')
                                );
                            } else {
                                $image = $this->model_tool_image->resize(
                                    'placeholder.png',
                                    $this->config->get('config_image_product_width'),
                                    $this->config->get('config_image_product_height')
                                );
                            }

                            // Get additional images
                            $additional_images = [];
                            $results = $this->model_catalog_product->getImages($product_id);
                            foreach ($results as $result) {
                                if (is_file(DIR_IMAGE . $result['image'])) {
                                    $additional_images[] = $this->model_tool_image->resize(
                                        $result['image'],
                                        $this->config->get('config_image_additional_width'),
                                        $this->config->get('config_image_additional_height')
                                    );
                                }
                            }

                            // Get categories
                            $categories = [];
                            $product_categories = $this->model_catalog_product->getCategories($product_id);
                            foreach ($product_categories as $category) {
                                $categories[] = (int)$category['category_id'];
                            }

                            $json['products'][] = (int)$product_info['product_id'];
                        }
                    }
                }
            }

            $json['success'] = true;
        } catch (\Exception $e) {
            $json['error']['warning'] = $e->getMessage();
            $this->log->write('Featured Products API Error: ' . $e->getMessage());
        }
        $this->response->setOutput($this->jsonp($json, true));
    }
}
