<?php

namespace Opencart\Catalog\Controller\Mobile;

class About extends ApiController
{
    public function info()
    {
        $json = [];

        $this->load->model('catalog/information');

        $json['about'] = $this->model_catalog_information->getInformation(1);
        $json['delivery_info'] = $this->model_catalog_information->getInformation(4);

        $json['contact'] = [
            'store' => $this->config->get('config_name'),
            'address' => nl2br($this->config->get('config_address')),
            'telephone' => $this->config->get('config_telephone'),
            'email' => $this->config->get('config_email'),
        ];

        $json['opening_times'] = nl2br($this->config->get('config_open'));

        $this->response->setOutput($this->jsonp($json, true));
    }
}
