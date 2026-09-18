<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use WorkOS\Resource\GenerateLinkIntent;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\Organization;
use WorkOS\Resource\OrganizationMembershipStatus;
use WorkOS\Resource\PaginationOrder;
use WorkOS\Resource\PortalLinkResponse;
use WorkOS\Resource\UserInvite;
use WorkOS\Resource\UserOrganizationMembership;

/**
 * WorkOS calls behind the Team plugin: organization invitations and one-time
 * Admin Portal links (SSO, Directory Sync, Audit Logs, ...).
 */
final readonly class WorkosTeamService
{
    /**
     * Only organization-level admin roles may use the Team plugin.
     *
     * @var list<string>
     */
    private const MANAGEMENT_ROLE_SLUGS = ['admin', 'owner'];

    /**
     * Admin Portal intent => translation key, in dashboard order.
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
        private WorkosClientFactory $workosClientFactory,
    ) {}

    /**
     * Organizations the user administers, as `[organizationId => Organization]`.
     * Organizations the API key can no longer load are skipped.
     *
     * @return array<string, Organization>
     */
    public function listAdminOrganizations(string $workosUserId): array
    {
        $client = $this->workosClientFactory->client();
        $response = $client->organizationMembership()->listOrganizationMemberships(
            userId: $workosUserId,
            limit: 50,
        );

        $result = [];
        foreach ($response->data as $membership) {
            if (!$membership instanceof UserOrganizationMembership || !self::canManage($membership)) {
                continue;
            }
            $organizationId = $membership->organizationId;
            if ($organizationId === '' || isset($result[$organizationId])) {
                continue;
            }
            try {
                $result[$organizationId] = $client->organizations()->getOrganization($organizationId);
            } catch (\Throwable) {
                // Skip organizations the API key can no longer load.
            }
        }

        return $result;
    }

    /**
     * Authorization guard for every action whose organization id comes from
     * the POST body: the user must be an active admin/owner of that
     * organization, otherwise a logged-in frontend user could invite, revoke
     * or mint portal links for arbitrary organizations the API key can reach.
     */
    public function assertMemberOfOrganization(string $workosUserId, string $organizationId): void
    {
        if ($workosUserId === '' || $organizationId === '') {
            throw new \RuntimeException('forbidden_organization', 1744278100);
        }

        $response = $this->workosClientFactory->client()->organizationMembership()->listOrganizationMemberships(
            userId: $workosUserId,
            organizationId: $organizationId,
            limit: 1,
        );
        $isMember = array_any(
            $response->data,
            static fn(mixed $membership): bool => $membership instanceof UserOrganizationMembership
                && self::canManage($membership)
                && $membership->userId === $workosUserId
                && $membership->organizationId === $organizationId
        );
        if (!$isMember) {
            throw new \RuntimeException('forbidden_organization', 1744278101);
        }
    }

    /**
     * Organization id of an invitation, so callers can authorize before acting on it.
     */
    public function findInvitationOrganizationId(string $invitationId): string
    {
        if ($invitationId === '') {
            return '';
        }
        try {
            return $this->workosClientFactory->client()->userManagement()->getInvitation($invitationId)->organizationId ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<UserInvite>
     */
    public function listInvitations(string $organizationId, int $limit = 25): array
    {
        $response = $this->workosClientFactory->client()->userManagement()->listInvitations(
            organizationId: $organizationId,
            limit: $limit,
            order: PaginationOrder::Desc,
        );

        return array_values(array_filter(
            $response->data,
            static fn(mixed $invitation): bool => $invitation instanceof UserInvite
        ));
    }

    public function sendInvitation(string $email, string $organizationId, string $inviterUserId, ?string $roleSlug): UserInvite
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('invalid_email', 1744278001);
        }
        if ($organizationId === '') {
            throw new \RuntimeException('organization_required', 1744278002);
        }

        return $this->workosClientFactory->client()->userManagement()->sendInvitation(
            email: $email,
            organizationId: $organizationId,
            inviterUserId: $inviterUserId !== '' ? $inviterUserId : null,
            roleSlug: $roleSlug,
        );
    }

    public function resendInvitation(string $invitationId): UserInvite
    {
        return $this->workosClientFactory->client()->userManagement()->resendInvitation($invitationId);
    }

    public function revokeInvitation(string $invitationId): Invitation
    {
        return $this->workosClientFactory->client()->userManagement()->revokeInvitation($invitationId);
    }

    public function generatePortalLink(string $organizationId, string $intent, ?string $returnUrl): PortalLinkResponse
    {
        if (!array_key_exists($intent, self::PORTAL_INTENTS)) {
            throw new \RuntimeException('invalid_intent', 1744278003);
        }

        return $this->workosClientFactory->client()->adminPortal()->generateLink(
            organization: $organizationId,
            intent: GenerateLinkIntent::from($intent),
            returnUrl: $returnUrl !== '' ? $returnUrl : null,
        );
    }

    private static function canManage(UserOrganizationMembership $membership): bool
    {
        return $membership->status === OrganizationMembershipStatus::Active
            && in_array(strtolower(trim($membership->role->slug)), self::MANAGEMENT_ROLE_SLUGS, true);
    }
}
