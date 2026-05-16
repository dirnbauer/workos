<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Resource\AuthenticationFactor;
use WorkOS\Resource\AuthenticationFactorsCreateRequestType;
use WorkOS\Resource\AuthenticationFactorTotp;
use WorkOS\Resource\Organization;
use WorkOS\Resource\User;
use WorkOS\Resource\UserAuthenticationFactorEnrollResponse;
use WorkOS\Resource\UserOrganizationMembership;
use WorkOS\Resource\UserSessionsListItem;

/**
 * High-level facade around the WorkOS UserManagement endpoints used by
 * the Account Center plugin: profile updates, password changes,
 * TOTP/MFA enrollment, sessions and organization memberships.
 */
final class WorkosAccountService
{
    public function __construct(
        private WorkosConfiguration $configuration,
        private WorkosClientFactory $workosClientFactory,
    ) {}

    public function getUser(string $workosUserId): User
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createUserManagement()->getUser($workosUserId);
    }

    public function updateProfile(string $workosUserId, ?string $firstName, ?string $lastName): User
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createUserManagement()->updateUser(
            id: $workosUserId,
            firstName: $firstName,
            lastName: $lastName,
        );
    }

    public function changePassword(string $workosUserId, string $newPassword): User
    {
        $this->assertConfigured();
        if (mb_strlen($newPassword) < 10) {
            throw new \RuntimeException('password_too_short', 1744277901);
        }
        return $this->workosClientFactory->createUserManagement()->updateUser(
            id: $workosUserId,
            password: $newPassword,
        );
    }

    /**
     * Enroll a brand-new TOTP factor. Returns the factor and a
     * scannable QR code (data URI) plus a URI for manual entry.
     */
    public function enrollTotpFactor(string $workosUserId, string $issuer, string $accountName): UserAuthenticationFactorEnrollResponse
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createMultiFactorAuth()->createUserAuthFactor(
            userlandUserId: $workosUserId,
            type: AuthenticationFactorsCreateRequestType::Totp->value,
            totpIssuer: $issuer,
            totpUser: $accountName,
        );
    }

    /**
     * Activate an existing factor by submitting the 6-digit code from
     * the user's authenticator app.
     *
     * @return array{response: mixed, factorId: string}
     */
    public function verifyTotpFactor(string $factorId, string $code): array
    {
        $this->assertConfigured();
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new \RuntimeException('invalid_code_format', 1744277902);
        }

        $mfa = $this->workosClientFactory->createMultiFactorAuth();
        $challenge = $mfa->challengeFactor(id: $factorId);
        $challengeId = $challenge->id;
        if ($challengeId === '') {
            throw new \RuntimeException('challenge_failed', 1744277903);
        }

        return [
            'response' => $mfa->verifyChallenge($challengeId, $code),
            'factorId' => $factorId,
        ];
    }

    /**
     * @return AuthenticationFactor[]
     */
    public function listTotpFactors(string $workosUserId): array
    {
        $this->assertConfigured();
        $paginated = $this->workosClientFactory->createMultiFactorAuth()->listUserAuthFactors(
            userlandUserId: $workosUserId,
            limit: 50,
        );

        $factors = [];
        foreach ($paginated->data as $factor) {
            if ($factor instanceof AuthenticationFactor && $factor->totp instanceof AuthenticationFactorTotp) {
                $factors[] = $factor;
            }
        }

        return $factors;
    }

    public function deleteFactor(string $factorId): void
    {
        $this->assertConfigured();
        $this->workosClientFactory->createMultiFactorAuth()->deleteFactor($factorId);
    }

    /**
     * @return UserSessionsListItem[]
     */
    public function listSessions(string $workosUserId, int $limit = 25): array
    {
        $this->assertConfigured();
        $paginated = $this->workosClientFactory->createUserManagement()->listSessions(
            id: $workosUserId,
            limit: $limit,
        );

        $sessions = [];
        foreach ($paginated->data as $session) {
            if ($session instanceof UserSessionsListItem) {
                $sessions[] = $session;
            }
        }
        return $sessions;
    }

    public function revokeSession(string $sessionId): void
    {
        $this->assertConfigured();
        $this->workosClientFactory->createUserManagement()->revokeSession($sessionId);
    }

    /**
     * @return array<int, array{membership: UserOrganizationMembership, organization: ?Organization}>
     */
    public function listOrganizationMemberships(string $workosUserId): array
    {
        $this->assertConfigured();
        $userManagement = $this->workosClientFactory->createUserManagement();

        $response = $userManagement->listOrganizationMemberships(
            userId: $workosUserId,
            limit: 50,
        );

        $organizations = $this->workosClientFactory->createOrganizations();
        $memberships = [];
        foreach ($response->data as $membership) {
            if (!$membership instanceof UserOrganizationMembership) {
                continue;
            }

            $organization = null;
            try {
                $organization = $organizations->getOrganization($membership->organizationId);
            } catch (\Throwable) {
                // Organization may have been removed; degrade gracefully.
            }

            $memberships[] = [
                'membership' => $membership,
                'organization' => $organization,
            ];
        }

        return $memberships;
    }

    private function assertConfigured(): void
    {
        if ($this->configuration->getApiKey() === '' || $this->configuration->getClientId() === '') {
            throw new \RuntimeException('WorkOS API key and client ID must be configured.', 1744277900);
        }
    }
}
