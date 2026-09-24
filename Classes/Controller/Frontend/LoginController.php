<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Domain\AuthenticatedSession;
use Webconsulting\WorkosAuth\Domain\LoginContext;
use Webconsulting\WorkosAuth\Domain\SocialProvider;
use Webconsulting\WorkosAuth\Exception\EmailVerificationRequiredException;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Security\WorkosErrorMessageResolver;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\PathUtility;
use Webconsulting\WorkosAuth\Service\RequestBody;
use Webconsulting\WorkosAuth\Service\Typo3SessionService;
use Webconsulting\WorkosAuth\Service\UserProvisioningService;
use Webconsulting\WorkosAuth\Service\WorkosAuthenticationService;

/**
 * "WorkOS Login" plugin: native password / magic-auth / sign-up forms and
 * the email-verification step, plus the signed-in profile card.
 *
 * Multi-step state (pending magic auth or email verification, one-shot error
 * messages, sign-up form values) lives in the frontend session.
 */
#[Autoconfigure(public: true)]
final class LoginController extends AbstractFrontendController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const string REQUEST_TOKEN_SCOPE = 'workos/frontend/login';

    private const string SESSION_ERROR = 'workos_auth_error';
    private const string SESSION_NOTICE = 'workos_auth_notice';
    private const string SESSION_SIGNUP_FORM = 'workos_signup_form';
    private const string SESSION_MAGIC_AUTH = 'workos_magic_auth';
    private const string SESSION_EMAIL_VERIFICATION = 'workos_email_verification';

    public function __construct(
        WorkosConfiguration $configuration,
        IdentityService $identityService,
        RequestTokenService $requestTokenService,
        private readonly WorkosAuthenticationService $workosAuthenticationService,
        private readonly UserProvisioningService $userProvisioningService,
        private readonly Typo3SessionService $typo3SessionService,
        private readonly WorkosErrorMessageResolver $errorMessageResolver,
    ) {
        parent::__construct($configuration, $identityService, $requestTokenService);
    }

    public function showAction(): ResponseInterface
    {
        $isLoggedIn = $this->isFrontendUserLoggedIn();
        $returnToUrl = $this->sanitizeReturnTo($this->requestedReturnTo(), PathUtility::currentPageReturnTarget($this->request));

        $workosProfile = $isLoggedIn
            ? $this->identityService->findProfileByLocalUser(LoginContext::Frontend, MixedCaster::int($this->getFrontendUser()->user['uid'] ?? null))
            : null;

        $site = $this->request->getAttribute('site');
        $siteBasePath = $site instanceof Site ? $site->getBase()->getPath() : '';
        $loginPath = PathUtility::joinBaseAndPath($siteBasePath, $this->configuration->getFrontendLoginPath());
        $logoutPath = PathUtility::joinBaseAndPath($siteBasePath, $this->configuration->getFrontendLogoutPath());
        $returnParam = ['returnTo' => $returnToUrl];

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'isLoggedIn' => $isLoggedIn,
            'displayName' => $isLoggedIn ? $this->resolveDisplayName() : '',
            'loginUrl' => PathUtility::appendQueryParameters($loginPath, $returnParam),
            'signUpUrl' => PathUtility::appendQueryParameters($loginPath, $returnParam + ['screen' => 'sign-up']),
            'logoutUrl' => PathUtility::appendQueryParameters($logoutPath, $returnParam),
            'socialProviders' => array_map(fn(SocialProvider $provider): array => [
                'key' => $provider->value,
                'label' => $this->translate($provider->labelKey()),
                'url' => PathUtility::appendQueryParameters($loginPath, $returnParam + ['provider' => $provider->value]),
            ], SocialProvider::cases()),
            'workosProfile' => $workosProfile,
            'avatarUrl' => $this->resolveAvatarUrl($workosProfile),
            'authError' => $isLoggedIn ? null : $this->consumeSessionString(self::SESSION_ERROR),
            'returnToUrl' => $returnToUrl,
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function signUpAction(): ResponseInterface
    {
        if ($this->isFrontendUserLoggedIn()) {
            return $this->redirect('show');
        }

        $savedForm = $this->consumeSessionArray(self::SESSION_SIGNUP_FORM) ?? [];
        $savedReturnTo = MixedCaster::string($savedForm['returnTo'] ?? null);

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'authError' => $this->consumeSessionString(self::SESSION_ERROR),
            'savedEmail' => MixedCaster::string($savedForm['email'] ?? null),
            'savedFirstName' => MixedCaster::string($savedForm['firstName'] ?? null),
            'savedLastName' => MixedCaster::string($savedForm['lastName'] ?? null),
            'returnToUrl' => $this->sanitizeReturnTo(
                $savedReturnTo !== '' ? $savedReturnTo : $this->requestedReturnTo(),
                PathUtility::currentPageReturnTarget($this->request)
            ),
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function signUpSubmitAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $email = $body->trimmedString('email');
        $password = $body->string('password');
        $formData = [
            'email' => $email,
            'firstName' => $body->trimmedString('firstName'),
            'lastName' => $body->trimmedString('lastName'),
            'returnTo' => $this->sanitizeReturnTo($this->requestedReturnTo(), $this->configuration->getFrontendSuccessRedirect()),
        ];

        $validationError = match (true) {
            !$this->hasValidRequestToken() => 'error.csrfTokenInvalid',
            $email === '' || $password === '' => 'error.fillEmailAndPassword',
            $password !== $body->string('passwordConfirm') => 'error.passwordsDoNotMatch',
            mb_strlen($password) < 10 => 'error.passwordTooShortClient',
            default => null,
        };
        if ($validationError !== null) {
            return $this->redirectToSignUpWithError($this->translate($validationError), $formData);
        }

        try {
            $this->workosAuthenticationService->createUser($email, $password, $formData['firstName'], $formData['lastName']);
            $session = $this->workosAuthenticationService->authenticateWithPassword($this->request, $email, $password);

            return $this->createLoginResponse($session, $formData['returnTo']);
        } catch (EmailVerificationRequiredException $e) {
            return $this->startEmailVerificationFlow($e, $formData['returnTo']);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS sign-up error: ' . SecretRedactor::redact($e->getMessage()));

            return $this->redirectToSignUpWithError($this->translate($this->errorMessageResolver->resolveSignUp($e->getMessage())), $formData);
        }
    }

    public function passwordAuthAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $email = $body->trimmedString('email');
        $password = $body->string('password');
        $returnTo = $this->sanitizeReturnTo($this->requestedReturnTo(), $this->configuration->getFrontendSuccessRedirect());

        if (!$this->hasValidRequestToken()) {
            return $this->redirectToShowWithError($this->translate('error.csrfTokenInvalid'));
        }
        if ($email === '' || $password === '') {
            return $this->redirectToShowWithError($this->translate('error.enterEmailAndPassword'));
        }

        try {
            $session = $this->workosAuthenticationService->authenticateWithPassword($this->request, $email, $password);

            return $this->createLoginResponse($session, $returnTo);
        } catch (EmailVerificationRequiredException $e) {
            return $this->startEmailVerificationFlow($e, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->resolveAuthenticationError($e));
        }
    }

    public function magicAuthSendAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $email = $body->trimmedString('email');
        $returnTo = $this->sanitizeReturnTo($this->requestedReturnTo(), $this->configuration->getFrontendSuccessRedirect());

        if (!$this->hasValidRequestToken()) {
            return $this->redirectToShowWithError($this->translate('error.csrfTokenInvalid'));
        }
        if ($email === '') {
            return $this->redirectToShowWithError($this->translate('error.enterEmail'));
        }

        try {
            $this->workosAuthenticationService->sendMagicAuthCode($email);
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_MAGIC_AUTH, ['email' => $email, 'returnTo' => $returnTo]);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->resolveAuthenticationError($e));
        }

        return $this->redirect('magicAuthCode');
    }

    public function magicAuthCodeAction(): ResponseInterface
    {
        $email = MixedCaster::string($this->getFrontendUser()->getSessionData(self::SESSION_MAGIC_AUTH)['email'] ?? null);
        if ($email === '') {
            return $this->redirect('show');
        }

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'magicAuthEmail' => $email,
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function magicAuthVerifyAction(): ResponseInterface
    {
        $sessionData = MixedCaster::stringKeyedArray($this->getFrontendUser()->getSessionData(self::SESSION_MAGIC_AUTH)) ?? [];
        $email = MixedCaster::string($sessionData['email'] ?? null);
        if ($email === '') {
            return $this->redirectToShowWithError($this->translate('error.magicAuthSessionExpired'));
        }
        if (!$this->hasValidRequestToken()) {
            return $this->redirectToShowWithError($this->translate('error.csrfTokenInvalid'));
        }

        $code = RequestBody::fromRequest($this->request)->trimmedString('code');
        if ($code === '') {
            return $this->redirect('magicAuthCode');
        }

        $returnTo = MixedCaster::string($sessionData['returnTo'] ?? null, '/');
        try {
            $session = $this->workosAuthenticationService->authenticateWithMagicAuth($this->request, $code, $email);
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_MAGIC_AUTH, null);

            return $this->createLoginResponse($session, $returnTo);
        } catch (EmailVerificationRequiredException $e) {
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_MAGIC_AUTH, null);

            return $this->startEmailVerificationFlow($e, $returnTo);
        } catch (\Throwable $e) {
            return $this->redirectToShowWithError($this->resolveAuthenticationError($e));
        }
    }

    public function verifyEmailAction(): ResponseInterface
    {
        $sessionData = $this->getPendingEmailVerification();
        if ($sessionData === null) {
            return $this->redirect('show');
        }

        $this->view->assignMultiple([
            'configured' => $this->configuration->isFrontendReady(),
            'verifyEmail' => MixedCaster::string($sessionData['email'] ?? null),
            'canResend' => MixedCaster::string($sessionData['userId'] ?? null) !== '',
            'authError' => $this->consumeSessionString(self::SESSION_ERROR),
            'notice' => $this->consumeSessionString(self::SESSION_NOTICE),
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function verifyEmailSubmitAction(): ResponseInterface
    {
        $sessionData = $this->getPendingEmailVerification();
        if ($sessionData === null) {
            return $this->redirectToShowWithError($this->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidRequestToken()) {
            return $this->redirectToVerifyEmailWithError($this->translate('error.csrfTokenInvalid'));
        }

        $code = RequestBody::fromRequest($this->request)->trimmedString('code');
        if ($code === '') {
            return $this->redirect('verifyEmail');
        }

        try {
            $session = $this->workosAuthenticationService->authenticateWithEmailVerification(
                $this->request,
                $code,
                MixedCaster::string($sessionData['pendingToken'])
            );
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_EMAIL_VERIFICATION, null);

            return $this->createLoginResponse($session, MixedCaster::string($sessionData['returnTo'] ?? null, '/'));
        } catch (\Throwable $e) {
            return $this->redirectToVerifyEmailWithError($this->resolveAuthenticationError($e));
        }
    }

    public function verifyEmailResendAction(): ResponseInterface
    {
        $userId = MixedCaster::string($this->getPendingEmailVerification()['userId'] ?? null);
        if ($userId === '') {
            return $this->redirectToShowWithError($this->translate('error.verificationSessionExpired'));
        }
        if (!$this->hasValidRequestToken()) {
            return $this->redirectToVerifyEmailWithError($this->translate('error.csrfTokenInvalid'));
        }

        try {
            $this->workosAuthenticationService->resendEmailVerification($userId);
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_NOTICE, $this->translate('message.verificationCodeResent'));
        } catch (\Throwable $e) {
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_ERROR, $this->resolveAuthenticationError($e));
        }

        return $this->redirect('verifyEmail');
    }

    /**
     * Prefer the WorkOS profile picture, otherwise a Gravatar identicon for
     * the WorkOS or local email; null when no email is known.
     *
     * @param array<string, mixed>|null $workosProfile
     */
    private function resolveAvatarUrl(?array $workosProfile): ?string
    {
        $picture = MixedCaster::string($workosProfile['profilePictureUrl'] ?? null);
        if ($picture !== '') {
            return $picture;
        }

        $email = MixedCaster::string($workosProfile['email'] ?? null);
        if ($email === '' && $this->isFrontendUserLoggedIn()) {
            $email = MixedCaster::string($this->getFrontendUser()->user['email'] ?? null);
        }
        $email = strtolower(trim($email));

        return $email === '' ? null : 'https://www.gravatar.com/avatar/' . hash('sha256', $email) . '?d=identicon&s=128';
    }

    private function createLoginResponse(AuthenticatedSession $session, string $returnTo): ResponseInterface
    {
        return $this->typo3SessionService->createFrontendLoginResponse(
            $this->request,
            $this->userProvisioningService->resolve(LoginContext::Frontend, $session->user),
            $returnTo !== '' ? $returnTo : '/',
            $session->sessionId,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingEmailVerification(): ?array
    {
        $sessionData = MixedCaster::stringKeyedArray($this->getFrontendUser()->getSessionData(self::SESSION_EMAIL_VERIFICATION));

        return $sessionData !== null && MixedCaster::string($sessionData['pendingToken'] ?? null) !== '' ? $sessionData : null;
    }

    private function startEmailVerificationFlow(EmailVerificationRequiredException $exception, string $returnTo): ResponseInterface
    {
        $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_EMAIL_VERIFICATION, [
            'pendingToken' => $exception->pendingAuthenticationToken,
            'email' => $exception->email,
            'userId' => $exception->userId,
            'returnTo' => $returnTo !== '' ? $returnTo : '/',
        ]);

        return $this->redirect('verifyEmail');
    }

    private function redirectToShowWithError(string $message): ResponseInterface
    {
        $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_ERROR, $message);

        return $this->redirect('show');
    }

    private function redirectToVerifyEmailWithError(string $message): ResponseInterface
    {
        $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_ERROR, $message);

        return $this->redirect('verifyEmail');
    }

    /**
     * @param array<string, string> $formData
     */
    private function redirectToSignUpWithError(string $message, array $formData): ResponseInterface
    {
        $frontendUser = $this->getFrontendUser();
        $frontendUser->setAndSaveSessionData(self::SESSION_ERROR, $message);
        $frontendUser->setAndSaveSessionData(self::SESSION_SIGNUP_FORM, $formData);

        return $this->redirect('signUp', null, null, $formData['returnTo'] !== '' ? ['returnTo' => $formData['returnTo']] : []);
    }

    private function sanitizeReturnTo(string $candidate, string $fallback): string
    {
        return PathUtility::sanitizeReturnTo($this->request, $candidate, $fallback);
    }

    /**
     * The return target the visitor asked for: the plugin argument (the
     * sign-in / sign-up links and the hidden form fields carry it as
     * `tx_workosauth_login[returnTo]`), else a plain `returnTo` form field or
     * query parameter (links into the login page from elsewhere).
     */
    private function requestedReturnTo(): string
    {
        if ($this->request->hasArgument('returnTo')) {
            $argument = trim(MixedCaster::string($this->request->getArgument('returnTo')));
            if ($argument !== '') {
                return $argument;
            }
        }

        $posted = RequestBody::fromRequest($this->request)->trimmedString('returnTo');

        return $posted !== '' ? $posted : trim(MixedCaster::string($this->request->getQueryParams()['returnTo'] ?? null));
    }

    private function resolveAuthenticationError(\Throwable $exception): string
    {
        $this->logger?->error('WorkOS auth error: ' . SecretRedactor::redact($exception->getMessage()));

        return $this->translate($this->errorMessageResolver->resolveLogin($exception));
    }
}
