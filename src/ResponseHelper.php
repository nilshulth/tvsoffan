<?php

namespace App;

use Psr\Http\Message\ResponseInterface as Response;

class ResponseHelper
{
    public static function jsonError(Response $response, string $message, int $statusCode = 500): Response
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
    }

    public static function jsonSuccess(Response $response, array $data = [], int $statusCode = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
    }

    public static function unauthorized(Response $response): Response
    {
        return self::jsonError($response, 'Unauthorized', 401);
    }

    public static function forbidden(Response $response): Response
    {
        return self::jsonError($response, 'Access denied', 403);
    }

    public static function serverError(Response $response): Response
    {
        return self::jsonError($response, 'Server error', 500);
    }
}