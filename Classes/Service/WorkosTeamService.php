<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Resource\EventsOrder;
use WorkOS\Resource\GenerateLinkIntent;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationMembershipStatus;
use WorkOS\Resource\PortalLinkResponse;
use WorkOS\Resource\UserOrganizationMembership;
use WorkOS\Resource\UserInvite;

/**
 * Backs the "WorkOS Team" frontend plugin: manages organization
 * invitations and generates one-time WorkOS Admin Portal links so
 * customer admins can self-serve SSO, Directory Sync, Audit Logs,
 * Domain Verification and certificate renewal.
 */
final class WorkosTeamService
{
    /**
     * Intent => translation key. Order matters for the dashboard.
     *
     * @var array<string, string>
     */
    public const PORTAL_INTENTS = [
        'sso' => 'team.portal.intent.sso',
        'dsync' => 'team.portal.intent.dsync',
        'audit_logs' => 'team.portal.intent.auditLogs',
        'log_streams' => 'team.portal.intent.logStreams',
        'domain_verification' => 'team.portal.intent.domain',
        'certificate_renewal' => 'team.portal.intent.certs',
    ];

    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosClientFactory $workosClientFactory,
    ) {}

    /**
     * Return active organization memberships for a WorkOS user as
     * `[organizationId => Organization]` (skip memberships whose
     * organization can't be loaded).
     *
     * @return array<string, Organization>
     */
    public function listAdminOrganizations(string $workosUserId): array
    {
        $this->assertConfigured();
        $userManagement = $this->workosClientFactory->createUserManagement();
        $organizations = $this->workosClientFactory->createOrganizations();

        $response = $userManagement->listOrganizationMemberships(
            userId: $workosUserId,
            statuses: [OrganizationMembershipStatus::Active],
            limit: 50,
        );

        $result = [];
        foreach ($response->data as $membership) {
            if (!$membership instanceof UserOrganizationMembership) {
                continue;
            }
            $organizationId = $membership->organizationId;
            if ($organizationId === '' || isset($result[$organizationId])) {
                continue;
            }

            try {
                $organization = $organizations->getOrganization($organizationId);
                $result[$organizationId] = $organization;
            } catch (\Throwable) {
                // Skip organizations the API key can no longer load.
            }
        }

        return $result;
    }

    /**
     * Authorization helper. Throws when the given WorkOS user is not an
     * active member of the given organization. Call this before any
     * action whose `organizationId` came from the user's POST body.
     *
     * Guards against CWE-285 (Improper Authorization) in the Team*
     * controller actions: without this check, a logged-in frontend
     * user could invite, revoke, or mint portal links for arbitrary
     * organizations the API key can reach.
     */
    public function assertMemberOfOrganization(string $workosUserId, string $organizationId): void
    {
        if ($workosUserId === '' || $organizationId === '') {
            throw new \RuntimeException('forbidden_organization', 1744278100);
        }
        $this->assertConfigured();
        $response = $this->workosClientFactory->createUserManagement()->listOrganizationMemberships(
            userId: $workosUserId,
            organizationId: $organizationId,
            statuses: [OrganizationMembershipStatus::Active],
            limit: 1,
        );
        foreach ($response->data as $membership) {
            if ($membership instanceof UserOrganizationMembership
                && $membership->userId === $workosUserId
                && $membership->organizationId === $organizationId) {
                return;
            }
        }
        throw new \RuntimeException('forbidden_organization', 1744278101);
    }

    /**
     * Fetch a single invitation so the caller can resolve the
     * organization id it belongs to before running authorization
     * checks. Returns null when the invitation cannot be loaded.
     */
    public function findInvitation(string $invitationId): ?UserInvite
    {
        if ($invitationId === '') {
            return null;
        }
        $this->assertConfigured();
        try {
            $invitation = $this->workosClientFactory->createUserManagement()->getInvitation($invitationId);
        } catch (\Throwable) {
            return null;
        }
        return $invitation;
    }

    /**
     * @return UserInvite[]
     */
    public function listInvitations(string $organizationId, int $limit = 25): array
    {
        $this->assertConfigured();
        $response = $this->workosClientFactory->createUserManagement()->listInvitations(
            organizationId: $organizationId,
            limit: $limit,
            order: EventsOrder::Desc,
        );
        return array_values(array_filter(
            $response->data,
            static fn (mixed $invitation): bool => $invitation instanceof UserInvite
        ));
    }

    public function sendInvitation(
        string $email,
        string $organizationId,
        ?string $inviterUserId = null,
        ?string $roleSlug = null,
        ?int $expiresInDays = null,
    ): UserInvite {
        $this->assertConfigured();
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('invalid_email', 1744278001);
        }
        if ($organizationId === '') {
            throw new \RuntimeException('organization_required', 1744278002);
        }

        return $this->workosClientFactory->createUserManagement()->sendInvitation(
            email: $email,
            organizationId: $organizationId,
            expiresInDays: $expiresInDays,
            inviterUserId: $inviterUserId !== null && $inviterUserId !== '' ? $inviterUserId : null,
            roleSlug: $roleSlug !== null && $roleSlug !== '' ? $roleSlug : null,
        );
    }

    public function resendInvitation(string $invitationId): UserInvite
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createUserManagement()->resendInvitation($invitationId);
    }

    public function revokeInvitation(string $invitationId): Invitation
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createUserManagement()->revokeInvitation($invitationId);
    }

    public function generatePortalLink(
        string $organizationId,
        string $intent,
        ?string $returnUrl = null,
    ): PortalLinkResponse {
        $this->assertConfigured();
        if (!array_key_exists($intent, self::PORTAL_INTENTS)) {
            throw new \RuntimeException('invalid_intent', 1744278003);
        }
        return $this->workosClientFactory->createPortal()->generateLink(
            organization: $organizationId,
            intent: GenerateLinkIntent::from($intent),
            returnUrl: $returnUrl !== null && $returnUrl !== '' ? $returnUrl : null,
        );
    }

    /**
     * @return array<int, array{slug: string, labelKey: string}>
     */
    public function describePortalIntents(): array
    {
        $intents = [];
        foreach (self::PORTAL_INTENTS as $slug => $labelKey) {
            $intents[] = ['slug' => $slug, 'labelKey' => $labelKey];
        }
        return $intents;
    }

    private function assertConfigured(): void
    {
        if ($this->configuration->getApiKey() === '' || $this->configuration->getClientId() === '') {
            throw new \RuntimeException('WorkOS API key and client ID must be configured.', 1744278000);
        }
    }
}
