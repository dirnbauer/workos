<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Service\AdminPortal;
use WorkOS\Service\MultiFactorAuth;
use WorkOS\Service\OrganizationMembershipService;
use WorkOS\Service\Organizations;
use WorkOS\Service\UserManagement;
use WorkOS\Service\Widgets;
use WorkOS\WorkOS;

/**
 * Single entry point to the WorkOS PHP SDK. Every accessor builds a fresh
 * client from the current extension configuration, so credential changes
 * made in the setup assistant take effect without a cache flush.
 */
final readonly class WorkosClientFactory
{
    public function __construct(
        private WorkosConfiguration $configuration,
    ) {}

    public function createClient(): WorkOS
    {
        return new WorkOS(
            apiKey: $this->configuration->getApiKey(),
            clientId: $this->configuration->getClientId(),
        );
    }

    public function createUserManagement(): UserManagement
    {
        return $this->createClient()->userManagement();
    }

    /**
     * Organization membership endpoints moved out of UserManagement in
     * workos-php 7.0 and live in their own service since then.
     */
    public function createOrganizationMembership(): OrganizationMembershipService
    {
        return $this->createClient()->organizationMembership();
    }

    public function createMultiFactorAuth(): MultiFactorAuth
    {
        return $this->createClient()->multiFactorAuth();
    }

    public function createWidgets(): Widgets
    {
        return $this->createClient()->widgets();
    }

    public function createOrganizations(): Organizations
    {
        return $this->createClient()->organizations();
    }

    public function createPortal(): AdminPortal
    {
        return $this->createClient()->adminPortal();
    }
}
