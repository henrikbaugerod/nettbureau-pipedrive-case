<?php
require_once 'src/PipedriveIntegration.php';

try {
    // Create new instance of pipedrive integration class and get testdata
    $pipedrive = new PipedriveIntegration("nettbureaucase", "24eaceaa89c83e18fd4aadd3dbab7a3b01ddffc8");
    $testData = json_decode(file_get_contents('test/test_data.json'), true);
    
    $responses = [];
    
    // Loop through data and add response to array
    foreach($testData as $item) {
        // Create an organization in pipedrive with relevant information
        $orgData = $item['organization'];
        $org = $pipedrive->createOrganization($orgData);
        $orgId = $org['data']['id'];
        if(!$orgId) throw new Exception("Failed to create organization for: " . $orgData['name']);
        
        // Create a person and connect it to the organization
        $personData = $item['person'];
        $person = $pipedrive->createPerson($personData, $orgId);
        $personId = $person['data']['id'];
        if(!$personId) throw new Exception("Failed to create person for: " . $personData['name']);
        
        // Create a lead and connect it to the organization and person
        $leadData = array_merge($personData, [
            'person_id' => $personId,
            'organization_id' => $orgId,
            'comment' => 'Lorem ipsum dolor sit amet.'
        ]);
        $lead = $pipedrive->createLead($leadData);
        
        // Add to array
        $responses[] = [
            'organization' => $org,
            'person' => $person,
            'lead' => $lead
        ];
    }

    echo '<pre>' . print_r($responses, true) . '</pre>';
} catch (Exception $e) {
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
}

return '<pre>EOF</pre>';