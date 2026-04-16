<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\Typo3SessionService;
use WebConsulting\WorkosAuth\Service\UserProvisioningService;
use WebConsulting\WorkosAuth\Service\WorkosAuthenticationService;

final class LoginController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;
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
                ['key' => 'GoogleOAuth', 'label' => $this->translate('provider.google'), 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'GoogleOAuth']))],
                ['key' => 'MicrosoftOAuth', 'label' => $this->translate('provider.microsoft'), 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'MicrosoftOAuth']))],
                ['key' => 'GitHubOAuth', 'label' => $this->translate('provider.github'), 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'GitHubOAuth']))],
                ['key' => 'AppleOAuth', 'label' => $this->translate('provider.apple'), 'url' => PathUtility::appendQueryParameters($loginPath, array_merge($returnParam, ['provider' => 'AppleOAuth']))],
            ],
            'workosProfile' => $workosProfile,
            'authError' => $authError,
        ]);

        return $this->htmlResponse();
    }

    public function signUpAction(): ResponseInterface
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        $isLoggedIn = $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user ?? null);
        if ($isLoggedIn) {
            return $this->redirect('show');
        }

        $authError = null;
        $savedForm = [];
        if ($frontendUser instanceof FrontendUserAuthentication) {
            $authError = $frontendUser->getSessionData('workos_auth_error');
            if (is_string($authError) && $authError !== '') {
                $frontendUser->setAndSaveSessionData('workos_auth_error', null);
            } else {
                $authError = null;
            }

            $savedForm = $frontendUser->getSessionData('workos_signup_form');
            if (is_array($savedForm)) {
                $frontendUser->setAndSaveSessionData('workos_signup_form', null);
            } else {
                $savedForm = [];
            }
        }

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'authError' => $authError,
            'savedEmail' => (string)($savedForm['email'] ?? ''),
            'savedFirstName' => (string)($savedForm['firstName'] ?? ''),
            'savedLastName' => (string)($savedForm['lastName'] ?? ''),
        ]);

        return $this->htmlResponse();
    }

    public function signUpSubmitAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $passwordConfirm = (string)($body['passwordConfirm'] ?? '');
        $firstName = trim((string)($body['firstName'] ?? ''));
        $lastName = trim((string)($body['lastName'] ?? ''));

        $formData = ['email' => $email, 'firstName' => $firstName, 'lastName' => $lastName];

        if ($email === '' || $password === '') {
            return $this->redirectToSignUpWithError($this->translate('error.fillEmailAndPassword'), $formData);
        }

        if ($password !== $passwordConfirm) {
            return $this->redirectToSignUpWithError($this->translate('error.passwordsDoNotMatch'), $formData);
        }

        if (mb_strlen($password) < 10) {
            return $this->redirectToSignUpWithError($this->translate('error.passwordTooShortClient'), $formData);
        }

        try {
            $workosUser = $this->workosAuthenticationService->createUser($email, $password, $firstName, $lastName);
            $result = $this->workosAuthenticationService->authenticateWithPassword($this->request, $email, $password);
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            $returnTo = (string)($body['returnTo'] ?? '/');
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToSignUpWithError($this->sanitizeSignUpError($e->getMessage()), $formData);
        }
    }

    public function passwordAuthAction(): ResponseInterface
    {
        $email = trim((string)($this->request->getParsedBody()['email'] ?? ''));
        $password = (string)($this->request->getParsedBody()['password'] ?? '');
        $returnTo = (string)($this->request->getParsedBody()['returnTo'] ?? '/');

        if ($email === '' || $password === '') {
            return $this->redirectToShowWithError($this->translate('error.enterEmailAndPassword'));
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
            return $this->redirectToShowWithError($this->translate('error.enterEmail'));
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
            return $this->redirectToShowWithError($this->translate('error.magicAuthSessionExpired'));
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

    private function redirectToSignUpWithError(string $message, array $formData = []): ResponseInterface
    {
        $fe = $this->getFrontendUser();
        $fe->setAndSaveSessionData('workos_auth_error', $message);
        if ($formData !== []) {
            $fe->setAndSaveSessionData('workos_signup_form', $formData);
        }
        return $this->redirect('signUp');
    }

    private function getFrontendUser(): FrontendUserAuthentication
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            throw new \RuntimeException('No frontend user session available.', 1744277820);
        }
        return $frontendUser;
    }

    private function sanitizeSignUpError(string $message): string
    {
        $this->logger?->error('WorkOS sign-up error: ' . $message);

        $lower = strtolower($message);
        if (str_contains($lower, 'password_too_short') || str_contains($lower, 'too short')) {
            return $this->translate('error.passwordTooShort');
        }
        if (str_contains($lower, 'password_too_weak') || str_contains($lower, 'too weak') || str_contains($lower, 'unguessable')) {
            return $this->translate('error.passwordTooWeak');
        }
        if (str_contains($lower, 'pwned') || str_contains($lower, 'breached') || str_contains($lower, 'compromised')) {
            return $this->translate('error.passwordBreached');
        }
        if (str_contains($lower, 'already exists') || str_contains($lower, 'duplicate') || str_contains($lower, 'user_exists')) {
            return $this->translate('error.userAlreadyExists');
        }
        if (str_contains($lower, 'password') && str_contains($lower, 'invalid')) {
            return $this->translate('error.passwordInvalid');
        }

        return $message;
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $this->logger?->error('WorkOS auth error: ' . $message);

        $lower = strtolower($message);
        if (str_contains($lower, 'password') || str_contains($lower, 'credentials') || str_contains($lower, 'unauthorized')) {
            return $this->translate('error.invalidEmailOrPassword');
        }
        if (str_contains($lower, 'magic') && (str_contains($lower, 'not enabled') || str_contains($lower, 'disabled'))) {
            return $this->translate('error.magicAuthDisabled');
        }
        if (str_contains($lower, 'authentication_method_not_allowed') || str_contains($lower, 'method_not_allowed')) {
            return $this->translate('error.methodNotAllowed');
        }
        if (str_contains($lower, 'code') && (str_contains($lower, 'expired') || str_contains($lower, 'invalid'))) {
            return $this->translate('error.invalidOrExpiredCode');
        }
        if (str_contains($lower, 'user_not_found') || str_contains($lower, 'not found')) {
            return $this->translate('error.userNotFound');
        }

        return $message;
    }

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'WorkosAuth') ?? $key;
    }
}
