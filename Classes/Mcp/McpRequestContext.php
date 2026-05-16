<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Mcp;

final class McpRequestContext
{
    /**
     * @param array<string, mixed> $claims
     * @param list<int> $frontendGroupUids
     * @param list<int> $backendGroupUids
     */
    public function __construct(
        public readonly string $authenticationMode,
        public readonly bool $workosRequired,
        public readonly ?string $workosUserId = null,
        public readonly ?string $email = null,
        public readonly ?int $frontendUserUid = null,
        public readonly array $frontendGroupUids = [],
        public readonly ?int $backendUserUid = null,
        public readonly array $backendGroupUids = [],
        public readonly array $claims = [],
    ) {}

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
            'authenticationMode' => $this->authenticationMode,
            'workosRequired' => $this->workosRequired,
            'workosAuthenticated' => $this->isWorkosAuthenticated(),
            'workosUserId' => $this->workosUserId,
            'email' => $this->email,
            'frontendUser' => [
                'uid' => $this->frontendUserUid,
                'groupUids' => $this->frontendGroupUids,
            ],
            'backendUser' => [
                'uid' => $this->backendUserUid,
                'groupUids' => $this->backendGroupUids,
            ],
        ];
    }
}
