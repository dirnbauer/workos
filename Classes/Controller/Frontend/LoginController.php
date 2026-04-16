<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class LoginController extends ActionController
{
    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly IdentityService $identityService,
        private readonly WorkosAuthenticationService $workosAuthenticationService,
        private readonly UserProvisioningService $userProvisioningService,
        private readonly Typo3SessionService $typo3SessionService,
    ) {}

    public function showAction(): ResponseInterface
    {
        $site = $this->request->getAttribute('site');
        $siteBasePath = $site !== null ? (string)$site->getBase()->getPath() : '';
        $currentUrl = method_exists($this->request, 'getUri') ? (string)$this->request->getUri() : '';

        $frontendUser = $this->request->getAttribute('frontend.user');
        $isLoggedIn = $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user ?? null);
        $displayName = $isLoggedIn
            ? (string)($frontendUser->user['name'] ?? $frontendUser->user['username'] ?? $frontendUser->user['email'] ?? '')
            : '';

        $authError = null;
        if (!$isLoggedIn && $frontendUser instanceof FrontendUserAuthentication) {
            $authError = $frontendUser->getSessionData('workos_auth_error');
            if (is_string($authError) && $authError !== '') {
                $frontendUser->setAndSaveSessionData('workos_auth_error', null);
            } else {
                $authError = null;
            }
        }

        $workosProfile = null;
        if ($isLoggedIn) {
            $workosProfile = $this->identityService->findProfileByLocalUser(
                'frontend',
                'fe_users',
                (int)$frontendUser->user['uid']
            );
        }

        $loginPath = PathUtility::joinBaseAndPath($siteBasePath, $this->configuration->getFrontendLoginPath());
        $logoutPath = PathUtility::joinBaseAndPath($siteBasePath, $this->configuration->getFrontendLogoutPath());
        $returnParam = ['returnTo' => $currentUrl];

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'isLoggedIn' => $isLoggedIn,
            'displayName' => $displayName,
            'loginUrl' => PathUtility::appendQueryParameters($loginPath, $returnParam),
            'signUpUrl' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['screen' => 'sign-up'])),
            'logoutUrl' => PathUtility::appendQueryParameters($logoutPath, $returnParam),
            'socialProviders' => [
                ['key' => 'GoogleOAuth', 'label' => 'Google', 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'GoogleOAuth']))],
                ['key' => 'MicrosoftOAuth', 'label' => 'Microsoft', 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'MicrosoftOAuth']))],
                ['key' => 'GitHubOAuth', 'label' => 'GitHub', 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'GitHubOAuth']))],
                ['key' => 'AppleOAuth', 'label' => 'Apple', 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'AppleOAuth']))],
            ],
            'workosProfile' => $workosProfile,
            'authError' => $authError,
        ]);

        return $this->htmlResponse();
    }

    public function passwordAuthAction(): ResponseInterface
    {
        $email = trim((string)($this->request->getParsedBody()['email'] ?? ''));
        $password = (string)($this->request->getParsedBody()['password'] ?? '');
        $returnTo = (string)($this->request->getParsedBody()['returnTo'] ?? '/');

        if ($email === '' || $password === '') {
            return $this->redirectToShowWithError('Please enter both email and password.');
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithPassword($this->request, $email, $password);
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    public function magicAuthSendAction(): ResponseInterface
    {
        $email = trim((string)($this->request->getParsedBody()['email'] ?? ''));
        $returnTo = (string)($this->request->getParsedBody()['returnTo'] ?? '/');

        if ($email === '') {
            return $this->redirectToShowWithError('Please enter your email address.');
        }

        try {
            $magicAuth = $this->workosAuthenticationService->sendMagicAuthCode($email);
            $this->getFrontendUser()->setAndSaveSessionData('workos_magic_auth', [
                'userId' => $magicAuth['userId'],
                'email' => $email,
                'returnTo' => $returnTo,
            ]);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->sanitizeErrorMessage($e->getMessage()));
        }

        return $this->redirect('magicAuthCode');
    }

    public function magicAuthCodeAction(): ResponseInterface
    {
        $sessionData = $this->getFrontendUser()->getSessionData('workos_magic_auth');
        if (!is_array($sessionData) || empty($sessionData['email'])) {
            return $this->redirect('show');
        }

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'magicAuthEmail' => $sessionData['email'],
        ]);

        return $this->htmlResponse();
    }

    public function magicAuthVerifyAction(): ResponseInterface
    {
        $code = trim((string)($this->request->getParsedBody()['code'] ?? ''));
        $sessionData = $this->getFrontendUser()->getSessionData('workos_magic_auth');

        if (!is_array($sessionData) || empty($sessionData['userId'])) {
            return $this->redirectToShowWithError('Magic auth session expired. Please try again.');
        }

        if ($code === '') {
            return $this->redirect('magicAuthCode');
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithMagicAuth(
                $this->request,
                $code,
                $sessionData['userId']
            );
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            $returnTo = $sessionData['returnTo'] ?? '/';
            $this->getFrontendUser()->setAndSaveSessionData('workos_magic_auth', null);
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    private function redirectToShowWithError(string $message): ResponseInterface
    {
        $this->getFrontendUser()->setAndSaveSessionData('workos_auth_error', $message);
        return $this->redirect('show');
    }

    private function getFrontendUser(): FrontendUserAuthentication
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            throw new \RuntimeException('No frontend user session available.', 1744277820);
        }
        return $frontendUser;
    }

    private function sanitizeErrorMessage(string $message): string
    {
        if (str_contains($message, 'password') || str_contains($message, 'credentials') || str_contains($message, 'unauthorized')) {
            return 'Invalid email or password.';
        }
        if (str_contains($message, 'code')) {
            return 'Invalid or expired code. Please try again.';
        }
        return 'Authentication failed. Please try again.';
    }
}
