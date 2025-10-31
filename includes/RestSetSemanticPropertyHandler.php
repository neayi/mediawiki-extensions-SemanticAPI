<?php

namespace MediaWiki\Extension\SemanticAPI;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Title\Title;
use SMW\StoreFactory;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\DataValueFactory;
use SMW\SemanticData;

class RestSetSemanticPropertyHandler extends Handler {

    public function execute() {
        $request = $this->getRequest();
        
        // Get the title from the URL path parameter
        $pathParams = $this->getValidatedParams();
        $titleText = $pathParams['title'] ?? null;
        
        // Get properties from request body - support both single and multiple properties
        $properties = [];
        
        // Try to get data from JSON body first
        $jsonBody = $request->getBody()->getContents();
        if (!empty($jsonBody)) {
            $jsonData = json_decode($jsonBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                if (isset($jsonData['properties']) && is_array($jsonData['properties'])) {
                    // Multiple properties format: {"properties": [{"property": "Prop1", "value": "Val1"}, ...]}
                    $properties = $jsonData['properties'];
                } else if (isset($jsonData['property']) && isset($jsonData['value'])) {
                    // Single property format (backward compatibility): {"property": "Prop1", "value": "Val1"}
                    $properties = [['property' => $jsonData['property'], 'value' => $jsonData['value']]];
                }
            }
        }
        
        // Fall back to form data if JSON parsing failed or data not found
        if (empty($properties)) {
            $formParams = $request->getPostParams();
            if (isset($formParams['property']) && isset($formParams['value'])) {
                $properties = [['property' => $formParams['property'], 'value' => $formParams['value']]];
            }
        }

        if ( !$titleText || empty($properties) ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'Missing required parameters: title or properties'
            ], 400 );
        }

        $title = Title::newFromText( $titleText );
        if ( !$title || !$title->exists() ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'Invalid or non-existing title'
            ], 400 );
        }

        $authority = $this->getAuthority();
        if ( !$authority->isAllowed( 'edit' ) ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'Permission denied'
            ], 403 );
        }

        // Validate and process all properties
        $processedProperties = [];
        $dataValueFactory = DataValueFactory::getInstance();
        
        foreach ($properties as $index => $propData) {
            if (!isset($propData['property']) || !isset($propData['value'])) {
                return $this->getResponseFactory()->createJson( [
                    'error' => "Missing property or value in item $index"
                ], 400 );
            }
            
            $propertyName = $propData['property'];
            $valueText = $propData['value'];
            
            if (empty($propertyName) || $valueText === null || $valueText === '') {
                return $this->getResponseFactory()->createJson( [
                    'error' => "Empty property name or value in item $index"
                ], 400 );
            }

            try {
                // Create property directly using the property name as key
                $property = new DIProperty( $propertyName );
                
                // Check if property is valid
                if ( !$property->isUserDefined() && !$property->isShown() ) {
                    return $this->getResponseFactory()->createJson( [
                        'error' => "Invalid or system property: $propertyName"
                    ], 400 );
                }
                
                $value = $dataValueFactory->newDataValueByProperty( $property, $valueText );

                if ( !$value || !$value->isValid() ) {
                    $errorText = $value ? implode( ', ', $value->getErrors() ) : 'Invalid value format';
                    return $this->getResponseFactory()->createJson( [
                        'error' => "Invalid value for property $propertyName: $errorText"
                    ], 400 );
                }
                
                $processedProperties[] = [
                    'property' => $property,
                    'value' => $value,
                    'name' => $propertyName,
                    'valueText' => $valueText
                ];
                
            } catch ( \Exception $e ) {
                return $this->getResponseFactory()->createJson( [
                    'error' => "SMW error for property $propertyName: " . $e->getMessage()
                ], 500 );
            }
        }

        $store = StoreFactory::getStore();
        $subject = DIWikiPage::newFromTitle( $title );
        
        // Get existing data first and add all properties to it
        $existingData = $store->getSemanticData( $subject );
        
        foreach ($processedProperties as $propData) {
            $existingData->addPropertyValue( $propData['property'], $propData['value']->getDataItem() );
        }
        
        // Update the store with enhanced data
        $store->updateData( $existingData );
        
        // Force SMW to refresh its cache
        $store->refreshData( $subject, 1, false );

        // Build response with all processed properties
        $responseProperties = [];
        foreach ($processedProperties as $propData) {
            $responseProperties[] = [
                'property' => $propData['name'],
                'property_key' => $propData['property']->getKey(),
                'property_label' => $propData['property']->getLabel(),
                'value' => $propData['valueText']
            ];
        }

        return $this->getResponseFactory()->createJson( [
            'result' => 'success',
            'title' => $title->getPrefixedText(),
            'properties' => $responseProperties,
            'count' => count($responseProperties)
        ] );
    }

    public function needsWriteAccess() {
        return true;
    }

    public function getParamSettings() {
        return [
            'title' => [
                self::PARAM_SOURCE => 'path',
                'type' => 'string',
                'required' => true
            ]
        ];
    }
}
