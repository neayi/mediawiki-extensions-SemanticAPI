<?php

namespace MediaWiki\Extension\SemanticAPI;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Title\Title;
use SMW\StoreFactory;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\SemanticData;

class RestDeleteSemanticPropertyHandler extends Handler {

    public function execute() {
        $request = $this->getRequest();
        
        // Get both title and property from the URL path parameters
        $pathParams = $this->getValidatedParams();
        $titleText = $pathParams['title'] ?? null;
        $propertyName = $pathParams['property'] ?? null;

        if ( !$titleText || !$propertyName ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'Missing required parameters: title or property'
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

        try {
            // Create property directly using the property name as key
            $property = new DIProperty( $propertyName );
            
            // Check if property is valid
            if ( !$property->isUserDefined() && !$property->isShown() ) {
                return $this->getResponseFactory()->createJson( [
                    'error' => 'Invalid or system property'
                ], 400 );
            }
            
            // Get the semantic store
            $store = StoreFactory::getStore();
            $subject = DIWikiPage::newFromTitle( $title );
            
            // Get existing semantic data
            $existingData = $store->getSemanticData( $subject );
            
            // Check if the property exists on this page
            $propertyValues = $existingData->getPropertyValues( $property );
            if ( empty($propertyValues) ) {
                return $this->getResponseFactory()->createJson( [
                    'error' => 'Property not found on this page'
                ], 404 );
            }
            
            // Create new semantic data without the property to delete
            $newData = new SemanticData( $subject );
            
            // Copy all properties except the one we want to delete
            foreach ( $existingData->getProperties() as $existingProperty ) {
                if ( !$existingProperty->equals( $property ) ) {
                    $values = $existingData->getPropertyValues( $existingProperty );
                    foreach ( $values as $value ) {
                        $newData->addPropertyValue( $existingProperty, $value );
                    }
                }
            }
            
            // Update the store with the new data (without the deleted property)
            $store->updateData( $newData );

        } catch ( \Exception $e ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'SMW error: ' . $e->getMessage()
            ], 500 );
        }

        return $this->getResponseFactory()->createJson( [
            'result' => 'success',
            'title' => $title->getPrefixedText(),
            'property' => $propertyName,
            'message' => 'Property deleted successfully'
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
            ],
            'property' => [
                self::PARAM_SOURCE => 'path',
                'type' => 'string',
                'required' => true
            ]
        ];
    }
}