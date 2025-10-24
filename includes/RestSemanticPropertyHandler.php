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

class RestSemanticPropertyHandler extends Handler {

    public function execute() {
        $request = $this->getRequest();

        $titleText = $request->getMethod() === 'POST' ?
            $request->getPostString('title') :
            $request->getQueryString('title');

        $propertyName = $request->getMethod() === 'POST' ?
            $request->getPostString('property') :
            $request->getQueryString('property');

        if (!$titleText || !$propertyName) {
            return $this->getResponseFactory()->createJson([
                'error' => 'Missing required parameters: title or property'
            ], 400);
        }

        $title = Title::newFromText($titleText);
        if (!$title || !$title->exists()) {
            return $this->getResponseFactory()->createJson([
                'error' => 'Invalid or non-existing title'
            ], 400);
        }

        $store = StoreFactory::getStore();
        $subject = DIWikiPage::newFromTitle($title);
        $data = new SemanticData($subject);
        $property = new DIProperty($propertyName);

        if ($request->getMethod() === 'POST') {
            $user = $this->getAuthority()->getUser();
            if (!$user->isAllowed('edit')) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Permission denied'
                ], 403);
            }

            $valueText = $request->getPostString('value');
            if (!$valueText) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Missing value parameter'
                ], 400);
            }

            $value = DataValueFactory::getInstance()->newDataValueByText($propertyName, $valueText);
            if (!$value) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Invalid value format'
                ], 400);
            }

            $data->addPropertyValue($property, $value);
            $store->updateData($data);

            return $this->getResponseFactory()->createJson([
                'result' => 'success',
                'title' => $title->getPrefixedText(),
                'property' => $propertyName,
                'value' => $valueText
            ]);
        } else {
            // GET → lecture
            $values = $data->getProperties()->getValues($property);
            $result = [];
            foreach ($values as $val) {
                $result[] = $val->getSortkey();
            }

            return $this->getResponseFactory()->createJson([
                'title' => $title->getPrefixedText(),
                'property' => $propertyName,
                'values' => $result
            ]);
        }
    }

    public function needsWriteAccess() {
        return $this->getRequest()->getMethod() === 'POST';
    }
}
