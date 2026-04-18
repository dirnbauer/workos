<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the English ↔ German XLIFF parity. If any
 * translation key added to `locallang.xlf` forgets its German
 * counterpart (or vice versa), this test fails with the missing keys
 * listed by name — which is the form we need to fix it.
 */
final class XliffParityTest extends TestCase
{
    /**
     * @return array<int, array{source: string, target: string}>
     */
    public static function pairsProvider(): array
    {
        $languageDir = dirname(__DIR__, 3) . '/Resources/Private/Language';
        return [
            ['source' => $languageDir . '/locallang.xlf',          'target' => $languageDir . '/de.locallang.xlf'],
            ['source' => $languageDir . '/locallang_db.xlf',       'target' => $languageDir . '/de.locallang_db.xlf'],
            ['source' => $languageDir . '/locallang_mod.xlf',      'target' => $languageDir . '/de.locallang_mod.xlf'],
            ['source' => $languageDir . '/locallang_mod_users.xlf','target' => $languageDir . '/de.locallang_mod_users.xlf'],
            ['source' => $languageDir . '/locallang_mod_setup.xlf','target' => $languageDir . '/de.locallang_mod_setup.xlf'],
        ];
    }

    #[DataProvider('pairsProvider')]
    public function testGermanFileCoversAllEnglishKeys(string $source, string $target): void
    {
        if (!file_exists($target)) {
            self::markTestSkipped(sprintf('No German counterpart for %s.', basename($source)));
        }

        $sourceKeys = self::extractKeys($source);
        $targetKeys = self::extractKeys($target);

        self::assertSame(
            [],
            array_values(array_diff($sourceKeys, $targetKeys)),
            sprintf('German file %s is missing trans-units: %s',
                basename($target),
                implode(', ', array_diff($sourceKeys, $targetKeys))
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private static function extractKeys(string $file): array
    {
        $content = file_get_contents($file);
        self::assertIsString($content);
        preg_match_all('/<trans-unit\s+id="([^"]+)"/', $content, $matches);
        $keys = array_values(array_unique($matches[1]));
        sort($keys);
        return $keys;
    }
}
