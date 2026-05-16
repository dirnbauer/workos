<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the `tx_workosauth_identity` TCA contract.
 *
 * The identity table is an authentication-state cache, not editorial
 * content. If a future refactor accidentally flips `versioningWS` to
 * true (or removes `adminOnly` / `hideTable`), workspace drafts could
 * diverge from live authentication mappings and admin users could no
 * longer distinguish it from editable content tables. These asserts
 * lock the contract in place.
 */
final class IdentityTableTcaTest extends TestCase
{
    /**
     * @var array{ctrl: array{
     *     versioningWS: bool,
     *     adminOnly: bool,
     *     hideTable: bool,
     *     rootLevel: int,
     * }, columns: array<string, mixed>}
     */
    private array $tca;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var array{ctrl: array{versioningWS: bool, adminOnly: bool, hideTable: bool, rootLevel: int}, columns: array<string, mixed>} $tca */
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/tx_workosauth_identity.php';
        $this->tca = $tca;
    }

    public function testWorkspaceVersioningIsExplicitlyDisabled(): void
    {
        self::assertFalse(
            $this->tca['ctrl']['versioningWS'],
            'tx_workosauth_identity must never be workspace-versioned: '
            . 'it is authentication state, not editorial content.'
        );
    }

    public function testTableIsAdminOnlyAndHidden(): void
    {
        self::assertTrue($this->tca['ctrl']['adminOnly']);
        self::assertTrue($this->tca['ctrl']['hideTable']);
    }

    public function testTableLivesAtRootLevelMinusOne(): void
    {
        self::assertSame(
            -1,
            $this->tca['ctrl']['rootLevel'],
            'Identity records must be allowed on pid 0 and any page (rootLevel=-1).'
        );
    }

    public function testAllFieldColumnsAreReadOnly(): void
    {
        $readOnlyFields = ['login_context', 'workos_user_id', 'email', 'user_table', 'user_uid', 'workos_profile_json'];
        foreach ($readOnlyFields as $field) {
            self::assertArrayHasKey($field, $this->tca['columns']);
            $column = $this->tca['columns'][$field];
            self::assertIsArray($column);
            self::assertArrayHasKey('config', $column);
            self::assertIsArray($column['config']);
            self::assertTrue(
                $column['config']['readOnly'] ?? false,
                sprintf('Column "%s" must be readOnly in the backend form.', $field)
            );
        }
    }
}
