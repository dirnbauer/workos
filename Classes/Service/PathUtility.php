<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class PathUtility
{
    /**
     * Longest return target the extension accepts or embeds in a link. A
     * longer one falls back to the default target, so no crafted URL can
     * turn the sign-in links into `414 URI Too Long` pages.
     */
    public const int MAX_RETURN_TO_LENGTH = 2048;

    /**
     * Query parameters a return target never carries: an earlier return
     * target (embedding it again nests each URL inside the next, so every
     * sign-in / sign-up toggle added a level), one-shot CSRF and state
     * tokens of the login flows, and the login hint (an email address).
     */
    private const array TRANSIENT_QUERY_PARAMETERS = [
        'returnTo',
        '__RequestToken',
        'workosMessage',
        'magicAuthState',
        'emailVerificationState',
        'login_hint',
    ];

    /**
     * Argument namespace of the WorkOS plugins (`tx_workosauth_login[...]`):
     * their action toggles and form values never belong in a return target.
     */
    private const string PLUGIN_ARGUMENT_PREFIX = 'tx_workosauth_';

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

    /**
     * A requested return target, validated and in canonical form (see
     * {@see canonicalReturnTarget()}), or the fallback when the candidate is
     * empty, too long or not a path / URL of the requested host.
     *
     * A same-origin absolute URL comes back as its path: the target is only
     * ever followed on this host, and a path keeps the links short.
     */
    public static function sanitizeReturnTo(ServerRequestInterface $request, ?string $candidate, string $fallback): string
    {
        $fallback = trim($fallback) !== '' ? trim($fallback) : '/';
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return $fallback;
        }

        // Browsers drop tabs and line breaks while parsing a URL and treat a
        // backslash like a slash, so `/<TAB>/evil.com` or `/\evil.com` become
        // `//evil.com`. A legitimate target never contains either, raw.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $candidate) === 1) {
            return $fallback;
        }

        // Reject protocol-relative URLs (`//evil.com/path`). Browsers follow
        // `Location: //host/path` as `scheme://host/path`, so these
        // would be open redirects if treated as safe paths.
        if (self::startsWithTwoSlashVariant($candidate)) {
            return $fallback;
        }

        if (str_starts_with($candidate, '/')) {
            return self::boundedReturnTarget(self::canonicalReturnTarget($candidate), $fallback);
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
        if (!$sameHost || !$sameScheme || !$samePort) {
            return $fallback;
        }

        $target = ($parsedCandidate['path'] ?? '') !== '' ? $parsedCandidate['path'] : '/';
        if (isset($parsedCandidate['query'])) {
            $target .= '?' . $parsedCandidate['query'];
        }
        if (isset($parsedCandidate['fragment'])) {
            $target .= '#' . $parsedCandidate['fragment'];
        }

        return self::boundedReturnTarget(self::canonicalReturnTarget($target), $fallback);
    }

    /**
     * Canonical form of a same-site return target (`/path?query#fragment`).
     *
     * The query loses every argument of the WorkOS plugins, any nested
     * `returnTo` and the one-shot tokens of the login flows, so a target
     * built from a page that was itself reached through a return target is
     * no longer than one built from the plain page: toggling between sign-in
     * and sign-up any number of times yields the same link. The `cHash` of a
     * query that lost arguments no longer matches (TYPO3 would answer 404),
     * so such a query is dropped as a whole and the target is the page.
     *
     * Returns '' for anything that is not a single-slash absolute path.
     */
    public static function canonicalReturnTarget(string $target): string
    {
        $fragment = '';
        $fragmentPosition = strpos($target, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($target, $fragmentPosition);
            $target = substr($target, 0, $fragmentPosition);
        }
        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');
        if (!str_starts_with($path, '/') || self::startsWithTwoSlashVariant($path)) {
            return '';
        }

        $kept = [];
        $removedArguments = false;
        $cacheHashPair = null;
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = self::queryParameterName($pair);
            if ($name === 'cHash') {
                $cacheHashPair = $pair;
            } elseif (in_array($name, self::TRANSIENT_QUERY_PARAMETERS, true) || str_starts_with($name, self::PLUGIN_ARGUMENT_PREFIX)) {
                $removedArguments = true;
            } else {
                $kept[] = $pair;
            }
        }

        if ($cacheHashPair !== null) {
            if ($removedArguments) {
                $kept = [];
            } elseif ($kept !== []) {
                $kept[] = $cacheHashPair;
            }
        }

        return $path . ($kept !== [] ? '?' . implode('&', $kept) : '') . $fragment;
    }

    /**
     * The page of the current request as a return target: where the WorkOS
     * plugins send the visitor back when no target was requested. Plugin
     * arguments and an earlier return target of the current URL are dropped
     * (see {@see canonicalReturnTarget()}); a query that would still exceed
     * the length limit is dropped too.
     */
    public static function currentPageReturnTarget(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';
        $query = $uri->getQuery();

        $target = self::canonicalReturnTarget($query !== '' ? $path . '?' . $query : $path);
        if ($target === '' || strlen($target) > self::MAX_RETURN_TO_LENGTH) {
            $target = self::canonicalReturnTarget($path);
        }

        return self::boundedReturnTarget($target, '/');
    }

    /**
     * Backend return target that opens a backend route through the entry
     * point (`/typo3/main?redirect=<route>`), the way TYPO3 continues after a
     * login. It carries no module token of the session being replaced.
     *
     * @param string $routeParameters query string of the route (`redirectParams`)
     */
    public static function backendRouteReturnTarget(string $backendBasePath, string $routeIdentifier, string $routeParameters = ''): string
    {
        $query = ['redirect' => $routeIdentifier];
        if ($routeParameters !== '') {
            $query['redirectParams'] = $routeParameters;
        }

        return self::joinBaseAndPath($backendBasePath, '/main') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function boundedReturnTarget(string $target, string $fallback): string
    {
        return $target !== '' && strlen($target) <= self::MAX_RETURN_TO_LENGTH ? $target : $fallback;
    }

    /**
     * Top-level name of a raw `name=value` query pair as PHP sees it:
     * `tx_workosauth_login%5Baction%5D=show` is `tx_workosauth_login`. PHP
     * turns dots and spaces of that name into underscores, so the filter
     * does the same.
     */
    private static function queryParameterName(string $pair): string
    {
        $name = urldecode(explode('=', $pair, 2)[0]);
        $bracket = strpos($name, '[');
        if ($bracket !== false) {
            $name = substr($name, 0, $bracket);
        }

        return strtr(ltrim($name, ' '), ['.' => '_', ' ' => '_']);
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
