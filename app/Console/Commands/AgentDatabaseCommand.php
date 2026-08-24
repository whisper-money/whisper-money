<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AgentDatabaseCommand extends Command
{
    protected $signature = 'agent:db
        {query : The SQL query to run}
        {--format=json : Output format: "json" or "table"}
        {--prod : Run against the production database connection}';

    protected $description = 'Run a database query and return the result in the selected format';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['json', 'table'], true)) {
            $this->error('Invalid format. Use "json" or "table".');

            return self::FAILURE;
        }

        $connection = $this->option('prod') ? 'prod' : config('database.default');

        try {
            $rows = array_map(
                static fn (object $row): array => (array) $row,
                DB::connection($connection)->select($this->argument('query')),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($format === 'json') {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Empty result set.');

            return self::SUCCESS;
        }

        $this->table(array_keys($rows[0]), $rows);

        return self::SUCCESS;
    }
}
