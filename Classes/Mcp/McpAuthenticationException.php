<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Mcp;

final class McpAuthenticationException extends \RuntimeException
{
    public static function missingToken(): self
    {
        return new self('MCP requests require a WorkOS bearer token.', 1746099201);
    }

    public static function missingAuthkitDomain(): self
    {
        return new self('MCP WorkOS authentication requires an AuthKit domain.', 1746099202);
    }

    public static function invalidToken(): self
    {
        return new self('The MCP bearer token is invalid or expired.', 1746099203);
    }
}
