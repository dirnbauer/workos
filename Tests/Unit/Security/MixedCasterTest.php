<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebConsulting\WorkosAuth\Security\MixedCaster;

final class MixedCasterTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function stringProvider(): array
    {
        return [
            'string passthrough' => ['hello', 'hello'],
            'int to string' => [42, '42'],
            'float to string' => [3.14, '3.14'],
            'true to string' => [true, '1'],
            'false to string' => [false, ''],
            'null uses default' => [null, ''],
            'array uses default' => [['a'], ''],
            'object uses default' => [new \stdClass(), ''],
        ];
    }

    #[DataProvider('stringProvider')]
    public function testString(mixed $value, string $expected): void
    {
        self::assertSame($expected, MixedCaster::string($value));
    }

    public function testStringHonoursCustomDefault(): void
    {
        self::assertSame('fallback', MixedCaster::string(null, 'fallback'));
        self::assertSame('fallback', MixedCaster::string(['a'], 'fallback'));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function intProvider(): array
    {
        return [
            'int passthrough' => [42, 42],
            'numeric string' => ['42', 42],
            'numeric negative' => ['-5', -5],
            'float rounds down' => [3.9, 3],
            'bool true' => [true, 1],
            'bool false' => [false, 0],
            'non-numeric string uses default' => ['abc', 0],
            'null uses default' => [null, 0],
            'array uses default' => [[1, 2], 0],
        ];
    }

    #[DataProvider('intProvider')]
    public function testInt(mixed $value, int $expected): void
    {
        self::assertSame($expected, MixedCaster::int($value));
    }

    public function testIntHonoursCustomDefault(): void
    {
        self::assertSame(99, MixedCaster::int('abc', 99));
        self::assertSame(99, MixedCaster::int(null, 99));
    }
}
