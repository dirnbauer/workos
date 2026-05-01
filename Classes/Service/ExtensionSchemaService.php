<?php

declare(strict_types=1);

namespace WebConsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

final class ExtensionSchemaService
{
    /**
     * @var list<string>
     */
    private const MANAGED_TABLES = [
        'tx_workosauth_identity',
    ];

    private const APPLY_ACTIONS = [
        'add',
        'change',
        'create_table',
        'change_table',
    ];

    public function __construct(
        private readonly SqlReader $sqlReader,
        private readonly SchemaMigrator $schemaMigrator,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     pendingCount: int,
     *     managedTables: list<string>,
     *     statements: list<array{connection: string, action: string, statement: string}>,
     *     error: string
     * }
     */
    public function getStatus(): array
    {
        try {
            $statements = $this->getManagedUpdateStatements();
        } catch (\Throwable $exception) {
            return [
                'ready' => false,
                'pendingCount' => 0,
                'managedTables' => self::MANAGED_TABLES,
                'statements' => [],
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'ready' => $statements === [],
            'pendingCount' => count($statements),
            'managedTables' => self::MANAGED_TABLES,
            'statements' => array_values($statements),
            'error' => '',
        ];
    }

    /**
     * @return array{
     *     appliedCount: int,
     *     errors: array<string, string>
     * }
     */
    public function applyPendingUpdates(): array
    {
        $databaseDefinitions = $this->getCreateTableStatements();
        $pendingStatements = $this->getManagedUpdateStatements($databaseDefinitions);
        $selectedStatements = [];
        foreach (array_keys($pendingStatements) as $hash) {
            $selectedStatements[$hash] = $hash;
        }

        if ($selectedStatements === []) {
            return [
                'appliedCount' => 0,
                'errors' => [],
            ];
        }

        $errors = $this->schemaMigrator->migrate($databaseDefinitions, $selectedStatements);

        return [
            'appliedCount' => count($selectedStatements) - count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string>|null $databaseDefinitions
     * @return array<string, array{connection: string, action: string, statement: string}>
     */
    private function getManagedUpdateStatements(?array $databaseDefinitions = null): array
    {
        $databaseDefinitions ??= $this->getCreateTableStatements();
        $updateSuggestionsPerConnection = $this->schemaMigrator->getUpdateSuggestions($databaseDefinitions);
        $managedStatements = [];

        foreach ($updateSuggestionsPerConnection as $connectionName => $updateSuggestions) {
            foreach (self::APPLY_ACTIONS as $action) {
                $statements = $updateSuggestions[$action] ?? [];
                if (!is_array($statements)) {
                    continue;
                }
                foreach ($statements as $hash => $statement) {
                    if (!is_string($hash) || !is_string($statement) || !$this->referencesManagedTable($statement)) {
                        continue;
                    }
                    $managedStatements[$hash] = [
                        'connection' => (string)$connectionName,
                        'action' => $action,
                        'statement' => $statement,
                    ];
                }
            }
        }

        return $managedStatements;
    }

    /**
     * @return list<string>
     */
    private function getCreateTableStatements(): array
    {
        return array_values($this->sqlReader->getCreateTableStatementArray(
            $this->sqlReader->getTablesDefinitionString()
        ));
    }

    private function referencesManagedTable(string $statement): bool
    {
        foreach (self::MANAGED_TABLES as $tableName) {
            if (preg_match('/\b' . preg_quote($tableName, '/') . '\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }
}
