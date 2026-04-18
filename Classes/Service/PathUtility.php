<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;

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

        if ($normalizedBase === '') {
            return $normalizedPath;
        }

        return $normalizedBase . $normalizedPath;
    }

    public static function joinBaseUrlAndPath(string $baseUrl, string $path): string
    {
        return rtrim(trim($baseUrl), '/') . self::normalizePath($path);
    }

    /**
     * @param array<string, mixed> $queryParameters
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
            $relativePath = substr($requestPath, strlen($siteBasePath));
            return self::normalizePath($relativePath);
        }

        return $requestPath;
    }

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

    public static function buildAbsoluteUrlFromRequest(ServerRequestInterface $request, string $path): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return self::normalizePath($path);
        }

        $port = $uri->getPort();
        $authority = $host;
        if ($port !== null && !self::isDefaultPort($uri->getScheme(), $port)) {
            $authority .= ':' . $port;
        }

        return $uri->getScheme() . '://' . $authority . self::normalizePath($path);
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
        // would be open-redirects if we treated them as safe paths.
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

    private static function startsWithTwoSlashVariant(string $candidate): bool
    {
        if (strlen($candidate) < 2) {
            return false;
        }
        $first = $candidate[0];
        $second = $candidate[1];
        $slashlike = ['/', '\\'];
        return in_array($first, $slashlike, true) && in_array($second, $slashlike, true);
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
