<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Domain;

/**
 * How the TYPO3 MCP endpoint authenticates callers.
 */
enum McpAuthenticationMode: string
{
    /** Anonymous in Development/Testing, WorkOS bearer tokens in Production. */
    case Auto = 'auto';
    /** Always require a WorkOS AuthKit bearer token. */
    case Workos = 'workos';
    /** Never require a token, in every application context. */
    case Anonymous = 'anonymous';

    public function requiresWorkos(bool $isProductionContext): bool
    {
        return match ($this) {
            self::Workos => true,
            self::Anonymous => false,
            self::Auto => $isProductionContext,
        };
    }

    public function labelKey(): string
    {
        return 'setup.mcp.authenticationMode.' . $this->value;
    }

    public function descriptionKey(): string
    {
        return 'module.mcp.mode.' . $this->value . '.description';
    }
}
