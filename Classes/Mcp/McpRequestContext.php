<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Mcp;

use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;

/**
 * Who is calling the MCP endpoint: anonymous, or a WorkOS user with the
 * linked TYPO3 frontend/backend users and their groups.
 */
final readonly class McpRequestContext
{
    /**
     * @param list<int> $frontendGroupUids
     * @param list<int> $backendGroupUids
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public McpAuthenticationMode $authenticationMode,
        public bool $workosRequired,
        public ?string $workosUserId = null,
        public ?string $email = null,
        public ?int $frontendUserUid = null,
        public array $frontendGroupUids = [],
        public ?int $backendUserUid = null,
        public array $backendGroupUids = [],
        public array $claims = [],
    ) {}

    public static function anonymous(): self
    {
        return new self(McpAuthenticationMode::Anonymous, false);
    }

    public function isWorkosAuthenticated(): bool
    {
        return $this->workosUserId !== null && $this->workosUserId !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'authenticationMode' => $this->authenticationMode->value,
            'workosRequired' => $this->workosRequired,
            'workosAuthenticated' => $this->isWorkosAuthenticated(),
            'workosUserId' => $this->workosUserId,
            'email' => $this->email,
            'frontendUser' => ['uid' => $this->frontendUserUid, 'groupUids' => $this->frontendGroupUids],
            'backendUser' => ['uid' => $this->backendUserUid, 'groupUids' => $this->backendGroupUids],
        ];
    }
}
