<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Site\Entity\Site;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\MixedCaster;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Security\WorkosErrorMessageResolver;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\RequestBody;
use Webconsulting\WorkosAuth\Service\WorkosAccountService;
use WorkOS\Resource\Organization;
use WorkOS\Resource\UserOrganizationMembership;
use WorkOS\Resource\UserSessionsListItem;

/**
 * "WorkOS Account Center" plugin: profile, password, TOTP factors, sessions
 * and organization memberships of the signed-in frontend user.
 */
#[Autoconfigure(public: true)]
final class AccountController extends AbstractFrontendController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const string REQUEST_TOKEN_SCOPE = 'workos/frontend/account';
    protected const string SESSION_FLASH = 'workos_account_flash';

    private const string SESSION_MFA_PENDING = 'workos_account_mfa_pending';

    public function __construct(
        WorkosConfiguration $configuration,
        IdentityService $identityService,
        RequestTokenService $requestTokenService,
        private readonly WorkosAccountService $accountService,
        private readonly WorkosErrorMessageResolver $errorMessageResolver,
    ) {
        parent::__construct($configuration, $identityService, $requestTokenService);
    }

    public function dashboardAction(): ResponseInterface
    {
        $workosUserId = $this->resolveLinkedWorkosUserId();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        // Every card degrades on its own: one failed WorkOS call only hides that card.
        $errors = [];
        $load = function (string $section, string $errorKey, callable $loader, mixed $default) use (&$errors): mixed {
            try {
                return $loader();
            } catch (\Throwable $e) {
                $this->logger?->warning(sprintf('WorkOS account: loading %s failed: %s', $section, SecretRedactor::redact($e->getMessage())));
                $errors[$section] = $this->translate($errorKey);
                return $default;
            }
        };

        $workosUser = $load('profile', 'account.error.loadProfile', fn() => $this->accountService->getUser($workosUserId), null);
        $factors = $load('mfa', 'account.error.loadFactors', fn() => $this->accountService->listTotpFactors($workosUserId), []);
        $sessions = $load('sessions', 'account.error.loadSessions', fn() => $this->accountService->listSessions($workosUserId, 25), []);
        $memberships = $load('organizations', 'account.error.loadOrganizations', fn() => $this->accountService->listOrganizationMemberships($workosUserId), []);

        $this->view->assignMultiple([
            'workosUser' => $workosUser,
            'factors' => $factors,
            'sessions' => array_map($this->prepareSessionRow(...), $sessions),
            'memberships' => array_map($this->prepareMembershipRow(...), $memberships),
            'pendingEnrollment' => $this->getPendingEnrollment(),
            'flash' => $this->consumeFlash(),
            'sectionErrors' => $errors,
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function updateProfileAction(): ResponseInterface
    {
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $body = RequestBody::fromRequest($this->request);
        $firstName = $body->trimmedString('firstName');
        $lastName = $body->trimmedString('lastName');

        try {
            $this->accountService->updateProfile($workosUserId, $firstName !== '' ? $firstName : null, $lastName !== '' ? $lastName : null);
            $this->setFlash('success', $this->translate('account.flash.profileUpdated'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: updateProfile failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.profileFailed'));
        }

        return $this->redirect('dashboard');
    }

    public function changePasswordAction(): ResponseInterface
    {
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $body = RequestBody::fromRequest($this->request);
        $newPassword = $body->string('password');
        $confirmPassword = $body->string('passwordConfirm');

        $validationError = match (true) {
            $newPassword === '' || $confirmPassword === '' => 'account.flash.passwordRequired',
            $newPassword !== $confirmPassword => 'account.flash.passwordMismatch',
            mb_strlen($newPassword) < 10 => 'account.flash.passwordTooShort',
            default => null,
        };
        if ($validationError !== null) {
            $this->setFlash('danger', $this->translate($validationError));
            return $this->redirect('dashboard');
        }

        try {
            $this->accountService->changePassword($workosUserId, $newPassword);
            $this->setFlash('success', $this->translate('account.flash.passwordUpdated'));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS account: changePassword failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate($this->errorMessageResolver->resolvePasswordChange($e->getMessage())));
        }

        return $this->redirect('dashboard');
    }

    public function startMfaEnrollmentAction(): ResponseInterface
    {
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        try {
            $factor = $this->accountService->enrollTotpFactor($workosUserId, $this->detectIssuer(), $this->detectAccountName($workosUserId))->authenticationFactor;
            if ($factor->id === '') {
                throw new \RuntimeException('WorkOS did not return an MFA factor.', 1744277950);
            }
            $totp = $factor->totp ?? throw new \RuntimeException('WorkOS did not return TOTP enrollment data.', 1744277951);

            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_MFA_PENDING, [
                'factorId' => $factor->id,
                'qrCode' => $totp->qrCode,
                'uri' => $totp->uri,
                'secret' => $totp->secret,
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
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $pending = $this->getPendingEnrollment();
        if ($pending === null) {
            $this->setFlash('danger', $this->translate('account.flash.mfaPendingMissing'));
            return $this->redirect('dashboard');
        }

        try {
            $this->accountService->verifyTotpFactor(MixedCaster::string($pending['factorId']), RequestBody::fromRequest($this->request)->trimmedString('code'));
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_MFA_PENDING, null);
            $this->setFlash('success', $this->translate('account.flash.mfaActivated'));
        } catch (\Throwable $e) {
            $this->logger?->info('WorkOS account: verify factor failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('account.flash.mfaCodeInvalid'));
        }

        return $this->redirect('dashboard');
    }

    public function cancelMfaEnrollmentAction(): ResponseInterface
    {
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $pending = $this->getPendingEnrollment();
        if ($pending !== null) {
            try {
                $this->accountService->deleteFactor(MixedCaster::string($pending['factorId']));
            } catch (\Throwable $e) {
                $this->logger?->warning('WorkOS account: delete pending factor failed: ' . SecretRedactor::redact($e->getMessage()));
            }
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_MFA_PENDING, null);
        }

        $this->setFlash('info', $this->translate('account.flash.mfaCancelled'));

        return $this->redirect('dashboard');
    }

    public function deleteFactorAction(): ResponseInterface
    {
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $factorId = RequestBody::fromRequest($this->request)->trimmedString('factorId');
        if ($factorId === '') {
            return $this->redirect('dashboard');
        }

        $owned = array_any($this->accountService->listTotpFactors($workosUserId), static fn($factor): bool => $factor->id === $factorId);
        if (!$owned) {
            $this->setFlash('danger', $this->translate('account.flash.forbidden'));
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
        $workosUserId = $this->authorizeAction();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $sessionId = RequestBody::fromRequest($this->request)->trimmedString('sessionId');
        if ($sessionId === '') {
            return $this->redirect('dashboard');
        }

        $owned = array_any($this->accountService->listSessions($workosUserId, 100), static fn($session): bool => $session->id === $sessionId);
        if (!$owned) {
            $this->setFlash('danger', $this->translate('account.flash.forbidden'));
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
     * Guard of every state-changing action: linked WorkOS user plus a valid
     * request token. Returns the WorkOS user id or the response to send instead.
     */
    private function authorizeAction(): ResponseInterface|string
    {
        $workosUserId = $this->resolveLinkedWorkosUserId();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }
        if (!$this->hasValidRequestToken()) {
            $this->setFlash('danger', $this->translate('account.flash.csrfInvalid'));
            return $this->redirect('dashboard');
        }

        return $workosUserId;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareSessionRow(UserSessionsListItem $session): array
    {
        $userAgent = $session->userAgent ?? '';
        $status = $session->status->value;

        return [
            'id' => $session->id,
            'ipAddress' => $session->ipAddress ?? '',
            'userAgent' => $userAgent,
            'organizationId' => $session->organizationId ?? '',
            'authenticationMethod' => $session->authMethod->value,
            'status' => $status,
            'expiresAt' => $this->formatDateTime($session->expiresAt),
            'createdAt' => $this->formatDateTime($session->createdAt),
            'updatedAt' => $this->formatDateTime($session->updatedAt),
            'deviceLabel' => self::summarizeUserAgent($userAgent),
            'isActive' => strtolower($status) === 'active',
        ];
    }

    /**
     * @param array{membership: UserOrganizationMembership, organization: ?Organization} $entry
     * @return array<string, mixed>
     */
    private function prepareMembershipRow(array $entry): array
    {
        $membership = $entry['membership'];

        return [
            'id' => $membership->id,
            'organizationId' => $membership->organizationId,
            'organizationName' => $entry['organization'] !== null ? $entry['organization']->name : ($membership->organizationName ?? ''),
            'status' => $membership->status->value,
            'roleSlugs' => $membership->role->slug !== '' ? [$membership->role->slug] : [],
            'directoryManaged' => $membership->directoryManaged,
            'createdAt' => $this->formatDateTime($membership->createdAt),
        ];
    }

    private static function summarizeUserAgent(string $userAgent): string
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
        $host = $site instanceof Site ? $site->getBase()->getHost() : '';
        $host = $host !== '' ? $host : $this->request->getUri()->getHost();

        return $host !== '' ? $host : 'TYPO3';
    }

    private function detectAccountName(string $fallback): string
    {
        $email = trim(MixedCaster::string($this->getFrontendUser()->user['email'] ?? null));

        return $email !== '' ? $email : $fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingEnrollment(): ?array
    {
        $data = MixedCaster::stringKeyedArray($this->getFrontendUser()->getSessionData(self::SESSION_MFA_PENDING));

        return $data !== null && MixedCaster::string($data['factorId'] ?? null) !== '' ? $data : null;
    }
}
