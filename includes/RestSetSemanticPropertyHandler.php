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

        $titleText = $request->getPostString( 'title' );
        $propertyName = $request->getPostString( 'property' );
        $valueText = $request->getPostString( 'value' );

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

        $user = $this->getAuthority()->getUser();
        if ( !$user->isAllowed( 'edit' ) ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'Permission denied'
            ], 403 );
        }

        $property = new DIProperty( $propertyName );
        $value = DataValueFactory::getInstance()->newDataValueByText( $propertyName, $valueText );

        if ( !$value ) {
            return $this->getResponseFactory()->createJson( [
                'error' => 'Invalid value format'
            ], 400 );
        }

        $store = StoreFactory::getStore();
        $subject = DIWikiPage::newFromTitle( $title );
        $data = new SemanticData( $subject );
        $data->addPropertyValue( $property, $value );

        $store->updateData( $data );

        return $this->getResponseFactory()->createJson( [
            'result' => 'success',
            'title' => $title->getPrefixedText(),
            'property' => $propertyName,
            'value' => $valueText
        ] );
    }

    public function needsWriteAccess() {
        return true;
    }
}
