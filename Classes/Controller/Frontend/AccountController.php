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
use WebConsulting\WorkosAuth\Security\FrontendCsrfService;
use WebConsulting\WorkosAuth\Security\MixedCaster;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\RequestBody;
use WebConsulting\WorkosAuth\Service\WorkosAccountService;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationMembership;
use WorkOS\Resource\Session;
use WebConsulting\WorkosAuth\Security\SecretRedactor;

/**
 * "WorkOS Account Center" plugin: lets a signed-in frontend user manage
 * their WorkOS profile, password, MFA factors, sessions and
 * organization memberships without leaving the TYPO3 site.
 */
final class AccountController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const CSRF_SCOPE = 'account';

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly IdentityService $identityService,
        private readonly WorkosAccountService $accountService,
        private readonly FrontendCsrfService $csrfService,
    ) {}

    public function dashboardAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $workosUserId = $context['workosUserId'];
        $workosUser = null;
        $factors = [];
        $sessions = [];
        $memberships = [];
        $errors = [];

        try {
            $workosUser = $this->accountService->getUser($workosUserId);
        } catch (\Throwable $e) {
            $this->logger?->warning('WorkOS account: getUser failed: ' . SecretRedactor::redact($e->getMessage()));
            $errors['profile'] = $this->translate('account.error.loadProfile');
        }

        try {
            $factors = $this->accountService->listTotpFactors($workosUserId);
        } catch (\Throwable $e) {
            $this->logger?->warning('WorkOS account: listAuthFactors failed: ' . SecretRedactor::redact($e->getMessage()));
            $errors['mfa'] = $this->translate('account.error.loadFactors');
        }

        try {
            $sessions = $this->accountService->listSessions($workosUserId, 25);
        } catch (\Throwable $e) {
            $this->logger?->warning('WorkOS account: listSessions failed: ' . SecretRedactor::redact($e->getMessage()));
            $errors['sessions'] = $this->translate('account.error.loadSessions');
        }

        try {
            $memberships = $this->accountService->listOrganizationMemberships($workosUserId);
        } catch (\Throwable $e) {
            $this->logger?->warning('WorkOS account: listOrganizationMemberships failed: ' . SecretRedactor::redact($e->getMessage()));
            $errors['organizations'] = $this->translate('account.error.loadOrganizations');
        }

        $flash = $this->consumeFlash();
        $pendingEnrollment = $this->getPendingEnrollment();

        $this->view->assignMultiple([
            'workosUser' => $workosUser,
            'factors' => $factors,
            'sessions' => array_map(fn ($s) => $this->prepareSessionRow($s), $sessions),
            'memberships' => array_map(fn ($m) => $this->prepareMembershipRow($m), $memberships),
            'pendingEnrollment' => $pendingEnrollment,
            'flash' => $flash,
            'sectionErrors' => $errors,
            'csrfToken' => $this->csrfService->issue($this->getFrontendUser(), self::CSRF_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function updateProfileAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }
        $firstName = $body->trimmedString('firstName');
        $lastName = $body->trimmedString('lastName');

        try {
            $this->accountService->updateProfile(
                $context['workosUserId'],
                $firstName !== '' ? $firstName : null,
                $lastName !== '' ? $lastName : null,
            );
            $this->setFlash('success', $this->translate('account.flash.profileUpdated'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: updateProfile failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.profileFailed'));
        }

        return $this->redirect('dashboard');
    }

    public function changePasswordAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }
        $newPassword = $body->string('password');
        $confirmPassword = $body->string('passwordConfirm');

        if ($newPassword === '' || $confirmPassword === '') {
            $this->setFlash('danger', $this->translate('account.flash.passwordRequired'));
            return $this->redirect('dashboard');
        }
        if ($newPassword !== $confirmPassword) {
            $this->setFlash('danger', $this->translate('account.flash.passwordMismatch'));
            return $this->redirect('dashboard');
        }
        if (mb_strlen($newPassword) < 10) {
            $this->setFlash('danger', $this->translate('account.flash.passwordTooShort'));
            return $this->redirect('dashboard');
        }

        try {
            $this->accountService->changePassword($context['workosUserId'], $newPassword);
            $this->setFlash('success', $this->translate('account.flash.passwordUpdated'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: changePassword failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->mapPasswordError($e->getMessage()));
        }

        return $this->redirect('dashboard');
    }

    public function startMfaEnrollmentAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, RequestBody::fromRequest($this->request)->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }

        $workosUserId = $context['workosUserId'];

        try {
            $issuer = $this->detectIssuer();
            $accountName = $this->detectAccountName($workosUserId);
            $enrollment = $this->accountService->enrollTotpFactor($workosUserId, $issuer, $accountName);

            $factor = $enrollment->authenticationFactor;
            if ($factor === null) {
                throw new \RuntimeException('WorkOS did not return an MFA factor.', 1744277950);
            }
            $factorId = $factor->id ?? '';
            if ($factorId === '') {
                throw new \RuntimeException('WorkOS did not return an MFA factor.', 1744277950);
            }

            $totp = $factor->totp ?? [];
            $this->getFrontendUser()->setAndSaveSessionData('workos_account_mfa_pending', [
                'factorId' => $factorId,
                'qrCode' => $this->stringFromMixed($totp['qr_code'] ?? null),
                'uri' => $this->stringFromMixed($totp['uri'] ?? null),
                'secret' => $this->stringFromMixed($totp['secret'] ?? null),
                'createdAt' => time(),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: enroll factor failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.mfaEnrollFailed'));
        }

        return $this->redirect('dashboard');
    }

    public function verifyMfaEnrollmentAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $pending = $this->getPendingEnrollment();
        if ($pending === null) {
            $this->setFlash('danger', $this->translate('account.flash.mfaPendingMissing'));
            return $this->redirect('dashboard');
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }
        $code = $body->trimmedString('code');
        try {
            $this->accountService->verifyTotpFactor($this->stringFromMixed($pending['factorId']), $code);
            $this->getFrontendUser()->setAndSaveSessionData('workos_account_mfa_pending', null);
            $this->setFlash('success', $this->translate('account.flash.mfaActivated'));
        } catch (\Throwable $e) {
            $this->logger?->info('WorkOS account: verify factor failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.mfaCodeInvalid'));
        }

        return $this->redirect('dashboard');
    }

    public function cancelMfaEnrollmentAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, RequestBody::fromRequest($this->request)->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }

        $pending = $this->getPendingEnrollment();
        if ($pending !== null) {
            try {
                $this->accountService->deleteFactor($this->stringFromMixed($pending['factorId']));
            } catch (\Throwable $e) {
                $this->logger?->warning('WorkOS account: delete pending factor failed: ' . SecretRedactor::redact($e->getMessage()));
            }
            $this->getFrontendUser()->setAndSaveSessionData('workos_account_mfa_pending', null);
        }

        $this->setFlash('info', $this->translate('account.flash.mfaCancelled'));
        return $this->redirect('dashboard');
    }

    public function deleteFactorAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }
        $factorId = $body->trimmedString('factorId');
        if ($factorId === '') {
            return $this->redirect('dashboard');
        }

        try {
            $this->accountService->deleteFactor($factorId);
            $this->setFlash('success', $this->translate('account.flash.mfaRemoved'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: delete factor failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.mfaRemoveFailed'));
        }

        return $this->redirect('dashboard');
    }

    public function revokeSessionAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }
        $sessionId = $body->trimmedString('sessionId');
        if ($sessionId === '') {
            return $this->redirect('dashboard');
        }

        try {
            $this->accountService->revokeSession($sessionId);
            $this->setFlash('success', $this->translate('account.flash.sessionRevoked'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: revoke session failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.sessionRevokeFailed'));
        }

        return $this->redirect('dashboard');
    }

    /**
     * @return array{response: ?ResponseInterface, workosUserId: string, displayName: string}
     */
    private function resolveContext(): array
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        $isLoggedIn = $frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user ?? null);

        if (!$this->configuration->isFrontendReady() || !$isLoggedIn) {
            $this->view->assignMultiple([
                'configured' => $this->configuration->isFrontendReady(),
                'isLoggedIn' => $isLoggedIn,
            ]);
            return ['response' => $this->htmlResponse(), 'workosUserId' => '', 'displayName' => ''];
        }

        $identity = $this->identityService->findIdentityByLocalUser(
            'frontend',
            'fe_users',
            MixedCaster::int($frontendUser->user['uid'] ?? null)
        );

        $workosUserId = is_array($identity) ? MixedCaster::string($identity['workos_user_id'] ?? null) : '';
        if ($workosUserId === '') {
            $this->view->assignMultiple([
                'configured' => true,
                'isLoggedIn' => true,
                'noWorkosLink' => true,
            ]);
            return ['response' => $this->htmlResponse(), 'workosUserId' => '', 'displayName' => ''];
        }

        $displayName = MixedCaster::string(
            $frontendUser->user['name'] ?? $frontendUser->user['username'] ?? $frontendUser->user['email'] ?? null
        );

        $this->view->assignMultiple([
            'configured' => true,
            'isLoggedIn' => true,
            'displayName' => $displayName,
            'workosUserId' => $workosUserId,
        ]);

        return ['response' => null, 'workosUserId' => $workosUserId, 'displayName' => $displayName];
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareSessionRow(Session $session): array
    {
        $userAgent = $session->userAgent ?? '';
        $status = $session->status ?? '';

        return [
            'id' => $session->id ?? '',
            'ipAddress' => $session->ipAddress ?? '',
            'userAgent' => $userAgent,
            'organizationId' => $session->organizationId ?? '',
            'authenticationMethod' => $session->authenticationMethod ?? '',
            'status' => $status,
            'expiresAt' => $session->expiresAt ?? '',
            'createdAt' => $session->createdAt ?? '',
            'updatedAt' => $session->updatedAt ?? '',
            'deviceLabel' => $this->summarizeUserAgent($userAgent),
            'isActive' => strtolower($status) === 'active',
        ];
    }

    /**
     * @param array{membership: OrganizationMembership, organization: ?Organization} $entry
     * @return array<string, mixed>
     */
    private function prepareMembershipRow(array $entry): array
    {
        $membership = $entry['membership'];
        $organization = $entry['organization'];

        $roleSlugs = [];
        foreach ($membership->roles ?? [] as $role) {
            $slug = is_array($role) ? ($role['slug'] ?? '') : '';
            if (is_string($slug) && $slug !== '') {
                $roleSlugs[] = $slug;
            }
        }
        if ($roleSlugs === [] && is_string($membership->role) && $membership->role !== '') {
            $roleSlugs[] = $membership->role;
        }

        return [
            'id' => $membership->id ?? '',
            'organizationId' => $membership->organizationId ?? '',
            'organizationName' => $organization !== null ? ($organization->name ?? '') : '',
            'status' => $membership->status ?? '',
            'roleSlugs' => $roleSlugs,
            'directoryManaged' => $membership->directoryManaged ?? false,
            'createdAt' => $membership->createdAt ?? '',
        ];
    }

    private function summarizeUserAgent(string $userAgent): string
    {
        if ($userAgent === '') {
            return '';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/'), str_contains($userAgent, 'Edge/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($userAgent, 'Windows NT') => 'Windows',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return $browser . ' · ' . $os;
    }

    private function detectIssuer(): string
    {
        $site = $this->request->getAttribute('site');
        if ($site instanceof \TYPO3\CMS\Core\Site\Entity\Site) {
            $host = $site->getBase()->getHost();
            if ($host !== '') {
                return $host;
            }
        }
        $host = $this->request->getUri()->getHost();
        return $host !== '' ? $host : 'TYPO3';
    }

    private function detectAccountName(string $fallback): string
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        if ($frontendUser instanceof FrontendUserAuthentication && is_array($frontendUser->user)) {
            $email = trim(MixedCaster::string($frontendUser->user['email'] ?? null));
            if ($email !== '') {
                return $email;
            }
        }
        return $fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingEnrollment(): ?array
    {
        $data = $this->getFrontendUser()->getSessionData('workos_account_mfa_pending');
        if (!is_array($data) || !isset($data['factorId']) || $data['factorId'] === '') {
            return null;
        }
        $keyed = [];
        foreach ($data as $key => $value) {
            $keyed[(string)$key] = $value;
        }
        return $keyed;
    }

    private function setFlash(string $type, string $message): void
    {
        $this->getFrontendUser()->setAndSaveSessionData('workos_account_flash', [
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function consumeFlash(): ?array
    {
        $flash = $this->getFrontendUser()->getSessionData('workos_account_flash');
        if (!is_array($flash) || !isset($flash['message']) || $flash['message'] === '') {
            return null;
        }
        $this->getFrontendUser()->setAndSaveSessionData('workos_account_flash', null);
        $keyed = [];
        foreach ($flash as $key => $value) {
            $keyed[(string)$key] = $value;
        }
        return $keyed;
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
            throw new \RuntimeException('No frontend user session available.', 1744277960);
        }
        return $frontendUser;
    }

    private function mapPasswordError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'too short') || str_contains($lower, 'password_too_short')) {
            return $this->translate('account.flash.passwordTooShort');
        }
        if (str_contains($lower, 'weak') || str_contains($lower, 'unguessable')) {
            return $this->translate('account.flash.passwordTooWeak');
        }
        if (str_contains($lower, 'pwned') || str_contains($lower, 'breached') || str_contains($lower, 'compromised')) {
            return $this->translate('account.flash.passwordBreached');
        }
        return $this->translate('account.flash.passwordFailed');
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate($key, 'WorkosAuth', $arguments !== [] ? $arguments : null) ?? $key;
    }
}
