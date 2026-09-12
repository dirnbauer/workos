<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Controller\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\RedirectResponse;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\RequestTokenService;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\RequestBody;
use Webconsulting\WorkosAuth\Service\WorkosTeamService;
use WorkOS\Resource\Organization;
use WorkOS\Resource\UserInvite;

/**
 * "WorkOS Team" plugin: lets a signed-in admin manage organization
 * invitations and launch one-time WorkOS Admin Portal sessions for
 * SSO, Directory Sync, Audit Logs, Domain Verification, etc.
 */
#[Autoconfigure(public: true)]
final class TeamController extends AbstractFrontendController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const string REQUEST_TOKEN_SCOPE = 'workos/frontend/team';
    protected const string SESSION_FLASH = 'workos_team_flash';

    private const string SESSION_ORG = 'workos_team_org';

    public function __construct(
        WorkosConfiguration $configuration,
        IdentityService $identityService,
        RequestTokenService $requestTokenService,
        private readonly WorkosTeamService $teamService,
    ) {
        parent::__construct($configuration, $identityService, $requestTokenService);
    }

    public function dashboardAction(?string $organizationId = null): ResponseInterface
    {
        $context = $this->resolveLinkedWorkosContext();
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
            fn(array $intent) => [
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
                'id' => $selectedOrg->id,
                'name' => $selectedOrg->name,
            ] : null,
            'invitations' => array_map($this->prepareInvitationRow(...), $invitations),
            'portalIntents' => $portalIntents,
            'flash' => $this->consumeFlash(),
            'sectionErrors' => $sectionErrors,
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function inviteAction(): ResponseInterface
    {
        $context = $this->resolveLinkedWorkosContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->hasValidRequestToken()) {
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
            $this->teamService->assertMemberOfOrganization($context['workosUserId'], $organizationId);
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
        $context = $this->resolveLinkedWorkosContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->hasValidRequestToken()) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($body->trimmedString('organizationId'));
        }
        $invitationId = $body->trimmedString('invitationId');
        $organizationId = $body->trimmedString('organizationId');

        if ($invitationId !== '') {
            try {
                $invitation = $this->teamService->findInvitation($invitationId);
                $invitationOrgId = $invitation === null ? '' : $invitation->organizationId ?? '';
                $this->teamService->assertMemberOfOrganization($context['workosUserId'], $invitationOrgId);
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
        $context = $this->resolveLinkedWorkosContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->hasValidRequestToken()) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($body->trimmedString('organizationId'));
        }
        $invitationId = $body->trimmedString('invitationId');
        $organizationId = $body->trimmedString('organizationId');

        if ($invitationId !== '') {
            try {
                $invitation = $this->teamService->findInvitation($invitationId);
                $invitationOrgId = $invitation === null ? '' : $invitation->organizationId ?? '';
                $this->teamService->assertMemberOfOrganization($context['workosUserId'], $invitationOrgId);
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
        $context = $this->resolveLinkedWorkosContext();
        if ($context['response'] !== null) {
            return $context['response'];
        }

        $body = RequestBody::fromRequest($this->request);
        if (!$this->hasValidRequestToken()) {
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
            $this->teamService->assertMemberOfOrganization($context['workosUserId'], $organizationId);
            $portalLink = $this->teamService->generatePortalLink(
                organizationId: $organizationId,
                intent: $intent,
                returnUrl: $returnUrl !== '' ? $returnUrl : null,
            );
            $link = $portalLink->link;
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
            $orgId = $organization->id;
            $rows[] = [
                'id' => $orgId,
                'name' => $organization->name,
                'domains' => $domains,
                'selected' => $orgId === $selectedOrgId,
            ];
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareInvitationRow(UserInvite $invitation): array
    {
        $state = strtolower($invitation->state->value);

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'state' => $state,
            'expiresAt' => $this->formatDateTime($invitation->expiresAt),
            'createdAt' => $this->formatDateTime($invitation->createdAt),
            'acceptUrl' => $invitation->acceptInvitationUrl,
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

    private function mapInvitationError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'forbidden_organization')) {
            return $this->translate('team.flash.forbidden');
        }
        if (str_contains($lower, 'already') && (str_contains($lower, 'invited') || str_contains($lower, 'exists'))) {
            return $this->translate('team.flash.inviteAlreadyExists');
        }
        if (str_contains($lower, 'invalid_email') || str_contains($lower, 'invalid email')) {
            return $this->translate('team.flash.inviteInvalidEmail');
        }
        return $this->translate('team.flash.inviteFailed');
    }
}
