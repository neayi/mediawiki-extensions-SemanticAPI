<?php

namespace MediaWiki\Extension\SemanticAPI;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

class RestSetSemanticPropertyHandler extends Handler {

    public function execute() {
        try {
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

            if (!$titleText || empty($properties)) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Missing required parameters: title or properties'
                ], 400);
            }

            // Validate all properties before making changes
            foreach ($properties as $index => $propData) {
                if (!isset($propData['property']) || !isset($propData['value'])) {
                    return $this->getResponseFactory()->createJson([
                        'error' => "Missing property or value in item $index"
                    ], 400);
                }
                
                $propertyName = $propData['property'];
                $valueText = $propData['value'];
                
                if (empty($propertyName) || $valueText === null || $valueText === '') {
                    return $this->getResponseFactory()->createJson([
                        'error' => "Empty property name or value in item $index"
                    ], 400);
                }
            }

            $title = Title::newFromText($titleText);
            if (!$title || !$title->exists()) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Invalid or non-existing title'
                ], 400);
            }

            $authority = $this->getAuthority();
            if (!$authority->isAllowed('edit')) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Permission denied'
                ], 403);
            }

            // Get the WikiPage object
            $wikiPage = new \WikiPage($title);
            
            // Retrieve the current page content
            $content = $wikiPage->getContent();
            if ($content) {
                $pageText = ContentHandler::getContentText($content);
            } else {
                $pageText = '';
            }

            // Update or add {{#set:}} statements for all properties
            foreach ($properties as $propData) {
                $propertyName = $propData['property'];
                $valueText = $propData['value'];
                
                // Escape pipe characters in values
                $escapedValue = str_replace('|', '{{!}}', $valueText);
                
                // Escape special regex characters in property name for pattern matching
                $escapedPropertyName = preg_quote($propertyName, '/');
                
                // Pattern to match existing {{#set:PropertyName=...}} statements
                $pattern = '/\{\{#set:\s*' . $escapedPropertyName . '\s*=\s*[^}]*\}\}/i';
                
                $newStatement = "{{#set:{$propertyName}={$escapedValue}}}";
                
                // Check if this property already has a {{#set:}} statement
                if (preg_match($pattern, $pageText)) {
                    // Replace existing statement
                    $pageText = preg_replace($pattern, $newStatement, $pageText);
                } else {
                    // Append new statement
                    $separator = "\n";
                    if (!empty($pageText) && !preg_match('/\n$/', $pageText)) {
                        $separator = "\n\n";
                    }
                    $pageText .= $separator . $newStatement;
                }
            }

            // Create new content object using ContentHandler
            $newContent = ContentHandler::makeContent($pageText, $title);

            // Save the page with the updated content using PageUpdater
            $updater = $wikiPage->newPageUpdater($authority);
            $updater->setContent(SlotRecord::MAIN, $newContent);
            $updater->saveRevision(
                \CommentStoreComment::newUnsavedComment('Updated properties via SemanticAPI'),
                EDIT_UPDATE
            );
            
            $status = $updater->getStatus();

            if (!$status->isOK()) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Failed to save page',
                    'details' => $status->getMessage()->text()
                ], 500);
            }

            // Return success response
            return $this->getResponseFactory()->createJson([
                'result' => 'success',
                'title' => $title->getPrefixedText(),
                'properties' => $properties,
                'count' => count($properties)
            ]);
        
        } catch (\Exception $e) {
            error_log("SemanticAPI Fatal Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->getResponseFactory()->createJson([
                'error' => 'Internal server error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    public function needsWriteAccess() {
        return true;
    }

    public function getParamSettings() {
        return [
            'title' => [
                self::PARAM_SOURCE => 'path',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => true
            ]
        ];
    }
}
