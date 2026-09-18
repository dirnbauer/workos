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
use Webconsulting\WorkosAuth\Security\WorkosErrorMessageResolver;
use Webconsulting\WorkosAuth\Service\IdentityService;
use Webconsulting\WorkosAuth\Service\RequestBody;
use Webconsulting\WorkosAuth\Service\WorkosTeamService;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationDomain;
use WorkOS\Resource\UserInvite;

/**
 * "WorkOS Team" plugin: organization invitations and one-time WorkOS Admin
 * Portal links for signed-in organization admins.
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
        private readonly WorkosErrorMessageResolver $errorMessageResolver,
    ) {
        parent::__construct($configuration, $identityService, $requestTokenService);
    }

    public function dashboardAction(?string $organizationId = null): ResponseInterface
    {
        $workosUserId = $this->resolveLinkedWorkosUserId();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

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
                'noOrganizations' => true,
                'sectionErrors' => $sectionErrors,
                'flash' => $this->consumeFlash(),
            ]);

            return $this->htmlResponse();
        }

        $selectedOrgId = $this->resolveSelectedOrganization($organizationId, $organizations);
        $selectedOrg = $organizations[$selectedOrgId];

        $invitations = [];
        try {
            $invitations = $this->teamService->listInvitations($selectedOrgId, 25);
        } catch (\Throwable $e) {
            $this->logger?->warning('WorkOS team: list invitations failed: ' . SecretRedactor::redact($e->getMessage()));
            $sectionErrors['invitations'] = $this->translate('team.error.loadInvitations');
        }

        $this->view->assignMultiple([
            'organizations' => array_map(
                fn(Organization $organization): array => $this->prepareOrganizationRow($organization, $selectedOrgId),
                array_values($organizations)
            ),
            'selectedOrganization' => ['id' => $selectedOrg->id, 'name' => $selectedOrg->name],
            'invitations' => array_map($this->prepareInvitationRow(...), $invitations),
            'portalIntents' => array_map(
                fn(string $slug, string $labelKey): array => ['slug' => $slug, 'label' => $this->translate($labelKey)],
                array_keys(WorkosTeamService::PORTAL_INTENTS),
                WorkosTeamService::PORTAL_INTENTS
            ),
            'flash' => $this->consumeFlash(),
            'sectionErrors' => $sectionErrors,
            'requestToken' => $this->requestTokenService->create(self::REQUEST_TOKEN_SCOPE),
        ]);

        return $this->htmlResponse();
    }

    public function inviteAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $organizationId = $body->trimmedString('organizationId');
        $workosUserId = $this->authorizeAction($organizationId);
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $email = $body->trimmedString('email');
        $roleSlug = $body->trimmedString('roleSlug');
        if ($email === '' || $organizationId === '') {
            $this->setFlash('danger', $this->translate('team.flash.inviteFieldsRequired'));
            return $this->redirectToDashboard($organizationId);
        }

        try {
            $this->teamService->assertMemberOfOrganization($workosUserId, $organizationId);
            $this->teamService->sendInvitation($email, $organizationId, $workosUserId, $roleSlug !== '' ? $roleSlug : null);
            $this->setFlash('success', $this->translate('team.flash.inviteSent', ['email' => $email]));
        } catch (\Throwable $e) {
            $this->logger?->error('WorkOS team: send invitation failed: ' . SecretRedactor::redact($e->getMessage()));
            $this->setFlash('danger', $this->translate($this->errorMessageResolver->resolveInvitation($e->getMessage())));
        }

        return $this->redirectToDashboard($organizationId);
    }

    public function resendInvitationAction(): ResponseInterface
    {
        return $this->handleInvitationAction(
            fn(string $invitationId): UserInvite => $this->teamService->resendInvitation($invitationId),
            'team.flash.inviteResent',
            'team.flash.inviteResendFailed'
        );
    }

    public function revokeInvitationAction(): ResponseInterface
    {
        return $this->handleInvitationAction(
            fn(string $invitationId) => $this->teamService->revokeInvitation($invitationId),
            'team.flash.inviteRevoked',
            'team.flash.inviteRevokeFailed'
        );
    }

    public function launchPortalAction(): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $organizationId = $body->trimmedString('organizationId');
        $workosUserId = $this->authorizeAction($organizationId);
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $intent = $body->trimmedString('intent');
        if ($intent === '' || $organizationId === '') {
            $this->setFlash('danger', $this->translate('team.flash.portalMissingArgs'));
            return $this->redirectToDashboard($organizationId);
        }

        try {
            $this->teamService->assertMemberOfOrganization($workosUserId, $organizationId);
            $link = $this->teamService->generatePortalLink($organizationId, $intent, (string)$this->request->getUri())->link;
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
     * Resend / revoke share the same shape: authorize against the
     * invitation's organization (never the posted one), then act.
     *
     * @param callable(string): mixed $operation
     */
    private function handleInvitationAction(callable $operation, string $successKey, string $failureKey): ResponseInterface
    {
        $body = RequestBody::fromRequest($this->request);
        $organizationId = $body->trimmedString('organizationId');
        $workosUserId = $this->authorizeAction($organizationId);
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }

        $invitationId = $body->trimmedString('invitationId');
        if ($invitationId !== '') {
            try {
                $this->teamService->assertMemberOfOrganization($workosUserId, $this->teamService->findInvitationOrganizationId($invitationId));
                $operation($invitationId);
                $this->setFlash('success', $this->translate($successKey));
            } catch (\Throwable $e) {
                $this->logger?->error('WorkOS team: invitation action failed: ' . SecretRedactor::redact($e->getMessage()));
                $this->setFlash('danger', $this->translate($failureKey));
            }
        }

        return $this->redirectToDashboard($organizationId);
    }

    /**
     * Guard of every state-changing action: linked WorkOS user plus a valid
     * request token. Returns the WorkOS user id or the response to send instead.
     */
    private function authorizeAction(string $organizationId): ResponseInterface|string
    {
        $workosUserId = $this->resolveLinkedWorkosUserId();
        if ($workosUserId instanceof ResponseInterface) {
            return $workosUserId;
        }
        if (!$this->hasValidRequestToken()) {
            $this->setFlash('danger', $this->translate('team.flash.csrfInvalid'));
            return $this->redirectToDashboard($organizationId);
        }

        return $workosUserId;
    }

    /**
     * @return array{id: string, name: string, domains: string, selected: bool}
     */
    private function prepareOrganizationRow(Organization $organization, string $selectedOrgId): array
    {
        $domains = array_map(
            static fn(OrganizationDomain $domain): string => $domain->domain,
            array_filter($organization->domains, static fn(mixed $domain): bool => $domain instanceof OrganizationDomain)
        );

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'domains' => implode(', ', $domains),
            'selected' => $organization->id === $selectedOrgId,
        ];
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
     * The requested organization wins and is remembered in the session;
     * otherwise the remembered one, otherwise the first.
     *
     * @param non-empty-array<string, Organization> $organizations
     */
    private function resolveSelectedOrganization(?string $requested, array $organizations): string
    {
        if ($requested !== null && isset($organizations[$requested])) {
            $this->getFrontendUser()->setAndSaveSessionData(self::SESSION_ORG, $requested);
            return $requested;
        }

        $stored = $this->getFrontendUser()->getSessionData(self::SESSION_ORG);

        return is_string($stored) && isset($organizations[$stored]) ? $stored : array_key_first($organizations);
    }

    private function redirectToDashboard(string $organizationId): ResponseInterface
    {
        return $this->redirect('dashboard', null, null, $organizationId !== '' ? ['organizationId' => $organizationId] : []);
    }
}
