<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\FrontendCsrfService;
use WebConsulting\WorkosAuth\Service\IdentityService;
use WebConsulting\WorkosAuth\Service\RequestBody;
use WebConsulting\WorkosAuth\Service\WorkosTeamService;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\Organization;
use WebConsulting\WorkosAuth\Security\SecretRedactor;

/**
 * "WorkOS Team" plugin: lets a signed-in admin manage organization
 * invitations and launch one-time WorkOS Admin Portal sessions for
 * SSO, Directory Sync, Audit Logs, Domain Verification, etc.
 */
final class TeamController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SESSION_FLASH = 'workos_team_flash';
    private const SESSION_ORG = 'workos_team_org';
    private const CSRF_SCOPE = 'team';

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly IdentityService $identityService,
        private readonly WorkosTeamService $teamService,
        private readonly FrontendCsrfService $csrfService,
    ) {}

    public function dashboardAction(?string $organizationId = null): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $workosUserId = $context['workosUserId'];
        $organizations = [];
        $sectionErrors = [];

        try {
            $organizations = $this->teamService->listAdminOrganizations($workosUserId);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS team: list memberships failed: ' . SecretRedactor::redact($e->getMessage()));
            $sectionErrors['organizations'] = $this->translate('team.error.loadOrganizations');
        }

        if ($organizations === []) {
            $this->view->assignMultiple([
                'configured' => true,
                'isLoggedIn' => true,
                'workosUserId' => $workosUserId,
                'noOrganizations' => true,
                'sectionErrors' => $sectionErrors,
                'flash' => $this->consumeFlash(),
            ]);
            return $this->htmlResponse();
        }

        $selectedOrgId = $this->resolveSelectedOrganization($organizationId, $organizations);
        $selectedOrg = $organizations[$selectedOrgId] ?? null;

        $invitations = [];
        try {
            $invitations = $this->teamService->listInvitations($selectedOrgId, 25);
        } catch (\Throwable $e) {
            $this->logger?->warning('WorkOS team: list invitations failed: ' . SecretRedactor::redact($e->getMessage()));
            $sectionErrors['invitations'] = $this->translate('team.error.loadInvitations');
        }

        $portalIntents = array_map(
            fn (array $intent) => [
                'slug' => $intent['slug'],
                'label' => $this->translate($intent['labelKey']),
            ],
            $this->teamService->describePortalIntents(),
        );

        $this->view->assignMultiple([
            'configured' => true,
            'isLoggedIn' => true,
            'workosUserId' => $workosUserId,
            'organizations' => $this->prepareOrganizations($organizations, $selectedOrgId),
            'selectedOrganization' => $selectedOrg !== null ? [
                'id' => $selectedOrg->id ?? '',
                'name' => $selectedOrg->name ?? '',
            ] : null,
            'invitations' => array_map(fn (Invitation $i) => $this->prepareInvitationRow($i), $invitations),
            'portalIntents' => $portalIntents,
            'flash' => $this->consumeFlash(),
            'sectionErrors' => $sectionErrors,
            'csrfToken' => $this->csrfService->issue($this->getFrontendUser(), self::CSRF_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function inviteAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($body->trimmedString('organizationId'));
        }
        $email = $body->trimmedString('email');
        $roleSlug = $body->trimmedString('roleSlug');
        $organizationId = $body->trimmedString('organizationId');

        if ($email === '' || $organizationId === '') {
            $this->setFlash('danger', $this->translate('team.flash.inviteFieldsRequired'));
            return $this->redirectToDashboard($organizationId);
        }

        try {
            $this->teamService->sendInvitation(
                email: $email,
                organizationId: $organizationId,
                inviterUserId: $context['workosUserId'],
                roleSlug: $roleSlug !== '' ? $roleSlug : null,
            );
            $this->setFlash('success', $this->translate('team.flash.inviteSent', ['email' => $email]));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS team: send invitation failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->mapInvitationError($e->getMessage()));
        }

        return $this->redirectToDashboard($organizationId);
    }

    public function resendInvitationAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($body->trimmedString('organizationId'));
        }
        $invitationId = $body->trimmedString('invitationId');
        $organizationId = $body->trimmedString('organizationId');

        if ($invitationId !== '') {
            try {
                $this->teamService->resendInvitation($invitationId);
                $this->setFlash('success', $this->translate('team.flash.inviteResent'));
            } catch (\Throwable $e) {
                $this->logger?->error('WorkOS team: resend invitation failed: ' . SecretRedactor::redact($e->getMessage()));
                $this->setFlash('danger', $this->translate('team.flash.inviteResendFailed'));
            }
        }

        return $this->redirectToDashboard($organizationId);
    }

    public function revokeInvitationAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($body->trimmedString('organizationId'));
        }
        $invitationId = $body->trimmedString('invitationId');
        $organizationId = $body->trimmedString('organizationId');

        if ($invitationId !== '') {
            try {
                $this->teamService->revokeInvitation($invitationId);
                $this->setFlash('success', $this->translate('team.flash.inviteRevoked'));
            } catch (\Throwable $e) {
                $this->logger?->error('WorkOS team: revoke invitation failed: ' . SecretRedactor::redact($e->getMessage()));
                $this->setFlash('danger', $this->translate('team.flash.inviteRevokeFailed'));
            }
        }

        return $this->redirectToDashboard($organizationId);
    }

    public function launchPortalAction(): ResponseInterface
    {
        $context = $this->resolveContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->csrfService->validate($this->getFrontendUser(), self::CSRF_SCOPE, $body->string('csrfToken'))) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($body->trimmedString('organizationId'));
        }
        $intent = $body->trimmedString('intent');
        $organizationId = $body->trimmedString('organizationId');

        if ($intent === '' || $organizationId === '') {
            $this->setFlash('danger', $this->translate('team.flash.portalMissingArgs'));
            return $this->redirectToDashboard($organizationId);
        }

        $returnUrl = (string)$this->request->getUri();

        try {
            $portalLink = $this->teamService->generatePortalLink(
                organizationId: $organizationId,
                intent: $intent,
                returnUrl: $returnUrl !== '' ? $returnUrl : null,
            );
            $link = $portalLink->link ?? '';
            if ($link === '') {
                throw new \RuntimeException('Empty portal link returned.', 1744278050);
            }
            return new RedirectResponse($link, 303);
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS team: generate portal link failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate('team.flash.portalFailed'));
            return $this->redirectToDashboard($organizationId);
        }
    }

    /**
     * @param array<string, Organization> $organizations
     * @return array<int, array{id:string,name:string,domains:string,selected:bool}>
     */
    private function prepareOrganizations(array $organizations, string $selectedOrgId): array
    {
        $rows = [];
        foreach ($organizations as $organization) {
            $domains = '';
            $orgDomains = $organization->domains;
            if (is_array($orgDomains)) {
                $names = [];
                foreach ($orgDomains as $entry) {
                    $domain = is_array($entry) ? ($entry['domain'] ?? '') : '';
                    if (is_string($domain) && $domain !== '') {
                        $names[] = $domain;
                    }
                }
                $domains = implode(', ', $names);
            }
            $orgId = $organization->id ?? '';
            $rows[] = [
                'id' => $orgId,
                'name' => $organization->name ?? '',
                'domains' => $domains,
                'selected' => $orgId === $selectedOrgId,
            ];
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareInvitationRow(Invitation $invitation): array
    {
        $state = strtolower($invitation->state ?? '');

        return [
            'id' => $invitation->id ?? '',
            'email' => $invitation->email ?? '',
            'state' => $state,
            'expiresAt' => $invitation->expiresAt ?? '',
            'createdAt' => $invitation->createdAt ?? '',
            'acceptUrl' => $invitation->acceptInvitationUrl ?? '',
            'isPending' => $state === 'pending',
        ];
    }

    /**
     * @param array<string, Organization> $organizations
     */
    private function resolveSelectedOrganization(?string $requested, array $organizations): string
    {
        if ($requested !== null && $requested !== '' && isset($organizations[$requested])) {
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_ORG, $requested);
            return $requested;
        }

        $stored = $this->getFrontendUser()->getSessionData(self::SESSION_ORG);
        if (is_string($stored) && isset($organizations[$stored])) {
            return $stored;
        }

        $firstKey = array_key_first($organizations);
        return $firstKey !== null ? $firstKey : '';
    }

    private function redirectToDashboard(string $organizationId = ''): ResponseInterface
    {
        if ($organizationId !== '') {
            return $this->redirect('dashboard', null, null, ['organizationId' => $organizationId]);
        }
        return $this->redirect('dashboard');
    }

    /**
     * @return array{response: ?ResponseInterface, workosUserId: string}
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
            return ['response' => $this->htmlResponse(), 'workosUserId' => ''];
        }

        $identity = $this->identityService->findIdentityByLocalUser(
            'frontend',
            'fe_users',
            (int)$frontendUser->user['uid']
        );

        $workosUserId = is_array($identity) ? (string)($identity['workos_user_id'] ?? '') : '';
        if ($workosUserId === '') {
            $this->view->assignMultiple([
                'configured' => true,
                'isLoggedIn' => true,
                'noWorkosLink' => true,
            ]);
            return ['response' => $this->htmlResponse(), 'workosUserId' => ''];
        }

        return ['response' => null, 'workosUserId' => $workosUserId];
    }

    private function getFrontendUser(): FrontendUserAuthentication
    {
        $frontendUser = $this->request->getAttribute('frontend.user');
        if (!$frontendUser instanceof FrontendUserAuthentication) {
            throw new \RuntimeException('No frontend user session available.', 1744278060);
        }
        return $frontendUser;
    }

    private function setFlash(string $type, string $message): void
    {
        $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_FLASH, [
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * @return array|null
     */
    private function consumeFlash(): ?array
    {
        $flash = $this->getFrontendUser()->getSessionData(self::SESSION_FLASH);
        if (!is_array($flash) || !isset($flash['message']) || $flash['message'] === '') {
            return null;
        }
        $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_FLASH, null);
        return $flash;
    }

    private function mapInvitationError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'already') && (str_contains($lower, 'invited') || str_contains($lower, 'exists'))) {
            return $this->translate('team.flash.inviteAlreadyExists');
        }
        if (str_contains($lower, 'invalid_email') || str_contains($lower, 'invalid email')) {
            return $this->translate('team.flash.inviteInvalidEmail');
        }
        return $this->translate('team.flash.inviteFailed');
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate($key, 'WorkosAuth', $arguments !== [] ? $arguments : null) ?? $key;
    }
}
