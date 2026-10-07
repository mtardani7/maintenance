<?php

namespace App\Console\Commands;

use App\Services\MiraIntentService;
use App\Services\MiraOllamaClient;
use App\Services\MiraQueryService;
use App\Services\MiraResponseFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MiraTestCommand extends Command
{
    protected $signature = 'mira:test';

    protected $description = 'Check MIRA Ollama, read-only PostgreSQL, intent, query, and response';

    public function handle(
        MiraOllamaClient $ollama,
        MiraIntentService $intents,
        MiraQueryService $queries,
        MiraResponseFormatter $formatter,
    ): int {
        $failures = 0;
        $intent = null;
        $queryResult = null;

        $this->report('Ollama', static fn () => $ollama->check(), $failures);
        $this->report('PostgreSQL', fn () => $this->checkReadOnlyDatabase(), $failures);

        $this->report('Intent', function () use ($intents, &$intent): void {
            $result = $intents->understand('Berapa tiket open hari ini?');
            $intent = $result['intent'];

            if (! is_array($intent)
                || $intent['entity'] !== 'ticket'
                || $intent['metric'] !== 'count'
                || $intent['group_by'] !== 'none'
                || $intent['period'] !== 'today'
                || ($intent['filter']['status'] ?? null) !== 'open') {
                throw new \RuntimeException('Ollama did not return the expected ticket-count intent.');
            }
        }, $failures);

        $this->report('Query', function () use ($queries, &$intent, &$queryResult): void {
            if (! is_array($intent)) {
                throw new \RuntimeException('No valid intent is available.');
            }

            $queryResult = $queries->run($intent);

            if (! is_array($queryResult)) {
                throw new \RuntimeException('Intent is not supported by the read-only query service.');
            }
        }, $failures);

        $this->report('Response', function () use ($formatter, &$queryResult, &$intent): void {
            if (! is_array($queryResult)) {
                throw new \RuntimeException('No query result is available.');
            }

            if (! is_array($intent)) {
                throw new \RuntimeException('No valid intent is available.');
            }

            $result = $formatter->format('Berapa tiket open hari ini?', $intent, $queryResult);

            if (trim($result) === '') {
                throw new \RuntimeException('Formatter returned an empty response.');
            }
        }, $failures);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkReadOnlyDatabase(): void
    {
        $connection = DB::connection('pgsql_mira');
        $configuredUser = (string) config('database.connections.pgsql_mira.username');
        $tables = ['maintenance_tickets', 'incidents', 'machines', 'plants', 'maintenance_ticket_spare_parts'];
        $checks = [];
        $bindings = [];

        foreach ($tables as $index => $table) {
            $checks[] = "has_table_privilege(current_user, ?, 'SELECT') as select_{$index}";
            $bindings[] = $table;

            $writeChecks = [];
            foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
                $writeChecks[] = "has_table_privilege(current_user, ?, '{$privilege}')";
                $bindings[] = $table;
            }
            $checks[] = '('.implode(' OR ', $writeChecks).") as write_{$index}";
        }

        $rows = $connection->select(
            'select current_user as username, '
                .'has_schema_privilege(current_user, ?, \'CREATE\') as can_create_schema, '
                .'has_database_privilege(current_user, current_database(), \'CREATE\') as can_create_database_objects, '
                .'has_database_privilege(current_user, current_database(), \'TEMPORARY\') as can_create_temp_tables, '
                .'roles.rolsuper as is_superuser, roles.rolcreatedb as can_create_database, '
                .'roles.rolcreaterole as can_create_role, '.implode(', ', $checks)
                .' from pg_roles as roles where roles.rolname = current_user',
            array_merge(['public'], $bindings),
        );
        $row = $rows[0] ?? null;

        if (! $row || $configuredUser !== 'mira_readonly' || $row->username !== 'mira_readonly'
            || $row->can_create_schema || $row->can_create_database_objects || $row->can_create_temp_tables
            || $row->is_superuser || $row->can_create_database || $row->can_create_role) {
            throw new \RuntimeException('MIRA database connection is not using mira_readonly.');
        }

        foreach ($tables as $index => $table) {
            if (! $row->{"select_{$index}"} || $row->{"write_{$index}"}) {
                throw new \RuntimeException("MIRA role does not have SELECT-only access to {$table}.");
            }
        }
    }

    private function report(string $name, callable $check, int &$failures): void
    {
        try {
            $check();
            $this->line("[OK] {$name}");
        } catch (Throwable) {
            $failures++;
            $this->error("[FAIL] {$name}");
        }
    }
}
