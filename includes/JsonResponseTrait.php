<?php

namespace MediaWiki\Extension\SemanticAPI;

use MediaWiki\Rest\Response;

trait JsonResponseTrait {

    /**
     * ResponseFactory::createJson() takes a content type as second argument,
     * not a status code, so the status has to be set on the response itself.
     */
    private function createJsonResponse( array $data, int $status = 200 ): Response {
        $response = $this->getResponseFactory()->createJson( $data );
        $response->setStatus( $status );
        return $response;
    }
}
