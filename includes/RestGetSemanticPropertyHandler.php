<?php

namespace MediaWiki\Extension\SemanticAPI;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Title\Title;
use SMW\StoreFactory;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\SemanticData;

class RestGetSemanticPropertyHandler extends Handler {

    public function execute() {
        $request = $this->getRequest();

        $titleText = $request->getQueryString('title');
        $propertyName = $request->getQueryString('property');

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

        $property = new DIProperty($propertyName);
        $store = StoreFactory::getStore();
        $subject = DIWikiPage::newFromTitle($title);
        $data = new SemanticData($subject);

        $values = $data->getProperties()->getValues($property);

        $result = [];
        foreach ($values as $val) {
            $result[] = $val->getSortkey(); // valeur brute
        }

        return $this->getResponseFactory()->createJson([
            'title' => $title->getPrefixedText(),
            'property' => $propertyName,
            'values' => $result
        ]);
    }

    public function needsWriteAccess() {
        return false; // lecture uniquement
    }
}
