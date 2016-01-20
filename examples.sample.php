<?php

require 'vendor/autoload.php';

use SalesforceRestAPI\SalesforceAPI;
use SalesforceRestAPI\SalesforceAPIException;

$instanceUrl    = 'https://<instance-name>.salesforce.com';
$apiVersion     = '35.0';
$consumerKey    = '';
$consumerSecret = '';
$username       = '';
$password       = '';
$securityToken  = '';

try {
    $salesforce = new SalesforceAPI($instanceUrl, $apiVersion, $consumerKey, $consumerSecret);
    $salesforce->login($username, $password, $securityToken);
}catch (SalesforceAPIException $e){
    exit('Failed to connect to Salesforce: ' . $e->getMessage());
}

$apiVersions = $salesforce->getAPIVersions();
$limits = $salesforce->getOrgLimits();
$resource = $salesforce->getAvailableResources();
$objects = $salesforce->getAllObjects();

$response = $salesforce->searchSOQL("SELECT CustomField__c, Name FROM Account WHERE CustomField__c = '1'", true);
$good_metadata = $salesforce->getObjectMetadata('Account');
$good_metadata_all = $salesforce->getObjectMetadata('Account', true);

$date = new DateTime();
$good_metadata_since = $salesforce->getObjectMetadata('Account', true, $date); // broken

$create_account = $salesforce->create( 'Account', ['name' => 'New Account', 'GoReact_Org_ID__c' => 0] );
$update_project = $salesforce->update( 'Account', $create_account['id'], ['name' => 'Changed'] );
$project = $salesforce->get( 'Account', $create_account['id'] );
$project_with_fields = $salesforce->get( 'Account', $create_account['id'], ['Name', 'OwnerId'] );
$delete_project = $salesforce->delete( 'Account', $create_account['id'] );