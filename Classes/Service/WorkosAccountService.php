<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Resource\AuthenticationFactorAndChallengeTotp;
use WorkOS\Resource\AuthenticationFactorTotp;
use WorkOS\Resource\OrganizationMembership;
use WorkOS\Resource\Organization;
use WorkOS\Resource\Session;
use WorkOS\Resource\User;
use WorkOS\Resource\UserAuthenticationFactorTotp;

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
            userId: $workosUserId,
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
            userId: $workosUserId,
            password: $newPassword,
        );
    }

    /**
     * Enroll a brand-new TOTP factor. Returns the factor and a
     * scannable QR code (data URI) plus a URI for manual entry.
     */
    public function enrollTotpFactor(string $workosUserId, string $issuer, string $accountName): AuthenticationFactorAndChallengeTotp
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createUserManagement()->enrollAuthFactor(
            userId: $workosUserId,
            type: 'totp',
            totpIssuer: $issuer,
            totpUser: $accountName,
        );
    }

    /**
     * Activate an existing factor by submitting the 6-digit code from
     * the user's authenticator app.
     */
    public function verifyTotpFactor(string $factorId, string $code): array
    {
        $this->assertConfigured();
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new \RuntimeException('invalid_code_format', 1744277902);
        }

        $this->workosClientFactory->createUserManagement();
        $mfa = new \WorkOS\MFA();
        $challenge = $mfa->challengeFactor(authenticationFactorId: $factorId);
        $challengeId = (string)($challenge->id ?? '');
        if ($challengeId === '') {
            throw new \RuntimeException('challenge_failed', 1744277903);
        }

        return [
            'response' => $mfa->verifyChallenge($challengeId, $code),
            'factorId' => $factorId,
        ];
    }

    /**
     * @return AuthenticationFactorTotp[]|UserAuthenticationFactorTotp[]
     */
    public function listTotpFactors(string $workosUserId): array
    {
        $this->assertConfigured();
        return $this->workosClientFactory->createUserManagement()->listAuthFactors($workosUserId);
    }

    public function deleteFactor(string $factorId): void
    {
        $this->assertConfigured();
        $this->workosClientFactory->createUserManagement();
        (new \WorkOS\MFA())->deleteFactor($factorId);
    }

    /**
     * @return Session[]
     */
    public function listSessions(string $workosUserId, int $limit = 25): array
    {
        $this->assertConfigured();
        $paginated = $this->workosClientFactory->createUserManagement()->listSessions(
            userId: $workosUserId,
            options: ['limit' => $limit, 'order' => 'desc'],
        );

        $sessions = [];
        foreach ($paginated->data ?? [] as $session) {
            if ($session instanceof Session) {
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
     * @return array<int, array{membership: OrganizationMembership, organization: ?Organization}>
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
        foreach (($response->data ?? []) as $membership) {
            if (!$membership instanceof OrganizationMembership) {
                continue;
            }

            $organization = null;
            try {
                $organization = $organizations->getOrganization((string)$membership->organizationId);
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
