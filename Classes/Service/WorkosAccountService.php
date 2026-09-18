<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use WorkOS\Resource\AuthenticationFactor;
use WorkOS\Resource\AuthenticationFactorsCreateRequestType;
use WorkOS\Resource\AuthenticationFactorTotp;
use WorkOS\Resource\Organization;
use WorkOS\Resource\User;
use WorkOS\Resource\UserAuthenticationFactorEnrollResponse;
use WorkOS\Resource\UserOrganizationMembership;
use WorkOS\Resource\UserSessionsListItem;
use WorkOS\Service\PasswordPlaintext;

/**
 * WorkOS calls behind the Account Center plugin: profile, password,
 * TOTP factors, sessions and organization memberships of one user.
 */
final readonly class WorkosAccountService
{
    public function __construct(
        private WorkosClientFactory $workosClientFactory,
    ) {}

    public function getUser(string $workosUserId): User
    {
        return $this->workosClientFactory->client()->userManagement()->getUser($workosUserId);
    }

    public function updateProfile(string $workosUserId, ?string $firstName, ?string $lastName): User
    {
        return $this->workosClientFactory->client()->userManagement()->updateUser(
            id: $workosUserId,
            firstName: $firstName,
            lastName: $lastName,
        );
    }

    public function changePassword(string $workosUserId, string $newPassword): User
    {
        if (mb_strlen($newPassword) < 10) {
            throw new \RuntimeException('password_too_short', 1744277901);
        }

        return $this->workosClientFactory->client()->userManagement()->updateUser(
            id: $workosUserId,
            password: new PasswordPlaintext($newPassword),
        );
    }

    /**
     * Enroll a new TOTP factor; the response carries the QR code (data URI),
     * the otpauth URI and the secret for manual entry.
     */
    public function enrollTotpFactor(string $workosUserId, string $issuer, string $accountName): UserAuthenticationFactorEnrollResponse
    {
        return $this->workosClientFactory->client()->multiFactorAuth()->createUserAuthFactor(
            userlandUserId: $workosUserId,
            type: AuthenticationFactorsCreateRequestType::Totp->value,
            totpIssuer: $issuer,
            totpUser: $accountName,
        );
    }

    /**
     * Activate a freshly enrolled factor with the six-digit authenticator code.
     */
    public function verifyTotpFactor(string $factorId, string $code): void
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new \RuntimeException('invalid_code_format', 1744277902);
        }

        $mfa = $this->workosClientFactory->client()->multiFactorAuth();
        $challengeId = $mfa->challengeFactor(id: $factorId)->id;
        if ($challengeId === '') {
            throw new \RuntimeException('challenge_failed', 1744277903);
        }

        $mfa->verifyChallenge($challengeId, $code);
    }

    /**
     * @return list<AuthenticationFactor>
     */
    public function listTotpFactors(string $workosUserId): array
    {
        $paginated = $this->workosClientFactory->client()->multiFactorAuth()->listUserAuthFactors(
            userlandUserId: $workosUserId,
            limit: 50,
        );

        return array_values(array_filter(
            $paginated->data,
            static fn(mixed $factor): bool => $factor instanceof AuthenticationFactor && $factor->totp instanceof AuthenticationFactorTotp
        ));
    }

    public function deleteFactor(string $factorId): void
    {
        $this->workosClientFactory->client()->multiFactorAuth()->deleteFactor($factorId);
    }

    /**
     * @return list<UserSessionsListItem>
     */
    public function listSessions(string $workosUserId, int $limit = 25): array
    {
        $paginated = $this->workosClientFactory->client()->userManagement()->listSessions(
            id: $workosUserId,
            limit: $limit,
        );

        return array_values(array_filter(
            $paginated->data,
            static fn(mixed $session): bool => $session instanceof UserSessionsListItem
        ));
    }

    public function revokeSession(string $sessionId): void
    {
        $this->workosClientFactory->client()->userManagement()->revokeSession($sessionId);
    }

    /**
     * Memberships with their organization; the organization is null when it
     * can no longer be loaded (e.g. deleted in WorkOS).
     *
     * @return list<array{membership: UserOrganizationMembership, organization: ?Organization}>
     */
    public function listOrganizationMemberships(string $workosUserId): array
    {
        $client = $this->workosClientFactory->client();
        $response = $client->organizationMembership()->listOrganizationMemberships(
            userId: $workosUserId,
            limit: 50,
        );

        $memberships = [];
        foreach ($response->data as $membership) {
            if (!$membership instanceof UserOrganizationMembership) {
                continue;
            }
            try {
                $organization = $client->organizations()->getOrganization($membership->organizationId);
            } catch (\Throwable) {
                $organization = null;
            }
            $memberships[] = ['membership' => $membership, 'organization' => $organization];
        }

        return $memberships;
    }
}
