<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Mcp;

final readonly class McpRequestContext
{
    /**
     * @param array<string, mixed> $claims
     * @param list<int> $frontendGroupUids
     * @param list<int> $backendGroupUids
     */
    public function __construct(
        public string $authenticationMode,
        public bool $workosRequired,
        public ?string $workosUserId = null,
        public ?string $email = null,
        public ?int $frontendUserUid = null,
        public array $frontendGroupUids = [],
        public ?int $backendUserUid = null,
        public array $backendGroupUids = [],
        public array $claims = [],
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
