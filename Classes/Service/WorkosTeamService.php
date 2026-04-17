<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationMembership;
use WorkOS\Resource\PortalLink;

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
            statuses: ['active'],
            limit: 50,
        );

        $result = [];
        foreach (($response->data ?? []) as $membership) {
            if (!$membership instanceof OrganizationMembership) {
                continue;
            }
            $organizationId = (string)$membership->organizationId;
            if ($organizationId === '' || isset($result[$organizationId])) {
                continue;
            }

            try {
                $organization = $organizations->getOrganization($organizationId);
                if ($organization instanceof Organization) {
                    $result[$organizationId] = $organization;
                }
            } catch (\Throwable) {
                // Skip organizations the API key can no longer load.
            }
        }

        return $result;
    }

    /**
     * @return Invitation[]
     */
    public function listInvitations(string $organizationId, int $limit = 25): array
    {
        $this->assertConfigured();
        $response = $this->workosClientFactory->createUserManagement()->listInvitations(
            organizationId: $organizationId,
            limit: $limit,
            order: 'desc',
        );

        $invitations = [];
        foreach (($response->data ?? []) as $invitation) {
            if ($invitation instanceof Invitation) {
                $invitations[] = $invitation;
            }
        }
        return $invitations;
    }

    public function sendInvitation(
        string $email,
        string $organizationId,
        ?string $inviterUserId = null,
        ?string $roleSlug = null,
        ?int $expiresInDays = null,
    ): Invitation {
        $this->assertConfigured();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('invalid_email', 1744278001);
        }
        if ($organizationId === '') {
            throw new \RuntimeException('organization_required', 1744278002);
        }

        return $this->workosClientFactory->createUserManagement()->sendInvitation(
            email: $email,
            organizationId: $organizationId,
            expiresInDays: $expiresInDays,
            inviterUserId: $inviterUserId !== '' ? $inviterUserId : null,
            roleSlug: $roleSlug !== null && $roleSlug !== '' ? $roleSlug : null,
        );
    }

    public function resendInvitation(string $invitationId): Invitation
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
    ): PortalLink {
        $this->assertConfigured();
        if (!array_key_exists($intent, self::PORTAL_INTENTS)) {
            throw new \RuntimeException('invalid_intent', 1744278003);
        }
        return $this->workosClientFactory->createPortal()->generateLink(
            organization: $organizationId,
            intent: $intent,
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
