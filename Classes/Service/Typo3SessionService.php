<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;

final class Typo3SessionService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $userRow
     */
    public function createFrontendLoginResponse(ServerRequestInterface $request, array $userRow, string $redirectUrl): ResponseInterface
    {
        $frontendUser = $this->resolveFrontendUserAuthentication($request);
        $loginRequest = $this->createPendingLoginRequest($request, 'frontend', 'logintype', $userRow);
        $frontendUser->start($loginRequest);
        $authenticatedUser = $this->normalizeUserRow(is_array($frontendUser->user ?? null) ? $frontendUser->user : null);
        $this->assertAuthenticatedUser($authenticatedUser, $userRow, 'frontend');
        $frontendUser->fetchGroupData($loginRequest);

        return $frontendUser->appendCookieToResponse(
            new RedirectResponse($redirectUrl, 303),
            $this->getNormalizedParams($request)
        );
    }

    public function createFrontendLogoutResponse(ServerRequestInterface $request, string $redirectUrl): ResponseInterface
    {
        $frontendUser = $this->resolveFrontendUserAuthentication($request);
        $frontendUser->start($request);
        $frontendUser->logoff();

        return $frontendUser->appendCookieToResponse(
            new RedirectResponse($redirectUrl, 303),
            $this->getNormalizedParams($request)
        );
    }

    /**
     * @param array<string, mixed> $userRow
     */
    public function createBackendLoginResponse(
        ServerRequestInterface $request,
        array $userRow,
        string $redirectUrl,
        ?string $workosUserId = null,
    ): ResponseInterface {
        $backendUser = new BackendUserAuthentication();
        $backendUser->setLogger($this->logger);
        $GLOBALS['BE_USER'] = $backendUser;
        $loginRequest = $this->createPendingLoginRequest($request, 'backend', 'login_status', $userRow);
        $backendUser->start($loginRequest);
        $authenticatedUser = $this->normalizeUserRow(is_array($backendUser->user ?? null) ? $backendUser->user : null);
        $this->assertAuthenticatedUser($authenticatedUser, $userRow, 'backend');
        $backendUser->initializeBackendLogin($loginRequest);
        if (is_string($workosUserId) && $workosUserId !== '') {
            $backendUser->setAndSaveSessionData('workos_auth_user_id', $workosUserId);
        }

        return $backendUser->appendCookieToResponse(
            $this->buildBackendBounceResponse($redirectUrl),
            $this->getNormalizedParams($request)
        );
    }

    private function resolveFrontendUserAuthentication(ServerRequestInterface $request): FrontendUserAuthentication
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication) {
            $frontendUser->setLogger($this->logger);
            return $frontendUser;
        }

        $frontendUser = new FrontendUserAuthentication();
        $frontendUser->setLogger($this->logger);
        return $frontendUser;
    }

    private function buildBackendBounceResponse(string $redirectUrl): ResponseInterface
    {
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Break the external WorkOS redirect chain so TYPO3's default
        // SameSite=Strict backend cookie is sent on the final navigation.
        $html = '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0;url=' . $escapedUrl . '">'
            . '<title>Signing in...</title>'
            . '</head>'
            . '<body>'
            . '<p><a id="workos-continue" href="' . $escapedUrl . '">Continue to the TYPO3 backend</a></p>'
            . '<script>document.getElementById("workos-continue").click();</script>'
            . '</body>'
            . '</html>';

        return new HtmlResponse($html);
    }

    private function getNormalizedParams(ServerRequestInterface $request): NormalizedParams
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams;
        }

        return NormalizedParams::createFromRequest($request);
    }

    /**
     * @param array<string, mixed> $userRow
     */
    private function createPendingLoginRequest(
        ServerRequestInterface $request,
        string $context,
        string $statusField,
        array $userRow,
    ): ServerRequestInterface {
        $parsedBody = $request->getParsedBody();
        $body = is_array($parsedBody) ? $parsedBody : [];
        $body[$statusField] = 'login';

        return $request
            ->withParsedBody($body)
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => $context,
                'user' => $userRow,
            ]);
    }

    /**
     * @param array<string, mixed>|null $authenticatedUser
     * @param array<string, mixed> $expectedUser
     */
    private function assertAuthenticatedUser(?array $authenticatedUser, array $expectedUser, string $context): void
    {
        $authenticatedUid = $this->intFromMixed($authenticatedUser['uid'] ?? null);
        $expectedUid = $this->intFromMixed($expectedUser['uid'] ?? null);

        if ($authenticatedUid > 0 && $authenticatedUid === $expectedUid) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'The TYPO3 %s authentication service did not authenticate the expected user.',
            $context
        ), 1745329201);
    }

    private function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param array<mixed, mixed>|null $userRow
     * @return array<string, mixed>|null
     */
    private function normalizeUserRow(?array $userRow): ?array
    {
        if ($userRow === null) {
            return null;
        }

        $narrowed = [];
        foreach ($userRow as $key => $value) {
            $narrowed[(string)$key] = $value;
        }

        return $narrowed;
    }
}
