<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Small PSR-7 response helpers shared by the frontend and backend
 * authentication middlewares.
 */
final class ResponseUtility
{
    private function __construct() {}

    public static function withCookie(ResponseInterface $response, ?Cookie $cookie): ResponseInterface
    {
        if ($cookie === null) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', $cookie->__toString());
    }

    public static function htmlError(string $title, string $message, int $statusCode): ResponseInterface
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new HtmlResponse('<h1>' . $escape($title) . '</h1><p>' . $escape($message) . '</p>', $statusCode);
    }
}
