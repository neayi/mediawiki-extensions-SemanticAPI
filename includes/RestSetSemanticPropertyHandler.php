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

    use JsonResponseTrait;

    public function execute() {
        try {
            $request = $this->getRequest();
            
            // Get the title from the URL path parameter
            $pathParams = $this->getValidatedParams();
            $titleText = $pathParams['title'] ?? null;
        
            // Get properties from request body - support both single and multiple properties
            $properties = [];
            
            // Only JSON bodies are accepted (the REST framework rejects form data with a 415)
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


            if (!$titleText || empty($properties)) {
                return $this->createJsonResponse([
                    'error' => 'Missing required parameters: title or properties'
                ], 400);
            }

            // Validate all properties before making changes
            foreach ($properties as $index => $propData) {
                if (!isset($propData['property']) || !isset($propData['value'])) {
                    return $this->createJsonResponse([
                        'error' => "Missing property or value in item $index"
                    ], 400);
                }
                
                $propertyName = $propData['property'];
                $valueText = $propData['value'];
                
                if (empty($propertyName) || $valueText === null || $valueText === '') {
                    return $this->createJsonResponse([
                        'error' => "Empty property name or value in item $index"
                    ], 400);
                }
            }

            $title = Title::newFromText($titleText);
            if (!$title || !$title->exists()) {
                return $this->createJsonResponse([
                    'error' => 'Invalid or non-existing title'
                ], 400);
            }

            $authority = $this->getAuthority();
            if (!$authority->isAllowed('edit')) {
                return $this->createJsonResponse([
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
                $pageText = SetStatementEditor::setProperty($pageText, (string)$propData['property'], (string)$propData['value']);
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
                return $this->createJsonResponse([
                    'error' => 'Failed to save page',
                    'details' => $status->getMessage()->text()
                ], 500);
            }

            // Return success response
            return $this->createJsonResponse([
                'result' => 'success',
                'title' => $title->getPrefixedText(),
                'properties' => $properties,
                'count' => count($properties)
            ]);
        
        } catch (\Throwable $e) {
            error_log("SemanticAPI Fatal Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->createJsonResponse([
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
