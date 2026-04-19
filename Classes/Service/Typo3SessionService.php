<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
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

        // TYPO3's BE session cookie defaults to SameSite=Strict. A WorkOS
        // flow routes the browser through workos.com / provider.com, so a
        // direct 303 back to /typo3/main would be treated by Chrome as a
        // continuation of a cross-site top-level navigation chain, and the
        // just-set Strict cookie would not be sent on that follow-up GET.
        // Instead we render a tiny same-origin HTML page that scripts a
        // click on its own anchor, which starts a fresh same-site
        // navigation where Strict cookies are included as expected.
        return $this->appendCookie($this->buildBackendBounceResponse($redirectUrl), $cookie);
    }

    private function buildBackendBounceResponse(string $redirectUrl): ResponseInterface
    {
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta http-equiv="refresh" content="0;url=' . $escapedUrl . '">'
            . '<title>Signing in…</title>'
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

    private function appendCookie(ResponseInterface $response, ?Cookie $cookie): ResponseInterface
    {
        if ($cookie === null) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', $cookie->__toString());
    }
}
