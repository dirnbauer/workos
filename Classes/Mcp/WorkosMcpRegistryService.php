<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Mcp;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use WebConsulting\WorkosAuth\Configuration\WorkosConfiguration;
use WebConsulting\WorkosAuth\Security\SecretRedactor;
use WebConsulting\WorkosAuth\Service\WorkosClientFactory;
use WorkOS\Resource\AuthorizedConnectApplicationListData;

final class WorkosMcpRegistryService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosClientFactory $workosClientFactory,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAuthorizedServers(McpRequestContext $context): array
    {
        if (!$this->configuration->shouldDiscoverWorkosMcpServers()
            || !$context->isWorkosAuthenticated()
            || !$this->configuration->hasWorkosCredentials()
        ) {
            return [];
        }

        try {
            $response = $this->workosClientFactory
                ->createUserManagement()
                ->listUserAuthorizedApplications(
                    userId: (string)$context->workosUserId,
                    limit: $this->configuration->getMcpServerLimit(),
                );
        } catch (\Throwable $exception) {
            $this->logger?->warning('WorkOS MCP discovery failed: ' . SecretRedactor::redact($exception->getMessage()));
            return [];
        }

        $servers = [];
        foreach ($response->data as $authorizedApplication) {
            if (!$authorizedApplication instanceof AuthorizedConnectApplicationListData) {
                continue;
            }
            $application = $authorizedApplication->application;
            $servers[] = [
                'authorizedApplicationId' => $authorizedApplication->id,
                'applicationId' => $application->id,
                'clientId' => $application->clientId,
                'name' => $application->name,
                'description' => $application->description,
                'availableScopes' => $application->scopes,
                'grantedScopes' => $authorizedApplication->grantedScopes,
            ];
            if (count($servers) >= $this->configuration->getMcpServerLimit()) {
                break;
            }
        }

        return $servers;
    }
}
