<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\SetCookieService;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Authentication\WorkosBackendUserAuthentication;
use WebConsulting\WorkosAuth\Authentication\WorkosFrontendUserAuthentication;

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
        $frontendUser = new WorkosFrontendUserAuthentication();
        $frontendUser->setLogger($this->logger);
        $frontendUser->signIn($userRow, $request);

        $cookie = SetCookieService::create(FrontendUserAuthentication::getCookieName(), 'FE')
            ->setSessionCookie($frontendUser->getSession(), $this->getNormalizedParams($request));

        return $this->appendCookie(new RedirectResponse($redirectUrl, 303), $cookie);
    }

    public function createFrontendLogoutResponse(ServerRequestInterface $request, string $redirectUrl): ResponseInterface
    {
        $frontendUser = new WorkosFrontendUserAuthentication();
        $frontendUser->setLogger($this->logger);
        $frontendUser->signOut($request);

        $cookie = SetCookieService::create(FrontendUserAuthentication::getCookieName(), 'FE')
            ->removeCookie($this->getNormalizedParams($request));

        return $this->appendCookie(new RedirectResponse($redirectUrl, 303), $cookie);
    }

    /**
     * @param array<string, mixed> $userRow
     */
    public function createBackendLoginResponse(ServerRequestInterface $request, array $userRow, string $redirectUrl): ResponseInterface
    {
        $backendUser = new WorkosBackendUserAuthentication();
        $backendUser->setLogger($this->logger);
        $backendUser->signIn($userRow, $request);

        $cookie = SetCookieService::create(BackendUserAuthentication::getCookieName(), 'BE')
            ->setSessionCookie($backendUser->getSession(), $this->getNormalizedParams($request));

        return $this->appendCookie(new RedirectResponse($redirectUrl, 303), $cookie);
    }

    private function getNormalizedParams(ServerRequestInterface $request): NormalizedParams
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams;
        }

        return NormalizedParams::createFromRequest($request);
    }

    private function appendCookie(ResponseInterface $response, ?Cookie $cookie): ResponseInterface
    {
        if ($cookie === null) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', $cookie->__toString());
    }
}
