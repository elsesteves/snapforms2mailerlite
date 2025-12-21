<?php

class MailerLiteClient {
    private $apiBaseUrl = 'https://connect.mailerlite.com/api';
    private $apiToken;
    private $fieldMappings = [];
    private $groups = [];
    private $requiredConsents = [];
    private $timeout = 30;

    // Construct with per-form config array
    public function __construct(array $config, $apiBaseUrl = null, $timeout = null) {
        if ($apiBaseUrl) {
            $this->apiBaseUrl = $apiBaseUrl;
        }
        if ($timeout) {
            $this->timeout = (int)$timeout;
        }

        $this->apiToken = $config['api_token'] ?? '';
        $this->groups = is_array($config['groups'] ?? null) ? $config['groups'] : [];
        $this->fieldMappings = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $this->requiredConsents = is_array($config['required_consents'] ?? null) ? $config['required_consents'] : [];

        if (!empty($config['settings']['timeout'])) {
            $this->timeout = (int)$config['settings']['timeout'];
        }

        if (empty($this->apiToken)) {
            throw new Exception('MailerLite API token is not configured');
        }
    }

    // Load all form configs from a JSON file
    public static function loadFormsConfig($configPath) {
        if (!file_exists($configPath)) {
            throw new Exception('MailerLite config file not found: ' . $configPath);
        }
        $json = file_get_contents($configPath);
        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new Exception('Invalid MailerLite config JSON');
        }
        
        if (isset($config['forms']) && is_array($config['forms'])) {
            $forms = $config['forms'];
            $indexed = [];
            foreach ($forms as $form) {
                if (!is_array($form) || !isset($form['id_form'])) {
                    throw new Exception('Each form entry must include an id_form');
                }
                $indexed[(string)$form['id_form']] = $form;
            }
            return $indexed;
        }

        //No matching forms found
        return null;
    }

    // Helper to instantiate a client for a given id_form from a config file
    public static function forForm($configPath, $id_form) {
        $forms = self::loadFormsConfig($configPath);
        if (!isset($forms[$id_form])) {
            throw new Exception('No MailerLite config found for form ID: ' . $id_form);
        }
        return new self($forms[$id_form]);
    }

    private function httpRequest($method, $url, $args = []) {
        $defaults = [
            'headers' => [],
            'body'    => null,
            'timeout' => $this->timeout,
        ];
        $opts = array_merge($defaults, $args);

        return wp_remote_request($url, [
            'method'  => strtoupper($method),
            'headers' => $opts['headers'],
            'body'    => $opts['body'],
            'timeout' => (int)$opts['timeout'],
        ]);
    }

    private function getSnapFieldValue($submission, $fieldId) {
        if (!isset($submission['fields'][$fieldId]['sub_data'])) {
            return '';
        }
        $data = $submission['fields'][$fieldId]['sub_data'];
        if (!empty($data['label'])) {
            return $data['label'];
        }
        if (!empty($data['value'])) {
            return $data['value'];
        }
        if (!empty($data['text'])) {
            return $data['text'];
        }
        return '';
    }

    private function hasRequiredConsents($submission) {
        if (empty($this->requiredConsents)) {
            return true;
        }
        foreach ($this->requiredConsents as $consentFieldId) {
            $dt = $submission['fields'][$consentFieldId]['sub_data']['consent']['dt_given'] ?? '';
            if (empty($dt)) {
                return false;
            }
        }
        return true;
    }

    public function addSubscriber($submission) {
        if (empty($submission['recipient']['email'])) {
            return new WP_Error('mailerlite_error', 'Submission recipient email is empty');
        }

        if (empty($submission['recipient']['name'])) {
            return new WP_Error('mailerlite_error', 'Submission recipient name is empty');
        }

        if (!$this->hasRequiredConsents($submission)) {
            return new WP_Error('mailerlite_error', 'Required consent not given');
        }

        $nameParts = explode(' ', $submission['recipient']['name']);
        $firstName = $nameParts[0] ?? '';
        $lastName = end($nameParts) ?: '';

        $fields = [
            'name'       => $firstName,
            'last_name'  => $lastName,
            'country'    => $submission['submission_context']['ip_geo_location']['country']['name'] ?? '',
        ];

        foreach ($this->fieldMappings as $snapFieldId => $mlFieldName) {
            $fields[$mlFieldName] = $this->getSnapFieldValue($submission, $snapFieldId);
        }

        $body = [
            'email'  => $submission['recipient']['email'] ?? '',
            'fields' => $fields,
        ];

        if (!empty($this->groups)) {
            $body['groups'] = $this->groups;
        }

        $url = rtrim($this->apiBaseUrl, '/') . '/subscribers';

        $response = $this->httpRequest('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => $this->timeout,
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code >= 200 && $response_code < 300) {
            return json_decode($response_body, true);
        }

        return new WP_Error('mailerlite_error', 'MailerLite API request failed', [
            'status_code'   => $response_code,
            'response_body' => $response_body,
        ]);
    }
}