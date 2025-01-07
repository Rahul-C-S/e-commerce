<?php

namespace Opencart\Catalog\Controller\Mobile;

class Auth extends ApiController
{
    /**
     * Auth middleware - validates Bearer token from headers
     * Call at the start of protected endpoints
     *
     * @return array|void Customer info if valid, outputs error response if invalid
     */


    public function login(): void
    {
        $json = [];

        try {
            // Validate required fields
            $required_fields = ['email', 'password'];
            foreach ($required_fields as $field) {
                if (empty($this->request->post[$field])) {
                    $json['error']['warning'] = 'Required field missing: ' . $field;
                }
            }

            if (!$json) {
                $this->load->model('account/customer');

                // Check login attempts
                $login_info = $this->model_account_customer->getLoginAttempts($this->request->post['email']);

                if (
                    $login_info && ($login_info['total'] >= $this->config->get('config_login_attempts')) &&
                    strtotime('-1 hour') < strtotime($login_info['date_modified'])
                ) {
                    $json['error']['warning'] = 'Too many failed login attempts. Please try again in 1 hour.';
                } else {
                    // Get customer info
                    $customer_info = $this->model_account_customer->getCustomerByEmail($this->request->post['email']);

                    if ($customer_info && !$customer_info['status']) {
                        $json['error']['warning'] = 'Account is not approved or inactive';
                    } elseif (!$customer_info || !password_verify(
                        html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8'),
                        $customer_info['password']
                    )) {
                        $json['error']['warning'] = 'Invalid email or password';
                        $this->model_account_customer->addLoginAttempt($this->request->post['email']);
                    } else {
                        // Generate device identifier using client provided data
                        $device_data = [
                            'user_agent' => $this->request->server['HTTP_USER_AGENT'] ?? '',
                            'ip' => $this->request->server['REMOTE_ADDR'],
                            'client_data' => $this->request->post['device_info'] ?? [],
                            'timestamp' => time()
                        ];

                        $device_id = $this->generateDeviceId($device_data);

                        // Check for existing token for this device
                        $existing_token = $this->db->query("SELECT token, device_info FROM " . DB_PREFIX . "customer_token 
                            WHERE customer_id = '" . (int)$customer_info['customer_id'] . "' 
                            AND device_id = '" . $this->db->escape($device_id) . "'
                            AND expires_at > NOW()");

                        if ($existing_token->num_rows) {
                            // Update existing token's expiry and device info
                            $this->db->query("UPDATE " . DB_PREFIX . "customer_token SET 
                                expires_at = '" . $this->db->escape(date('Y-m-d H:i:s', strtotime('+30 days'))) . "',
                                ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "',
                                device_info = '" . $this->db->escape(json_encode($device_data)) . "',
                                last_used = NOW()
                                WHERE token = '" . $this->db->escape($existing_token->row['token']) . "'");

                            $token = $existing_token->row['token'];
                        } else {
                            // Generate new token
                            $token = $this->generateToken();
                            $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

                            // Store token with device info
                            $this->db->query("INSERT INTO " . DB_PREFIX . "customer_token SET 
                                customer_id = '" . (int)$customer_info['customer_id'] . "', 
                                token = '" . $this->db->escape($token) . "',
                                device_id = '" . $this->db->escape($device_id) . "',
                                device_info = '" . $this->db->escape(json_encode($device_data)) . "',
                                expires_at = '" . $this->db->escape($expires_at) . "',
                                ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "',
                                created_at = NOW(),
                                last_used = NOW()");
                        }

                        // Log the login
                        $this->model_account_customer->addLogin(
                            $customer_info['customer_id'],
                            $this->request->server['REMOTE_ADDR']
                        );

                        // Clear failed attempts
                        $this->model_account_customer->deleteLoginAttempts($this->request->post['email']);

                        $json['status'] = true;
                        $json['success'] = 'Successfully logged in';
                        $json['data'] = [
                            'token' => $token,
                            'device_id' => $device_id,
                            'expires_at' => $expires_at ?? date('Y-m-d H:i:s', strtotime('+30 days')),
                            'customer' => [
                                'id' => $this->encodeKey($customer_info['customer_id']),
                                'email' => $customer_info['email'],
                                'firstname' => $customer_info['firstname'],
                                'lastname' => $customer_info['lastname'],
                                'telephone' => $customer_info['telephone']
                            ]
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $json['error']['warning'] = 'An error occurred during login';
            $json['status'] = false;
            $this->log->write('Login API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Validates customer token and returns customer info
     * 
     * @param string $token Auth token
     * @return array|false Customer info if valid, false otherwise
     */

    public function register(): void
    {
        $json = [];

        try {
            // Check required fields
            $required_fields = [
                'firstname',
                'lastname',
                'email',
                'telephone',
                'password',
                'confirm'
            ];

            foreach ($required_fields as $field) {
                if (empty($this->request->post[$field])) {
                    $json['error']['warning'] = 'Required field missing: ' . $field;
                    break;
                }
            }

            if (!$json) {
                // Validate customer group
                $customer_group_id = (int)($this->request->post['customer_group_id'] ?? $this->config->get('config_customer_group_id'));

                $this->load->model('account/customer_group');
                $customer_group_info = $this->model_account_customer_group->getCustomerGroup($customer_group_id);

                if (!$customer_group_info || !in_array($customer_group_id, (array)$this->config->get('config_customer_group_display'))) {
                    $json['error']['warning'] = 'Invalid customer group';
                }

                // Field validations
                if ((oc_strlen($this->request->post['firstname']) < 1) || (oc_strlen($this->request->post['firstname']) > 32)) {
                    $json['error']['warning'] = 'First name must be between 1 and 32 characters';
                }

                if ((oc_strlen($this->request->post['lastname']) < 1) || (oc_strlen($this->request->post['lastname']) > 32)) {
                    $json['error']['warning'] = 'Last name must be between 1 and 32 characters';
                }

                if ((oc_strlen($this->request->post['email']) > 96) || !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) {
                    $json['error']['warning'] = 'Invalid email address';
                }

                $this->load->model('account/customer');
                if ($this->model_account_customer->getTotalCustomersByEmail($this->request->post['email'])) {
                    $json['error']['warning'] = 'Email address is already registered';
                }

                if ((oc_strlen($this->request->post['telephone']) < 3) || (oc_strlen($this->request->post['telephone']) > 32)) {
                    $json['error']['warning'] = 'Telephone must be between 3 and 32 characters';
                }

                // Password validation
                if ((oc_strlen(html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')) < 4) ||
                    (oc_strlen(html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')) > 40)
                ) {
                    $json['error']['warning'] = 'Password must be between 4 and 40 characters';
                }

                if ($this->request->post['confirm'] != $this->request->post['password']) {
                    $json['error']['warning'] = 'Password confirmation does not match';
                }
            }

            if (!$json) {
                $customer_id = $this->model_account_customer->addCustomer($this->request->post);

                // Auto-login after registration if no approval needed
                if (!$customer_group_info['approval']) {
                    // Generate device identifier
                    $device_data = [
                        'user_agent' => $this->request->server['HTTP_USER_AGENT'] ?? '',
                        'ip' => $this->request->server['REMOTE_ADDR'],
                        'client_data' => $this->request->post['device_info'] ?? [],
                        'timestamp' => time(),
                        'registration' => true  // Flag to indicate this is from registration
                    ];

                    $device_id = $this->generateDeviceId($device_data);

                    // Generate API token
                    $token = $this->generateToken();
                    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

                    // Store token with device info
                    $this->db->query("INSERT INTO " . DB_PREFIX . "customer_token SET 
                        customer_id = '" . (int)$customer_id . "', 
                        token = '" . $this->db->escape($token) . "',
                        device_id = '" . $this->db->escape($device_id) . "',
                        device_info = '" . $this->db->escape(json_encode($device_data)) . "',
                        expires_at = '" . $this->db->escape($expires_at) . "',
                        ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "',
                        created_at = NOW(),
                        last_used = NOW()");

                    // Log the registration
                    $this->model_account_customer->addLogin(
                        $customer_id,
                        $this->request->server['REMOTE_ADDR']
                    );

                    $json['status'] = true;
                    $json['success'] = 'Account successfully created';
                    $json['data'] = [
                        'token' => $token,
                        'device_id' => $device_id,
                        'expires_at' => $expires_at,
                        'customer' => [
                            'id' => $this->encodeKey($customer_id),
                            'email' => $this->request->post['email'],
                            'firstname' => $this->request->post['firstname'],
                            'lastname' => $this->request->post['lastname'],
                            'telephone' => $this->request->post['telephone']
                        ]
                    ];
                } else {
                    $json['status'] = true;
                    $json['success'] = 'Your account has been created and must be approved by an administrator';
                }

                // Clear any previous login attempts
                $this->model_account_customer->deleteLoginAttempts($this->request->post['email']);
            }
        } catch (\Exception $e) {
            $json['error']['warning'] = 'An error occurred during registration';
            $json['status'] = false;
            $this->log->write('Register API Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function forgotPassword(): void
    {
        $json = [];

        try {
            if (empty($this->request->post['email'])) {
                $json['error']['warning'] = 'Email is required';
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            $this->load->model('account/customer');

            $customer_info = $this->model_account_customer->getCustomerByEmail($this->request->post['email']);

            if (!$customer_info) {
                $json['error']['warning'] = 'No account found with this email address';
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            // Generate reset code
            $code = oc_token(40);
            $this->model_account_customer->editCode($this->request->post['email'], $code);

            // Store reset attempt in session
            $this->session->data['reset_email'] = $this->request->post['email'];
            $this->session->data['reset_code'] = $code;
            $this->session->data['reset_time'] = time();

            $json['status'] = true;
            $json['success'] = 'Password reset instructions have been sent to your email';
            $json['data'] = [
                'reset_token' => $code,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ];
        } catch (\Exception $e) {
            $json['error']['warning'] = 'Failed to process password reset request';
            $json['status'] = false;
            $this->log->write('Forgot Password Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function resetPassword(): void
    {
        // Validate token first
        $customer_info = $this->authCheck();
        if (!$customer_info) return;
        $json = [];

        try {
            $this->load->language('account/password');



            $keys = [
                'password',
                'confirm'
            ];

            foreach ($keys as $key) {
                if (!isset($this->request->post[$key])) {
                    $this->request->post[$key] = '';
                }
            }

            if ((oc_strlen(html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')) < 4) || (oc_strlen(html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8')) > 40)) {
                $json['error']['waring'] = $this->language->get('error_password');
            }

            if ($this->request->post['confirm'] != $this->request->post['password']) {
                $json['error']['warning'] = $this->language->get('error_confirm');
            }


            $this->load->model('account/customer');

            $this->model_account_customer->editPassword($customer_info['email'], $this->request->post['password']);

            $json['status'] = true;
            $json['success'] = $this->language->get('text_success');
        } catch (\Exception $e) {
            $json['error']['warning'] = 'Failed to reset password';
            $json['status'] = false;
            $this->log->write('Reset Password Error: ' . $e->getMessage());
        }
        $this->log->write(print_r($json, true));
        $this->response->setOutput($this->jsonp($json, true));
    }

    public function deleteAccount(): void
    {
        // Validate token first
        $customer_info = $this->authCheck();
        if (!$customer_info) return;

        $json = [];

        try {
            // Verify password for security
            if (empty($this->request->post['password'])) {
                $json['error']['warning'] = 'Password is required to delete account';
            } elseif (!password_verify(
                html_entity_decode($this->request->post['password'], ENT_QUOTES, 'UTF-8'),
                $customer_info['password']
            )) {
                $json['error']['warning'] = 'Invalid password';
            }

            if (!$json) {
                $this->load->model('account/customer');

                // Delete customer and related data
                $this->model_account_customer->deleteCustomer($customer_info['customer_id']);

                // Clear all tokens
                $this->db->query("DELETE FROM " . DB_PREFIX . "customer_token 
                    WHERE customer_id = '" . (int)$customer_info['customer_id'] . "'");

                $json['status'] = true;
                $json['success'] = 'Account has been successfully deleted';
            }
        } catch (\Exception $e) {
            $json['error']['warning'] = 'Failed to delete account';
            $json['status'] = false;
            $this->log->write('Delete Account Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function validateToken(): void
    {
        $json = [];

        try {
            $token = $this->request->get['token'] ?? $this->request->post['token'] ?? null;
            $device_id = $this->request->get['device_id'] ?? $this->request->post['device_id'] ?? null;

            if (!$token || !$device_id) {
                $json['error']['warning'] = 'Token and device ID are required';
                $json['status'] = false;
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            // Verify both token and device ID match
            $query = "SELECT ct.*, c.status 
                FROM " . DB_PREFIX . "customer_token ct
                LEFT JOIN " . DB_PREFIX . "customer c ON (c.customer_id = ct.customer_id)
                WHERE ct.token = '" . $this->db->escape($token) . "' 
                AND ct.device_id = '" . $this->db->escape($device_id) . "'
                AND ct.expires_at > NOW()";

            $token_info = $this->db->query($query);

            if ($token_info->num_rows && $token_info->row['status']) {
                // Update last used timestamp and check for device info changes
                $current_device_data = [
                    'user_agent' => $this->request->server['HTTP_USER_AGENT'] ?? '',
                    'ip' => $this->request->server['REMOTE_ADDR'],
                    'client_data' => $this->request->post['device_info'] ?? [],
                    'timestamp' => time()
                ];

                $this->db->query("UPDATE " . DB_PREFIX . "customer_token SET 
                    last_used = NOW(),
                    device_info = '" . $this->db->escape(json_encode($current_device_data)) . "'
                    WHERE token = '" . $this->db->escape($token) . "'
                    AND device_id = '" . $this->db->escape($device_id) . "'");

                $json['status'] = true;
                $json['valid'] = true;
                $json['data'] = [
                    'expires_at' => $token_info->row['expires_at'],
                    'last_used' => date('Y-m-d H:i:s')
                ];
            } else {
                $json['status'] = true;
                $json['valid'] = false;
                $json['error']['warning'] = 'Invalid or expired token';
            }
        } catch (\Exception $e) {
            $json['error']['warning'] = 'Token validation failed';
            $json['status'] = false;
            $this->log->write('Token Validation Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    public function logout(): void
    {
        $json = [];

        try {
            $token = $this->request->get['token'] ?? $this->request->post['token'] ?? null;
            $device_id = $this->request->get['device_id'] ?? $this->request->post['device_id'] ?? null;

            if (!$token || !$device_id) {
                $json['error']['warning'] = 'Token and device ID are required';
                $json['status'] = false;
                $this->response->setOutput($this->jsonp($json, true));
                return;
            }

            // Remove the token for this device
            $this->db->query("DELETE FROM " . DB_PREFIX . "customer_token 
                WHERE token = '" . $this->db->escape($token) . "'
                AND device_id = '" . $this->db->escape($device_id) . "'");

            // Optional: Log the logout event
            $this->log->write('Customer logged out - Device ID: ' . $device_id);

            $json['status'] = true;
            $json['success'] = 'Successfully logged out';
        } catch (\Exception $e) {
            $json['error']['warning'] = 'Logout failed';
            $json['status'] = false;
            $this->log->write('Logout Error: ' . $e->getMessage());
        }

        $this->response->setOutput($this->jsonp($json, true));
    }

    /**
     * Generates a secure token for authentication
     * 
     * @return string Generated token
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generates a device identifier based on provided device data
     * 
     * @param array $device_data Array containing device information
     * @return string Generated device identifier
     */
    private function generateDeviceId(array $device_data): string
    {
        // Sort the device data to ensure consistent ordering
        ksort($device_data);

        // Create a unique fingerprint from device characteristics
        $fingerprint_data = [
            'user_agent' => $device_data['user_agent'],
            'client_data' => $device_data['client_data'],
        ];

        // Create a unique string from fingerprint data
        $device_string = json_encode($fingerprint_data);

        // Generate a hash using the device string and a server-side salt
        $server_salt = $this->config->get('config_encryption');
        $device_hash = hash_hmac('sha256', $device_string, $server_salt);

        // Add timestamp component for uniqueness
        $timestamp_component = substr(hash('sha256', $device_data['timestamp'] . $server_salt), 0, 8);

        return $device_hash . $timestamp_component;
    }
}
