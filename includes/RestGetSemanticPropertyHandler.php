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
        // Get the title from the URL path parameter
        $pathParams = $this->getValidatedParams();
        $titleText = $pathParams['title'] ?? null;

        if (!$titleText) {
            return $this->getResponseFactory()->createJson([
                'error' => 'Missing required parameter: title'
            ], 400);
        }

        $title = Title::newFromText($titleText);
        if (!$title || !$title->exists()) {
            return $this->getResponseFactory()->createJson([
                'error' => 'Invalid or non-existing title'
            ], 400);
        }

        try {
            $store = StoreFactory::getStore();
            $subject = DIWikiPage::newFromTitle($title);
            
            // Get all semantic data for this page
            $semanticData = $store->getSemanticData($subject);
            $properties = $semanticData->getProperties();

            $result = [];
            foreach ($properties as $property) {
                $propertyKey = $property->getKey();
                $propertyLabel = $property->getLabel();
                $values = $semanticData->getPropertyValues($property);

                $propertyValues = [];
                foreach ($values as $val) {
                    // Get the appropriate representation of the value
                    if (method_exists($val, 'getSerialization')) {
                        $serialized = $val->getSerialization();
                        // Clean up SMW's internal serialization format (remove #0## suffix)
                        $cleaned = preg_replace('/#\d+##$/', '', $serialized);
                        $propertyValues[] = $cleaned;
                    } elseif (method_exists($val, 'getString')) {
                        $propertyValues[] = $val->getString();
                    } elseif (method_exists($val, 'getWikiValue')) {
                        $propertyValues[] = $val->getWikiValue();
                    } else {
                        $propertyValues[] = (string)$val;
                    }
                }

                if (!empty($propertyValues)) {
                    // Normalize property key - remove localized namespace prefixes
                    $normalizedKey = $propertyKey;
                    if (strpos($propertyKey, 'Attribut:') === 0) {
                        $normalizedKey = substr($propertyKey, 9); // Remove "Attribut:" prefix
                    }
                    if (strpos($normalizedKey, 'Property:') === 0) {
                        $normalizedKey = substr($normalizedKey, 9); // Remove "Property:" prefix
                    }
                    
                    $result[$normalizedKey] = [
                        'label' => $normalizedKey,
                        'values' => $propertyValues,
                        'count' => count($propertyValues)
                    ];
                }
            }

            return $this->getResponseFactory()->createJson([
                'title' => $title->getPrefixedText(),
                'properties' => $result,
                'total_properties' => count($result)
            ]);
        } catch (\Exception $e) {
            return $this->getResponseFactory()->createJson([
                'error' => 'SMW error: ' . $e->getMessage(),
                'title' => $title->getPrefixedText() ?? $titleText
            ], 500);
        }
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

    public function needsWriteAccess() {
        return false; // read only
    }
}
