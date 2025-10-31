<?php

namespace MediaWiki\Extension\SemanticAPI;

use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Title\Title;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Content\ContentHandler;
use MediaWiki\CommentStore\CommentStoreComment;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

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
            // Get the WikiPage object
            $wikiPage = new \WikiPage($title);
            
            // Retrieve the current page content
            $content = $wikiPage->getContent();
            if (!$content) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Page has no content'
                ], 400);
            }
            
            $pageText = ContentHandler::getContentText($content);
            
            // Escape special regex characters in property name for pattern matching
            $escapedPropertyName = preg_quote($propertyName, '/');
            
            // Pattern to match {{#set:PropertyName=...}} statements
            $pattern = '/\{\{#set:\s*' . $escapedPropertyName . '\s*=\s*[^}]*\}\}\s*\n?/i';
            
            // Check if the property exists in the page
            if (!preg_match($pattern, $pageText)) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Property not found on this page'
                ], 404);
            }
            
            // Remove the {{#set:}} statement for this property
            $newPageText = preg_replace($pattern, '', $pageText);
            
            // Clean up multiple consecutive newlines
            $newPageText = preg_replace('/\n{3,}/', "\n\n", $newPageText);
            
            // Create new content object
            $newContent = ContentHandler::makeContent($newPageText, $title);

            // Save the page with the updated content using PageUpdater
            $updater = $wikiPage->newPageUpdater($authority);
            $updater->setContent(SlotRecord::MAIN, $newContent);
            $updater->saveRevision(
                CommentStoreComment::newUnsavedComment('Deleted property ' . $propertyName . ' via SemanticAPI'),
                EDIT_UPDATE
            );
            
            $status = $updater->getStatus();

            if (!$status->isOK()) {
                return $this->getResponseFactory()->createJson([
                    'error' => 'Failed to save page',
                    'details' => $status->getMessage()->text()
                ], 500);
            }

        } catch ( \Exception $e ) {
            error_log("SemanticAPI Delete Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->getResponseFactory()->createJson( [
                'error' => 'Internal server error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => true
            ],
            'property' => [
                self::PARAM_SOURCE => 'path',
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => true
            ]
        ];
    }
}