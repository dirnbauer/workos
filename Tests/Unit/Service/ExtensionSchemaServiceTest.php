<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use Webconsulting\WorkosAuth\Service\ExtensionSchemaService;

final class ExtensionSchemaServiceTest extends TestCase
{
    private const WORKOS_STATEMENT = 'CREATE TABLE tx_workosauth_identity (uid INT);';
    private const OTHER_STATEMENT = 'CREATE TABLE tx_other_extension (uid INT);';

    public function testStatusOnlyReportsWorkosSchemaSuggestions(): void
    {
        $schemaMigrator = $this->createMock(SchemaMigrator::class);
        $schemaMigrator->expects(self::once())
            ->method('getUpdateSuggestions')
            ->with([self::WORKOS_STATEMENT, self::OTHER_STATEMENT])
            ->willReturn(['Default' => $this->suggestions()]);

        $status = (new ExtensionSchemaService($this->createSqlReader(), $schemaMigrator))->getStatus();

        self::assertFalse($status['ready']);
        self::assertSame(1, $status['pendingCount']);
        self::assertSame(['tx_workosauth_identity'], $status['managedTables']);
        self::assertSame(self::WORKOS_STATEMENT, $status['statements'][0]['statement']);
        self::assertSame('create_table', $status['statements'][0]['action']);
        self::assertSame('', $status['error']);
    }

    public function testStatusIsReadyWithoutPendingStatements(): void
    {
        $schemaMigrator = self::createStub(SchemaMigrator::class);
        $schemaMigrator->method('getUpdateSuggestions')->willReturn(['Default' => ['create_table' => [md5(self::OTHER_STATEMENT) => self::OTHER_STATEMENT]]]);

        $status = (new ExtensionSchemaService($this->createSqlReader(), $schemaMigrator))->getStatus();

        self::assertTrue($status['ready']);
        self::assertSame(0, $status['pendingCount']);
    }

    public function testStatusReportsMigratorFailuresAsNotReady(): void
    {
        $schemaMigrator = self::createStub(SchemaMigrator::class);
        $schemaMigrator->method('getUpdateSuggestions')->willThrowException(new \RuntimeException('no connection'));

        $status = (new ExtensionSchemaService($this->createSqlReader(), $schemaMigrator))->getStatus();

        self::assertFalse($status['ready']);
        self::assertSame('no connection', $status['error']);
    }

    public function testApplyPendingUpdatesPassesOnlyWorkosStatementHashesToSchemaMigrator(): void
    {
        $workosHash = md5(self::WORKOS_STATEMENT);
        $schemaMigrator = $this->createMock(SchemaMigrator::class);
        $schemaMigrator->method('getUpdateSuggestions')->willReturn(['Default' => $this->suggestions()]);
        $schemaMigrator->expects(self::once())
            ->method('migrate')
            ->with([self::WORKOS_STATEMENT, self::OTHER_STATEMENT], [$workosHash => $workosHash])
            ->willReturn([]);

        $result = (new ExtensionSchemaService($this->createSqlReader(), $schemaMigrator))->applyPendingUpdates();

        self::assertSame(['appliedCount' => 1, 'errors' => []], $result);
    }

    public function testApplyPendingUpdatesDoesNotMigrateWhenNothingIsPending(): void
    {
        $schemaMigrator = $this->createMock(SchemaMigrator::class);
        $schemaMigrator->method('getUpdateSuggestions')->willReturn([]);
        $schemaMigrator->expects(self::never())->method('migrate');

        self::assertSame(
            ['appliedCount' => 0, 'errors' => []],
            (new ExtensionSchemaService($this->createSqlReader(), $schemaMigrator))->applyPendingUpdates()
        );
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function suggestions(): array
    {
        return [
            'create_table' => [
                md5(self::WORKOS_STATEMENT) => self::WORKOS_STATEMENT,
                md5(self::OTHER_STATEMENT) => self::OTHER_STATEMENT,
            ],
            'add' => [],
            'change' => [],
            'change_table' => [],
        ];
    }

    private function createSqlReader(): SqlReader
    {
        $statements = [self::WORKOS_STATEMENT, self::OTHER_STATEMENT];
        $sqlReader = self::createStub(SqlReader::class);
        $sqlReader->method('getTablesDefinitionString')->willReturn(implode("\n\n", $statements));
        $sqlReader->method('getCreateTableStatementArray')->willReturn($statements);

        return $sqlReader;
    }
}
