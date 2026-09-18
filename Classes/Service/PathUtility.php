<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class PathUtility
{
    private function __construct() {}

    public static function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        return '/' . trim($trimmed, '/');
    }

    public static function joinBaseAndPath(string $basePath, string $path): string
    {
        $normalizedBase = rtrim(trim($basePath), '/');
        $normalizedPath = self::normalizePath($path);

        return $normalizedBase === '' ? $normalizedPath : $normalizedBase . $normalizedPath;
    }

    public static function joinBaseUrlAndPath(string $baseUrl, string $path): string
    {
        return rtrim(trim($baseUrl), '/') . self::normalizePath($path);
    }

    /**
     * @param array<string, scalar|null> $queryParameters
     */
    public static function appendQueryParameters(string $url, array $queryParameters): string
    {
        $filteredParameters = array_filter(
            $queryParameters,
            static fn(mixed $value): bool => $value !== null && $value !== ''
        );

        if ($filteredParameters === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($filteredParameters);
    }

    public static function getPathRelativeToSiteBase(string $requestPath, string $siteBasePath): string
    {
        $requestPath = self::normalizePath($requestPath);
        $siteBasePath = self::normalizePath($siteBasePath);

        if ($siteBasePath === '/') {
            return $requestPath;
        }

        if ($requestPath === $siteBasePath) {
            return '/';
        }

        if (str_starts_with($requestPath . '/', $siteBasePath . '/')) {
            return self::normalizePath(substr($requestPath, strlen($siteBasePath)));
        }

        return $requestPath;
    }

    /**
     * Backend entry point path (e.g. `/typo3`) guessed from a backend request path.
     */
    public static function guessBackendBasePath(string $requestPath): string
    {
        foreach (['/module/', '/login', '/main', '/logout'] as $marker) {
            $position = strpos($requestPath, $marker);
            if ($position !== false) {
                return rtrim(substr($requestPath, 0, $position), '/');
            }
        }

        return rtrim($requestPath, '/');
    }

    public static function guessBasePathFromMatchedPath(string $requestPath, string $configuredPath): string
    {
        $requestPath = self::normalizePath($requestPath);
        $configuredPath = self::normalizePath($configuredPath);

        if ($configuredPath !== '/' && str_ends_with($requestPath, $configuredPath)) {
            return rtrim(substr($requestPath, 0, -strlen($configuredPath)), '/');
        }

        return self::guessBackendBasePath($requestPath);
    }

    /**
     * `scheme://host[:port]` of the request, or '' when the request has no host.
     */
    public static function originFromRequest(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        return self::buildOrigin($scheme, $host, $uri->getPort());
    }

    /**
     * Normalize a user-supplied URL to its `scheme://host[:port]` origin; '' when invalid.
     */
    public static function normalizeOrigin(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            $parts = parse_url($value);
        } catch (\ValueError) {
            return '';
        }
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        return self::buildOrigin($scheme, $host, $parts['port'] ?? null);
    }

    public static function buildAbsoluteUrlFromRequest(ServerRequestInterface $request, string $path): string
    {
        $origin = self::originFromRequest($request);

        return $origin === '' ? self::normalizePath($path) : $origin . self::normalizePath($path);
    }

    /**
     * Absolute base URL of a site. Sites with a path-only base (e.g. `/camino/`)
     * have no host, so the request's origin is used to make the URL absolute.
     */
    public static function siteBaseUrl(Site $site, ServerRequestInterface $request): string
    {
        $siteBase = $site->getBase();
        if ($siteBase->getHost() !== '') {
            return rtrim((string)$siteBase, '/');
        }

        return rtrim(self::buildAbsoluteUrlFromRequest($request, $siteBase->getPath()), '/');
    }

    public static function sanitizeReturnTo(ServerRequestInterface $request, ?string $candidate, string $fallback): string
    {
        $fallback = trim($fallback) !== '' ? trim($fallback) : '/';
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return $fallback;
        }

        // Reject protocol-relative URLs (`//evil.com/path`) and their
        // backslash variants (`/\`, `\\`, `\/`). Browsers follow
        // `Location: //host/path` as `scheme://host/path`, so these
        // would be open redirects if treated as safe paths.
        if (self::startsWithTwoSlashVariant($candidate)) {
            return $fallback;
        }

        if (str_starts_with($candidate, '/')) {
            return $candidate;
        }

        $parsedCandidate = parse_url($candidate);
        $requestUri = $request->getUri();

        if (!is_array($parsedCandidate) || !isset($parsedCandidate['host'], $parsedCandidate['scheme'])) {
            return $fallback;
        }

        $sameHost = $parsedCandidate['host'] === $requestUri->getHost();
        $sameScheme = $parsedCandidate['scheme'] === $requestUri->getScheme();
        $candidatePort = $parsedCandidate['port'] ?? null;
        $samePort = $candidatePort === null || $candidatePort === $requestUri->getPort();

        return $sameHost && $sameScheme && $samePort ? $candidate : $fallback;
    }

    private static function buildOrigin(string $scheme, string $host, ?int $port): string
    {
        $origin = $scheme . '://' . $host;
        if ($port !== null && $port > 0 && !self::isDefaultPort($scheme, $port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private static function startsWithTwoSlashVariant(string $candidate): bool
    {
        if (strlen($candidate) < 2) {
            return false;
        }
        $slashlike = ['/', '\\'];
        return in_array($candidate[0], $slashlike, true) && in_array($candidate[1], $slashlike, true);
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
