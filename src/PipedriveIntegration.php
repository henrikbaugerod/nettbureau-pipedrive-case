<?php
/**
 * PipedriveIntegration
 * 
 * Class for creating organizations, persons and leads in Pipedrive.
 * Uses curl for the API requests
 */

class PipedriveIntegration {
    private $apiKey;
    private $apiUrl;

    public function __construct(string $domain, string $apiKey) {
        $this->apiKey = $apiKey;
        $this->apiUrl = 'https://' . $domain . '.pipedrive.com/api/';
    }
    
    private function log(string $message) {
        $time = date('Y-m-d H:i:s');
        file_put_contents(__DIR__ . '/pipedrive_integration.log', "[$time] $message" . PHP_EOL, FILE_APPEND);
    }

    private function apiCall(string $method, string $endpoint, array $data = [], int $version = 2) {
        $url =  $this->apiUrl . 'v' . $version . '/' . $endpoint;

        $curl = curl_init();
            $headers = [
            'x-api-token: ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => !empty($data) ? json_encode($data) : null,
            CURLOPT_HTTPHEADER => $headers
        ));
        
        $response = curl_exec($curl);
        
        // Check for cURL errors
        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            $this->log('cURL Error: ' . $error);
            throw new Exception("cURL Error: $error");
        }
        
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        $result = json_decode($response, true);

        if ($status >= 400 || (isset($result['success']) && !$result['success'])) {
            $message = $result['error'] ?? "API returned status $status";
            $this->log('API Error: ' . $message);
            throw new Exception("API Error: " . $message);
        }
        
        return $result;
    }
    
    private function getOrganization(string $name) {
        $response = $this->apiCall('GET', 'organizations/search?term=' . urlencode($name));
        return $response;
    }
    
    public function createOrganization(array $data) {
        // Check for existing organization before creating new one
        $existingOrg = $this->getOrganization($data['name']);
        if(count($existingOrg['data']['items']) > 0) {
            // Organization exists, return first item
            $this->log('Organization already exists with name: ' . $data['name']);
            return ['data' => $existingOrg['data']['items'][0]['item']];
        }
        
        // Create new
        $response = $this->apiCall('POST', 'organizations', $data);
        $this->log('Creating new organization with name: ' . $data['name']);
        return $response;
    }
    
    private function getPerson(string $name, int $orgId) {
        $response = $this->apiCall('GET', 'persons/search?term=' . urlencode($name) . '&org_id=' . $orgId);
        return $response;
    }
    
    public function createPerson(array $data, int $orgId) {
        // Check for existing person by name
        $existingPerson = $this->getPerson($data['name'], $orgId);
        if(count($existingPerson['data']['items']) > 0) {
            // Person exists, return first item
            $this->log('Person already exists with name: ' . $data['name'] . ' and org_id: ' . $orgId);
            return ['data' => $existingPerson['data']['items'][0]['item']];
        }
        
        // Create new person
        $payload = [
            'name' => $data['name'],
            'org_id' => $orgId,
            'emails' => [
                [
                    'value' => $data['email'],
                    'primary' => true
                ]
            ],
            'phones' => [
                [
                    'value' => $data['phone'],
                    'primary' => true
                ]
            ],
            'custom_fields' => [
                $this->getCustomFieldsKey('contact_type') => $this->getContactTypeId($data['contact_type'])
            ]
        ];
        
        $response = $this->apiCall('POST', 'persons', $payload);
        $this->log('Creating new person with name: ' . $data['name'] . ' and org_id: ' . $orgId);
        return $response;
    }
    
    private function getLead(string $name, int $personId, int $orgId) {
        $response = $this->apiCall('GET', 'leads/search?term=' . urlencode($name) . '&person_id=' . $personId . '&organization_id=' . $orgId);
        return $response;
    }
    
    public function createLead(array $data) {        
        $payload = [
            'title' => 'Lead: ' . $data['deal_type'] . ' for ' . $data['name'],
            'person_id' => $data['person_id'],
            'organization_id' => $data['organization_id'],
            'expected_close_date' => date('Y-m-d', strtotime('+1 week')),
            $this->getCustomFieldsKey('housing_type') => $this->getHousingTypeId($data['housing_type']),
            $this->getCustomFieldsKey('property_size') => $data['property_size'],
            $this->getCustomFieldsKey('comment') => $data['comment'],
            $this->getCustomFieldsKey('deal_type') => $this->getDealTypeId($data['deal_type'])
        ];
        
        // Check if lead already exists
        $existingLead = $this->getLead($payload['title'], $data['person_id'], $data['organization_id']);
        if(count($existingLead['data']['items']) > 0) {
            // Person exists, return first item
            $this->log('Lead already exists with name: ' . $payload['title'] . ' and person_id: ' . $data['person_id'] . ' and org_id: ' . $data['organization_id']);
            return ['data' => $existingLead['data']['items'][0]['item']];
        }
        
        // Create new lead
        $response = $this->apiCall('POST', 'leads', $payload, 1);
        $this->log('Creating new lead with name: ' . $data['name'] . ' and person_id: ' . $data['person_id'] . ' and org_id: ' . $data['organization_id']);
        return $response;
    }
    
    private function getContactTypeId($type) {
        return match(strtolower($type)) {
            'privat' => 27,
            'borettslag' => 28,
            'bedrift' => 29,
            default => null
        };
    }
    
    private function getHousingTypeId($type) {
        return match(strtolower($type)) {
            'enebolig' => 30,
            'leilighet' => 31,
            'tomannsbolig' => 32,
            'rekkehus' => 33,
            'hytte' => 34,
            'annet' => 35,
            default => null
        };
    }
    
    private function getDealTypeId($type) {
        return match(strtolower($type)) {
            'alle strømavtaler er aktuelle' => 42,
            'fastpris' => 43,
            'spotpris' => 44,
            'kraftforvaltning' => 45,
            'annen avtale/vet ikke' => 46,
            default => null
        };
    }
    
    private function getCustomFieldsKey($field) {
        return match(strtolower($field)) {
            'housing_type' => '35c4e320a6dee7094535c0fe65fd9e748754a171',
            'property_size' => '533158ca6c8a97cc1207b273d5802bd4a074f887',
            'comment' => '1fe6a0769bd867d36c25892576862e9b423302f3',
            'deal_type' => '761dd27362225e433e1011b3bd4389a48ae4a412',
            'contact_type' => 'c0b071d74d13386af76f5681194fd8cd793e6020',
            default => null
        };
    }
}