<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\Service\AdminPortal;
use WorkOS\Service\MultiFactorAuth;
use WorkOS\Service\Organizations;
use WorkOS\Service\UserManagement;
use WorkOS\Service\Widgets;
use WorkOS\WorkOS;

final class WorkosClientFactory
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
