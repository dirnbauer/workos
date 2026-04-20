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
use WebConsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use WebConsulting\WorkosAuth\Security\SecretRedactor;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\PathUtility;
use WebConsulting\WorkosAuth\Service\RequestBody;
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
        $siteBasePath = $site instanceof \TYPO3\CMS\Core\Site\Entity\Site ? $site->getBase()->getPath() : '';
        $currentUrl = (string)$this->request->getUri();

        $frontendUser = $this->request->getAttribute('frontend.user');
        $isLoggedIn = $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user ?? null);
        $displayName = '';
        if ($isLoggedIn && $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user)) {
            foreach (['name', 'username', 'email'] as $candidate) {
                if (isset($frontendUser->user[$candidate]) && is_string($frontendUser->user[$candidate]) && $frontendUser->user[$candidate] !== '') {
                    $displayName = $frontendUser->user[$candidate];
                    break;
                }
            }
        }

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
        if ($isLoggedIn && $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user)) {
            $userUid = $frontendUser->user['uid'] ?? null;
            if (is_int($userUid) || (is_string($userUid) && ctype_digit($userUid))) {
                $workosProfile = $this->identityService->findProfileByLocalUser(
                    'frontend',
                    'fe_users',
                    (int)$userUid
                );
            }
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
            'savedEmail' => $this->stringFromMixed($savedForm['email'] ?? null),
            'savedFirstName' => $this->stringFromMixed($savedForm['firstName'] ?? null),
            'savedLastName' => $this->stringFromMixed($savedForm['lastName'] ?? null),
        ]);

        return $this->htmlResponse();
    }

    public function signUpSubmitAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $email = $body->trimmedString('email');
        $password = $body->string('password');
        $passwordConfirm = $body->string('passwordConfirm');
        $firstName = $body->trimmedString('firstName');
        $lastName = $body->trimmedString('lastName');

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

        $returnTo = $body->string('returnTo', '/');
        try {
            $this->workosAuthenticationService->createUser($email, $password, $firstName, $lastName);
            $result = $this->workosAuthenticationService->authenticateWithPassword($this->request, $email, $password);
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo);
        } catch (EmailVerificationRequiredException $e) {
            return $this->startEmailVerificationFlow($e, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToSignUpWithError($this->sanitizeSignUpError($e->getMessage()), $formData);
        }
    }

    public function passwordAuthAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $email = $body->trimmedString('email');
        $password = $body->string('password');
        $returnTo = $body->string('returnTo', '/');

        if ($email === '' || $password === '') {
            return $this->redirectToShowWithError($this->translate('error.enterEmailAndPassword'));
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithPassword($this->request, $email, $password);
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo);
        } catch (EmailVerificationRequiredException $e) {
            return $this->startEmailVerificationFlow($e, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    public function magicAuthSendAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $email = $body->trimmedString('email');
        $returnTo = $body->string('returnTo', '/');

        if ($email === '') {
            return $this->redirectToShowWithError($this->translate('error.enterEmail'));
        }

        try {
            $magicAuth = $this->workosAuthenticationService->sendMagicAuthCode($email);
            $this->getFrontendUser()->setAndSaveSessionData('workos_magic_auth', [
                'userId' => $magicAuth['email'],
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
        if (!is_array($sessionData) || !isset($sessionData['email']) || $sessionData['email'] === '') {
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
        $code = RequestBody::fromRequest($this->request)->trimmedString('code');
        $sessionData = $this->getFrontendUser()->getSessionData('workos_magic_auth');

        if (!is_array($sessionData) || !isset($sessionData['userId']) || $sessionData['userId'] === '') {
            return $this->redirectToShowWithError($this->translate('error.magicAuthSessionExpired'));
        }

        if ($code === '') {
            return $this->redirect('magicAuthCode');
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithMagicAuth(
                $this->request,
                $code,
                $this->stringFromMixed($sessionData['userId'])
            );
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            $returnTo = $this->stringFromMixed($sessionData['returnTo'] ?? '/');
            $this->getFrontendUser()->setAndSaveSessionData('workos_magic_auth', null);
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo === '' ? '/' : $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->sanitizeErrorMessage($e->getMessage()));
        }
    }

    public function verifyEmailAction(): ResponseInterface
    {
        $sessionData = $this->getFrontendUser()->getSessionData('workos_email_verification');
        if (!is_array($sessionData) || !isset($sessionData['pendingToken']) || $sessionData['pendingToken'] === '') {
            return $this->redirect('show');
        }

        $authError = $this->getFrontendUser()->getSessionData('workos_auth_error');
        if (is_string($authError) && $authError !== '') {
            $this->getFrontendUser()->setAndSaveSessionData('workos_auth_error', null);
        } else {
            $authError = null;
        }

        $resendNotice = $this->getFrontendUser()->getSessionData('workos_auth_notice');
        if (is_string($resendNotice) && $resendNotice !== '') {
            $this->getFrontendUser()->setAndSaveSessionData('workos_auth_notice', null);
        } else {
            $resendNotice = null;
        }

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'verifyEmail' => $this->stringFromMixed($sessionData['email'] ?? null),
            'canResend' => $this->stringFromMixed($sessionData['userId'] ?? null) !== '',
            'authError' => $authError,
            'notice' => $resendNotice,
        ]);

        return $this->htmlResponse();
    }

    public function verifyEmailSubmitAction(): ResponseInterface
    {
        $code = RequestBody::fromRequest($this->request)->trimmedString('code');
        $sessionData = $this->getFrontendUser()->getSessionData('workos_email_verification');

        if (!is_array($sessionData) || !isset($sessionData['pendingToken']) || $sessionData['pendingToken'] === '') {
            return $this->redirectToShowWithError($this->translate('error.verificationSessionExpired'));
        }

        if ($code === '') {
            return $this->redirect('verifyEmail');
        }

        try {
            $result = $this->workosAuthenticationService->authenticateWithEmailVerification(
                $this->request,
                $code,
                $this->stringFromMixed($sessionData['pendingToken'])
            );
            $frontendUser = $this->userProvisioningService->resolveFrontendUser($result['workosUser']);
            $returnTo = $this->stringFromMixed($sessionData['returnTo'] ?? '/');
            $this->getFrontendUser()->setAndSaveSessionData('workos_email_verification', null);
            return $this->typo3SessionService->createFrontendLoginResponse($this->request, $frontendUser, $returnTo === '' ? '/' : $returnTo);
        } catch (\Throwable $e) {
            $this->getFrontendUser()->setAndSaveSessionData(
                'workos_auth_error',
                $this->sanitizeErrorMessage($e->getMessage())
            );
            return $this->redirect('verifyEmail');
        }
    }

    public function verifyEmailResendAction(): ResponseInterface
    {
        $sessionData = $this->getFrontendUser()->getSessionData('workos_email_verification');
        if (!is_array($sessionData) || !isset($sessionData['userId']) || $sessionData['userId'] === '') {
            return $this->redirectToShowWithError($this->translate('error.verificationSessionExpired'));
        }

        try {
            $this->workosAuthenticationService->resendEmailVerification($this->stringFromMixed($sessionData['userId']));
            $this->getFrontendUser()->setAndSaveSessionData(
                'workos_auth_notice',
                $this->translate('message.verificationCodeResent')
            );
        } catch (\Throwable $e) {
            $this->getFrontendUser()->setAndSaveSessionData(
                'workos_auth_error',
                $this->sanitizeErrorMessage($e->getMessage())
            );
        }

        return $this->redirect('verifyEmail');
    }

    private function startEmailVerificationFlow(EmailVerificationRequiredException $exception, string $returnTo): ResponseInterface
    {
        $this->getFrontendUser()->setAndSaveSessionData('workos_email_verification', [
            'pendingToken' => $exception->pendingAuthenticationToken,
            'email' => $exception->email,
            'userId' => $exception->userId,
            'returnTo' => $returnTo !== '' ? $returnTo : '/',
        ]);
        return $this->redirect('verifyEmail');
    }

    private function redirectToShowWithError(string $message): ResponseInterface
    {
        $this->getFrontendUser()->setAndSaveSessionData('workos_auth_error', $message);
        return $this->redirect('show');
    }

    /**
     * @param array<string, string> $formData
     */
    private function redirectToSignUpWithError(string $message, array $formData = []): ResponseInterface
    {
        $fe = $this->getFrontendUser();
        $fe->setAndSaveSessionData('workos_auth_error', $message);
        if ($formData !== []) {
            $fe->setAndSaveSessionData('workos_signup_form', $formData);
        }
        return $this->redirect('signUp');
    }

    private function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
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
        $this->logger?->error('WorkOS sign-up error: ' . SecretRedactor::redact($message));

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

        return $this->translate('error.generic');
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $this->logger?->error('WorkOS auth error: ' . SecretRedactor::redact($message));

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

        return $this->translate('error.generic');
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate($key, 'WorkosAuth', $arguments !== [] ? $arguments : null) ?? $key;
    }
}
