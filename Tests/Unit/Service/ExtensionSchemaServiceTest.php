<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use WebConsulting\WorkosAuth\Service\ExtensionSchemaService;

final class ExtensionSchemaServiceTest extends TestCase
{
    public function testStatusOnlyReportsWorkosSchemaSuggestions(): void
    {
        $workosStatement = 'CREATE TABLE tx_workosauth_identity (uid INT);';
        $otherStatement = 'CREATE TABLE tx_other_extension (uid INT);';
        $databaseDefinitions = [$workosStatement, $otherStatement];

        $schemaMigrator = $this->createMock(SchemaMigrator::class);
        $schemaMigrator->expects(self::once())
            ->method('getUpdateSuggestions')
            ->with($databaseDefinitions)
            ->willReturn([
                'Default' => [
                    'create_table' => [
                        md5($workosStatement) => $workosStatement,
                        md5($otherStatement) => $otherStatement,
                    ],
                    'add' => [],
                    'change' => [],
                    'change_table' => [],
                ],
            ]);

        $status = (new ExtensionSchemaService(
            $this->createSqlReader($databaseDefinitions),
            $schemaMigrator,
        ))->getStatus();

        self::assertFalse($status['ready']);
        self::assertSame(1, $status['pendingCount']);
        self::assertSame('tx_workosauth_identity', $status['managedTables'][0]);
        self::assertSame($workosStatement, $status['statements'][0]['statement']);
    }

    public function testApplyPendingUpdatesPassesOnlyWorkosStatementHashesToSchemaMigrator(): void
    {
        $workosStatement = 'CREATE TABLE tx_workosauth_identity (uid INT);';
        $otherStatement = 'CREATE TABLE tx_other_extension (uid INT);';
        $databaseDefinitions = [$workosStatement, $otherStatement];
        $workosHash = md5($workosStatement);
        $otherHash = md5($otherStatement);

        $schemaMigrator = $this->createMock(SchemaMigrator::class);
        $schemaMigrator->expects(self::once())
            ->method('getUpdateSuggestions')
            ->with($databaseDefinitions)
            ->willReturn([
                'Default' => [
                    'create_table' => [
                        $workosHash => $workosStatement,
                        $otherHash => $otherStatement,
                    ],
                    'add' => [],
                    'change' => [],
                    'change_table' => [],
                ],
            ]);
        $schemaMigrator->expects(self::once())
            ->method('migrate')
            ->with(
                $databaseDefinitions,
                self::callback(static function (array $selectedStatements) use ($workosHash, $otherHash): bool {
                    self::assertSame($workosHash, $selectedStatements[$workosHash] ?? null);
                    self::assertArrayNotHasKey($otherHash, $selectedStatements);
                    return true;
                })
            )
            ->willReturn([]);

        $result = (new ExtensionSchemaService(
            $this->createSqlReader($databaseDefinitions),
            $schemaMigrator,
        ))->applyPendingUpdates();

        self::assertSame(1, $result['appliedCount']);
        self::assertSame([], $result['errors']);
    }

    /**
     * @param list<string> $databaseDefinitions
     */
    private function createSqlReader(array $databaseDefinitions): SqlReader
    {
        $sqlReader = $this->createMock(SqlReader::class);
        $sqlReader->method('getTablesDefinitionString')->willReturn(implode("\n\n", $databaseDefinitions));
        $sqlReader->method('getCreateTableStatementArray')->willReturn($databaseDefinitions);

        return $sqlReader;
    }
}
