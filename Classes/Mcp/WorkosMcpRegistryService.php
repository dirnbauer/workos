<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Mcp;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Webconsulting\WorkosAuth\Configuration\WorkosConfiguration;
use Webconsulting\WorkosAuth\Security\SecretRedactor;
use Webconsulting\WorkosAuth\Service\WorkosClientFactory;
use WorkOS\Resource\AuthorizedConnectApplicationListData;

/**
 * WorkOS is the source of truth for MCP applications: this lists the Connect
 * applications the current WorkOS user has authorized, capped by the
 * configured limit.
 */
final class WorkosMcpRegistryService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly WorkosConfiguration $configuration,
        private readonly WorkosClientFactory $workosClientFactory,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listAuthorizedServers(McpRequestContext $context): array
    {
        if (!$this->configuration->shouldDiscoverWorkosMcpServers()
            || !$context->isWorkosAuthenticated()
            || !$this->configuration->hasWorkosCredentials()
        ) {
            return [];
        }

        $limit = $this->configuration->getMcpServerLimit();
        try {
            $response = $this->workosClientFactory->client()->userManagement()->listUserAuthorizedApplications(
                userId: (string)$context->workosUserId,
                limit: $limit,
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
            if (count($servers) >= $limit) {
                break;
            }
        }

        return $servers;
    }
}
