<?php

namespace Opencart\Catalog\Controller\Mobile;

class Home extends ApiController
{
    public function index()
    {
        echo "Mobile Home";
        return;
    }

    public function banner(): void
    {
        $json = [];

        try {
            // Load required models with correct core paths
            $this->load->model('design/banner');
            $this->load->model('tool/image');

            // Get all slideshow modules with direct query
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "module` WHERE `code` = 'opencart.banner'");

            $json['slideshows'] = [];

            if ($query->num_rows) {
                foreach ($query->rows as $module) {
                    // Ensure setting is a valid JSON string before decoding
                    $setting = [];
                    if (isset($module['setting']) && is_string($module['setting'])) {
                        $setting = json_decode($module['setting'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new \Exception('Error decoding module settings: ' . json_last_error_msg());
                        }
                    }

                    // Check if required settings exist
                    if (empty($setting['width']) || empty($setting['height']) || empty($setting['banner_id'])) {
                        continue;
                    }

                    $slideshow = [
                        'module_id' => (int)$module['module_id'],
                        'name' => $module['name'],
                        'width' => (int)$setting['width'],
                        'height' => (int)$setting['height'],
                        'effect' => $setting['effect'] ?? 'slide',
                        'controls' => $setting['controls'] ?? true,
                        'indicators' => $setting['indicators'] ?? true,
                        'items' => $setting['items'] ?? 4,
                        'interval' => (int)($setting['interval'] ?? 5000),
                        'slides' => []
                    ];

                    // Get banner data using model
                    $results = $this->model_design_banner->getBanner($setting['banner_id']);

                    foreach ($results as $result) {
                        if (is_file(DIR_IMAGE . html_entity_decode($result['image'], ENT_QUOTES, 'UTF-8'))) {
                            $slide = [
                                'title' => $result['title'],
                                'link' => $result['link'],
                                'image' => $this->model_tool_image->resize(
                                    html_entity_decode($result['image'], ENT_QUOTES, 'UTF-8'),
                                    $setting['width'],
                                    $setting['height']
                                )
                            ];

                            // Add mobile image if exists
                            if (!empty($result['mobile_image']) && is_file(DIR_IMAGE . html_entity_decode($result['mobile_image'], ENT_QUOTES, 'UTF-8'))) {
                                $slide['mobile_image'] = $this->model_tool_image->resize(
                                    html_entity_decode($result['mobile_image'], ENT_QUOTES, 'UTF-8'),
                                    $setting['width'],
                                    $setting['height']
                                );
                            }

                            // Add additional fields if they exist
                            if (!empty($result['description'])) {
                                $slide['description'] = html_entity_decode($result['description'], ENT_QUOTES, 'UTF-8');
                            }

                            if (!empty($result['button_text'])) {
                                $slide['button_text'] = html_entity_decode($result['button_text'], ENT_QUOTES, 'UTF-8');
                            }

                            $slideshow['slides'][] = $slide;
                        }
                    }

                    // Only add slideshow if it has slides
                    if (!empty($slideshow['slides'])) {
                        $json['slideshows'][] = $slideshow;
                    }
                }
            }

            if (empty($json['slideshows'])) {
                throw new \Exception('No active slideshows found');
            }

            $json['success'] = true;
            $json['message'] = 'Slideshow data retrieved successfully';
        } catch (\Exception $e) {
            $json['success'] = false;
            $json['error']['warning'] = $e->getMessage();
            $this->log->write('Slideshow API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }
    public function getStoreInfo(): void
    {
        $json = [];

        try {
            // Load required models
            $this->load->model('setting/setting');
            $this->load->model('localisation/language');

            // Get store settings
            $store_id = (int)($this->request->get['store_id'] ?? 0);
            $settings = $this->model_setting_setting->getSetting('config', $store_id);


            $json['store'] = [
                'name' => $settings['config_name'] ?? '',
                'email' => $settings['config_email'] ?? '',
                'telephone' => $settings['config_telephone'] ?? '',
                'address' => $settings['config_address'] ?? '',
                'currency' => $settings['config_currency'] ?? 'USD'
            ];

            $json['success'] = true;
            $json['message'] = 'Store information retrieved successfully';
        } catch (\Exception $e) {
            $json['success'] = false;
            $json['error']['warning'] = $e->getMessage();
            $this->log->write('Store Info API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

   
}
