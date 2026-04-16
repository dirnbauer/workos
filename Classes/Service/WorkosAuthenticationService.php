<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ServerRequestInterface;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\StateService;
use WorkOS\Resource\User;
use WorkOS\UserManagement;

final class WorkosAuthenticationService
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosClientFactory $workosClientFactory,
        private StateService $stateService,
    ) {}

    public function buildFrontendAuthorizationUrl(
        ServerRequestInterface $request,
        string $returnTo,
        string $screenHint = 'sign-in',
        ?string $provider = null,
        ?string $loginHint = null,
        ?string $organizationId = null,
    ): string {
        $callbackPath = PathUtility::getPathRelativeToSiteBase(
            PathUtility::joinBaseAndPath((string)$request->getAttribute('site')->getBase()->getPath(), $this->configuration->getFrontendCallbackPath()),
            '/'
        );

        return $this->buildAuthorizationUrl(
            callbackUrl: PathUtility::buildAbsoluteUrlFromRequest($request, $callbackPath),
            context: 'frontend',
            returnTo: $returnTo,
            screenHint: $screenHint,
            provider: $provider,
            loginHint: $loginHint,
            organizationId: $organizationId,
        );
    }

    public function buildBackendAuthorizationUrl(
        ServerRequestInterface $request,
        string $backendBasePath,
        string $returnTo,
        ?string $loginHint = null,
    ): string {
        return $this->buildAuthorizationUrl(
            callbackUrl: PathUtility::buildAbsoluteUrlFromRequest(
                $request,
                PathUtility::joinBaseAndPath($backendBasePath, $this->configuration->getBackendCallbackPath())
            ),
            context: 'backend',
            returnTo: $returnTo,
            loginHint: $loginHint,
        );
    }

    public function handleCallback(ServerRequestInterface $request, string $expectedContext): array
    {
        $queryParameters = $request->getQueryParams();
        $code = trim((string)($queryParameters['code'] ?? ''));
        if ($code === '') {
            throw new \RuntimeException('The WorkOS callback did not contain an authorization code.', 1744277801);
        }

        $stateToken = $this->stateService->extractTokenFromCallbackState((string)($queryParameters['state'] ?? ''));
        $payload = $this->stateService->consume($stateToken);
        if (($payload['context'] ?? '') !== $expectedContext) {
            throw new \RuntimeException('The WorkOS callback context did not match the login flow.', 1744277802);
        }

        $userManagement = $this->workosClientFactory->createUserManagement();
        $authenticationResponse = $userManagement->authenticateWithCode(
            $this->configuration->getClientId(),
            $code,
            $this->getRemoteAddress($request),
            trim($request->getHeaderLine('User-Agent')) ?: null,
        );

        if (!$authenticationResponse->user instanceof User) {
            throw new \RuntimeException('WorkOS did not return a valid user object.', 1744277803);
        }

        return [
            'workosUser' => $authenticationResponse->user,
            'returnTo' => (string)($payload['returnTo'] ?? '/'),
        ];
    }

    private function buildAuthorizationUrl(
        string $callbackUrl,
        string $context,
        string $returnTo,
        string $screenHint = 'sign-in',
        ?string $provider = null,
        ?string $loginHint = null,
        ?string $organizationId = null,
    ): string {
        $this->assertBaseConfiguration();

        $userManagement = $this->workosClientFactory->createUserManagement();
        $stateToken = $this->stateService->issue([
            'context' => $context,
            'returnTo' => $returnTo,
        ]);

        $effectiveProvider = $provider ?? UserManagement::AUTHORIZATION_PROVIDER_AUTHKIT;
        $effectiveOrgId = $organizationId ?: ($this->configuration->getAuthkitOrganizationId() ?: null);

        return $userManagement->getAuthorizationUrl(
            $callbackUrl,
            ['token' => $stateToken],
            $effectiveProvider,
            $this->configuration->getAuthkitConnectionId() ?: null,
            $effectiveOrgId,
            $this->configuration->getAuthkitDomainHint() ?: null,
            $loginHint,
            $provider === null ? $screenHint : null,
        );
    }

    public function authenticateWithPassword(ServerRequestInterface $request, string $email, string $password): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();
        $response = $userManagement->authenticateWithPassword(
            $this->configuration->getClientId(),
            $email,
            $password,
            $this->getRemoteAddress($request),
            trim($request->getHeaderLine('User-Agent')) ?: null,
        );

        if (!$response->user instanceof User) {
            throw new \RuntimeException('WorkOS did not return a valid user object.', 1744277810);
        }

        return ['workosUser' => $response->user];
    }

    public function sendMagicAuthCode(string $email): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();
        $magicAuth = $userManagement->createMagicAuth($email);

        return [
            'magicAuthId' => $magicAuth->id ?? '',
            'userId' => $magicAuth->userId ?? '',
            'email' => $email,
        ];
    }

    public function authenticateWithMagicAuth(ServerRequestInterface $request, string $code, string $userId): array
    {
        $this->assertBaseConfiguration();
        $userManagement = $this->workosClientFactory->createUserManagement();
        $response = $userManagement->authenticateWithMagicAuth(
            $this->configuration->getClientId(),
            $code,
            $userId,
            $this->getRemoteAddress($request),
            trim($request->getHeaderLine('User-Agent')) ?: null,
        );

        if (!$response->user instanceof User) {
            throw new \RuntimeException('WorkOS did not return a valid user object.', 1744277811);
        }

        return ['workosUser' => $response->user];
    }

    private function assertBaseConfiguration(): void
    {
        if ($this->configuration->getApiKey() === '' || $this->configuration->getClientId() === '') {
            throw new \RuntimeException('WorkOS API key and client ID must be configured before login can be used.', 1744277804);
        }

        if (mb_strlen($this->configuration->getCookiePassword()) < 32) {
            throw new \RuntimeException('WorkOS cookie password must be at least 32 characters long.', 1744277805);
        }
    }

    private function getRemoteAddress(ServerRequestInterface $request): ?string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (is_object($normalizedParams) && method_exists($normalizedParams, 'getRemoteAddress')) {
            $value = $normalizedParams->getRemoteAddress();
            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
