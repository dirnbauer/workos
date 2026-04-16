<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\UserManagement;
use WorkOS\Widgets;
use WorkOS\WorkOS;

final class WorkosClientFactory
{
    public function __construct(
        private WorkosConfiguration $configuration,
    ) {}

    public function createUserManagement(): UserManagement
    {
        $this->primeClient();
        return new UserManagement();
    }

    public function createWidgets(): Widgets
    {
        $this->primeClient();
        return new Widgets();
    }

    private function primeClient(): void
    {
        WorkOS::setApiKey($this->configuration->getApiKey());
        WorkOS::setClientId($this->configuration->getClientId());
    }
}
