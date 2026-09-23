<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use Webconsulting\WorkosAuth\Authentication\WorkosTypo3AuthenticationService;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Security\MixedCaster;

/**
 * Hands an already-resolved local user row to TYPO3's own FE/BE
 * authentication lifecycle. The request gets a server-side "pending login"
 * attribute that {@see WorkosTypo3AuthenticationService} consumes, so TYPO3
 * core creates the session (with session fixation protection, login logging
 * and backend MFA evaluation intact) instead of this extension.
 */
final readonly class Typo3SessionService
{
    /**
     * Backend session key holding the WorkOS user id of the signed-in user.
     */
    public const string SESSION_WORKOS_USER_ID = 'workos_auth_user_id';

    /**
     * FE and BE session key holding the WorkOS session the sign-in opened;
     * {@see \Webconsulting\WorkosAuth\EventListener\EndWorkosSessionOnLogout}
     * ends it together with the TYPO3 session.
     */
    public const string SESSION_WORKOS_SESSION_ID = 'workos_auth_session_id';

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $userRow
     */
    public function createFrontendLoginResponse(
        ServerRequestInterface $request,
        array $userRow,
        string $redirectUrl,
        ?string $workosSessionId = null,
    ): ResponseInterface {
        $frontendUser = $this->resolveFrontendUserAuthentication($request);
        $loginRequest = $this->createPendingLoginRequest($request, LoginContext::Frontend, $userRow);
        $frontendUser->start($loginRequest);
        $this->assertAuthenticatedUser($frontendUser, $userRow, LoginContext::Frontend);
        $frontendUser->fetchGroupData($loginRequest);
        if ($workosSessionId !== null && $workosSessionId !== '') {
            $frontendUser->setAndSaveSessionData(self::SESSION_WORKOS_SESSION_ID, $workosSessionId);
        }

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
        string $workosUserId,
        ?string $workosSessionId = null,
    ): ResponseInterface {
        $backendUser = new BackendUserAuthentication();
        $backendUser->setLogger($this->logger);
        $GLOBALS['BE_USER'] = $backendUser;
        $loginRequest = $this->createPendingLoginRequest($request, LoginContext::Backend, $userRow);
        $backendUser->start($loginRequest);
        $this->assertAuthenticatedUser($backendUser, $userRow, LoginContext::Backend);
        $backendUser->initializeBackendLogin($loginRequest);
        if ($workosUserId !== '') {
            $backendUser->setAndSaveSessionData(self::SESSION_WORKOS_USER_ID, $workosUserId);
        }
        if ($workosSessionId !== null && $workosSessionId !== '') {
            $backendUser->setAndSaveSessionData(self::SESSION_WORKOS_SESSION_ID, $workosSessionId);
        }

        return $backendUser->appendCookieToResponse(
            $this->buildBackendBounceResponse($redirectUrl),
            $this->getNormalizedParams($request)
        );
    }

    /**
     * Reuse the request-bound frontend user so the final session write stays
     * inside TYPO3's FrontendUserAuthenticator lifecycle (an anonymous
     * pending-code session cleared in the same request must not overwrite
     * the freshly authenticated one).
     */
    private function resolveFrontendUserAuthentication(ServerRequestInterface $request): FrontendUserAuthentication
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            $frontendUser = new FrontendUserAuthentication();
        }
        $frontendUser->setLogger($this->logger);

        return $frontendUser;
    }

    /**
     * Break the external WorkOS redirect chain with a same-origin page so the
     * default SameSite=Strict backend cookie is sent on the final navigation.
     * The meta refresh does the navigation; an inline script would only be
     * blocked by the backend's Content Security Policy.
     */
    private function buildBackendBounceResponse(string $redirectUrl): ResponseInterface
    {
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new HtmlResponse(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0;url=' . $escapedUrl . '">'
            . '<title>Signing in…</title></head><body>'
            . '<p><a href="' . $escapedUrl . '">Continue to the TYPO3 backend</a></p>'
            . '</body></html>'
        );
    }

    private function getNormalizedParams(ServerRequestInterface $request): NormalizedParams
    {
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams
            ? $normalizedParams
            : NormalizedParams::createFromRequest($request);
    }

    /**
     * @param array<string, mixed> $userRow
     */
    private function createPendingLoginRequest(ServerRequestInterface $request, LoginContext $context, array $userRow): ServerRequestInterface
    {
        $body = MixedCaster::stringKeyedArray($request->getParsedBody()) ?? [];
        $body[$context->loginStatusField()] = 'login';

        return $request
            ->withParsedBody($body)
            ->withAttribute(WorkosTypo3AuthenticationService::PENDING_LOGIN_ATTRIBUTE, [
                'context' => $context->value,
                'user' => $userRow,
            ]);
    }

    /**
     * @param array<string, mixed> $expectedUser
     */
    private function assertAuthenticatedUser(AbstractUserAuthentication $authentication, array $expectedUser, LoginContext $context): void
    {
        $authenticatedUid = MixedCaster::int($authentication->user['uid'] ?? null);
        $expectedUid = MixedCaster::int($expectedUser['uid'] ?? null);

        if ($authenticatedUid <= 0 || $authenticatedUid !== $expectedUid) {
            throw new \RuntimeException(sprintf(
                'The TYPO3 %s authentication service did not authenticate the expected user.',
                $context->value
            ), 1745329201);
        }
    }
}
