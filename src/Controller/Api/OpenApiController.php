<?php

namespace Cloudexus\Controller\Api;

use Cloudexus\Core\Config;

/**
 * docs/openapi.json (built by bin/openapi.php), with this installation's
 * address as its server: for Postman, Insomnia or a client generator to
 * read. No token needed — it says nothing web/API.md does not — and readable
 * from any page, so that an online editor can load it by its address.
 */
class OpenApiController
{
    public function show(): never
    {
        // Read as objects: an empty {} in it has to stay an object, not become [].
        $document = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.json'), false, 512, JSON_THROW_ON_ERROR);
        $document->servers = [['url' => rtrim((string) Config::get('app.base_url'), '/') . '/api']];

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
