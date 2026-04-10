<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\UserManagement;
use WorkOS\WorkOS;

final class WorkosClientFactory
{
    public function __construct(
        private WorkosConfiguration $configuration,
    ) {}

    public function createUserManagement(): UserManagement
    {
        WorkOS::setApiKey($this->configuration->getApiKey());
        WorkOS::setClientId($this->configuration->getClientId());

        return new UserManagement();
    }
}
