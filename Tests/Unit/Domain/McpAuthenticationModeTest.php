<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\WorkosAuth\Domain\McpAuthenticationMode;

final class McpAuthenticationModeTest extends TestCase
{
    /**
     * @return iterable<string, array{McpAuthenticationMode, bool, bool}>
     */
    public static function requiresWorkosProvider(): iterable
    {
        yield 'workos in development' => [McpAuthenticationMode::Workos, false, true];
        yield 'workos in production' => [McpAuthenticationMode::Workos, true, true];
        yield 'anonymous in development' => [McpAuthenticationMode::Anonymous, false, false];
        yield 'anonymous in production' => [McpAuthenticationMode::Anonymous, true, false];
        yield 'auto in development' => [McpAuthenticationMode::Auto, false, false];
        yield 'auto in production' => [McpAuthenticationMode::Auto, true, true];
    }

    #[DataProvider('requiresWorkosProvider')]
    public function testRequiresWorkos(McpAuthenticationMode $mode, bool $isProduction, bool $expected): void
    {
        self::assertSame($expected, $mode->requiresWorkos($isProduction));
    }

    public function testLabelKeysFollowTheStoredValue(): void
    {
        self::assertSame('setup.mcp.authenticationMode.workos', McpAuthenticationMode::Workos->labelKey());
        self::assertSame('module.mcp.mode.anonymous.description', McpAuthenticationMode::Anonymous->descriptionKey());
    }
}
