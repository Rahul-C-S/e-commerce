<?php

namespace Opencart\Catalog\Controller\Mobile;

class Address extends ApiController
{



    public function list(): void
    {
        $json = [];
        if (!$this->authCheck()) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $customer = $this->authCheck();

        if (!$customer) return;

        $this->load->model('account/address');

        $results = $this->model_account_address->getAddresses($customer['customer_id']);

        foreach ($results as $result) {
            $json['addresses'][] = [
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

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function countries(): void
    {
        if (!$this->authCheck()) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        $json = [];

        $this->load->model('localisation/country');

        $json['countries'] = $this->model_localisation_country->getCountries();

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function country(): void
    {

        $json = [];
        if (!$this->authCheck()) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }

        if (isset($this->request->post['country_id'])) {
            $country_id = (int)$this->request->post['country_id'];
        } else {
            $country_id = 0;
        }

        $this->load->model('localisation/country');

        $country_info = $this->model_localisation_country->getCountry($country_id);

        if ($country_info) {
            $this->load->model('localisation/zone');

            $json = [
                'country_id'        => (int)$country_info['country_id'],
                'name'              => $country_info['name'],
                'zone'              => $this->model_localisation_zone->getZonesByCountryId($country_id),
                'status'            => $country_info['status']
            ];
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * @return void
     */
    public function save(): void
    {
        $this->load->language('account/address');

        $json = [];

        $customer = $this->authCheck();

        if (!$customer) {
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }

        if (!$json) {
            $keys = [
                'firstname',
                'lastname',
                'address_1',
                'address_2',
                'city',
                'postcode',
                'country_id',
                'zone_id'
            ];

            foreach ($keys as $key) {
                if (!isset($this->request->post[$key])) {
                    $this->request->post[$key] = '';
                }
            }

            if ((oc_strlen($this->request->post['firstname']) < 1) || (oc_strlen($this->request->post['firstname']) > 32)) {
                $json['error']['warning'] = $this->language->get('error_firstname');
            }

            if ((oc_strlen($this->request->post['lastname']) < 1) || (oc_strlen($this->request->post['lastname']) > 32)) {
                $json['error']['warning'] = $this->language->get('error_lastname');
            }

            if ((oc_strlen($this->request->post['address_1']) < 3) || (oc_strlen($this->request->post['address_1']) > 128)) {
                $json['error']['warning'] = $this->language->get('error_address_1');
            }

            if ((oc_strlen($this->request->post['city']) < 2) || (oc_strlen($this->request->post['city']) > 128)) {
                $json['error']['warning'] = $this->language->get('error_city');
            }

            $this->load->model('localisation/country');

            $country_info = $this->model_localisation_country->getCountry((int)$this->request->post['country_id']);

            if ($country_info && $country_info['postcode_required'] && (oc_strlen($this->request->post['postcode']) < 2 || oc_strlen($this->request->post['postcode']) > 10)) {
                $json['error']['warning'] = $this->language->get('error_postcode');
            }

            if ($this->request->post['country_id'] == '') {
                $json['error']['warning'] = $this->language->get('error_country');
            }

            if ($this->request->post['zone_id'] == '') {
                $json['error']['warning'] = $this->language->get('error_zone');
            }
        }

        if (!$json) {
            $this->load->model('account/address');

            // Add Address
            if (!isset($this->request->post['address_id'])) {
                $this->model_account_address->addAddress($customer['customer_id'], $this->request->post);
                $json['success'] = true;
                $json['message'] = $this->language->get('text_add');
            }

            // Edit Address
            if (isset($this->request->post['address_id'])) {
                $this->model_account_address->editAddress($this->request->post['address_id'], $this->request->post);
                $json['success'] = true;
                $json['message'] = $this->language->get('text_edit');
            }
        }

        $this->log->write(print_r($json,true));

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * @return void
     */
    public function delete(): void
    {
        $this->load->language('account/address');
        $json = [];
    
        $customer = $this->authCheck();
        if (!$customer) {
            $this->response->setOutput($this->jsonp([], true));
            return;
        }
    
        $address_id = isset($this->request->post['address_id']) ? (int)$this->request->post['address_id'] : 0;
        $customer_id = $customer['customer_id'];
    
        $this->load->model('account/address');
        
    
        // Check if it's the last address
        if ($this->model_account_address->getTotalAddresses($customer_id) == 1) {
            $json['error']['warning'] = $this->language->get('error_delete');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }
    
        $this->load->model('account/subscription');
        $shipping_subscription = $this->model_account_subscription->getTotalSubscriptionByShippingAddressId($address_id);
        $payment_subscription = $this->model_account_subscription->getTotalSubscriptionByPaymentAddressId($address_id);
        
        if ($shipping_subscription || $payment_subscription) {
            $json['error']['warning'] = $this->language->get('error_subscription');
            $this->response->setOutput($this->jsonp($json, true));
            return;
        }
    
		$this->db->query("DELETE FROM `" . DB_PREFIX . "address` WHERE `address_id` = '" . (int)$address_id . "' AND `customer_id` = '" . (int)$customer_id . "'");

        
        $json['success'] = true;
        $json['message'] = $this->language->get('text_delete');
        
        $this->response->setOutput($this->jsonp($json, true));
    }
}
