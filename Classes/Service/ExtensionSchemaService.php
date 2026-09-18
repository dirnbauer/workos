<?php

declare(strict_types=1);

namespace Webconsulting\WorkosAuth\Service;

use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;

/**
 * Reports and applies the pending schema changes of this extension's tables
 * through TYPO3's schema migrator (the same mechanism as `extension:setup`).
 *
 * @phpstan-type SchemaStatement array{connection: string, action: string, statement: string}
 */
final readonly class ExtensionSchemaService
{
    /**
     * @var list<string>
     */
    public const MANAGED_TABLES = ['tx_workosauth_identity'];

    /**
     * Non-destructive update suggestion groups of SchemaMigrator::getUpdateSuggestions().
     */
    private const APPLY_ACTIONS = ['add', 'change', 'create_table', 'change_table'];

    public function __construct(
        private SqlReader $sqlReader,
        private SchemaMigrator $schemaMigrator,
    ) {}

    /**
     * @return array{ready: bool, pendingCount: int, managedTables: list<string>, statements: list<SchemaStatement>, error: string}
     */
    public function getStatus(): array
    {
        try {
            $statements = array_values($this->getManagedUpdateStatements($this->getCreateTableStatements()));
            $error = '';
        } catch (\Throwable $exception) {
            $statements = [];
            $error = $exception->getMessage();
        }

        return [
            'ready' => $statements === [] && $error === '',
            'pendingCount' => count($statements),
            'managedTables' => self::MANAGED_TABLES,
            'statements' => $statements,
            'error' => $error,
        ];
    }

    /**
     * @return array{appliedCount: int, errors: array<string, string>}
     */
    public function applyPendingUpdates(): array
    {
        $databaseDefinitions = $this->getCreateTableStatements();
        $hashes = array_keys($this->getManagedUpdateStatements($databaseDefinitions));
        if ($hashes === []) {
            return ['appliedCount' => 0, 'errors' => []];
        }

        $errors = [];
        foreach ($this->schemaMigrator->migrate($databaseDefinitions, array_combine($hashes, $hashes)) as $hash => $message) {
            if (is_scalar($message) || $message instanceof \Stringable) {
                $errors[(string)$hash] = (string)$message;
            }
        }

        return ['appliedCount' => count($hashes) - count($errors), 'errors' => $errors];
    }

    /**
     * @param list<string> $databaseDefinitions
     * @return array<string, SchemaStatement> keyed by statement hash
     */
    private function getManagedUpdateStatements(array $databaseDefinitions): array
    {
        $managedStatements = [];
        foreach ($this->schemaMigrator->getUpdateSuggestions($databaseDefinitions) as $connectionName => $updateSuggestions) {
            foreach (self::APPLY_ACTIONS as $action) {
                $statements = $updateSuggestions[$action] ?? [];
                if (!is_array($statements)) {
                    continue;
                }
                foreach ($statements as $hash => $statement) {
                    if (is_string($hash) && is_string($statement) && self::referencesManagedTable($statement)) {
                        $managedStatements[$hash] = [
                            'connection' => (string)$connectionName,
                            'action' => $action,
                            'statement' => $statement,
                        ];
                    }
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
        return array_values(array_filter(
            $this->sqlReader->getCreateTableStatementArray($this->sqlReader->getTablesDefinitionString()),
            is_string(...)
        ));
    }

    private static function referencesManagedTable(string $statement): bool
    {
        return array_any(
            self::MANAGED_TABLES,
            static fn(string $tableName): bool => preg_match('/\b' . preg_quote($tableName, '/') . '\b/i', $statement) === 1
        );
    }
}
