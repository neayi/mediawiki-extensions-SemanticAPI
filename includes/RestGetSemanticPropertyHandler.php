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
                $values = $semanticData->getPropertyValues($property);

                if (empty($values)) {
                    continue;
                }

                // Get property type information
                $typeId = $property->findPropertyTypeID();
                $typeName = $this->getReadableTypeName($typeId);

                $propertyValues = [];
                foreach ($values as $val) {
                    $formattedValue = $this->formatValueByType($val, $typeId);
                    $propertyValues[] = $formattedValue;
                }

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
                    'type' => $typeName,
                    'type_id' => $typeId,
                    'values' => $propertyValues,
                    'count' => count($propertyValues)
                ];
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

    /**
     * Convert SMW type ID to human-readable type name
     */
    private function getReadableTypeName($typeId) {
        $typeMapping = [
            '_wpg' => 'page',           // Page/Wiki page
            '_txt' => 'text',           // Text
            '_cod' => 'code',           // Code (monospace text)
            '_str' => 'string',         // String (short text)
            '_num' => 'number',         // Number
            '_qty' => 'quantity',       // Quantity (number with unit)
            '_dat' => 'date',           // Date
            '_boo' => 'boolean',        // Boolean
            '_uri' => 'url',            // URL
            '_ema' => 'email',          // Email
            '_tel' => 'telephone',      // Telephone
            '_geo' => 'geographic',     // Geographic coordinates
            '_tem' => 'temperature',    // Temperature
            '_rec' => 'record',         // Record (compound property)
            '_ref_rec' => 'reference',  // Reference to record
            '_keyw' => 'keyword',       // Keyword
            '_mlt_rec' => 'monolingual_text', // Monolingual text
        ];

        return $typeMapping[$typeId] ?? 'unknown';
    }

    /**
     * Format value according to its semantic type
     */
    private function formatValueByType($dataItem, $typeId) {
        try {
            switch ($typeId) {
                case '_wpg': // Page
                    return $this->formatPageValue($dataItem);
                
                case '_num': // Number
                case '_qty': // Quantity
                    return $this->formatNumberValue($dataItem);
                
                case '_dat': // Date
                    return $this->formatDateValue($dataItem);
                
                case '_boo': // Boolean
                    return $this->formatBooleanValue($dataItem);
                
                case '_uri': // URL
                    return $this->formatUrlValue($dataItem);
                
                case '_ema': // Email
                    return $this->formatEmailValue($dataItem);
                
                case '_geo': // Geographic coordinates
                    return $this->formatGeographicValue($dataItem);
                
                case '_tem': // Temperature
                    return $this->formatTemperatureValue($dataItem);
                
                case '_rec': // Record
                    return $this->formatRecordValue($dataItem);
                
                case '_mlt_rec': // Monolingual text
                    return $this->formatMonolingualTextValue($dataItem);
                
                case '_txt': // Text
                case '_cod': // Code
                case '_str': // String
                case '_keyw': // Keyword
                default:
                    return $this->formatTextValue($dataItem);
            }
        } catch (\Exception $e) {
            // Fallback to basic serialization if specialized formatting fails
            return $this->formatTextValue($dataItem);
        }
    }

    private function formatPageValue($dataItem) {
        if (method_exists($dataItem, 'getTitle')) {
            $title = $dataItem->getTitle();
            return [
                'title' => $title->getPrefixedText(),
                'namespace' => $title->getNamespace(),
                'display_title' => $title->getText(),
                'url' => $title->getFullURL(),
                'exists' => $title->exists()
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatNumberValue($dataItem) {
        if (method_exists($dataItem, 'getNumber')) {
            $number = $dataItem->getNumber();
            $result = [$number];
            
            // Check for units in quantities
            if (method_exists($dataItem, 'getUnit')) {
                $unit = $dataItem->getUnit();
                if (!empty($unit)) {
                    $result['unit'] = $unit;
                    $result['formatted'] = $number . ' ' . $unit;
                }
            }
            
            return $result;
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatDateValue($dataItem) {
        if (method_exists($dataItem, 'getMwTimestamp')) {
            $timestamp = $dataItem->getMwTimestamp();
            $unixTime = strtotime($timestamp);
            
            return [
                'timestamp' => $timestamp,
                'iso' => $unixTime ? date('c', $unixTime) : null,
                'formatted' => $unixTime ? date('Y-m-d H:i:s', $unixTime) : $timestamp,
                'year' => $unixTime ? (int)date('Y', $unixTime) : null,
                'precision' => method_exists($dataItem, 'getPrecision') ? $dataItem->getPrecision() : null,
                'display' => $this->getStringValue($dataItem)
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatBooleanValue($dataItem) {
        if (method_exists($dataItem, 'getBoolean')) {
            return [
                'value' => $dataItem->getBoolean(),
                'display' => $dataItem->getBoolean() ? 'Yes' : 'No'
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatUrlValue($dataItem) {
        if (method_exists($dataItem, 'getURI')) {
            $uri = $dataItem->getURI();
            return [
                'url' => $uri,
                'display' => $uri,
                'domain' => parse_url($uri, PHP_URL_HOST)
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatEmailValue($dataItem) {
        $email = $this->getStringValue($dataItem);
        return [
            'email' => $email,
            'display' => $email,
            'domain' => strpos($email, '@') ? substr($email, strpos($email, '@') + 1) : null
        ];
    }

    private function formatGeographicValue($dataItem) {
        if (method_exists($dataItem, 'getCoordinateSet')) {
            $coords = $dataItem->getCoordinateSet();
            return [
                'latitude' => $coords['lat'] ?? null,
                'longitude' => $coords['lon'] ?? null,
                'altitude' => $coords['alt'] ?? null,
                'display' => $this->getStringValue($dataItem)
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatTemperatureValue($dataItem) {
        if (method_exists($dataItem, 'getNumber') && method_exists($dataItem, 'getUnit')) {
            $value = $dataItem->getNumber();
            $unit = $dataItem->getUnit();
            return [
                'value' => $value,
                'unit' => $unit,
                'celsius' => $unit === '°F' ? ($value - 32) * 5/9 : $value,
                'fahrenheit' => $unit === '°C' ? $value * 9/5 + 32 : $value,
                'formatted' => $value . '°' . str_replace('°', '', $unit)
            ];
        }
        return $this->formatNumberValue($dataItem);
    }

    private function formatRecordValue($dataItem) {
        if (method_exists($dataItem, 'getSemanticData')) {
            $semanticData = $dataItem->getSemanticData();
            $fields = [];
            foreach ($semanticData->getProperties() as $property) {
                $values = $semanticData->getPropertyValues($property);
                $fields[$property->getKey()] = array_map([$this, 'formatTextValue'], $values);
            }
            return [
                'fields' => $fields,
                'display' => $this->getStringValue($dataItem)
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatMonolingualTextValue($dataItem) {
        if (method_exists($dataItem, 'getText') && method_exists($dataItem, 'getLanguageCode')) {
            return [
                'text' => $dataItem->getText(),
                'language' => $dataItem->getLanguageCode(),
                'display' => $dataItem->getText()
            ];
        }
        return $this->formatTextValue($dataItem);
    }

    private function formatTextValue($dataItem) {
        return $this->getStringValue($dataItem);
    }

    private function getStringValue($dataItem) {
        if (method_exists($dataItem, 'getSerialization')) {
            $serialized = $dataItem->getSerialization();
            // Clean up SMW's internal serialization format (remove #0## suffix)
            return preg_replace('/#\d+##$/', '', $serialized);
        } elseif (method_exists($dataItem, 'getString')) {
            return $dataItem->getString();
        } elseif (method_exists($dataItem, 'getWikiValue')) {
            return $dataItem->getWikiValue();
        } else {
            return (string)$dataItem;
        }
    }
}
