<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WorkOS\WorkOS;

/**
 * Single entry point to the WorkOS PHP SDK. Every call builds a fresh client
 * from the current extension configuration, so credential changes made in
 * the setup assistant take effect without a cache flush.
 */
final readonly class WorkosClientFactory
{
    public function __construct(
        private WorkosConfiguration $configuration,
    ) {}

    /**
     * @throws \RuntimeException when API key or client id are not configured
     */
    public function client(): WorkOS
    {
        if (!$this->configuration->hasWorkosCredentials()) {
            throw new \RuntimeException('WorkOS API key and client ID must be configured.', 1744277900);
        }

        return new WorkOS(
            apiKey: $this->configuration->getApiKey(),
            clientId: $this->configuration->getClientId(),
        );
    }
}
